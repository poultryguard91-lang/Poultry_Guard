<?php
/**
 * POST /api/auth/login.php
 *
 * Body: { "username": "...", "password": "..." }
 *
 * Opens a session on success and returns the user's role plus a fresh CSRF
 * token for the client to attach to later state-changing requests.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/api.php';

api_require_method('POST');

api_run(function (array $in): ApiResult {
    $username = v_string($in['username'] ?? null, 'Username', 1, 50);
    $password = (string)($in['password'] ?? '');

    // auth_login throws ValidationException (-> HTTP 422) with a message that
    // never reveals whether it was the username or the password that was wrong.
    $user = auth_login($username, $password);

    return api_ok([
        'user' => [
            'user_id'  => (int)$user['user_id'],
            'name'     => $user['name'],
            'username' => $user['username'],
            'role'     => $user['role'],
        ],
        'permissions' => ROLE_PERMISSIONS[$user['role']] ?? [],
        'csrf_token'  => csrf_token(),
        'redirect'    => url('dashboard/'),
    ], 'Signed in successfully');
});
