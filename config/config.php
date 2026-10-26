<?php
/**
 * PoultryGuard - Application configuration and bootstrap.
 *
 * Every entry point (page or API endpoint) loads this file first. It sets up
 * error handling, paths, timezone and the session cookie policy. It must be
 * included before any output is sent so the session cookie can be issued.
 */

declare(strict_types=1);

// ---------------------------------------------------------------------
// Paths
// ---------------------------------------------------------------------
define('APP_ROOT', dirname(__DIR__));
define('APP_LOG_DIR', APP_ROOT . DIRECTORY_SEPARATOR . 'logs');

/**
 * Work out the URL prefix the app is served from (e.g. "/PoultryGuard") by
 * comparing the project directory against the web server document root. This
 * keeps every link correct whether the project sits in htdocs/PoultryGuard or
 * at the root of a virtual host.
 */
$__docRoot = isset($_SERVER['DOCUMENT_ROOT']) ? realpath($_SERVER['DOCUMENT_ROOT']) : false;
$__appRoot = realpath(APP_ROOT);
$__base    = '';
if ($__docRoot !== false && $__appRoot !== false) {
    $__docRoot = str_replace('\\', '/', $__docRoot);
    $__appRoot = str_replace('\\', '/', $__appRoot);
    if ($__appRoot !== $__docRoot && str_starts_with($__appRoot, $__docRoot)) {
        $__base = substr($__appRoot, strlen($__docRoot));
    }
}
define('BASE_URL', rtrim($__base, '/'));

// ---------------------------------------------------------------------
// Application constants
// ---------------------------------------------------------------------
define('APP_NAME', 'PoultryGuard');
define('APP_TAGLINE', 'Precision IoT Automation for Poultry Feeding and Hydration');
define('APP_VERSION', '1.0.0');

/** Idle time (seconds) after which a web session is discarded. */
define('SESSION_TIMEOUT', 1800);

/** Login throttling: attempts allowed per IP+username within the window. */
define('LOGIN_MAX_ATTEMPTS', 5);
define('LOGIN_LOCKOUT_SECONDS', 900);

/** Fallback for how long a device may stay silent before it counts as offline. */
define('DEFAULT_OFFLINE_TIMEOUT', 300);
// The ESP32 firmware never runs the feeder longer than this (MOTOR_MAX_SEC).
define('FEED_MAX_SEC_LIMIT', 20);

/** Roles. */
define('ROLE_OWNER', 'owner');
define('ROLE_STAFF', 'staff');

// ---------------------------------------------------------------------
// Timezone
// ---------------------------------------------------------------------
date_default_timezone_set(getenv('PG_TIMEZONE') ?: 'Asia/Manila');

// ---------------------------------------------------------------------
// Error handling - technical detail goes to a log file, never to the browser
// ---------------------------------------------------------------------
if (!is_dir(APP_LOG_DIR)) {
    @mkdir(APP_LOG_DIR, 0775, true);
}
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
// On Railway log to stderr so output reaches the platform's log viewer.
ini_set('error_log', getenv('RAILWAY_ENVIRONMENT') ? 'php://stderr' : APP_LOG_DIR . DIRECTORY_SEPARATOR . 'error.log');
error_reporting(E_ALL);

// ---------------------------------------------------------------------
// Session cookie policy (must run before session_start)
// ---------------------------------------------------------------------
if (session_status() === PHP_SESSION_NONE) {
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? '') === '443')
        // Railway terminates TLS at its edge and forwards plain HTTP.
        || (strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https');

    session_name('POULTRYGUARD_SESSID');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => BASE_URL === '' ? '/' : BASE_URL,
        'httponly' => true,
        'secure'   => $secure,
        'samesite' => 'Lax',
    ]);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
}

require_once APP_ROOT . '/config/database.php';
