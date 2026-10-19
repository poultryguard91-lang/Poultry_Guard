<?php
/**
 * PoultryGuard - entry point.
 *
 * Sends visitors to the dashboard when they have a session and to the sign-in
 * page when they do not. Also surfaces a clear, actionable message when the
 * database has not been imported yet, which is the most common first-run
 * problem on a fresh XAMPP install.
 */

declare(strict_types=1);

require_once __DIR__ . '/config/config.php';

try {
    // Touching the connection here turns a misconfigured install into a helpful
    // page rather than a redirect loop.
    db()->query('SELECT 1');
} catch (DatabaseUnavailableException) {
    http_response_code(503);
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Setup required &middot; <?= e(APP_NAME) ?></title>
        <link rel="icon" type="image/png" href="<?= e(asset_url('assets/img/favicon.png')) ?>">
        <link rel="stylesheet" href="<?= e(url('assets/vendor/bootstrap.min.css')) ?>">
        <link rel="stylesheet" href="<?= e(asset_url('assets/css/style.css')) ?>">
    </head>
    <body>
    <div class="pg-login-wrap">
        <div class="pg-login-card" style="max-width: 560px;">
            <div class="pg-login-head" style="background: linear-gradient(165deg, #fff, #fdf0d7);">
                <img class="pg-login-logo" style="max-width: 190px;"
                     src="<?= e(asset_url('assets/img/logo.png')) ?>" alt="<?= e(APP_NAME) ?>">
                <h1 class="h5 fw-bold mb-0" style="color: #7a4a05;">Database connection failed</h1>
            </div>
            <div class="p-4">
                <p class="small">
                    <?= e(APP_NAME) ?> could not reach its MySQL database. Work through these steps:
                </p>
                <ol class="small">
                    <li class="mb-2">Open the <strong>XAMPP Control Panel</strong> and start
                        <strong>Apache</strong> and <strong>MySQL</strong>.</li>
                    <li class="mb-2">Import the schema: open
                        <a href="http://localhost/phpmyadmin" target="_blank" rel="noopener">phpMyAdmin</a>,
                        choose <strong>Import</strong>, and select
                        <code>database/poultryguard.sql</code> from this project.</li>
                    <li class="mb-2">Confirm the credentials in <code>config/database.php</code>
                        match your MySQL setup (XAMPP defaults to user <code>root</code> with an
                        empty password).</li>
                    <li>Reload this page.</li>
                </ol>
                <div class="alert alert-secondary small py-2 px-3 mb-3">
                    Technical details are written to <code>logs/error.log</code> rather than shown here.
                </div>
                <a href="" class="btn btn-primary w-100 fw-semibold">Retry</a>
            </div>
        </div>
    </div>
    </body>
    </html>
    <?php
    exit;
}

require_once __DIR__ . '/includes/auth.php';

header('Location: ' . url(is_logged_in() && current_user() ? 'dashboard/' : 'login.php'));
exit;
