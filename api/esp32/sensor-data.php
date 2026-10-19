<?php
/**
 * POST /api/esp32/sensor-data.php
 *
 * Primary telemetry endpoint. The ESP32 posts the water level (as a percentage
 * from its level strip / float switch) and the DHT11 temperature and humidity.
 * The server stores them, refreshes the device heartbeat and evaluates the
 * alert thresholds.
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
    // Water (level strip / float switch, reported as a percentage)
    // -----------------------------------------------------------------
    $waterPct = null;

    if (isset($in['water_percentage']) && $in['water_percentage'] !== '') {
        if (!is_numeric($in['water_percentage']) || $in['water_percentage'] < 0 || $in['water_percentage'] > 100) {
            $warnings[] = 'Water level was not a valid percentage and was discarded.';
            create_alert($deviceId, 'sensor_failure', 'WARNING',
                'Water level sensor sent an invalid value. Check the wiring.');
            touch_device($deviceId, ['water_sensor_status' => 'error']);
        } else {
            $depth    = (float)$settings['water_capacity'];
            $distance = distance_from_percentage((float)$in['water_percentage'], $depth, (float)$settings['sensor_offset_cm']);
            $water    = water_from_distance($distance, $depth, (float)$settings['sensor_offset_cm']);
            $waterPct = $water['percentage'];

            db()->prepare(
                'INSERT INTO water_readings (device_id, water_level, water_percentage, distance_cm, source, `timestamp`)
                 VALUES (?, ?, ?, ?, ?, ?)'
            )->execute([
                $deviceId, $water['level'], $water['percentage'], $distance, $source,
                reading_timestamp($in['timestamp'] ?? null),
            ]);
            $stored['water_level']      = $water['level'];
            $stored['water_percentage'] = $water['percentage'];
        }
    }

    // -----------------------------------------------------------------
    // Climate (DHT11)
    // -----------------------------------------------------------------
    if (isset($in['temperature']) || isset($in['humidity'])) {
        $climate = record_climate(
            $deviceId, $in['temperature'] ?? null, $in['humidity'] ?? null, $source,
            reading_timestamp($in['timestamp'] ?? null), $source === 'live'
        );
        if ($climate === null) {
            $warnings[] = 'Temperature/humidity sample was invalid and was discarded.';
        } else {
            $stored += $climate;
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
        'water_sensor_status' => isset($in['water_sensor_status']) ? v_enum($in['water_sensor_status'], 'Water sensor status', ['ok','error','unknown'], 'unknown') : (isset($stored['water_level']) ? 'ok' : null),
        'motor_status'        => isset($in['motor_status']) ? v_enum($in['motor_status'], 'Feeder status', ['idle','running','error'], 'idle') : null,
        'solenoid_status'     => isset($in['solenoid_status']) ? v_enum($in['solenoid_status'], 'Pump status', ['idle','running','error'], 'idle') : null,
    ]);

    // -----------------------------------------------------------------
    // Threshold evaluation -> alerts
    // -----------------------------------------------------------------
    if ($waterPct !== null) {
        evaluate_alerts($deviceId, $settings, $waterPct);
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
