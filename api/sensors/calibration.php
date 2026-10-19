<?php
/**
 * /api/sensors/calibration.php
 *
 * GET  - current calibration values, the live raw reading and the history.
 * POST - save a new calibration, or send a tare command to the scale.
 *
 * Load cell
 *   calibration_factor is HX711 counts per gram, offset_value is the tare in
 *   raw counts. Both are pushed to the firmware, which does the conversion
 *   locally so it still weighs correctly with no network.
 *
 *   Supply either a factor directly, or a two-point calibration
 *   (offset_value + raw_value observed with a known reference mass), in which
 *   case the factor is derived as:
 *
 *       factor = (raw_at_reference - tare_offset) / reference_weight_grams
 *
 * Ultrasonic
 *   calibration_factor is the usable tank depth in cm, offset_value is the gap
 *   between the sensor face and the full-water line. These are also mirrored
 *   into system_settings, which is what the server uses to turn a distance
 *   reading into a water depth.
 *
 * Requires the sensors.calibrate permission (Farm Staff and Farm Owner).
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/device.php';

api_require_method('GET', 'POST');

api_run(function (array $in): ApiResult {
    require_permission('sensors.calibrate');

    $device   = api_resolve_device($in);
    $deviceId = (int)$device['device_id'];
    $settings = get_settings($deviceId);

    if (strtoupper($_SERVER['REQUEST_METHOD']) === 'GET') {
        return api_ok(calibration_snapshot($deviceId, $device, $settings),
            'Calibration data retrieved successfully');
    }

    // ------------------------------------------------------------------
    // POST
    // ------------------------------------------------------------------
    $user = require_auth();
    require_csrf($in);

    $action = v_enum($in['action'] ?? 'save', 'Action', ['save', 'tare'], 'save');

    // --- Tare: ask the device to zero its scale with an empty hopper -----
    if ($action === 'tare') {
        if (!device_is_online($device['last_seen'], (int)$settings['offline_timeout'])) {
            throw new ValidationException('ESP32 device is offline. The scale can only be tared while it is connected.');
        }
        $commandId = queue_command($deviceId, 'tare_scale', null, (int)$user['user_id']);

        log_activity('CALIBRATE_SENSOR',
            sprintf('Tare requested for the load cell on %s.', $device['device_name']),
            (int)$user['user_id']);

        return api_ok(['command_id' => $commandId],
            'Tare command sent. Make sure the hopper is empty; the device will report the new offset.');
    }

    // --- Save calibration values ----------------------------------------
    $sensorType = v_enum($in['sensor_type'] ?? null, 'Sensor type', ['load_cell', 'ultrasonic']);
    $notes      = v_string($in['notes'] ?? null, 'Notes', 0, 255, '');

    $pdo = db();
    $pdo->beginTransaction();

    try {
        if ($sensorType === 'load_cell') {
            $offset = v_float($in['offset_value'] ?? null, 'Tare offset', -100000000, 100000000, 0.0);

            if (isset($in['reference_weight']) && $in['reference_weight'] !== ''
                && isset($in['raw_value']) && $in['raw_value'] !== '') {
                // Two-point calibration from a known reference mass.
                $reference = v_float($in['reference_weight'], 'Reference weight', 1, 50000);
                $raw       = v_float($in['raw_value'], 'Raw value', -100000000, 100000000);
                $factor    = ($raw - $offset) / $reference;

                if (abs($factor) < 0.000001) {
                    throw new ValidationException(
                        'The raw reading matches the tare offset, so no calibration factor can be derived. '
                        . 'Place the reference mass on the scale and read the raw value again.'
                    );
                }
            } else {
                $reference = null;
                $factor    = v_float($in['calibration_factor'] ?? null, 'Calibration factor', -1000000, 1000000);
                if (abs($factor) < 0.000001) {
                    throw new ValidationException('The calibration factor cannot be zero.');
                }
            }

            save_calibration($deviceId, 'load_cell', $factor, $offset, $reference, $notes, (int)$user['user_id']);

            $summary = sprintf('Load cell calibrated on %s (factor %.4f, offset %.0f).',
                $device['device_name'], $factor, $offset);
        } else {
            $depth  = v_float($in['calibration_factor'] ?? $in['tank_depth'] ?? null, 'Tank depth', 1, 400);
            $offset = v_float($in['offset_value'] ?? $in['sensor_offset'] ?? null, 'Sensor offset', 0, 400, 0.0);

            if ($depth + $offset > 400) {
                throw new ValidationException(
                    'Tank depth plus sensor offset exceeds the 400 cm range of the HC-SR04.'
                );
            }

            $reference = isset($in['reference_value']) && $in['reference_value'] !== ''
                ? v_float($in['reference_value'], 'Reference distance', 0, 400) : null;

            save_calibration($deviceId, 'ultrasonic', $depth, $offset, $reference, $notes, (int)$user['user_id']);

            // The server converts distance -> depth using system_settings, so
            // keep those columns in step with the calibration that was just saved.
            $pdo->prepare(
                'UPDATE system_settings SET water_capacity = ?, sensor_offset_cm = ?, updated_by = ?
                 WHERE device_id = ?'
            )->execute([$depth, $offset, (int)$user['user_id'], $deviceId]);

            // Keep the refill target inside the newly declared tank depth.
            $pdo->prepare(
                'UPDATE system_settings SET water_target = LEAST(water_target, ?),
                                            water_threshold = LEAST(water_threshold, ?)
                 WHERE device_id = ?'
            )->execute([$depth, $depth, $deviceId]);

            $summary = sprintf('Ultrasonic sensor calibrated on %s (depth %.1f cm, offset %.1f cm).',
                $device['device_name'], $depth, $offset);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    log_activity('CALIBRATE_SENSOR', $summary, (int)$user['user_id']);

    // Nudge the device to pick the new values up rather than waiting for its
    // next scheduled config refresh.
    if (device_is_online($device['last_seen'], (int)$settings['offline_timeout'])) {
        try {
            queue_command($deviceId, 'reload_settings', null, (int)$user['user_id']);
        } catch (ValidationException) {
            // A reload is already queued - nothing more to do.
        }
    }

    return api_ok(
        calibration_snapshot($deviceId, get_device($deviceId) ?? $device, get_settings($deviceId)),
        'Calibration saved successfully'
    );
});

/** Replace the active calibration row for a sensor with a new one. */
function save_calibration(
    int $deviceId,
    string $sensorType,
    float $factor,
    float $offset,
    ?float $reference,
    string $notes,
    int $userId
): void {
    // Previous rows stay in the table as an audit trail, just deactivated.
    db()->prepare(
        'UPDATE sensor_calibration SET is_active = 0 WHERE device_id = ? AND sensor_type = ?'
    )->execute([$deviceId, $sensorType]);

    db()->prepare(
        'INSERT INTO sensor_calibration
            (device_id, sensor_type, calibration_factor, offset_value, reference_value,
             is_active, notes, calibrated_by)
         VALUES (?, ?, ?, ?, ?, 1, ?, ?)'
    )->execute([
        $deviceId, $sensorType, $factor, $offset, $reference,
        $notes !== '' ? $notes : null, $userId,
    ]);
}

