<?php
/**
 * PoultryGuard - Shared helper library.
 *
 * Validation, formatting, domain rules (thresholds, statuses), alerting,
 * activity logging and the consumption / FCR calculations used by the
 * reports module. Loaded by every page and API endpoint.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';

// =====================================================================
// Output escaping
// =====================================================================

/** Escape a value for safe echoing into HTML. */
function e(mixed $value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Build an absolute application URL from a root-relative path. */
function url(string $path = ''): string
{
    return BASE_URL . '/' . ltrim($path, '/');
}

/**
 * URL for a static asset, stamped with the file's modification time.
 *
 * .htaccess tells browsers to cache CSS and JS for a week, so without this a
 * theme change would keep showing the old stylesheet until the cache expired.
 * The stamp changes whenever the file does, and never otherwise.
 */
function asset_url(string $path): string
{
    $url  = url($path);
    $file = __DIR__ . '/../' . ltrim($path, '/');
    $time = @filemtime($file);

    return $time === false ? $url : $url . '?v=' . $time;
}

// =====================================================================
// Input validation
//
// Every endpoint runs untrusted input through these so a malformed or
// hostile request is rejected before it reaches the database.
// =====================================================================

/**
 * Validate a numeric value and require it to sit inside a range.
 *
 * @throws ValidationException
 */
function v_float(mixed $value, string $field, float $min, float $max, ?float $default = null): float
{
    if ($value === null || $value === '') {
        if ($default !== null) {
            return $default;
        }
        throw new ValidationException("{$field} is required.");
    }
    if (!is_numeric($value)) {
        throw new ValidationException("{$field} must be a number.");
    }
    $n = (float)$value;
    if (is_nan($n) || is_infinite($n)) {
        throw new ValidationException("{$field} is not a valid number.");
    }
    if ($n < $min || $n > $max) {
        throw new ValidationException(sprintf('%s must be between %s and %s.', $field, rtrim(rtrim(number_format($min, 2, '.', ''), '0'), '.'), rtrim(rtrim(number_format($max, 2, '.', ''), '0'), '.')));
    }
    return $n;
}

/** Validate a whole number inside a range. @throws ValidationException */
function v_int(mixed $value, string $field, int $min, int $max, ?int $default = null): int
{
    if ($value === null || $value === '') {
        if ($default !== null) {
            return $default;
        }
        throw new ValidationException("{$field} is required.");
    }
    if (!is_numeric($value)) {
        throw new ValidationException("{$field} must be a number.");
    }
    $f = (float)$value;
    if (floor($f) !== $f) {
        throw new ValidationException("{$field} must be a whole number.");
    }
    $n = (int)$f;
    if ($n < $min || $n > $max) {
        throw new ValidationException("{$field} must be between {$min} and {$max}.");
    }
    return $n;
}

/** Validate and trim a string. @throws ValidationException */
function v_string(mixed $value, string $field, int $min, int $max, ?string $default = null): string
{
    if ($value === null || (is_string($value) && trim($value) === '')) {
        if ($default !== null) {
            return $default;
        }
        if ($min === 0) {
            return '';
        }
        throw new ValidationException("{$field} is required.");
    }
    if (!is_scalar($value)) {
        throw new ValidationException("{$field} is invalid.");
    }
    $s = trim((string)$value);
    $len = mb_strlen($s);
    if ($len < $min || $len > $max) {
        throw new ValidationException("{$field} must be between {$min} and {$max} characters.");
    }
    return $s;
}

/** Validate that a value is one of an allowed set. @throws ValidationException */
function v_enum(mixed $value, string $field, array $allowed, ?string $default = null): string
{
    if ($value === null || $value === '') {
        if ($default !== null) {
            return $default;
        }
        throw new ValidationException("{$field} is required.");
    }
    $s = (string)$value;
    if (!in_array($s, $allowed, true)) {
        throw new ValidationException("{$field} is not a valid option.");
    }
    return $s;
}

/** Validate a Y-m-d date. @throws ValidationException */
function v_date(mixed $value, string $field, ?string $default = null): string
{
    if ($value === null || $value === '') {
        if ($default !== null) {
            return $default;
        }
        throw new ValidationException("{$field} is required.");
    }
    $d = DateTime::createFromFormat('Y-m-d', (string)$value);
    if (!$d || $d->format('Y-m-d') !== (string)$value) {
        throw new ValidationException("{$field} must be a valid date (YYYY-MM-DD).");
    }
    return (string)$value;
}

/** Validation failures are user-correctable, so they map to HTTP 422. */
class ValidationException extends RuntimeException
{
}

/** Permission failures map to HTTP 403. */
class AuthorizationException extends RuntimeException
{
}

// =====================================================================
// Formatting
// =====================================================================

/** Render grams as a human string, switching to kg above 1000 g. */
function format_weight(?float $grams): string
{
    if ($grams === null) {
        return '--';
    }
    if (abs($grams) >= 1000) {
        return number_format($grams / 1000, 2) . ' kg';
    }
    return number_format($grams, 0) . ' g';
}

/** Render a DB datetime in a readable form. */
function format_datetime(?string $ts): string
{
    if (!$ts) {
        return 'Never';
    }
    $t = strtotime($ts);
    return $t ? date('M j, Y g:i A', $t) : 'Never';
}

/** "3 minutes ago" style relative time. */
function time_ago(?string $ts): string
{
    if (!$ts) {
        return 'never';
    }
    $t = strtotime($ts);
    if (!$t) {
        return 'never';
    }
    $diff = time() - $t;
    if ($diff < 0) {
        return 'just now';
    }
    if ($diff < 60) {
        return $diff . ' second' . ($diff === 1 ? '' : 's') . ' ago';
    }
    $units = [3600 => 'minute', 86400 => 'hour', 2592000 => 'day', 31536000 => 'month'];
    $prev = 60;
    foreach ($units as $limit => $name) {
        if ($diff < $limit) {
            $n = (int)floor($diff / $prev);
            return $n . ' ' . $name . ($n === 1 ? '' : 's') . ' ago';
        }
        $prev = $limit;
    }
    $n = (int)floor($diff / 31536000);
    return $n . ' year' . ($n === 1 ? '' : 's') . ' ago';
}

// =====================================================================
// Devices and settings
// =====================================================================

/** Fetch one device row, or null. */
function get_device(int $deviceId): ?array
{
    $st = db()->prepare('SELECT * FROM devices WHERE device_id = ?');
    $st->execute([$deviceId]);
    $row = $st->fetch();
    return $row ?: null;
}

/** All devices, newest activity first. */
function get_devices(bool $activeOnly = false): array
{
    $sql = 'SELECT * FROM devices';
    if ($activeOnly) {
        $sql .= " WHERE status = 'active'";
    }
    $sql .= ' ORDER BY device_name ASC';
    return db()->query($sql)->fetchAll();
}

/**
 * Automation settings for a device. A row is created from the schema
 * defaults if one is somehow missing, so callers never get null.
 */
function get_settings(int $deviceId): array
{
    $st = db()->prepare('SELECT * FROM system_settings WHERE device_id = ?');
    $st->execute([$deviceId]);
    $row = $st->fetch();
    if ($row) {
        return $row;
    }
    db()->prepare('INSERT INTO system_settings (device_id) VALUES (?)')->execute([$deviceId]);
    $st->execute([$deviceId]);
    return $st->fetch() ?: [];
}

/**
 * Is the device considered online? A device is online when it has
 * contacted the API within its configured offline timeout.
 */
function device_is_online(?string $lastSeen, int $timeoutSeconds = DEFAULT_OFFLINE_TIMEOUT): bool
{
    if (!$lastSeen) {
        return false;
    }
    $t = strtotime($lastSeen);
    return $t !== false && (time() - $t) <= $timeoutSeconds;
}

// =====================================================================
// Threshold / status rules
// =====================================================================

/**
 * Classify a level percentage against the configured alert thresholds.
 *
 * @return string one of NORMAL | LOW | CRITICAL
 */
function level_status(float $percentage, float $lowPercent, float $criticalPercent): string
{
    if ($percentage < $criticalPercent) {
        return 'CRITICAL';
    }
    if ($percentage < $lowPercent) {
        return 'LOW';
    }
    return 'NORMAL';
}

/** Bootstrap contextual colour for a status label. */
function status_color(string $status): string
{
    return match (strtoupper($status)) {
        'CRITICAL' => 'danger',
        'LOW', 'WARNING' => 'warning',
        'NORMAL', 'OK', 'ONLINE' => 'success',
        'OFFLINE' => 'secondary',
        default => 'info',
    };
}

/**
 * Convert a sensor-to-surface distance into water depth (the level strip's
 * percentage is converted to a distance first, see distance_from_percentage()).
 *
 * The sensor points down from above the tank. `sensor_offset_cm` is the gap
 * between the sensor face and the full-water line; `tank_depth` is the usable
 * depth of water below that line.
 *
 * @return array{level: float, percentage: float}
 */
function water_from_distance(float $distanceCm, float $tankDepthCm, float $sensorOffsetCm): array
{
    $level = $tankDepthCm - ($distanceCm - $sensorOffsetCm);
    $level = max(0.0, min($tankDepthCm, $level));
    $pct   = $tankDepthCm > 0 ? round($level / $tankDepthCm * 100, 2) : 0.0;
    return ['level' => round($level, 2), 'percentage' => $pct];
}

// =====================================================================
// Alerts
// =====================================================================

/**
 * Record an alert.
 *
 * Devices report every 30 seconds, so the same condition would otherwise
 * generate hundreds of duplicate rows. `$cooldownSeconds` suppresses a repeat
 * of the same alert type for the same device inside the cooldown window.
 *
 * @return int|null the new alert id, or null when suppressed as a duplicate.
 */
function create_alert(
    int $deviceId,
    string $type,
    string $severity,
    string $message,
    int $cooldownSeconds = 1800
): ?int {
    $pdo = db();

    if ($cooldownSeconds > 0) {
        $dup = $pdo->prepare(
            'SELECT alert_id FROM alerts
             WHERE device_id = ? AND alert_type = ?
               AND created_at > DATE_SUB(NOW(), INTERVAL ? SECOND)
             LIMIT 1'
        );
        $dup->execute([$deviceId, $type, $cooldownSeconds]);
        if ($dup->fetchColumn() !== false) {
            return null;
        }
    }

    $st = $pdo->prepare(
        'INSERT INTO alerts (device_id, alert_type, severity, message)
         VALUES (?, ?, ?, ?)'
    );
    $st->execute([$deviceId, $type, $severity, mb_substr($message, 0, 255)]);
    return (int)$pdo->lastInsertId();
}

/** Count of unread alerts, optionally for one device. */
function unread_alert_count(?int $deviceId = null): int
{
    if ($deviceId !== null) {
        $st = db()->prepare("SELECT COUNT(*) FROM alerts WHERE status = 'unread' AND device_id = ?");
        $st->execute([$deviceId]);
    } else {
        $st = db()->query("SELECT COUNT(*) FROM alerts WHERE status = 'unread'");
    }
    return (int)$st->fetchColumn();
}

/**
 * Evaluate a fresh sensor sample against the alert thresholds and raise
 * alerts where needed. Called whenever the ESP32 uploads data.
 */
function evaluate_alerts(int $deviceId, array $settings, float $waterPct): void
{
    $waterStatus = level_status($waterPct, (float)$settings['water_low_percent'], (float)$settings['water_critical_percent']);
    if ($waterStatus === 'CRITICAL') {
        create_alert($deviceId, 'critical_water', 'CRITICAL',
            sprintf('Water level at %.1f%% — critical. Check the supply line.', $waterPct));
    } elseif ($waterStatus === 'LOW') {
        create_alert($deviceId, 'low_water', 'WARNING',
            sprintf('Water level dropped to %.1f%% — below the %.0f%% low threshold.', $waterPct, (float)$settings['water_low_percent']));
    }
}

// =====================================================================
// Activity logging
// =====================================================================

/**
 * Append to activity_logs. `$userId` is null for actions taken by a device
 * rather than a person.
 */
function log_activity(string $action, string $description, ?int $userId = null, ?string $ip = null): void
{
    try {
        $userId ??= ($_SESSION['user_id'] ?? null);
        $ip     ??= client_ip();
        $st = db()->prepare(
            'INSERT INTO activity_logs (user_id, action, description, ip_address)
             VALUES (?, ?, ?, ?)'
        );
        $st->execute([
            $userId !== null ? (int)$userId : null,
            mb_substr($action, 0, 60),
            mb_substr($description, 0, 255),
            $ip,
        ]);
    } catch (Throwable $e) {
        // Logging must never break the operation it is recording.
        error_log('[PoultryGuard] Activity log failed: ' . $e->getMessage());
    }
}

/** Best-effort client IP for logging. */
function client_ip(): string
{
    // Behind Railway's proxy REMOTE_ADDR is the proxy; it sets X-Real-IP.
    $ip = getenv('RAILWAY_ENVIRONMENT') ? ($_SERVER['HTTP_X_REAL_IP'] ?? null) : null;
    if (!is_string($ip) || filter_var($ip, FILTER_VALIDATE_IP) === false) {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'cli';
    }
    return substr((string)$ip, 0, 45);
}

// =====================================================================
// Readings
// =====================================================================

/** Most recent water reading for a device, or null. */
function latest_water_reading(int $deviceId): ?array
{
    $st = db()->prepare(
        'SELECT * FROM water_readings WHERE device_id = ? ORDER BY timestamp DESC, reading_id DESC LIMIT 1'
    );
    $st->execute([$deviceId]);
    return $st->fetch() ?: null;
}

/**
 * Down-sampled history for the trend charts.
 *
 * Pulling 2,880 rows for a 24 hour window would make the chart unreadable
 * and the payload large, so rows are averaged into fixed-width buckets.
 *
 */
function reading_history(int $deviceId, int $hours, int $bucketMinutes = 30): array
{
    $table = 'water_readings';
    $value = 'water_level';
    $pct   = 'water_percentage';

    // Bucket by flooring the timestamp to a multiple of $bucketMinutes.
    // The bucket width appears twice in the expression, and a native (non-
    // emulated) prepared statement cannot reuse one named placeholder, so it
    // is bound under two names.
    $bucketSeconds = $bucketMinutes * 60;

    $sql = "SELECT
                FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(`timestamp`) / :bucket_a) * :bucket_b) AS bucket_time,
                ROUND(AVG(`{$value}`), 2) AS value,
                ROUND(AVG(`{$pct}`), 2)   AS percentage
            FROM `{$table}`
            WHERE device_id = :device
              AND `timestamp` >= DATE_SUB(NOW(), INTERVAL :hours HOUR)
            GROUP BY bucket_time
            ORDER BY bucket_time ASC";

    $st = db()->prepare($sql);
    $st->bindValue(':bucket_a', $bucketSeconds, PDO::PARAM_INT);
    $st->bindValue(':bucket_b', $bucketSeconds, PDO::PARAM_INT);
    $st->bindValue(':device', $deviceId, PDO::PARAM_INT);
    $st->bindValue(':hours', $hours, PDO::PARAM_INT);
    $st->execute();

    return array_map(static fn(array $r): array => [
        'time'       => $r['bucket_time'],
        'value'      => (float)$r['value'],
        'percentage' => (float)$r['percentage'],
    ], $st->fetchAll());
}

