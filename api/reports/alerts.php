<?php
/**
 * GET /api/reports/alerts.php?device_id=1&from=&to=&period=daily
 *
 * The system report: alert frequency, device uptime, sensor activity and the
 * split between manual and automated actions.
 *
 * Uptime is estimated by comparing how many telemetry samples actually landed
 * against how many the configured reading interval should have produced over
 * the period. It is an estimate, and it is labelled as one in the UI.
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
    $expr     = period_expression($range['period']);

    // ------------------------------------------------------------------
    // Alert frequency
    // ------------------------------------------------------------------
    // period_expression() is written against the readings tables, which use a
    // `timestamp` column; alerts are dated by `created_at`.
    $alertExpr = str_replace('`timestamp`', '`created_at`', $expr);

    $freqSt = db()->prepare(
        "SELECT {$alertExpr} AS period,
                COUNT(*) AS total,
                SUM(severity = 'CRITICAL') AS critical,
                SUM(severity = 'WARNING')  AS warning,
                SUM(severity = 'INFO')     AS info
         FROM alerts
         WHERE device_id = ? AND created_at >= ? AND created_at < ?
         GROUP BY period ORDER BY period ASC"
    );
    $freqSt->execute([$deviceId, $range['from'], $range['to']]);
    $frequency = array_map(static fn(array $r): array => [
        'period'   => $r['period'],
        'total'    => (int)$r['total'],
        'critical' => (int)$r['critical'],
        'warning'  => (int)$r['warning'],
        'info'     => (int)$r['info'],
    ], $freqSt->fetchAll());

    $typeSt = db()->prepare(
        "SELECT alert_type, severity, COUNT(*) AS total,
                SUM(status = 'resolved') AS resolved
         FROM alerts
         WHERE device_id = ? AND created_at >= ? AND created_at < ?
         GROUP BY alert_type, severity
         ORDER BY total DESC"
    );
    $typeSt->execute([$deviceId, $range['from'], $range['to']]);
    $byType = array_map(static fn(array $r): array => [
        'alert_type' => $r['alert_type'],
        'severity'   => $r['severity'],
        'total'      => (int)$r['total'],
        'resolved'   => (int)$r['resolved'],
    ], $typeSt->fetchAll());

    // ------------------------------------------------------------------
    // Sensor activity and estimated uptime
    // ------------------------------------------------------------------
    // Positional placeholders: a native prepared statement cannot bind one
    // named placeholder to several occurrences.
    $actSt = db()->prepare(
        'SELECT
            (SELECT COUNT(*) FROM climate_readings  WHERE device_id = ? AND `timestamp` >= ? AND `timestamp` < ?) AS climate_readings,
            (SELECT COUNT(*) FROM water_readings WHERE device_id = ? AND `timestamp` >= ? AND `timestamp` < ?) AS water_readings,
            (SELECT COUNT(*) FROM climate_readings  WHERE device_id = ? AND `timestamp` >= ? AND `timestamp` < ? AND source = \'offline_sync\') AS climate_offline,
            (SELECT COUNT(*) FROM water_readings WHERE device_id = ? AND `timestamp` >= ? AND `timestamp` < ? AND source = \'offline_sync\') AS water_offline'
    );
    $trip = [$deviceId, $range['from'], $range['to']];
    $actSt->execute(array_merge($trip, $trip, $trip, $trip));
    $activity = $actSt->fetch() ?: [];

    $seconds  = max(1, strtotime($range['to']) - strtotime($range['from']));
    // Do not count the future when the range ends today.
    $seconds  = min($seconds, max(1, time() - strtotime($range['from'])));
    $interval = max(1, (int)$settings['reading_interval']);
    $expected = (int)floor($seconds / $interval);
    $actual   = (int)($activity['climate_readings'] ?? 0);
    $uptime   = $expected > 0 ? min(100.0, round($actual / $expected * 100, 2)) : 0.0;

    // ------------------------------------------------------------------
    // Manual vs automated actions
    // ------------------------------------------------------------------
    $actionSt = db()->prepare(
        "SELECT method, SUM(feed_events) AS feed_events, SUM(water_events) AS water_events FROM (
            SELECT dispensing_method AS method, COUNT(*) AS feed_events, 0 AS water_events
            FROM feed_dispensing
            WHERE device_id = ? AND `timestamp` >= ? AND `timestamp` < ?
            GROUP BY dispensing_method
            UNION ALL
            SELECT dispensing_method AS method, 0 AS feed_events, COUNT(*) AS water_events
            FROM water_dispensing
            WHERE device_id = ? AND `timestamp` >= ? AND `timestamp` < ?
            GROUP BY dispensing_method
         ) x GROUP BY method"
    );
    $actionSt->execute(array_merge($trip, $trip));
    $actions = array_map(static fn(array $r): array => [
        'method'       => $r['method'],
        'feed_events'  => (int)$r['feed_events'],
        'water_events' => (int)$r['water_events'],
        'total'        => (int)$r['feed_events'] + (int)$r['water_events'],
    ], $actionSt->fetchAll());

    // ------------------------------------------------------------------
    // Operator activity log
    // ------------------------------------------------------------------
    $logSt = db()->prepare(
        'SELECT l.*, u.name AS user_name, u.role
         FROM activity_logs l
         LEFT JOIN users u ON u.user_id = l.user_id
         WHERE l.created_at >= ? AND l.created_at < ?
         ORDER BY l.created_at DESC
         LIMIT 200'
    );
    $logSt->execute([$range['from'], $range['to']]);

    $totals = array_reduce($frequency, static function (array $c, array $r): array {
        $c['total']    += $r['total'];
        $c['critical'] += $r['critical'];
        $c['warning']  += $r['warning'];
        $c['info']     += $r['info'];
        return $c;
    }, ['total' => 0, 'critical' => 0, 'warning' => 0, 'info' => 0]);

    return api_ok([
        'device' => ['device_id' => $deviceId, 'device_name' => $device['device_name'], 'location' => $device['location']],
        'range'  => $range,
        'frequency' => $frequency,
        'by_type'   => $byType,
        'summary'   => $totals + [
            'resolved' => (int)array_sum(array_column($byType, 'resolved')),
        ],
        'uptime' => [
            'estimated_percent' => $uptime,
            'expected_readings' => $expected,
            'actual_readings'   => $actual,
            'reading_interval'  => $interval,
            'currently_online'  => device_is_online($device['last_seen'], (int)$settings['offline_timeout']),
            'last_seen'         => $device['last_seen'],
        ],
        'sensor_activity' => [
            'climate_readings'  => (int)($activity['climate_readings'] ?? 0),
            'water_readings' => (int)($activity['water_readings'] ?? 0),
            // Rows that arrived through the offline buffer show how much of the
            // period the controller spent running without a network.
            'climate_offline_sync'  => (int)($activity['climate_offline'] ?? 0),
            'water_offline_sync' => (int)($activity['water_offline'] ?? 0),
        ],
        'actions'      => $actions,
        'activity_log' => $logSt->fetchAll(),
    ], 'System report generated successfully');
});
