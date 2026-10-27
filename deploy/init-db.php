<?php
/**
 * First-boot database setup for Railway.
 *
 * Imports database/poultryguard.sql into the database Railway already created
 * (the DROP/CREATE DATABASE/USE statements are removed) but only when the
 * schema is not there yet, so redeploys never touch live data.
 *
 *   PG_ADMIN_PASSWORD  password for the initial owner account (if unset, a
 *                      random one is generated and printed to the deploy log)
 *   PG_ADMIN_USERNAME  owner username (default "owner")
 *   PG_LOAD_DEMO_DATA  set to 1 to load the sample accounts/devices/history
 */
declare(strict_types=1);

require __DIR__ . '/../config/database.php';

$pdo = null;
for ($i = 0; $i < 20 && $pdo === null; $i++) {
    try {
        $pdo = db();
    } catch (Throwable $e) {
        fwrite(STDERR, "[init-db] waiting for database...\n");
        sleep(3);
    }
}
if ($pdo === null) {
    fwrite(STDERR, "[init-db] database unreachable\n");
    exit(1);
}

if ($pdo->query("SHOW TABLES LIKE 'users'")->fetch()) {
    // Additive migration for installs created before DHT11 support.
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `climate_readings` (
            `reading_id`    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `device_id`     INT UNSIGNED  NOT NULL,
            `temperature_c` DECIMAL(5,2)  NOT NULL,
            `humidity_pct`  DECIMAL(5,2)  NOT NULL,
            `heat_level`    ENUM('normal','warning','danger') NOT NULL DEFAULT 'normal',
            `source`        ENUM('live','offline_sync') NOT NULL DEFAULT 'live',
            `timestamp`     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`reading_id`),
            KEY `idx_climate_device_time` (`device_id`, `timestamp`),
            CONSTRAINT `fk_climate_device` FOREIGN KEY (`device_id`)
                REFERENCES `devices` (`device_id`) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    // Scheduled feeding (added after the first release).
    if (!$pdo->query("SHOW COLUMNS FROM `system_settings` LIKE 'feed_schedule'")->fetch()) {
        $pdo->exec("ALTER TABLE `system_settings` ADD COLUMN `feed_schedule` TEXT NULL DEFAULT NULL AFTER `auto_feed`");
    }
    // Timed feeder (replaced the weight-based hopper): databases created from the original
    // schema still require target_weight on every feed record, which the firmware never
    // sends, so each record was rejected with HTTP 500. Give the legacy columns defaults.
    foreach (['target_weight', 'start_weight'] as $legacy) {
        if ($pdo->query("SHOW COLUMNS FROM `feed_dispensing` LIKE '{$legacy}'")->fetch()) {
            $pdo->exec("ALTER TABLE `feed_dispensing` MODIFY `{$legacy}` DECIMAL(10,2) NOT NULL DEFAULT 0.00");
        }
    }
    echo "[init-db] schema present, climate table and feed schedule ensured\n";
    exit(0);
}

/** Split on ; outside quotes, dropping -- comments. */
function split_sql(string $sql): array
{
    $out = [];
    $buf = '';
    $q = null;
    $n = strlen($sql);
    for ($i = 0; $i < $n; $i++) {
        $c = $sql[$i];
        if ($q !== null) {
            $buf .= $c;
            if ($c === '\\' && $i + 1 < $n) {
                $buf .= $sql[++$i];
            } elseif ($c === $q) {
                $q = null;
            }
            continue;
        }
        if ($c === "'" || $c === '"' || $c === '`') {
            $q = $c;
            $buf .= $c;
            continue;
        }
        if ($c === '-' && ($sql[$i + 1] ?? '') === '-') {
            while ($i < $n && $sql[$i] !== "\n") {
                $i++;
            }
            continue;
        }
        if ($c === ';') {
            if (trim($buf) !== '') {
                $out[] = trim($buf);
            }
            $buf = '';
            continue;
        }
        $buf .= $c;
    }
    if (trim($buf) !== '') {
        $out[] = trim($buf);
    }
    return $out;
}

$marker = '-- --- Default accounts';
$sql    = (string)file_get_contents(__DIR__ . '/../database/poultryguard.sql');
$pos    = strpos($sql, $marker);
$schema = $pos === false ? $sql : substr($sql, 0, $pos);
$demo   = $pos === false ? '' : substr($sql, $pos);

$run = static function (string $chunk) use ($pdo): void {
    foreach (split_sql($chunk) as $stmt) {
        // Railway supplies the database; never drop/create/switch it.
        if (preg_match('/^(DROP\s+DATABASE|CREATE\s+DATABASE|USE)\b/i', $stmt)) {
            continue;
        }
        $pdo->exec($stmt);
    }
};

$run($schema);
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

if (getenv('PG_LOAD_DEMO_DATA') === '1') {
    $run($demo);
    echo "[init-db] schema + demo data loaded (change the demo passwords!)\n";
    exit(0);
}

$pass = getenv('PG_ADMIN_PASSWORD') ?: '';
if ($pass === '') {
    $pass = bin2hex(random_bytes(9));
    echo "[init-db] PG_ADMIN_PASSWORD not set; generated owner password: {$pass}\n";
}
$pdo->prepare("INSERT INTO users (name, username, email, password, role, status) VALUES (?, ?, NULL, ?, 'owner', 'active')")
    ->execute(['Farm Administrator', getenv('PG_ADMIN_USERNAME') ?: 'owner', password_hash($pass, PASSWORD_DEFAULT)]);
echo "[init-db] schema created and owner account created. Add devices from the Devices page.\n";