/**
 * Down-sampled DHT11 history (average temperature / humidity per bucket).
 */
function climate_history(int $deviceId, int $hours, int $bucketMinutes = 30): array
{
    $bucketSeconds = $bucketMinutes * 60;
    $st = db()->prepare(
        'SELECT FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(`timestamp`) / :bucket_a) * :bucket_b) AS bucket_time,
                ROUND(AVG(temperature_c), 1) AS temperature,
                ROUND(AVG(humidity_pct), 0)  AS humidity
         FROM climate_readings
         WHERE device_id = :device AND `timestamp` >= DATE_SUB(NOW(), INTERVAL :hours HOUR)
         GROUP BY bucket_time ORDER BY bucket_time ASC'
    );
    $st->bindValue(':bucket_a', $bucketSeconds, PDO::PARAM_INT);
    $st->bindValue(':bucket_b', $bucketSeconds, PDO::PARAM_INT);
    $st->bindValue(':device', $deviceId, PDO::PARAM_INT);
    $st->bindValue(':hours', $hours, PDO::PARAM_INT);
    $st->execute();
    return array_map(static fn(array $r): array => [
        'time'        => $r['bucket_time'],
        'temperature' => (float)$r['temperature'],
        'humidity'    => (float)$r['humidity'],
    ], $st->fetchAll());
}

