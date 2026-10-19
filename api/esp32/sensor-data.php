<?php
/**
 * POST /api/esp32/sensor-data.php
 *
 * Primary telemetry endpoint. The ESP32 posts a load-cell weight and an
 * HC-SR04 distance; the server validates them, converts distance to a water
 * depth using the authoritative calibration, stores both readings, refreshes
 * the device heartbeat and evaluates the alert thresholds.
 *
 * The response carries the current automation settings and any queued manual
 * command, so a single request per cycle keeps the firmware fully in sync.
 *
 * Auth: X-API-Key header (device key).
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/device.php';

api_require_method('POST');

api_run(function (array $in): ApiResult {
    $device   = api_device_auth($in);
    $deviceId = (int)$device['device_id'];
    $settings = get_settings($deviceId);

    $warnings = [];
    $stored   = [];

    // Readings that arrive from the offline buffer are tagged so reports can
    // tell live telemetry apart from a catch-up upload.
    $source = v_enum($in['source'] ?? 'live', 'Source', ['live', 'offline_sync'], 'live');

    // -----------------------------------------------------------------
    // Feed (load cell + HX711)
    // -----------------------------------------------------------------
    $feedPct    = null;
    $feedWeight = null;

    if (array_key_exists('feed_weight', $in) && $in['feed_weight'] !== null && $in['feed_weight'] !== '') {
        if (!is_numeric($in['feed_weight'])) {
            $warnings[] = 'Feed weight was not a number and was discarded.';
            create_alert($deviceId, 'abnormal_reading', 'WARNING',
                'Load cell sent a non-numeric weight value.');
        } else {
            $w = (float)$in['feed_weight'];
            $capacity = (float)$settings['feed_capacity'];

            if ($w < 0) {
                // A negative mass is physically impossible - the tare drifted.
                $warnings[] = 'Negative feed weight rejected.';
                create_alert($deviceId, 'abnormal_reading', 'WARNING',
                    sprintf('Load cell reported a negative weight (%.0f g). Check the tare offset.', $w));
                touch_device($deviceId, ['feed_sensor_status' => 'error']);
            } elseif ($w > $capacity * 1.5) {
                // Well above a full hopper means the cell or wiring is faulty.
                $warnings[] = 'Impossible feed weight rejected.';
                create_alert($deviceId, 'sensor_failure', 'CRITICAL',
                    sprintf('Load cell reported %.0f g, far above the %.0f g hopper capacity.', $w, $capacity));
                touch_device($deviceId, ['feed_sensor_status' => 'error']);
            } else {
                $feedWeight = round($w, 2);
                $feedPct    = feed_percentage($feedWeight, $capacity);
                $raw = isset($in['raw_value']) && is_numeric($in['raw_value']) ? (int)$in['raw_value'] : null;

                $st = db()->prepare(
                    'INSERT INTO feed_readings (device_id, feed_weight, feed_percentage, raw_value, source, `timestamp`)
                     VALUES (?, ?, ?, ?, ?, ?)'
                );
                $st->execute([
                    $deviceId, $feedWeight, $feedPct, $raw, $source,
                    reading_timestamp($in['timestamp'] ?? null),
                ]);
                $stored['feed_weight']     = $feedWeight;
                $stored['feed_percentage'] = $feedPct;
            }
        }
    }

    // -----------------------------------------------------------------
    // Water (HC-SR04)
    // -----------------------------------------------------------------
    $waterPct = null;

    if (array_key_exists('distance_cm', $in) && $in['distance_cm'] !== null && $in['distance_cm'] !== '') {
        if (!is_numeric($in['distance_cm'])) {
            $warnings[] = 'Water distance was not a number and was discarded.';
            create_alert($deviceId, 'abnormal_reading', 'WARNING',
                'Ultrasonic sensor sent a non-numeric distance value.');
        } else {
            $distance = (float)$in['distance_cm'];
            $depth    = (float)$settings['water_capacity'];
            $offset   = (float)$settings['sensor_offset_cm'];

            // HC-SR04 range is roughly 2-400 cm; 0 means no echo returned.
            if ($distance <= 0 || $distance > 400) {
                $warnings[] = 'Out-of-range ultrasonic distance rejected.';
                create_alert($deviceId, 'sensor_failure', 'CRITICAL',
                    sprintf('HC-SR04 returned an out-of-range distance (%.1f cm). No echo or wiring fault.', $distance));
                touch_device($deviceId, ['water_sensor_status' => 'error']);
            } elseif ($distance < $offset - 5) {
                // Closer than the sensor mount allows: something is blocking it.
                $warnings[] = 'Implausible ultrasonic distance rejected.';
                create_alert($deviceId, 'abnormal_reading', 'WARNING',
                    sprintf('Ultrasonic distance %.1f cm is closer than the %.1f cm sensor mount offset.', $distance, $offset));
            } else {
                $water    = water_from_distance($distance, $depth, $offset);
                $waterPct = $water['percentage'];

                $st = db()->prepare(
                    'INSERT INTO water_readings (device_id, water_level, water_percentage, distance_cm, source, `timestamp`)
                     VALUES (?, ?, ?, ?, ?, ?)'
                );
                $st->execute([
                    $deviceId, $water['level'], $water['percentage'], round($distance, 2), $source,
                    reading_timestamp($in['timestamp'] ?? null),
                ]);
                $stored['water_level']      = $water['level'];
                $stored['water_percentage'] = $water['percentage'];
                $stored['distance_cm']      = round($distance, 2);
            }
        }
    }

    if ($stored === [] && $warnings === []) {
        throw new ValidationException('No sensor values were supplied.');
    }

    // -----------------------------------------------------------------
    // Heartbeat + hardware state reported by the firmware
    // -----------------------------------------------------------------
    touch_device($deviceId, [
        'firmware_version'    => isset($in['firmware_version']) ? v_string($in['firmware_version'], 'Firmware version', 0, 20, '') : null,
        'ip_address'          => isset($in['ip_address']) ? v_string($in['ip_address'], 'IP address', 0, 45, '') : null,
        'wifi_rssi'           => isset($in['wifi_rssi']) && is_numeric($in['wifi_rssi']) ? (int)$in['wifi_rssi'] : null,
        'feed_sensor_status'  => isset($in['feed_sensor_status'])  ? v_enum($in['feed_sensor_status'], 'Feed sensor status', ['ok','error','unknown'], 'unknown') : ($feedWeight !== null ? 'ok' : null),
        'water_sensor_status' => isset($in['water_sensor_status']) ? v_enum($in['water_sensor_status'], 'Water sensor status', ['ok','error','unknown'], 'unknown') : (isset($stored['water_level']) ? 'ok' : null),
        'motor_status'        => isset($in['motor_status'])    ? v_enum($in['motor_status'], 'Motor status', ['idle','running','error'], 'idle') : null,
        'solenoid_status'     => isset($in['solenoid_status']) ? v_enum($in['solenoid_status'], 'Solenoid status', ['idle','running','error'], 'idle') : null,
    ]);

    // -----------------------------------------------------------------
    // Threshold evaluation -> alerts
    // -----------------------------------------------------------------
    if ($feedPct !== null || $waterPct !== null) {
        evaluate_alerts(
            $deviceId,
            $settings,
            $feedPct ?? 100.0,   // a missing value must not trigger its own alert
            $waterPct ?? 100.0
        );
    }

    return api_ok([
        'stored'           => $stored,
        'warnings'         => $warnings,
        'server_time'      => date('Y-m-d H:i:s'),
        'settings'         => device_config_payload($deviceId, $settings),
        'pending_commands' => take_pending_commands($deviceId),
    ], $warnings === []
        ? 'Sensor data saved successfully'
        : 'Sensor data processed with warnings');
});
