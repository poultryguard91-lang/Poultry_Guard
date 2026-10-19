<?php
/**
 * PoultryGuard - REST API bootstrap.
 *
 * Every endpoint under /api is a thin wrapper around api_run(), which gives
 * the whole API one consistent JSON envelope, one place where exceptions are
 * translated into HTTP status codes, and one guarantee that a raw PHP or
 * MySQL error can never reach the client.
 *
 * Envelope:
 *   { "success": bool, "message": string, "data": mixed }
 */

declare(strict_types=1);

require_once __DIR__ . '/auth.php';

/** Requested record does not exist -> HTTP 404. */
class NotFoundException extends RuntimeException
{
}

/** A successful endpoint result. */
final class ApiResult
{
    public function __construct(
        public array $data = [],
        public string $message = 'OK',
        public int $status = 200
    ) {
    }
}

/** Build a success result. */
function api_ok(array $data = [], string $message = 'OK', int $status = 200): ApiResult
{
    return new ApiResult($data, $message, $status);
}

/**
 * Emit the JSON envelope and stop.
 */
function api_send(bool $success, string $message, mixed $data = null, int $status = 200): never
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('X-Content-Type-Options: nosniff');
    }
    echo json_encode(
        ['success' => $success, 'message' => $message, 'data' => $data],
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
    );
    exit;
}

/**
 * Read and merge request input: JSON body, form body and query string.
 * JSON wins over form fields, which win over the query string.
 */
function api_input(): array
{
    static $input = null;
    if ($input !== null) {
        return $input;
    }

    $input = $_GET;

    if (!empty($_POST)) {
        $input = array_merge($input, $_POST);
    }

    $type = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
    if (stripos($type, 'application/json') !== false) {
        $raw = file_get_contents('php://input');
        if ($raw !== false && $raw !== '') {
            // Cap the payload so a malformed or hostile body cannot exhaust memory.
            if (strlen($raw) > 262144) {
                api_send(false, 'Request body is too large.', null, 413);
            }
            try {
                $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                api_send(false, 'Request body is not valid JSON.', null, 400);
            }
            if (is_array($decoded)) {
                $input = array_merge($input, $decoded);
            }
        }
    }

    return $input;
}

/** Reject any HTTP method the endpoint does not implement. */
function api_require_method(string ...$methods): void
{
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    $allowed = array_map('strtoupper', $methods);
    if (!in_array($method, $allowed, true)) {
        header('Allow: ' . implode(', ', $allowed));
        api_send(false, 'This endpoint does not accept ' . $method . ' requests.', null, 405);
    }
}

/**
 * Run an endpoint handler, converting any exception into the right status
 * code and a message that is safe to show a farm worker.
 *
 * @param callable(array):(ApiResult|array|null) $handler
 */
function api_run(callable $handler): never
{
    try {
        $result = $handler(api_input());

        if ($result instanceof ApiResult) {
            api_send(true, $result->message, $result->data, $result->status);
        }
        api_send(true, 'OK', $result ?? []);
    } catch (ValidationException $e) {
        api_send(false, $e->getMessage(), null, 422);
    } catch (AuthenticationException $e) {
        api_send(false, $e->getMessage(), null, 401);
    } catch (AuthorizationException $e) {
        api_send(false, $e->getMessage(), null, 403);
    } catch (NotFoundException $e) {
        api_send(false, $e->getMessage(), null, 404);
    } catch (DatabaseUnavailableException) {
        api_send(false, 'Database connection failed. Please try again shortly.', null, 503);
    } catch (PDOException $e) {
        error_log('[PoultryGuard API] SQL error: ' . $e->getMessage());
        api_send(false, 'The system could not complete that database operation.', null, 500);
    } catch (Throwable $e) {
        error_log('[PoultryGuard API] ' . $e::class . ': ' . $e->getMessage()
            . ' @ ' . $e->getFile() . ':' . $e->getLine());
        api_send(false, 'An unexpected system error occurred. The issue has been logged.', null, 500);
    }
}

// =====================================================================
// Device (ESP32) authentication
// =====================================================================

/**
 * Authenticate a firmware request by its device API key.
 *
 * The key travels in the X-API-Key header. Lookup is by esp32_uid, then the
 * stored key is compared in constant time, so a wrong key cannot be probed
 * byte by byte.
 *
 * @throws AuthenticationException|NotFoundException
 */
function api_device_auth(array $input): array
{
    $key = $_SERVER['HTTP_X_API_KEY'] ?? ($input['api_key'] ?? null);
    if (!is_string($key) || $key === '') {
        throw new AuthenticationException('Missing device API key.');
    }

    $uid = $input['esp32_uid'] ?? $input['device_uid'] ?? null;

    if (is_string($uid) && $uid !== '') {
        $st = db()->prepare('SELECT * FROM devices WHERE esp32_uid = ? LIMIT 1');
        $st->execute([$uid]);
    } else {
        // Firmware may identify itself by key alone.
        $st = db()->prepare('SELECT * FROM devices WHERE api_key = ? LIMIT 1');
        $st->execute([$key]);
    }
    $device = $st->fetch();

    if (!$device || !hash_equals((string)$device['api_key'], $key)) {
        throw new AuthenticationException('Invalid device credentials.');
    }
    if ($device['status'] !== 'active') {
        throw new AuthorizationException('This device has been disabled.');
    }

    return $device;
}

/**
 * Resolve the device a browser request is asking about, defaulting to the
 * first active device so the dashboard works out of the box.
 *
 * @throws NotFoundException
 */
function api_resolve_device(array $input, string $field = 'device_id'): array
{
    $raw = $input[$field] ?? null;

    if ($raw === null || $raw === '') {
        $row = db()->query(
            "SELECT * FROM devices WHERE status = 'active' ORDER BY device_id ASC LIMIT 1"
        )->fetch();
        if (!$row) {
            throw new NotFoundException('No active device has been registered yet.');
        }
        return $row;
    }

    $id = v_int($raw, 'Device', 1, PHP_INT_MAX);
    $device = get_device($id);
    if (!$device) {
        throw new NotFoundException('That device could not be found.');
    }
    return $device;
}

/** Refresh a device's heartbeat columns after a successful firmware call. */
function touch_device(int $deviceId, array $extra = []): void
{
    $columns = ['last_seen = NOW()'];
    $params  = [];

    $allowed = [
        'firmware_version'    => PDO::PARAM_STR,
        'ip_address'          => PDO::PARAM_STR,
        'wifi_rssi'           => PDO::PARAM_INT,
        'water_sensor_status' => PDO::PARAM_STR,
        'motor_status'        => PDO::PARAM_STR,
        'solenoid_status'     => PDO::PARAM_STR,
    ];

    foreach ($allowed as $col => $_type) {
        if (array_key_exists($col, $extra) && $extra[$col] !== null) {
            $columns[] = "`{$col}` = ?";
            $params[]  = $extra[$col];
        }
    }

    $params[] = $deviceId;
    $sql = 'UPDATE devices SET ' . implode(', ', $columns) . ' WHERE device_id = ?';
    db()->prepare($sql)->execute($params);
}