// =====================================================================
// Consumption analytics
//
// Consumption is a mass balance, not simply "what was dispensed":
//
//     consumed = (level at start of period - level at end of period)
//              + (everything added during the period)
//
// That is what the birds actually ate or drank.
// =====================================================================

/**
 * Resolve the date filters every report accepts into a concrete range.
 *
 * `to` is returned as the start of the following day so that a BETWEEN-style
 * `>= from AND < to` comparison includes everything recorded on the end date,
 * which a naive "23:59:59" bound would miss for sub-second timestamps.
 *
 * @return array{from:string, to:string, from_date:string, to_date:string, period:string, label:string}
 */
function resolve_range(array $in, int $defaultDays = 7): array
{
    $period = v_enum($in['period'] ?? 'daily', 'Period', ['daily', 'weekly', 'monthly'], 'daily');

    $toDate   = v_date($in['to'] ?? null, 'To date', date('Y-m-d'));
    $fromDate = v_date($in['from'] ?? null, 'From date', date('Y-m-d', strtotime("-{$defaultDays} days", strtotime($toDate))));

    if (strtotime($fromDate) > strtotime($toDate)) {
        throw new ValidationException('The start date cannot be after the end date.');
    }
    // A year of daily buckets is already a very large report.
    if ((strtotime($toDate) - strtotime($fromDate)) > 366 * 86400) {
        throw new ValidationException('The reporting range cannot be longer than one year.');
    }

    return [
        'from'      => $fromDate . ' 00:00:00',
        'to'        => date('Y-m-d 00:00:00', strtotime($toDate . ' +1 day')),
        'from_date' => $fromDate,
        'to_date'   => $toDate,
        'period'    => $period,
        'label'     => date('M j, Y', strtotime($fromDate)) . ' – ' . date('M j, Y', strtotime($toDate)),
    ];
}

