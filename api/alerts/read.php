<?php
/**
 * POST /api/alerts/read.php
 *
 * Change alert status. Body:
 *   { "alert_id": 12, "status": "read" }          - one alert
 *   { "action": "read_all", "device_id": 1 }      - every unread alert
 *   { "action": "resolve_all", "device_id": 1 }   - clear the board
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/api.php';

api_require_method('POST');

api_run(function (array $in): ApiResult {
    $user = require_permission('alerts.manage');
    require_csrf($in);

    $action = v_enum($in['action'] ?? 'update', 'Action', ['update', 'read_all', 'resolve_all'], 'update');

    // ------------------------------------------------------------------
    // Bulk actions
    // ------------------------------------------------------------------
    if ($action !== 'update') {
        $params = [];
        $scope  = '';
        if (!empty($in['device_id']) && $in['device_id'] !== 'all') {
            $scope    = ' AND device_id = ?';
            $params[] = v_int($in['device_id'], 'Device', 1, PHP_INT_MAX);
        }

        if ($action === 'read_all') {
            $st = db()->prepare("UPDATE alerts SET status = 'read' WHERE status = 'unread'{$scope}");
            $st->execute($params);
            $n = $st->rowCount();
            log_activity('ALERTS_READ_ALL', "Marked {$n} alert(s) as read.", (int)$user['user_id']);
            return api_ok(['affected' => $n], "{$n} alert(s) marked as read");
        }

        $st = db()->prepare(
            "UPDATE alerts SET status = 'resolved', resolved_at = NOW()
             WHERE status <> 'resolved'{$scope}"
        );
        $st->execute($params);
        $n = $st->rowCount();
        log_activity('ALERTS_RESOLVE_ALL', "Resolved {$n} alert(s).", (int)$user['user_id']);
        return api_ok(['affected' => $n], "{$n} alert(s) resolved");
    }

    // ------------------------------------------------------------------
    // Single alert
    // ------------------------------------------------------------------
    $alertId = v_int($in['alert_id'] ?? null, 'Alert', 1, PHP_INT_MAX);
    $status  = v_enum($in['status'] ?? null, 'Status', ['unread', 'read', 'resolved']);

    $st = db()->prepare('SELECT * FROM alerts WHERE alert_id = ?');
    $st->execute([$alertId]);
    $alert = $st->fetch();
    if (!$alert) {
        throw new NotFoundException('That alert could not be found.');
    }

    db()->prepare(
        'UPDATE alerts SET status = ?, resolved_at = ? WHERE alert_id = ?'
    )->execute([
        $status,
        $status === 'resolved' ? date('Y-m-d H:i:s') : null,
        $alertId,
    ]);

    if ($status === 'resolved') {
        log_activity('ALERT_RESOLVED',
            sprintf('Alert #%d (%s) marked as resolved.', $alertId, $alert['alert_type']),
            (int)$user['user_id']);
    }

    return api_ok([
        'alert_id' => $alertId,
        'status'   => $status,
        'unread'   => unread_alert_count((int)$alert['device_id']),
    ], 'Alert updated successfully');
});
