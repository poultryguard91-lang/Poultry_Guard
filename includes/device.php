<?php
/**
 * PoultryGuard - Device-side helpers shared by the /api/esp32 endpoints.
 *
 * Config delivery, the manual-command queue, timestamp sanitising and the
 * routines that record dispensing events reported by the firmware.
 */

declare(strict_types=1);

require_once __DIR__ . '/api.php';

/**
 * Accept a device-supplied timestamp only when it is plausible; otherwise use
 * server time. Offline buffers replay real timestamps, but a device whose
 * clock never synchronised would otherwise poison the history.
 */
function reading_timestamp(mixed $value): string
{
    if (is_string($value) && $value !== '') {
        $t = strtotime($value);
        // Accept anything from the last 30 days up to 5 minutes in the future.
        if ($t !== false && $t > time() - 2592000 && $t < time() + 300) {
            return date('Y-m-d H:i:s', $t);
        }
    }
    return date('Y-m-d H:i:s');
}

/** Heat thresholds in degrees C; keep in step with TEMP_WARN / TEMP_DANGER in the firmware. */
const CLIMATE_WARN_C   = 32.0;
const CLIMATE_DANGER_C = 35.0;

/**
 * The water level strip / float switch has no distance to report, so the
 * firmware sends a percentage. Convert it to the sensor-to-surface distance the
 * rest of the system (tables, charts, reports) is built on.
 */
function distance_from_percentage(float $percentage, float $tankDepthCm, float $sensorOffsetCm): float
{
    $pct = max(0.0, min(100.0, $percentage));
    return round($sensorOffsetCm + $tankDepthCm - ($pct / 100.0 * $tankDepthCm), 2);
}

/**
 * Store a DHT11 sample and raise a heat alert when it is too hot.
 *
 * @return array<string,mixed>|null the stored values, or null when the sample was unusable.
 */
function record_climate(int $deviceId, mixed $temperature, mixed $humidity, string $source, string $timestamp, bool $alert = true): ?array
{
    if (!is_numeric($temperature) || !is_numeric($humidity)) {
        return null;
    }
    $t = (float)$temperature;
    $h = (float)$humidity;
    // The DHT11 measures 0-50 C and 20-90 %RH; anything far outside is a wiring fault.
    if ($t < -10 || $t > 70 || $h < 0 || $h > 100) {
        return null;
    }

    $level = $t >= CLIMATE_DANGER_C ? 'danger' : ($t >= CLIMATE_WARN_C ? 'warning' : 'normal');

    db()->prepare(
        'INSERT INTO climate_readings (device_id, temperature_c, humidity_pct, heat_level, source, `timestamp`)
         VALUES (?, ?, ?, ?, ?, ?)'
    )->execute([$deviceId, round($t, 2), round($h, 2), $level, $source, $timestamp]);

    if ($alert && $level === 'danger') {
        create_alert($deviceId, 'abnormal_reading', 'CRITICAL',
            sprintf('Heat danger: %.1f C in the house. Turn on fans, add shade and cool water.', $t));
    } elseif ($alert && $level === 'warning') {
        create_alert($deviceId, 'abnormal_reading', 'WARNING',
            sprintf('Heat warning: %.1f C in the house. Chickens may start heat stress.', $t));
    }

    return ['temperature_c' => round($t, 2), 'humidity_pct' => round($h, 2), 'heat_level' => $level];
}

/**
 * The automation profile the firmware caches in NVS so it can keep feeding
 * and watering to the same rules with no network connection.
 *
 * `settings_version` lets the device detect a change cheaply: if the value
 * differs from the one it holds, it rewrites its local copy.
 */
function device_config_payload(int $deviceId, ?array $settings = null): array
{
    $settings ??= get_settings($deviceId);

    return [
        'feed_max_duration'  => (int)$settings['feed_max_duration'],
        'auto_feed'          => (bool)$settings['auto_feed'],
        'feed_schedule'      => feed_schedule_decode($settings['feed_schedule'] ?? null),
        'water_capacity'     => (float)$settings['water_capacity'],
        'sensor_offset_cm'   => (float)$settings['sensor_offset_cm'],
        'water_threshold'    => (float)$settings['water_threshold'],
        'water_target'       => (float)$settings['water_target'],
        'water_max_duration' => (int)$settings['water_max_duration'],
        'auto_water'         => (bool)$settings['auto_water'],
        'reading_interval'   => (int)$settings['reading_interval'],
        'settings_version'   => (int)strtotime((string)$settings['updated_at']),
    ];
}

