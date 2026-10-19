<?php
/**
 * POST /api/esp32/sync.php
 *
 * Offline catch-up. While the network is down the ESP32 keeps feeding and
 * watering and buffers readings in memory. When connectivity comes
 * back it replays that buffer here in one batch, with the original timestamps,
 * so nothing that happened during the outage is lost from the history.
 *
 * Body:
 *   {
 *     "esp32_uid": "...",
 *     "readings":   [ { "timestamp": "...", "water_percentage": 62, "temperature": 29.5, "humidity": 70 } ],
 *     "dispensing": [ { "type": "feed", "timestamp": "...", ... } ],
 *     "alerts":     [ { "alert_type": "...", "severity": "...", "message": "..." } ]
 *   }
 *
 * The whole batch is written in one transaction: either every buffered record
 * lands or none does, so the device can safely retry after a failure.
 *
 * Auth: X-API-Key header (device key).
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/device.php';

/** Guard against an oversized replay exhausting server memory. */
const SYNC_MAX_RECORDS = 250;

api_require_method('POST');

api_run(function (array $in): ApiResult {
    $device   = api_device_auth($in);
    $deviceId = (int)$device['device_id'];
    $settings = get_settings($deviceId);

    $readings   = is_array($in['readings'] ?? null)   ? $in['readings']   : [];
    $dispensing = is_array($in['dispensing'] ?? null) ? $in['dispensing'] : [];
    $alerts     = is_array($in['alerts'] ?? null)     ? $in['alerts']     : [];

    $total = count($readings) + count($dispensing) + count($alerts);
    if ($total === 0) {
        throw new ValidationException('The sync batch contained no records.');
    }
    if ($total > SYNC_MAX_RECORDS) {
        throw new ValidationException(
            'The sync batch is too large. Send at most ' . SYNC_MAX_RECORDS . ' records per request.'
        );
    }

    $depth    = (float)$settings['water_capacity'];
    $offset   = (float)$settings['sensor_offset_cm'];

    $counts   = ['water_readings' => 0, 'climate_readings' => 0, 'dispensing' => 0, 'alerts' => 0];
    $rejected = [];
    $lastWaterPct = null;

    $pdo = db();
    $pdo->beginTransaction();

    try {
        $waterStmt = $pdo->prepare(
            'INSERT INTO water_readings (device_id, water_level, water_percentage, distance_cm, source, `timestamp`)
             VALUES (?, ?, ?, ?, ?, ?)'
        );

        foreach ($readings as $i => $r) {
            if (!is_array($r)) {
                $rejected[] = "readings[{$i}]: not an object";
                continue;
            }
            $ts = reading_timestamp($r['timestamp'] ?? null);

            // --- buffered DHT11 sample ---
            if (isset($r['temperature']) || isset($r['humidity'])) {
                if (record_climate($deviceId, $r['temperature'] ?? null, $r['humidity'] ?? null, 'offline_sync', $ts, false) !== null) {
                    $counts['climate_readings']++;
                } else {
                    $rejected[] = "readings[{$i}]: invalid temperature/humidity";
                }
            }

            // --- buffered water sample (percentage from the level sensor) ---
            if (isset($r['water_percentage'])) {
                $p = $r['water_percentage'];
                if (!is_numeric($p) || $p < 0 || $p > 100) {
                    $rejected[] = "readings[{$i}]: water level out of range";
                } else {
                    $d     = distance_from_percentage((float)$p, $depth, $offset);
                    $water = water_from_distance($d, $depth, $offset);
                    $waterStmt->execute([
                        $deviceId, $water['level'], $water['percentage'], $d,
                        'offline_sync', $ts,
                    ]);
                    $counts['water_readings']++;
                    $lastWaterPct = $water['percentage'];
                }
            }
        }

        foreach ($dispensing as $i => $d) {
            if (!is_array($d)) {
                $rejected[] = "dispensing[{$i}]: not an object";
                continue;
            }
            try {
                // Anything the device did while disconnected is an offline action.
                $d['dispensing_method'] = $d['dispensing_method'] ?? 'offline';
                $type = v_enum($d['type'] ?? null, 'Dispensing type', ['feed', 'water']);
                if ($type === 'feed') {
                    record_feed_dispensing($deviceId, $d);
                } else {
                    record_water_dispensing($deviceId, $d);
                }
                $counts['dispensing']++;
            } catch (ValidationException $e) {
                $rejected[] = "dispensing[{$i}]: " . $e->getMessage();
            }
        }

        foreach ($alerts as $i => $a) {
            if (!is_array($a)) {
                $rejected[] = "alerts[{$i}]: not an object";
                continue;
            }
            try {
                $type = v_enum($a['alert_type'] ?? null, 'Alert type', [
                    'low_feed', 'critical_feed', 'low_water', 'critical_water',
                    'sensor_failure', 'device_offline', 'internet_disconnected',
                    'motor_error', 'solenoid_error', 'abnormal_reading',
                    'system_error', 'dispensing_timeout', 'info',
                ]);
                $sev = v_enum($a['severity'] ?? 'INFO', 'Severity', ['INFO', 'WARNING', 'CRITICAL'], 'INFO');
                $msg = v_string($a['message'] ?? null, 'Alert message', 1, 255);

                // Buffered alerts replay with their original time, so the
                // duplicate-suppression window does not apply to them.
                $st = $pdo->prepare(
                    'INSERT INTO alerts (device_id, alert_type, severity, message, created_at)
                     VALUES (?, ?, ?, ?, ?)'
                );
                $st->execute([$deviceId, $type, $sev, $msg, reading_timestamp($a['timestamp'] ?? null)]);
                $counts['alerts']++;
            } catch (ValidationException $e) {
                $rejected[] = "alerts[{$i}]: " . $e->getMessage();
            }
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    touch_device($deviceId);

    // Evaluate thresholds once, against the newest replayed sample, instead of
    // once per buffered row - otherwise a long outage would produce hundreds
    // of identical alerts the moment the device reconnects.
    if ($lastWaterPct !== null) {
        evaluate_alerts($deviceId, $settings, $lastWaterPct);
    }

    log_activity('OFFLINE_SYNC', sprintf(
        'Device %s uploaded %d buffered records after an outage.',
        $device['device_name'],
        array_sum($counts)
    ), null, client_ip());

    return api_ok([
        'synced'   => $counts,
        'total'    => array_sum($counts),
        'rejected' => $rejected,
    ], sprintf('%d buffered records synchronised successfully', array_sum($counts)));
});
