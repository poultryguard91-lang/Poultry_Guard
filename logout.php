<?php
/**
 * PoultryGuard - sign out.
 *
 * The dashboard signs out through the API (with a CSRF token). This page is
 * the no-JavaScript fallback: it shows a confirmation form rather than ending
 * the session on a bare GET, so a stray link or prefetch cannot sign someone
 * out without their intent.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

if (!is_logged_in()) {
    header('Location: ' . url('login.php'));
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && csrf_valid($_POST['csrf_token'] ?? null)) {
    auth_logout();
    header('Location: ' . url('login.php?signed_out=1'));
    exit;
}

$user = current_user();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Sign out &middot; <?= e(APP_NAME) ?></title>
<link rel="stylesheet" href="<?= e(url('assets/vendor/bootstrap.min.css')) ?>">
<link rel="stylesheet" href="<?= e(url('assets/css/style.css')) ?>">
</head>
<body>
<div class="pg-login-wrap">
    <div class="pg-login-card">
        <div class="pg-login-head">
            <h1 class="h5 fw-bold mb-0">Sign out of <?= e(APP_NAME) ?>?</h1>
        </div>
        <div class="p-4">
            <p class="small mb-3">
                You are signed in as <strong><?= e($user['name'] ?? '') ?></strong>.
            </p>
            <div class="alert alert-info py-2 px-3 small">
                Automatic feeding and watering keep running on the ESP32 controller
                while nobody is signed in.
            </div>
            <form method="post" action="<?= e(url('logout.php')) ?>" class="d-grid gap-2">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-danger fw-semibold">Sign out</button>
                <a href="<?= e(url('dashboard/')) ?>" class="btn btn-light">Cancel</a>
            </form>
        </div>
    </div>
</div>
</body>
</html>