/** Whitelisted SQL expressions for period grouping. */
function period_expression(string $period): string
{
    return match ($period) {
        'weekly'  => 'DATE(DATE_SUB(`timestamp`, INTERVAL WEEKDAY(`timestamp`) DAY))',
        'monthly' => "DATE_FORMAT(`timestamp`, '%Y-%m-01')",
        default   => 'DATE(`timestamp`)',
    };
}

/**
 * First and last reading value in each period.
 *
 * @return array<string, array{first: float, last: float}>
 */
function period_boundaries(string $table, string $column, int $deviceId, string $from, string $to, string $period): array
{
    $expr = period_expression($period);
    $sql = "SELECT period,
                   MAX(CASE WHEN rn_first = 1 THEN val END) AS first_val,
                   MAX(CASE WHEN rn_last  = 1 THEN val END) AS last_val
            FROM (
                SELECT {$expr} AS period,
                       `{$column}` AS val,
                       ROW_NUMBER() OVER (PARTITION BY {$expr} ORDER BY `timestamp` ASC,  reading_id ASC)  AS rn_first,
                       ROW_NUMBER() OVER (PARTITION BY {$expr} ORDER BY `timestamp` DESC, reading_id DESC) AS rn_last
                FROM `{$table}`
                WHERE device_id = :device
                  AND `timestamp` >= :from AND `timestamp` < :to
            ) t
            GROUP BY period
            ORDER BY period ASC";

    $st = db()->prepare($sql);
    $st->execute([':device' => $deviceId, ':from' => $from, ':to' => $to]);

    $out = [];
    foreach ($st->fetchAll() as $r) {
        $out[(string)$r['period']] = [
            'first' => (float)$r['first_val'],
            'last'  => (float)$r['last_val'],
        ];
    }
    return $out;
}

