<?php
/**
 * GET /api/reports/water.php?device_id=1&from=&to=&period=daily|weekly|monthly
 *
 * Water consumption and refill history for the reports module.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/api.php';

api_require_method('GET');

api_run(function (array $in): ApiResult {
    require_permission('reports.view');

    $device   = api_resolve_device($in);
    $deviceId = (int)$device['device_id'];
    $settings = get_settings($deviceId);
    $range    = resolve_range($in, 14);

    $series = water_consumption($deviceId, $range['from'], $range['to'], $range['period']);

    $totalLiters = array_sum(array_column($series, 'consumed_liters'));
    $totalCm     = array_sum(array_column($series, 'consumed_cm'));
    $periods     = max(1, count($series));

    $methodSt = db()->prepare(
        "SELECT dispensing_method, status, COUNT(*) AS events,
                COALESCE(SUM(GREATEST(ending_level - starting_level, 0)), 0) AS amount_cm
         FROM water_dispensing
         WHERE device_id = ? AND `timestamp` >= ? AND `timestamp` < ?
         GROUP BY dispensing_method, status"
    );
    $methodSt->execute([$deviceId, $range['from'], $range['to']]);
    $methods = $methodSt->fetchAll();

    $logSt = db()->prepare(
        'SELECT wd.*, u.name AS user_name
         FROM water_dispensing wd
         LEFT JOIN users u ON u.user_id = wd.user_id
         WHERE wd.device_id = ? AND wd.`timestamp` >= ? AND wd.`timestamp` < ?
         ORDER BY wd.`timestamp` DESC
         LIMIT 500'
    );
    $logSt->execute([$deviceId, $range['from'], $range['to']]);

    $depth      = max(0.01, (float)$settings['water_capacity']);
    $litersFull = (float)$settings['water_capacity_liters'];

    $log = array_map(static function (array $r) use ($depth, $litersFull): array {
        $added = $r['ending_level'] !== null
            ? round((float)$r['ending_level'] - (float)$r['starting_level'], 2) : null;
        $r['added_cm']     = $added;
        $r['added_liters'] = $added !== null ? round($added / $depth * $litersFull, 2) : null;
        $r['accuracy_cm']  = $r['ending_level'] !== null
            ? round((float)$r['ending_level'] - (float)$r['target_level'], 2) : null;
        return $r;
    }, $logSt->fetchAll());

    $peak = $series === [] ? null : max(array_column($series, 'consumed_liters'));

    return api_ok([
        'device'  => ['device_id' => $deviceId, 'device_name' => $device['device_name'], 'location' => $device['location']],
        'range'   => $range,
        'series'  => $series,
        'summary' => [
            'total_consumed_liters' => round($totalLiters, 2),
            'total_consumed_cm'     => round($totalCm, 2),
            'total_refilled_cm'     => round(array_sum(array_column($series, 'refilled_cm')), 2),
            'average_per_period'    => round($totalLiters / $periods, 2),
            'peak_period'           => $peak !== null ? round($peak, 2) : 0.0,
            'periods'               => count($series),
            'total_events'          => (int)array_sum(array_column($series, 'events')),
            'tank_capacity_liters'  => $litersFull,
            'tank_depth_cm'         => (float)$settings['water_capacity'],
        ],
        'methods' => array_map(static fn(array $m): array => [
            'method'    => $m['dispensing_method'],
            'status'    => $m['status'],
            'events'    => (int)$m['events'],
            'amount_cm' => round((float)$m['amount_cm'], 2),
        ], $methods),
        'log' => $log,
    ], 'Water report generated successfully');
});