/** Everything the calibration page renders. */
function calibration_snapshot(int $deviceId, array $device, array $settings): array
{
    $calib   = get_calibration($deviceId);
    $feed    = latest_feed_reading($deviceId);
    $water   = latest_water_reading($deviceId);
    $online  = device_is_online($device['last_seen'], (int)$settings['offline_timeout']);

    $hist = db()->prepare(
        'SELECT sc.*, u.name AS calibrated_by_name
         FROM sensor_calibration sc
         LEFT JOIN users u ON u.user_id = sc.calibrated_by
         WHERE sc.device_id = ?
         ORDER BY sc.calibrated_at DESC
         LIMIT 20'
    );
    $hist->execute([$deviceId]);

    return [
        'device' => [
            'device_id'   => $deviceId,
            'device_name' => $device['device_name'],
            'online'      => $online,
        ],
        'load_cell' => [
            'calibration_factor' => isset($calib['load_cell']) ? (float)$calib['load_cell']['calibration_factor'] : null,
            'offset_value'       => isset($calib['load_cell']) ? (float)$calib['load_cell']['offset_value'] : null,
            'reference_value'    => isset($calib['load_cell']) && $calib['load_cell']['reference_value'] !== null
                ? (float)$calib['load_cell']['reference_value'] : null,
            'calibrated_at'      => $calib['load_cell']['calibrated_at'] ?? null,
            'sensor_status'      => $online ? $device['feed_sensor_status'] : 'unknown',
            'current_raw'        => $feed && $feed['raw_value'] !== null ? (int)$feed['raw_value'] : null,
            'current_weight'     => $feed ? (float)$feed['feed_weight'] : null,
            'current_weight_display' => format_weight($feed ? (float)$feed['feed_weight'] : null),
            'reading_ago'        => time_ago($feed['timestamp'] ?? null),
        ],
        'ultrasonic' => [
            'calibration_factor' => (float)$settings['water_capacity'],
            'offset_value'       => (float)$settings['sensor_offset_cm'],
            'calibrated_at'      => $calib['ultrasonic']['calibrated_at'] ?? null,
            'sensor_status'      => $online ? $device['water_sensor_status'] : 'unknown',
            'current_distance'   => $water ? (float)$water['distance_cm'] : null,
            'current_level'      => $water ? (float)$water['water_level'] : null,
            'current_percentage' => $water ? (float)$water['water_percentage'] : null,
            'reading_ago'        => time_ago($water['timestamp'] ?? null),
        ],
        'history' => $hist->fetchAll(),
    ];
}