/**
 * Hand the device any queued manual commands and mark them as sent.
 *
 * Commands older than five minutes are expired rather than delivered, so a
 * device that was offline never wakes up and acts on a stale instruction -
 * an operator who asked for feed ten minutes ago has moved on.
 */
function take_pending_commands(int $deviceId): array
{
    db()->prepare(
        "UPDATE device_commands SET status = 'expired'
         WHERE device_id = ? AND status = 'pending'
           AND created_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE)"
    )->execute([$deviceId]);

    $st = db()->prepare(
        "SELECT command_id, command, payload FROM device_commands
         WHERE device_id = ? AND status = 'pending'
         ORDER BY created_at ASC LIMIT 5"
    );
    $st->execute([$deviceId]);
    $rows = $st->fetchAll();

    if ($rows === []) {
        return [];
    }

    $ids = array_column($rows, 'command_id');
    $ph  = implode(',', array_fill(0, count($ids), '?'));
    db()->prepare("UPDATE device_commands SET status = 'sent', sent_at = NOW() WHERE command_id IN ({$ph})")
        ->execute($ids);

    return array_map(static fn(array $r): array => [
        'command_id' => (int)$r['command_id'],
        'command'    => $r['command'],
        'payload'    => $r['payload'] !== null ? json_decode((string)$r['payload'], true) : null,
    ], $rows);
}

/**
 * Queue a manual command for a device.
 *
 * @throws ValidationException when an identical command is already waiting.
 */
function queue_command(int $deviceId, string $command, ?array $payload, ?int $userId): int
{
    $dup = db()->prepare(
        "SELECT command_id FROM device_commands
         WHERE device_id = ? AND command = ? AND status IN ('pending','sent')
           AND created_at > DATE_SUB(NOW(), INTERVAL 2 MINUTE) LIMIT 1"
    );
    $dup->execute([$deviceId, $command]);
    if ($dup->fetchColumn() !== false) {
        throw new ValidationException('That command is already queued and waiting for the device.');
    }

    $st = db()->prepare(
        'INSERT INTO device_commands (device_id, command, payload, issued_by) VALUES (?, ?, ?, ?)'
    );
    $st->execute([
        $deviceId,
        $command,
        $payload !== null ? json_encode($payload, JSON_UNESCAPED_SLASHES) : null,
        $userId,
    ]);
    return (int)db()->lastInsertId();
}

/**
 * Record a completed (or failed) feeder run reported by the device.
 *
 * The feeder is timed (no load cell), so a run is described by how long the
 * motor ran, not by weights.
 *
 * @return int the new dispensing_id
 */
