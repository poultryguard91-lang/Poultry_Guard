<?php
/**
 * POST /api/auth/logout.php
 *
 * Ends the session. Requires a valid CSRF token so a third-party page cannot
 * sign the user out behind their back.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/api.php';

api_require_method('POST');

api_run(function (array $in): ApiResult {
    require_auth();
    require_csrf($in);
    auth_logout();

    return api_ok(['redirect' => url('login.php')], 'Signed out successfully');
});