/**
 * Total added per period from the dispensing log.
 *
 * @return array<string, array{amount: float, events: int}>
 */
function period_dispensed(string $table, string $amountExpr, int $deviceId, string $from, string $to, string $period): array
{
    $expr = period_expression($period);
    $sql = "SELECT {$expr} AS period,
                   COALESCE(SUM({$amountExpr}), 0) AS amount,
                   COUNT(*) AS events
            FROM `{$table}`
            WHERE device_id = :device
              AND status = 'completed'
              AND `timestamp` >= :from AND `timestamp` < :to
            GROUP BY period";

    $st = db()->prepare($sql);
    $st->execute([':device' => $deviceId, ':from' => $from, ':to' => $to]);

    $out = [];
    foreach ($st->fetchAll() as $r) {
        $out[(string)$r['period']] = [
            'amount' => (float)$r['amount'],
            'events' => (int)$r['events'],
        ];
    }
    return $out;
}

/**
 * Feeder activity per period: how many runs and how many seconds the motor ran.
 *
 * @return list<array{period:string, runs:int, seconds:float}>
 */
function feed_runs(int $deviceId, string $from, string $to, string $period = 'daily'): array
{
    $expr = period_expression($period);
    $st = db()->prepare(
        "SELECT {$expr} AS period, COUNT(*) AS runs, COALESCE(SUM(duration_ms), 0) / 1000 AS seconds
         FROM feed_dispensing
         WHERE device_id = :device AND status = 'completed'
           AND `timestamp` >= :from AND `timestamp` < :to
         GROUP BY period ORDER BY period ASC"
    );
    $st->execute([':device' => $deviceId, ':from' => $from, ':to' => $to]);
    return array_map(static fn(array $r): array => [
        'period'  => (string)$r['period'],
        'runs'    => (int)$r['runs'],
        'seconds' => round((float)$r['seconds'], 1),
    ], $st->fetchAll());
}

