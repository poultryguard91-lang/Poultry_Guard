<?php
/**
 * PoultryGuard - Authentication, session management and role-based access.
 *
 * Passwords are stored only as bcrypt hashes (password_hash / password_verify).
 * Authorisation is expressed once, in ROLE_PERMISSIONS below, and every page,
 * menu item and API endpoint consults that single matrix - so a permission
 * change never has to be made in two places.
 */

declare(strict_types=1);

require_once __DIR__ . '/functions.php';

/** Not signed in (or session expired) -> HTTP 401. */
class AuthenticationException extends RuntimeException
{
}

/**
 * Role -> permission matrix.
 *
 * This mirrors the roles defined in the system specification:
 *   - Farm Owner  oversees the business: monitoring, reporting, devices,
 *                 users, alert/notification policy.
 *   - Farm Staff  operates the equipment: thresholds, calibration and the
 *                 manual feed/water controls.
 * Both roles may view dashboards, monitoring, alerts and device status.
 */
const ROLE_PERMISSIONS = [
    ROLE_OWNER => [
        'dashboard.view',
        'monitoring.view',
        'alerts.view',
        'alerts.manage',
        'devices.view',
        'devices.manage',
        'sensors.calibrate',
        'settings.view',
        'settings.alerts',
        'reports.view',
        'users.manage',
        'logs.view',
        'production.manage',
    ],
    ROLE_STAFF => [
        'dashboard.view',
        'monitoring.view',
        'alerts.view',
        'alerts.manage',
        'devices.view',
        'sensors.calibrate',
        'settings.view',
        'settings.thresholds',
        'automation.control',
    ],
];

/**
 * Start the session and enforce the idle timeout.
 * Safe to call repeatedly.
 */
function session_boot(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (isset($_SESSION['user_id'], $_SESSION['last_activity'])) {
        if (time() - (int)$_SESSION['last_activity'] > SESSION_TIMEOUT) {
            $expiredUser = (int)$_SESSION['user_id'];
            session_unset();
            session_destroy();
            session_start();
            $_SESSION['flash_error'] = 'Your session expired after a period of inactivity. Please sign in again.';
            log_activity('SESSION_EXPIRED', 'Session timed out after inactivity.', $expiredUser);
            return;
        }
    }
    $_SESSION['last_activity'] = time();
}

// The session is needed by nearly everything, so open it on include.
session_boot();

// =====================================================================
// Session state
// =====================================================================

function is_logged_in(): bool
{
    return isset($_SESSION['user_id']);
}

/** The signed-in user's row, refreshed from the database, or null. */
function current_user(): ?array
{
    static $cached = null;
    if (!is_logged_in()) {
        return null;
    }
    if ($cached !== null && (int)$cached['user_id'] === (int)$_SESSION['user_id']) {
        return $cached;
    }
    $st = db()->prepare('SELECT * FROM users WHERE user_id = ? AND status = ?');
    $st->execute([(int)$_SESSION['user_id'], 'active']);
    $row = $st->fetch();
    if (!$row) {
        // Account was deactivated or deleted while the session was alive.
        auth_logout(false);
        return null;
    }
    unset($row['password']);
    $cached = $row;
    return $cached;
}

function current_user_id(): ?int
{
    $u = current_user();
    return $u ? (int)$u['user_id'] : null;
}

function current_role(): ?string
{
    $u = current_user();
    return $u ? (string)$u['role'] : null;
}

// =====================================================================
// Authorisation
// =====================================================================

/** Does the signed-in user hold this permission? */
function user_can(string $permission): bool
{
    $role = current_role();
    if ($role === null) {
        return false;
    }
    return in_array($permission, ROLE_PERMISSIONS[$role] ?? [], true);
}

/** Require a signed-in session. @throws AuthenticationException */
function require_auth(): array
{
    $user = current_user();
    if (!$user) {
        throw new AuthenticationException('You must sign in to continue.');
    }
    return $user;
}

/** Require a specific permission. @throws AuthenticationException|AuthorizationException */
function require_permission(string $permission): array
{
    $user = require_auth();
    if (!user_can($permission)) {
        throw new AuthorizationException('You do not have permission to perform this action.');
    }
    return $user;
}

/**
 * Page-level guard: redirect to the login screen rather than throwing, and
 * remember where the user was heading so login can return them there.
 */
function require_page_auth(?string $permission = null): array
{
    if (!is_logged_in() || !current_user()) {
        $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'] ?? url('dashboard/');
        header('Location: ' . url('login.php'));
        exit;
    }
    $user = current_user();
    if ($permission !== null && !user_can($permission)) {
        header('Location: ' . url('dashboard/?denied=1'));
        exit;
    }
    return $user;
}

// =====================================================================
// Login / logout
// =====================================================================

