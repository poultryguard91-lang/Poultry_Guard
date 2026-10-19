<?php
/**
 * GET /api/water/read.php?device_id=1&hours=24
 *
 * Current water state, the trend series and the recent refill log for the
 * water monitoring page.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/api.php';

api_require_method('GET');

api_run(function (array $in): ApiResult {
    require_permission('monitoring.view');

    $device   = api_resolve_device($in);
    $deviceId = (int)$device['device_id'];
    $settings = get_settings($deviceId);
    $hours    = v_int($in['hours'] ?? null, 'Hours', 1, 720, 24);
    $limit    = v_int($in['limit'] ?? null, 'Limit', 1, 200, 20);

    $reading = latest_water_reading($deviceId);
    $depth   = (float)$settings['water_capacity'];
    $level   = $reading ? (float)$reading['water_level'] : null;
    $pct     = $reading ? (float)$reading['water_percentage'] : null;

    $st = db()->prepare(
        'SELECT wd.*, u.name AS user_name
         FROM water_dispensing wd
         LEFT JOIN users u ON u.user_id = wd.user_id
         WHERE wd.device_id = ?
         ORDER BY wd.`timestamp` DESC
         LIMIT ' . $limit
    );
    $st->execute([$deviceId]);
    $history = array_map(static function (array $r): array {
        $r['added_cm'] = $r['ending_level'] !== null
            ? round((float)$r['ending_level'] - (float)$r['starting_level'], 2) : null;
        $r['accuracy_cm'] = $r['ending_level'] !== null
            ? round((float)$r['ending_level'] - (float)$r['target_level'], 2) : null;
        $r['ago'] = time_ago($r['timestamp']);
        return $r;
    }, $st->fetchAll());

    $accSt = db()->prepare(
        "SELECT COUNT(*) AS runs,
                ROUND(AVG(ABS(ending_level - target_level)), 2) AS avg_error,
                ROUND(MAX(ABS(ending_level - target_level)), 2) AS max_error
         FROM water_dispensing
         WHERE device_id = ? AND status = 'completed' AND ending_level IS NOT NULL
           AND `timestamp` >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
    );
    $accSt->execute([$deviceId]);
    $accuracy = $accSt->fetch() ?: [];

    return api_ok([
        'device' => [
            'device_id'       => $deviceId,
            'device_name'     => $device['device_name'],
            'online'          => device_is_online($device['last_seen'], (int)$settings['offline_timeout']),
            'solenoid_status' => $device['solenoid_status'],
            'sensor_status'   => $device['water_sensor_status'],
        ],
        'current' => [
            'level'             => $level,
            'level_display'     => $level === null ? '--' : number_format($level, 1) . ' cm',
            'percentage'        => $pct,
            'liters'            => $pct === null ? null
                : round($pct / 100 * (float)$settings['water_capacity_liters'], 1),
            'capacity_liters'   => (float)$settings['water_capacity_liters'],
            'distance_cm'       => $reading ? (float)$reading['distance_cm'] : null,
            'sensor_offset_cm'  => (float)$settings['sensor_offset_cm'],
            'status'            => $pct === null ? 'UNKNOWN'
                : level_status($pct, (float)$settings['water_low_percent'], (float)$settings['water_critical_percent']),
            'capacity'          => $depth,
            'threshold'         => (float)$settings['water_threshold'],
            'threshold_percent' => round((float)$settings['water_threshold'] / max(0.01, $depth) * 100, 2),
            'target'            => (float)$settings['water_target'],
            'auto'              => (bool)$settings['auto_water'],
            'reading_time'      => $reading['timestamp'] ?? null,
            'reading_ago'       => time_ago($reading['timestamp'] ?? null),
        ],
        'accuracy' => [
            'runs'      => (int)($accuracy['runs'] ?? 0),
            'avg_error' => $accuracy['avg_error'] !== null ? (float)$accuracy['avg_error'] : null,
            'max_error' => $accuracy['max_error'] !== null ? (float)$accuracy['max_error'] : null,
            'target'    => 1.0, // the ±1 cm measurement accuracy target
        ],
        'history'    => reading_history($deviceId, $hours, $hours <= 6 ? 5 : ($hours <= 48 ? 30 : 180)),
        'dispensing' => $history,
    ], 'Water data retrieved successfully');
});
