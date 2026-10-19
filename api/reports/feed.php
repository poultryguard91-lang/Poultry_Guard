<?php
/**
 * GET /api/reports/feed.php?device_id=1&from=&to=&period=daily|weekly|monthly
 *
 * Feed consumption and dispensing history for the reports module.
 *
 * Consumption is a mass balance rather than a simple sum of what was
 * dispensed - see feed_consumption() - so it reflects what the birds actually
 * ate over the period.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/api.php';

api_require_method('GET');

api_run(function (array $in): ApiResult {
    require_permission('reports.view');

    $device   = api_resolve_device($in);
    $deviceId = (int)$device['device_id'];
    $range    = resolve_range($in, 14);

    $series = feed_consumption($deviceId, $range['from'], $range['to'], $range['period']);

    $totalConsumed  = array_sum(array_column($series, 'consumed'));
    $totalDispensed = array_sum(array_column($series, 'dispensed'));
    $periods        = max(1, count($series));

    // Breakdown of how dispensing was triggered - one of the system reports.
    $methodSt = db()->prepare(
        "SELECT dispensing_method, status, COUNT(*) AS events,
                COALESCE(SUM(GREATEST(COALESCE(dispensed_amount, actual_weight - start_weight), 0)), 0) AS amount
         FROM feed_dispensing
         WHERE device_id = ? AND `timestamp` >= ? AND `timestamp` < ?
         GROUP BY dispensing_method, status"
    );
    $methodSt->execute([$deviceId, $range['from'], $range['to']]);
    $methods = $methodSt->fetchAll();

    // Dispensing log for the printable table.
    $logSt = db()->prepare(
        'SELECT fd.*, u.name AS user_name
         FROM feed_dispensing fd
         LEFT JOIN users u ON u.user_id = fd.user_id
         WHERE fd.device_id = ? AND fd.`timestamp` >= ? AND fd.`timestamp` < ?
         ORDER BY fd.`timestamp` DESC
         LIMIT 500'
    );
    $logSt->execute([$deviceId, $range['from'], $range['to']]);
    $log = array_map(static function (array $r): array {
        $r['dispensed_display'] = format_weight($r['dispensed_amount'] !== null ? (float)$r['dispensed_amount'] : null);
        $r['accuracy_g'] = $r['actual_weight'] !== null
            ? round((float)$r['actual_weight'] - (float)$r['target_weight'], 2) : null;
        return $r;
    }, $logSt->fetchAll());

    $peak = $series === [] ? null : max(array_column($series, 'consumed'));

    return api_ok([
        'device'  => ['device_id' => $deviceId, 'device_name' => $device['device_name'], 'location' => $device['location']],
        'range'   => $range,
        'series'  => $series,
        'summary' => [
            'total_consumed_g'   => round($totalConsumed, 2),
            'total_consumed_kg'  => round($totalConsumed / 1000, 3),
            'total_dispensed_g'  => round($totalDispensed, 2),
            'total_dispensed_kg' => round($totalDispensed / 1000, 3),
            'average_per_period' => round($totalConsumed / $periods, 2),
            'average_display'    => format_weight($totalConsumed / $periods),
            'peak_period'        => $peak !== null ? round($peak, 2) : 0.0,
            'peak_display'       => format_weight($peak ?? 0),
            'periods'            => count($series),
            'total_events'       => (int)array_sum(array_column($series, 'events')),
        ],
        'methods' => array_map(static fn(array $m): array => [
            'method' => $m['dispensing_method'],
            'status' => $m['status'],
            'events' => (int)$m['events'],
            'amount' => round((float)$m['amount'], 2),
        ], $methods),
        'log' => $log,
    ], 'Feed report generated successfully');
});
