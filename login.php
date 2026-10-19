<?php
/**
 * PoultryGuard - sign in.
 *
 * Posts to the same URL (classic form post) so the page works even with
 * JavaScript disabled; the submit handler upgrades it to a fetch call for a
 * smoother experience when JS is available.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

// Already signed in? Go straight to the dashboard.
if (is_logged_in() && current_user()) {
    header('Location: ' . url('dashboard/'));
    exit;
}

$error = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        // The login form is the one place a CSRF token cannot be required from
        // an existing session, so the token is issued with the form itself.
        if (!csrf_valid($_POST['csrf_token'] ?? null)) {
            throw new ValidationException('Your sign-in form expired. Please try again.');
        }
        auth_login((string)($_POST['username'] ?? ''), (string)($_POST['password'] ?? ''));

        $target = $_SESSION['redirect_after_login'] ?? url('dashboard/');
        unset($_SESSION['redirect_after_login']);
        // Only ever redirect somewhere inside this application.
        if (!is_string($target) || !str_starts_with($target, BASE_URL === '' ? '/' : BASE_URL)) {
            $target = url('dashboard/');
        }
        header('Location: ' . $target);
        exit;
    } catch (ValidationException $e) {
        $error = $e->getMessage();
    } catch (DatabaseUnavailableException) {
        $error = 'Database connection failed. Check that MySQL is running in XAMPP.';
    } catch (Throwable $e) {
        error_log('[PoultryGuard] Login error: ' . $e->getMessage());
        $error = 'An unexpected error occurred while signing you in. Please try again.';
    }
}

if (isset($_GET['expired'])) {
    $error ??= 'Your session expired. Please sign in again.';
}
$flashError = flash('error');
$error ??= $flashError;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Sign in &middot; <?= e(APP_NAME) ?></title>
<link rel="icon" type="image/png" href="<?= e(asset_url('assets/img/favicon.png')) ?>">
<link rel="stylesheet" href="<?= e(url('assets/vendor/bootstrap.min.css')) ?>">
<link rel="stylesheet" href="<?= e(asset_url('assets/css/style.css')) ?>">
</head>
<body>
<div class="pg-login-wrap">
    <div class="pg-login-card">
        <div class="pg-login-head">
            <h1 class="mb-0">
                <img class="pg-login-logo" src="<?= e(asset_url('assets/img/logo.png')) ?>"
                     alt="<?= e(APP_NAME) ?>" width="900" height="740">
            </h1>
            <p class="mb-0 small"><?= e(APP_TAGLINE) ?></p>
        </div>

        <div class="p-4">
            <?php if ($error !== null): ?>
                <div class="alert alert-danger py-2 px-3 small" role="alert" id="loginError">
                    <?= e($error) ?>
                </div>
            <?php else: ?>
                <div class="alert alert-danger py-2 px-3 small d-none" role="alert" id="loginError"></div>
            <?php endif; ?>

            <form method="post" action="<?= e(url('login.php')) ?>" id="loginForm" novalidate>
                <?= csrf_field() ?>

                <div class="mb-3">
                    <label for="username" class="form-label fw-semibold small">Username</label>
                    <input type="text" class="form-control" id="username" name="username"
                           value="<?= e($_POST['username'] ?? '') ?>"
                           required autofocus autocomplete="username"
                           maxlength="50" placeholder="Enter your username">
                    <div class="invalid-feedback">Please enter your username.</div>
                </div>

                <div class="mb-3">
                    <label for="password" class="form-label fw-semibold small">Password</label>
                    <div class="input-group">
                        <input type="password" class="form-control" id="password" name="password"
                               required autocomplete="current-password" placeholder="Enter your password">
                        <button class="btn btn-outline-secondary" type="button" id="togglePassword"
                                aria-label="Show password" tabindex="-1">Show</button>
                    </div>
                    <div class="invalid-feedback">Please enter your password.</div>
                </div>

                <button type="submit" class="btn btn-primary w-100 fw-semibold py-2" id="loginBtn">
                    Sign in
                </button>
            </form>
        </div>
    </div>
</div>

<script src="<?= e(url('assets/vendor/bootstrap.bundle.min.js')) ?>"></script>
<script>
(function () {
    'use strict';
    var form = document.getElementById('loginForm');
    var errorBox = document.getElementById('loginError');
    var btn = document.getElementById('loginBtn');

    // Show/hide password
    var toggle = document.getElementById('togglePassword');
    var pwd = document.getElementById('password');
    toggle.addEventListener('click', function () {
        var isText = pwd.type === 'text';
        pwd.type = isText ? 'password' : 'text';
        toggle.textContent = isText ? 'Show' : 'Hide';
        toggle.setAttribute('aria-label', isText ? 'Show password' : 'Hide password');
    });

    function showError(message) {
        errorBox.textContent = message;
        errorBox.classList.remove('d-none');
    }

    form.addEventListener('submit', async function (e) {
        // Client-side validation first, so obvious mistakes never hit the server.
        var username = form.username.value.trim();
        var password = form.password.value;

        form.classList.add('was-validated');
        if (!username || !password) {
            e.preventDefault();
            showError('Please enter both your username and password.');
            return;
        }

        if (!window.fetch) {
            return; // fall back to the normal form post
        }

        e.preventDefault();
        errorBox.classList.add('d-none');
        var original = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Signing in...';

        try {
            var res = await fetch(<?= json_encode(url('api/auth/login.php'), JSON_UNESCAPED_SLASHES) ?>, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify({ username: username, password: password })
            });
            var payload = await res.json();

            if (res.ok && payload.success) {
                window.location.href = payload.data.redirect;
                return;
            }
            showError(payload.message || 'Sign in failed. Please try again.');
        } catch (err) {
            showError('Cannot reach the server. Check that Apache is running in XAMPP.');
        }

        btn.disabled = false;
        btn.innerHTML = original;
    });
})();
</script>
</body>
</html>