function record_feed_dispensing(int $deviceId, array $in): int
{
    $settings = get_settings($deviceId);

    $method  = v_enum($in['dispensing_method'] ?? 'automatic', 'Dispensing method', ['automatic', 'manual', 'offline'], 'automatic');
    $status  = v_enum($in['status'] ?? 'completed', 'Status', ['pending', 'running', 'completed', 'failed', 'aborted', 'timeout'], 'completed');
    $ms      = v_int($in['duration_ms'] ?? null, 'Duration', 0, 3600000, 0);
    $notes   = v_string($in['notes'] ?? null, 'Notes', 0, 255, '');
    $userId  = isset($in['user_id']) && is_numeric($in['user_id']) ? (int)$in['user_id'] : null;

    $st = db()->prepare(
        'INSERT INTO feed_dispensing
            (device_id, user_id, dispensing_method, status, duration_ms, notes, `timestamp`)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $st->execute([
        $deviceId, $userId, $method, $status, $ms, $notes !== '' ? $notes : null,
        reading_timestamp($in['timestamp'] ?? null),
    ]);
    $id = (int)db()->lastInsertId();

    if ($status === 'timeout') {
        create_alert($deviceId, 'dispensing_timeout', 'CRITICAL',
            sprintf('Feeder motor stopped by the %d s safety timeout.', (int)$settings['feed_max_duration']), 300);
    } elseif ($status === 'failed') {
        create_alert($deviceId, 'motor_error', 'WARNING',
            $notes !== '' ? $notes : 'Feeder run failed. Check the feeder motor and relay.', 300);
    } elseif ($status === 'completed') {
        create_alert($deviceId, 'info', 'INFO',
            sprintf('%s feeding completed: feeder ran %.1f s.', ucfirst($method), $ms / 1000), 300);
        log_activity(
            $method === 'manual' ? 'MANUAL_FEED' : 'AUTO_FEED',
            sprintf('%s feeding completed on device #%d (feeder ran %.1f s).', ucfirst($method), $deviceId, $ms / 1000),
            $userId
        );
    }

    return $id;
}

/**
 * Record a completed (or failed) water refill event reported by the device.
 *
 * @return int the new dispensing_id
 */
function record_water_dispensing(int $deviceId, array $in): int
{
    $settings = get_settings($deviceId);
    $depth    = (float)$settings['water_capacity'];

    // A level strip / float switch reports percentages, not centimetres.
    foreach (['starting' => 'starting_level', 'target' => 'target_level', 'ending' => 'ending_level'] as $k => $col) {
        if (isset($in[$k . '_percentage']) && is_numeric($in[$k . '_percentage'])) {
            $in[$col] = round(max(0.0, min(100.0, (float)$in[$k . '_percentage'])) / 100.0 * $depth, 2);
        }
    }

    $start  = v_float($in['starting_level'] ?? null, 'Starting level', 0, $depth * 1.5, 0.0);
    $target = v_float($in['target_level']   ?? null, 'Target level',   0, $depth * 1.5, (float)$settings['water_target']);
    $end    = isset($in['ending_level']) && $in['ending_level'] !== ''
        ? v_float($in['ending_level'], 'Ending level', 0, $depth * 1.5)
        : null;
    $method = v_enum($in['dispensing_method'] ?? 'automatic', 'Dispensing method', ['automatic', 'manual', 'offline'], 'automatic');
    $status = v_enum($in['status'] ?? 'completed', 'Status', ['pending', 'running', 'completed', 'failed', 'aborted', 'timeout'], 'completed');
    $ms     = v_int($in['duration_ms'] ?? null, 'Duration', 0, 7200000, 0);
    $notes  = v_string($in['notes'] ?? null, 'Notes', 0, 255, '');
    $userId = isset($in['user_id']) && is_numeric($in['user_id']) ? (int)$in['user_id'] : null;

    $st = db()->prepare(
        'INSERT INTO water_dispensing
            (device_id, user_id, starting_level, ending_level, target_level,
             dispensing_method, status, duration_ms, notes, `timestamp`)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $st->execute([
        $deviceId, $userId, $start, $end, $target,
        $method, $status, $ms, $notes !== '' ? $notes : null,
        reading_timestamp($in['timestamp'] ?? null),
    ]);
    $id = (int)db()->lastInsertId();

    if ($status === 'timeout') {
        create_alert($deviceId, 'dispensing_timeout', 'CRITICAL',
            sprintf('Water pump stopped by the %d s safety timeout before reaching the target level.',
                (int)$settings['water_max_duration']), 300);
    } elseif ($status === 'failed') {
        create_alert($deviceId, 'solenoid_error', 'WARNING',
            $notes !== '' ? $notes : 'Water refill failed. Check the pump and water supply.', 300);
    } elseif ($status === 'completed') {
        create_alert($deviceId, 'info', 'INFO',
            sprintf('%s water refill completed: %.1f cm to %.1f cm in %.1f s.',
                ucfirst($method), $start, $end ?? $target, $ms / 1000), 300);
        log_activity(
            $method === 'manual' ? 'MANUAL_WATER' : 'AUTO_WATER',
            sprintf('%s water refill completed on device #%d (%.1f cm to %.1f cm).',
                ucfirst($method), $deviceId, $start, $end ?? $target),
            $userId
        );
    }

    return $id;
}