/** Failed attempts recorded for this IP + username inside the lockout window. */
function login_attempt_count(string $username, string $ip): int
{
    $st = db()->prepare(
        'SELECT COUNT(*) FROM login_attempts
         WHERE username = ? AND ip_address = ? AND success = 0
           AND created_at > DATE_SUB(NOW(), INTERVAL ? SECOND)'
    );
    $st->execute([$username, $ip, LOGIN_LOCKOUT_SECONDS]);
    return (int)$st->fetchColumn();
}

function record_login_attempt(string $username, string $ip, bool $success): void
{
    $st = db()->prepare(
        'INSERT INTO login_attempts (username, ip_address, success) VALUES (?, ?, ?)'
    );
    $st->execute([mb_substr($username, 0, 50), $ip, $success ? 1 : 0]);
}

/**
 * Verify credentials and open a session.
 *
 * @throws ValidationException with a message safe to show the user.
 */
function auth_login(string $username, string $password): array
{
    $ip = client_ip();
    $username = trim($username);

    if ($username === '' || $password === '') {
        throw new ValidationException('Please enter both your username and password.');
    }

    if (login_attempt_count($username, $ip) >= LOGIN_MAX_ATTEMPTS) {
        throw new ValidationException(
            'Too many failed sign-in attempts. Please try again in ' .
            (int)(LOGIN_LOCKOUT_SECONDS / 60) . ' minutes.'
        );
    }

    $st = db()->prepare('SELECT * FROM users WHERE username = ? LIMIT 1');
    $st->execute([$username]);
    $user = $st->fetch();

    // Verify against a dummy hash when the user does not exist so that a wrong
    // username and a wrong password take the same amount of time to reject.
    $hash = $user['password'] ?? '$2y$10$usesomesillystringforeudismthatnevermatchesXXXXXXXXXXXXXXXXXX';
    $ok = password_verify($password, $hash);

    if (!$user || !$ok) {
        record_login_attempt($username, $ip, false);
        throw new ValidationException('Incorrect username or password.');
    }

    if ($user['status'] !== 'active') {
        record_login_attempt($username, $ip, false);
        throw new ValidationException('This account has been deactivated. Contact the farm owner.');
    }

    // Transparently upgrade the hash if PHP's default cost has moved on.
    if (password_needs_rehash($user['password'], PASSWORD_DEFAULT)) {
        $up = db()->prepare('UPDATE users SET password = ? WHERE user_id = ?');
        $up->execute([password_hash($password, PASSWORD_DEFAULT), (int)$user['user_id']]);
    }

    record_login_attempt($username, $ip, true);

    // New session id on privilege change defeats session fixation.
    session_regenerate_id(true);
    $_SESSION['user_id']       = (int)$user['user_id'];
    $_SESSION['role']          = $user['role'];
    $_SESSION['name']          = $user['name'];
    $_SESSION['last_activity'] = time();
    $_SESSION['csrf_token']    = bin2hex(random_bytes(32));

    db()->prepare('UPDATE users SET last_login = NOW() WHERE user_id = ?')
        ->execute([(int)$user['user_id']]);

    log_activity('LOGIN', "User {$user['username']} signed in.", (int)$user['user_id']);

    unset($user['password']);
    return $user;
}

/** End the session. */
function auth_logout(bool $log = true): void
{
    if ($log && is_logged_in()) {
        $uid = (int)$_SESSION['user_id'];
        $name = $_SESSION['name'] ?? 'user';
        log_activity('LOGOUT', "User {$name} signed out.", $uid);
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

// =====================================================================
// CSRF protection
//
// Every state-changing request from the browser must carry the token, which
// only same-origin pages can read.
// =====================================================================

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/** Hidden input for classic form posts. */
function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

/** Constant-time comparison of a submitted token. */
function csrf_valid(?string $token): bool
{
    return is_string($token)
        && !empty($_SESSION['csrf_token'])
        && hash_equals((string)$_SESSION['csrf_token'], $token);
}

/**
 * Enforce CSRF on a state-changing request.
 * The token may arrive as a form field or as the X-CSRF-Token header
 * (which is what the fetch() calls in assets/js/app.js send).
 *
 * @throws AuthorizationException
 */
function require_csrf(array $input = []): void
{
    $token = $input['csrf_token']
        ?? $_POST['csrf_token']
        ?? $_SERVER['HTTP_X_CSRF_TOKEN']
        ?? null;

    if (!csrf_valid(is_string($token) ? $token : null)) {
        throw new AuthorizationException('Your session token expired. Please refresh the page and try again.');
    }
}

/** Pull and clear a one-shot flash message. */
function flash(string $key): ?string
{
    $k = 'flash_' . $key;
    if (!isset($_SESSION[$k])) {
        return null;
    }
    $v = (string)$_SESSION[$k];
    unset($_SESSION[$k]);
    return $v;
}

function set_flash(string $key, string $message): void
{
    $_SESSION['flash_' . $key] = $message;
}