/**
 * Water consumption per period, in centimetres of level and in litres.
 *
 * @return list<array{period:string, consumed_cm:float, consumed_liters:float, refilled_cm:float, events:int}>
 */
function water_consumption(int $deviceId, string $from, string $to, string $period = 'daily'): array
{
    $settings   = get_settings($deviceId);
    $depthCm    = max(0.01, (float)$settings['water_capacity']);
    $litersFull = (float)$settings['water_capacity_liters'];

    $bounds   = period_boundaries('water_readings', 'water_level', $deviceId, $from, $to, $period);
    $refilled = period_dispensed(
        'water_dispensing',
        'GREATEST(ending_level - starting_level, 0)',
        $deviceId, $from, $to, $period
    );

    $periods = array_unique(array_merge(array_keys($bounds), array_keys($refilled)));
    sort($periods);

    $rows = [];
    foreach ($periods as $p) {
        $drop  = isset($bounds[$p]) ? $bounds[$p]['first'] - $bounds[$p]['last'] : 0.0;
        $added = $refilled[$p]['amount'] ?? 0.0;
        $cm    = max(0.0, $drop + $added);
        $rows[] = [
            'period'          => $p,
            'consumed_cm'     => round($cm, 2),
            'consumed_liters' => round($cm / $depthCm * $litersFull, 2),
            'refilled_cm'     => round($added, 2),
            'events'          => $refilled[$p]['events'] ?? 0,
        ];
    }
    return $rows;
}

// =====================================================================
// Feeding schedule
// =====================================================================

/** Maximum number of feeding times per device. */
const FEED_SCHEDULE_MAX = 8;

/**
 * Validate a feeding schedule from user input.
 *
 * Each entry is {"time": "HH:MM", "seconds": n}. Times must be unique and the
 * run time must not exceed the device's maximum feeder run time. The result is
 * sorted by time and normalised, ready to store as JSON.
 *
 * @return list<array{time:string, seconds:int}>
 * @throws ValidationException
 */
function parse_feed_schedule(mixed $input, int $maxSeconds): array
{
    if (is_string($input) && $input !== '') {
        $input = json_decode($input, true);
    }
    if ($input === null || $input === '' || $input === []) {
        return [];
    }
    if (!is_array($input)) {
        throw new ValidationException('The feeding schedule is not valid.');
    }
    if (count($input) > FEED_SCHEDULE_MAX) {
        throw new ValidationException('A schedule can hold at most ' . FEED_SCHEDULE_MAX . ' feeding times.');
    }

    $out = [];
    foreach ($input as $i => $row) {
        $n = (int)$i + 1;
        if (!is_array($row)) {
            throw new ValidationException("Feeding time #{$n} is not valid.");
        }
        $time = trim((string)($row['time'] ?? ''));
        if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $time)) {
            throw new ValidationException("Feeding time #{$n} must be a time like 06:30.");
        }
        $sec = $row['seconds'] ?? null;
        if (!is_numeric($sec) || (int)$sec != $sec || (int)$sec < 1 || (int)$sec > $maxSeconds) {
            throw new ValidationException("Feeding time {$time}: run time must be a whole number from 1 to {$maxSeconds} seconds.");
        }
        if (isset($out[$time])) {
            throw new ValidationException("The time {$time} is listed twice.");
        }
        $out[$time] = ['time' => $time, 'seconds' => (int)$sec];
    }
    ksort($out);
    return array_values($out);
}

/** Decode the stored schedule JSON; never throws. */
function feed_schedule_decode(?string $json): array
{
    if ($json === null || $json === '') {
        return [];
    }
    $rows = json_decode($json, true);
    if (!is_array($rows)) {
        return [];
    }
    return array_values(array_filter(array_map(static function ($r) {
        return is_array($r) && isset($r['time'], $r['seconds'])
            ? ['time' => (string)$r['time'], 'seconds' => (int)$r['seconds']] : null;
    }, $rows)));
}
