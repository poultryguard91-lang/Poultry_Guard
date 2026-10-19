<?php
/**
 * GET /api/feed/read.php?device_id=1&hours=24
 *
 * Current feed state, the trend series and the recent dispensing log for the
 * feed monitoring page.
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

    $reading  = latest_feed_reading($deviceId);
    $capacity = (float)$settings['feed_capacity'];
    $weight   = $reading ? (float)$reading['feed_weight'] : null;
    $pct      = $reading ? (float)$reading['feed_percentage'] : null;

    // Recent activations, most recent first.
    $st = db()->prepare(
        'SELECT fd.*, u.name AS user_name
         FROM feed_dispensing fd
         LEFT JOIN users u ON u.user_id = fd.user_id
         WHERE fd.device_id = ?
         ORDER BY fd.`timestamp` DESC
         LIMIT ' . $limit
    );
    $st->execute([$deviceId]);
    $history = array_map(static function (array $r): array {
        $r['dispensed_display'] = format_weight($r['dispensed_amount'] !== null ? (float)$r['dispensed_amount'] : null);
        // How close the auger got to the requested weight - the ±5 g accuracy target.
        $r['accuracy_g'] = ($r['actual_weight'] !== null)
            ? round((float)$r['actual_weight'] - (float)$r['target_weight'], 2) : null;
        $r['ago'] = time_ago($r['timestamp']);
        return $r;
    }, $st->fetchAll());

    // Aggregate accuracy across completed automatic/manual runs.
    $accSt = db()->prepare(
        "SELECT COUNT(*) AS runs,
                ROUND(AVG(ABS(actual_weight - target_weight)), 2) AS avg_error,
                ROUND(MAX(ABS(actual_weight - target_weight)), 2) AS max_error
         FROM feed_dispensing
         WHERE device_id = ? AND status = 'completed' AND actual_weight IS NOT NULL
           AND `timestamp` >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
    );
    $accSt->execute([$deviceId]);
    $accuracy = $accSt->fetch() ?: [];

    return api_ok([
        'device' => [
            'device_id'   => $deviceId,
            'device_name' => $device['device_name'],
            'online'      => device_is_online($device['last_seen'], (int)$settings['offline_timeout']),
            'motor_status'=> $device['motor_status'],
            'sensor_status' => $device['feed_sensor_status'],
        ],
        'current' => [
            'weight'            => $weight,
            'weight_display'    => format_weight($weight),
            'percentage'        => $pct,
            'raw_value'         => $reading && $reading['raw_value'] !== null ? (int)$reading['raw_value'] : null,
            'status'            => $pct === null ? 'UNKNOWN'
                : level_status($pct, (float)$settings['feed_low_percent'], (float)$settings['feed_critical_percent']),
            'capacity'          => $capacity,
            'threshold'         => (float)$settings['feed_threshold'],
            'threshold_percent' => feed_percentage((float)$settings['feed_threshold'], $capacity),
            'target'            => (float)$settings['target_feed'],
            'tolerance'         => (float)$settings['feed_tolerance'],
            'auto'              => (bool)$settings['auto_feed'],
            'remaining_to_full' => $weight === null ? null : round(max(0, $capacity - $weight), 2),
            'reading_time'      => $reading['timestamp'] ?? null,
            'reading_ago'       => time_ago($reading['timestamp'] ?? null),
        ],
        'accuracy' => [
            'runs'      => (int)($accuracy['runs'] ?? 0),
            'avg_error' => $accuracy['avg_error'] !== null ? (float)$accuracy['avg_error'] : null,
            'max_error' => $accuracy['max_error'] !== null ? (float)$accuracy['max_error'] : null,
            'target'    => (float)$settings['feed_tolerance'],
        ],
        'history'    => reading_history($deviceId, 'feed', $hours, $hours <= 6 ? 5 : ($hours <= 48 ? 30 : 180)),
        'dispensing' => $history,
    ], 'Feed data retrieved successfully');
});
