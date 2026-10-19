<?php
/**
 * GET /api/dashboard/index.php?device_id=1[&charts=1&hours=24]
 *
 * Everything the dashboard shows, in one request: feed and water state,
 * device/sensor/actuator status, alert counts and (optionally) the chart
 * series. The front end polls this so the page never has to reload.
 *
 * It also acts as the server-side offline watchdog: any active device that
 * has stopped reporting gets a device_offline alert raised here, which means
 * the system notices a dead controller without needing a cron job.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/device.php';

api_require_method('GET');

api_run(function (array $in): ApiResult {
    require_permission('dashboard.view');

    $device   = api_resolve_device($in);
    $deviceId = (int)$device['device_id'];
    $settings = get_settings($deviceId);

    check_offline_devices();

    // Re-read the device row: the watchdog may have just changed its state.
    $device = get_device($deviceId) ?? $device;
    $online = device_is_online($device['last_seen'], (int)$settings['offline_timeout']);

    // ------------------------------------------------------------------
    // Feeder card (timed feeder: runs, not weights)
    // ------------------------------------------------------------------
    $lastFeed = fetch_last_dispensing('feed_dispensing', $deviceId);

    $feed = [
        'running'               => $online && $device['motor_status'] === 'running',
        'max_duration'          => (int)$settings['feed_max_duration'],
        'last_run'              => $lastFeed['timestamp'] ?? null,
        'last_run_ago'          => time_ago($lastFeed['timestamp'] ?? null),
        'last_run_seconds'      => $lastFeed ? round((int)($lastFeed['duration_ms'] ?? 0) / 1000, 1) : null,
        'last_run_method'       => $lastFeed['dispensing_method'] ?? null,
    ];

    // ------------------------------------------------------------------
    // Water card
    // ------------------------------------------------------------------
    $waterReading = latest_water_reading($deviceId);
    $waterLevel   = $waterReading ? (float)$waterReading['water_level'] : null;
    $waterPct     = $waterReading ? (float)$waterReading['water_percentage'] : null;

    $lastWater = fetch_last_dispensing('water_dispensing', $deviceId);

    $water = [
        'level'             => $waterLevel,
        'level_display'     => $waterLevel === null ? '--' : number_format($waterLevel, 1) . ' cm',
        'percentage'        => $waterPct,
        'liters'            => $waterPct === null ? null
            : round($waterPct / 100 * (float)$settings['water_capacity_liters'], 1),
        'capacity_liters'   => (float)$settings['water_capacity_liters'],
        'status'            => $waterPct === null ? 'UNKNOWN'
            : level_status($waterPct, (float)$settings['water_low_percent'], (float)$settings['water_critical_percent']),
        'capacity'          => (float)$settings['water_capacity'],
        'threshold'         => (float)$settings['water_threshold'],
        'threshold_percent' => round((float)$settings['water_threshold'] / max(0.01, (float)$settings['water_capacity']) * 100, 2),
        'target'            => (float)$settings['water_target'],
        'auto'              => (bool)$settings['auto_water'],
        'distance_cm'       => $waterReading ? (float)$waterReading['distance_cm'] : null,
        'reading_time'      => $waterReading['timestamp'] ?? null,
        'reading_ago'       => time_ago($waterReading['timestamp'] ?? null),
        'last_refill'       => $lastWater['timestamp'] ?? null,
        'last_refill_ago'   => time_ago($lastWater['timestamp'] ?? null),
        'last_refill_method'=> $lastWater['dispensing_method'] ?? null,
    ];

    // ------------------------------------------------------------------
    // Climate card (DHT11)
    // ------------------------------------------------------------------
    $cs = db()->prepare('SELECT * FROM climate_readings WHERE device_id = ? ORDER BY `timestamp` DESC LIMIT 1');
    $cs->execute([$deviceId]);
    $climateRow = $cs->fetch() ?: null;
    $climate = [
        'temperature'  => $climateRow ? (float)$climateRow['temperature_c'] : null,
        'humidity'     => $climateRow ? (float)$climateRow['humidity_pct'] : null,
        'heat_level'   => $climateRow ? strtoupper($climateRow['heat_level']) : 'UNKNOWN',
        'reading_ago'  => time_ago($climateRow['timestamp'] ?? null),
        'warn_c'       => CLIMATE_WARN_C,
        'danger_c'     => CLIMATE_DANGER_C,
    ];

    // ------------------------------------------------------------------
    // Device / hardware status
    // ------------------------------------------------------------------
    $system = [
        'device_id'        => $deviceId,
        'device_name'      => $device['device_name'],
        'location'         => $device['location'],
        'esp32_status'     => $online ? 'ONLINE' : 'OFFLINE',
        'online'           => $online,
        // The controller reaching our API is itself the proof that its
        // Wi-Fi and internet path is working.
        'internet_status'  => $online ? 'CONNECTED' : 'DISCONNECTED',
        'last_seen'        => $device['last_seen'],
        'last_seen_ago'    => time_ago($device['last_seen']),
        'firmware_version' => $device['firmware_version'],
        'ip_address'       => $device['ip_address'],
        'wifi_rssi'        => $device['wifi_rssi'] !== null ? (int)$device['wifi_rssi'] : null,
        // While the device is unreachable we cannot vouch for any of its
        // peripherals, so they report as unknown rather than as last seen.
        'water_sensor'     => $online ? $device['water_sensor_status'] : 'unknown',
        'motor_status'     => $online ? $device['motor_status']    : 'unknown',
        'solenoid_status'  => $online ? $device['solenoid_status'] : 'unknown',
        'offline_timeout'  => (int)$settings['offline_timeout'],
        'reading_interval' => (int)$settings['reading_interval'],
    ];

    // ------------------------------------------------------------------
    // Alerts panel
    // ------------------------------------------------------------------
    $counts = db()->prepare(
        "SELECT
            SUM(status = 'unread')                             AS unread,
            SUM(severity = 'CRITICAL' AND status <> 'resolved') AS critical,
            SUM(severity = 'WARNING'  AND status <> 'resolved') AS warning,
            SUM(severity = 'INFO'     AND status <> 'resolved') AS info
         FROM alerts WHERE device_id = ?"
    );
    $counts->execute([$deviceId]);
    $c = $counts->fetch() ?: [];

    $recentSt = db()->prepare(
        'SELECT alert_id, alert_type, severity, message, status, created_at
         FROM alerts WHERE device_id = ?
         ORDER BY created_at DESC LIMIT 8'
    );
    $recentSt->execute([$deviceId]);
    $recent = array_map(static fn(array $a): array => $a + ['ago' => time_ago($a['created_at'])],
        $recentSt->fetchAll());

    $alerts = [
        'unread'   => (int)($c['unread'] ?? 0),
        'critical' => (int)($c['critical'] ?? 0),
        'warning'  => (int)($c['warning'] ?? 0),
        'info'     => (int)($c['info'] ?? 0),
        'recent'   => $recent,
    ];

    // ------------------------------------------------------------------
    // Today's consumption summary
    // ------------------------------------------------------------------
    $todayFrom = date('Y-m-d 00:00:00');
    $todayTo   = date('Y-m-d H:i:s', strtotime('+1 second'));
    $feedToday  = feed_runs($deviceId, $todayFrom, $todayTo, 'daily');
    $waterToday = water_consumption($deviceId, $todayFrom, $todayTo, 'daily');

    $today = [
        'feed_runs'            => $feedToday !== []  ? $feedToday[0]['runs'] : 0,
        'feed_seconds'         => $feedToday !== []  ? $feedToday[0]['seconds'] : 0.0,
        'water_consumed_l'     => $waterToday !== [] ? $waterToday[0]['consumed_liters'] : 0.0,
        'water_events'         => $waterToday !== [] ? $waterToday[0]['events'] : 0,
    ];

    $payload = [
        'device'  => $system,
        'devices' => array_map(static function (array $d): array {
            return [
                'device_id'   => (int)$d['device_id'],
                'device_name' => $d['device_name'],
                'location'    => $d['location'],
                'status'      => $d['status'],
                'online'      => device_is_online($d['last_seen']),
            ];
        }, get_devices()),
        'feed'        => $feed,
        'water'       => $water,
        'climate'     => $climate,
        'alerts'      => $alerts,
        'today'       => $today,
        'server_time' => date('Y-m-d H:i:s'),
    ];

    // Chart series are only built when asked for, so the frequent status poll
    // stays small and the heavier history query runs on its own schedule.
    if (!empty($in['charts'])) {
        $hours = v_int($in['hours'] ?? null, 'Hours', 1, 720, 24);
        $bucket = $hours <= 6 ? 5 : ($hours <= 48 ? 30 : 180);

        $days = max(7, (int)ceil($hours / 24));
        $from = date('Y-m-d 00:00:00', strtotime("-{$days} days"));
        $to   = date('Y-m-d H:i:s', time() + 1);

        $payload['charts'] = [
            'water_level'       => reading_history($deviceId, $hours, $bucket),
            'climate'           => climate_history($deviceId, $hours, $bucket),
            'feed_runs'         => feed_runs($deviceId, $from, $to, 'daily'),
            'water_consumption' => water_consumption($deviceId, $from, $to, 'daily'),
        ];
    }

    return api_ok($payload, 'Dashboard data retrieved successfully');
});

/** Latest completed dispensing row of a given kind, or null. */
function fetch_last_dispensing(string $table, int $deviceId): ?array
{
    $st = db()->prepare(
        "SELECT * FROM `{$table}`
         WHERE device_id = ? AND status = 'completed'
         ORDER BY `timestamp` DESC LIMIT 1"
    );
    $st->execute([$deviceId]);
    return $st->fetch() ?: null;
}

/**
 * Server-side watchdog. Any active device whose last contact is older than its
 * configured timeout gets a device_offline alert (rate-limited by create_alert),
 * so a controller that loses power is noticed even though it cannot report it
 * itself.
 */
function check_offline_devices(): void
{
    $rows = db()->query(
        "SELECT d.device_id, d.device_name, d.last_seen,
                COALESCE(s.offline_timeout, " . DEFAULT_OFFLINE_TIMEOUT . ") AS offline_timeout
         FROM devices d
         LEFT JOIN system_settings s ON s.device_id = d.device_id
         WHERE d.status = 'active'"
    )->fetchAll();

    foreach ($rows as $d) {
        if ($d['last_seen'] === null) {
            continue; // never connected yet - not an outage
        }
        if (!device_is_online($d['last_seen'], (int)$d['offline_timeout'])) {
            create_alert(
                (int)$d['device_id'],
                'device_offline',
                'CRITICAL',
                sprintf('%s has not reported since %s.', $d['device_name'], format_datetime($d['last_seen'])),
                1800
            );
        }
    }
}
