<?php
/**
 * PoultryGuard - MySQL/MariaDB connection.
 *
 * A single lazily-created PDO handle is shared for the whole request. The
 * credentials live here on the server only; they are never emitted to the
 * browser, to JavaScript or to the ESP32 firmware, which reaches the database
 * exclusively through the PHP REST API.
 */

declare(strict_types=1);

// Resolution order: PG_DB_* variables, then the variables Railway's MySQL
// service provides (MYSQL_URL / MYSQLHOST ...), then XAMPP defaults.
$__url = parse_url((string)(getenv('MYSQL_URL') ?: ''));
if (!is_array($__url)) {
    $__url = [];
}
$__env = static function (array $names, string $fallback): string {
    foreach ($names as $n) {
        $v = getenv($n);
        if ($v !== false && $v !== '') {
            return $v;
        }
    }
    return $fallback;
};

define('DB_HOST', $__env(['PG_DB_HOST', 'MYSQLHOST'], ($__url['host'] ?? '') ?: '127.0.0.1'));
define('DB_PORT', $__env(['PG_DB_PORT', 'MYSQLPORT'], (string)(($__url['port'] ?? 0) ?: 3306)));
define('DB_NAME', $__env(['PG_DB_NAME', 'MYSQLDATABASE'], (ltrim($__url['path'] ?? '', '/') ?: 'poultryguard')));
define('DB_USER', $__env(['PG_DB_USER', 'MYSQLUSER'], (urldecode($__url['user'] ?? '') ?: 'root')));
define('DB_PASS', $__env(['PG_DB_PASS', 'MYSQLPASSWORD'], urldecode($__url['pass'] ?? '')));

/**
 * Raised when the database is unreachable, so callers can show a friendly
 * message instead of leaking a driver-level error string.
 */
class DatabaseUnavailableException extends RuntimeException
{
}

final class Database
{
    private static ?PDO $pdo = null;

    /**
     * Return the shared PDO connection, connecting on first use.
     *
     * @throws DatabaseUnavailableException when the server cannot be reached.
     */
    public static function connection(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            DB_HOST,
            DB_PORT,
            DB_NAME
        );

        try {
            self::$pdo = new PDO($dsn, DB_USER, DB_PASS, [
                // Real exceptions instead of silent false returns.
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                // Genuine server-side prepared statements: placeholders are never
                // interpolated into the SQL string, which is what makes the whole
                // application immune to SQL injection through bound parameters.
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_STRINGIFY_FETCHES  => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET SESSION sql_mode='STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION', time_zone='" . date('P') . "'",
            ]);
        } catch (PDOException $e) {
            error_log('[PoultryGuard] Database connection failed: ' . $e->getMessage());
            throw new DatabaseUnavailableException('Database connection failed.', 0, $e);
        }

        return self::$pdo;
    }
}

/**
 * Convenience accessor used throughout the application.
 */
function db(): PDO
{
    return Database::connection();
}
