<?php
/**
 * GET /api/users/get.php
 *
 * User accounts and the recent activity log for the user management page.
 * Password hashes are never included in the response.
 *
 * Requires the users.manage permission (Farm Owner).
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/api.php';

api_require_method('GET');

api_run(function (array $in): ApiResult {
    require_permission('users.manage');

    $st = db()->query(
        'SELECT user_id, name, username, email, role, status, last_login, created_at
         FROM users ORDER BY role ASC, name ASC'
    );
    $users = array_map(static function (array $u): array {
        $u['last_login_ago'] = time_ago($u['last_login']);
        $u['permissions']    = ROLE_PERMISSIONS[$u['role']] ?? [];
        return $u;
    }, $st->fetchAll());

    $logs = [];
    if (user_can('logs.view')) {
        $limit = v_int($in['log_limit'] ?? null, 'Log limit', 1, 200, 30);
        $ls = db()->prepare(
            'SELECT l.*, u.name AS user_name, u.role
             FROM activity_logs l
             LEFT JOIN users u ON u.user_id = l.user_id
             ORDER BY l.created_at DESC
             LIMIT ' . $limit
        );
        $ls->execute();
        $logs = array_map(static function (array $l): array {
            $l['ago'] = time_ago($l['created_at']);
            return $l;
        }, $ls->fetchAll());
    }

    return api_ok([
        'users'   => $users,
        'logs'    => $logs,
        'summary' => [
            'total'    => count($users),
            'owners'   => count(array_filter($users, static fn(array $u): bool => $u['role'] === ROLE_OWNER)),
            'staff'    => count(array_filter($users, static fn(array $u): bool => $u['role'] === ROLE_STAFF)),
            'active'   => count(array_filter($users, static fn(array $u): bool => $u['status'] === 'active')),
            'inactive' => count(array_filter($users, static fn(array $u): bool => $u['status'] !== 'active')),
        ],
    ], 'Users retrieved successfully');
});
