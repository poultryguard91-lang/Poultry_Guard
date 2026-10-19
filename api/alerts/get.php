<?php
/**
 * GET /api/alerts/get.php
 *
 * Filterable, paginated alert list used by the alert panel and the alert
 * history page.
 *
 * Query: device_id, severity, status, alert_type, from, to, page, per_page
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/api.php';

api_require_method('GET');

api_run(function (array $in): ApiResult {
    require_permission('alerts.view');

    $where  = [];
    $params = [];

    // Device filter: "all" (or empty) spans every device.
    if (!empty($in['device_id']) && $in['device_id'] !== 'all') {
        $where[]  = 'a.device_id = ?';
        $params[] = v_int($in['device_id'], 'Device', 1, PHP_INT_MAX);
    }

    if (!empty($in['severity']) && $in['severity'] !== 'all') {
        $where[]  = 'a.severity = ?';
        $params[] = v_enum($in['severity'], 'Severity', ['INFO', 'WARNING', 'CRITICAL']);
    }

    if (!empty($in['status']) && $in['status'] !== 'all') {
        $where[]  = 'a.status = ?';
        $params[] = v_enum($in['status'], 'Status', ['unread', 'read', 'resolved']);
    }

    if (!empty($in['alert_type']) && $in['alert_type'] !== 'all') {
        $where[]  = 'a.alert_type = ?';
        $params[] = v_enum($in['alert_type'], 'Alert type', [
            'low_feed', 'critical_feed', 'low_water', 'critical_water',
            'sensor_failure', 'device_offline', 'internet_disconnected',
            'motor_error', 'solenoid_error', 'abnormal_reading',
            'system_error', 'dispensing_timeout', 'info',
        ]);
    }

    if (!empty($in['from'])) {
        $where[]  = 'a.created_at >= ?';
        $params[] = v_date($in['from'], 'From date') . ' 00:00:00';
    }
    if (!empty($in['to'])) {
        $where[]  = 'a.created_at <= ?';
        $params[] = v_date($in['to'], 'To date') . ' 23:59:59';
    }

    $sql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);

    // Total for the pager.
    $countSt = db()->prepare("SELECT COUNT(*) FROM alerts a{$sql}");
    $countSt->execute($params);
    $total = (int)$countSt->fetchColumn();

    $perPage = v_int($in['per_page'] ?? null, 'Per page', 1, 200, 25);
    $page    = v_int($in['page'] ?? null, 'Page', 1, 100000, 1);
    $pages   = max(1, (int)ceil($total / $perPage));
    $page    = min($page, $pages);
    $offset  = ($page - 1) * $perPage;

    // LIMIT/OFFSET are integers already validated above, so interpolating them
    // is safe; MySQL will not accept them as bound parameters in every driver mode.
    $listSt = db()->prepare(
        "SELECT a.*, d.device_name
         FROM alerts a
         JOIN devices d ON d.device_id = a.device_id
         {$sql}
         ORDER BY a.created_at DESC, a.alert_id DESC
         LIMIT {$perPage} OFFSET {$offset}"
    );
    $listSt->execute($params);

    $rows = array_map(static function (array $a): array {
        $a['ago']   = time_ago($a['created_at']);
        $a['color'] = status_color($a['severity']);
        return $a;
    }, $listSt->fetchAll());

    // Severity tallies for the summary cards, using the same filters.
    $sumSt = db()->prepare(
        "SELECT
            COUNT(*)                                            AS total,
            SUM(a.status = 'unread')                            AS unread,
            SUM(a.severity = 'CRITICAL')                        AS critical,
            SUM(a.severity = 'WARNING')                         AS warning,
            SUM(a.severity = 'INFO')                            AS info,
            SUM(a.status = 'resolved')                          AS resolved
         FROM alerts a{$sql}"
    );
    $sumSt->execute($params);
    $summary = $sumSt->fetch() ?: [];

    return api_ok([
        'alerts'  => $rows,
        'summary' => [
            'total'    => (int)($summary['total'] ?? 0),
            'unread'   => (int)($summary['unread'] ?? 0),
            'critical' => (int)($summary['critical'] ?? 0),
            'warning'  => (int)($summary['warning'] ?? 0),
            'info'     => (int)($summary['info'] ?? 0),
            'resolved' => (int)($summary['resolved'] ?? 0),
        ],
        'pagination' => [
            'page'     => $page,
            'per_page' => $perPage,
            'total'    => $total,
            'pages'    => $pages,
        ],
    ], 'Alerts retrieved successfully');
});
