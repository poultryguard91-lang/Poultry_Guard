<?php
/**
 * PoultryGuard - ESP32 device simulator
 * =====================================
 *
 * Speaks exactly the same REST protocol as the real firmware, so you can run
 * and demonstrate the whole system - live dashboard, automatic dispensing,
 * manual commands, alerts, offline sync - with no hardware attached.
 *
 * It mirrors PoultryGuard_ESP32.ino: the same thresholds, the same safety
 * timeouts, the same decision to keep feeding while offline and buffer the
 * records. If a change works here, it will work on the device.
 *
 * Usage (from a terminal, with Apache and MySQL running):
 *
 *   php tools/device_simulator.php
 *   php tools/device_simulator.php --device=2 --speed=60
 *   php tools/device_simulator.php --offline=120
 *   php tools/device_simulator.php --once
 *
 * Options:
 *   --url=...       Base URL of the installation  (default http://localhost/PoultryGuard)
 *   --uid=...       ESP32 UID to report as        (default the first demo device)
 *   --key=...       Device API key                (default the demo key)
 *   --device=1|2    Shortcut for the two demo devices
 *   --speed=N       Simulated seconds per real second (default 60, so a day
 *                   of consumption plays out in 24 minutes)
 *   --interval=N    Real seconds between uploads  (default 3)
 *   --offline=N     Spend N cycles offline after startup, then reconnect and
 *                   sync - the offline-automation demonstration
 *   --once          Send a single reading and exit
 *   --quiet         Less output
 *
 * Stop it with Ctrl+C. The relays are simulated; nothing physical moves.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This simulator can only be run from the command line.\n");
}

// ---------------------------------------------------------------------
// Options
// ---------------------------------------------------------------------
$opts = getopt('', [
    'url::', 'uid::', 'key::', 'device::', 'speed::',
    'interval::', 'offline::', 'once', 'quiet', 'help',
]);

if (isset($opts['help'])) {
    // The docblock above is the help text.
    $doc = file_get_contents(__FILE__);
    echo substr($doc, strpos($doc, '/**'), strpos($doc, '*/') - strpos($doc, '/**') + 2), "\n";
    exit(0);
}

$DEMO = [
    1 => ['uid' => 'ESP32-A1B2C3D4E5F6', 'key' => '97161c5b9d4ab435f52f8a8a9b48af25e8c09751'],
    2 => ['uid' => 'ESP32-F6E5D4C3B2A1', 'key' => '81a1ca36a1e6116bf35d0a077c9baeb454dd5e80'],
];
$which = (int)($opts['device'] ?? 1);
$which = isset($DEMO[$which]) ? $which : 1;

$BASE     = rtrim((string)($opts['url'] ?? 'http://localhost/PoultryGuard'), '/');
$UID      = (string)($opts['uid'] ?? $DEMO[$which]['uid']);
$KEY      = (string)($opts['key'] ?? $DEMO[$which]['key']);
$SPEED    = max(1.0, (float)($opts['speed'] ?? 60));
$INTERVAL = max(1, (int)($opts['interval'] ?? 3));
$OFFLINE_CYCLES = (int)($opts['offline'] ?? 0);
$ONCE     = isset($opts['once']);
$QUIET    = isset($opts['quiet']);

// ---------------------------------------------------------------------
// Simulated hardware state
// ---------------------------------------------------------------------
$state = [
    // Physical quantities
    'feed_weight'  => 7250.0,   // grams in the hopper
    'water_level'  => 78.0,     // cm of depth

    // Consumption rates, in units per simulated hour
    'feed_rate'    => 270.0,    // g/h  (~6.5 kg/day, about 65 broilers)
    'water_rate'   => 1.7,      // cm/h

    // Actuator rates while running
    'auger_rate'   => 120.0,    // g/s
    'valve_rate'   => 0.42,     // cm/s

    'motor'    => 'idle',
    'solenoid' => 'idle',
];

$settings = null;          // filled from the server, cached like NVS
$buffer   = [];            // offline buffer, like LittleFS
$job      = null;          // the running dispensing cycle, if any
$online   = true;
$cycle    = 0;

// ---------------------------------------------------------------------
// Output helpers
// ---------------------------------------------------------------------
function say(string $tag, string $message, bool $force = false): void
{
    global $QUIET;
    if ($QUIET && !$force) {
        return;
    }
    printf("[%s] %-5s %s\n", date('H:i:s'), $tag, $message);
}

function fail(string $message): void
{
    fwrite(STDERR, "\n  ERROR: {$message}\n\n");
}

// ---------------------------------------------------------------------
// HTTP - mirrors postJson() in the firmware
// ---------------------------------------------------------------------
function api(string $path, array $body): ?array
{
    global $BASE, $KEY;

    $ch = curl_init($BASE . $path);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($body),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'X-API-Key: ' . $KEY,
        ],
    ]);

    $raw  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        say('NET', "transport error on {$path}: {$err}");
        return null;
    }

    $decoded = json_decode((string)$raw, true);
    if (!is_array($decoded)) {
        say('NET', "invalid JSON from {$path} (HTTP {$code})");
        return null;
    }
    if (empty($decoded['success'])) {
        say('API', "{$path} -> HTTP {$code}: " . ($decoded['message'] ?? 'unknown error'));
        return null;
    }

    return $decoded['data'] ?? [];
}

/** The identity fields every firmware request carries. */
function identity(): array
{
    global $UID, $state;
    return [
        'esp32_uid'           => $UID,
        'firmware_version'    => '1.0.0-sim',
        'ip_address'          => '127.0.0.1',
        'wifi_rssi'           => -55 - random_int(0, 12),
        'feed_sensor_status'  => 'ok',
        'water_sensor_status' => 'ok',
        'motor_status'        => $state['motor'],
        'solenoid_status'     => $state['solenoid'],
    ];
}

// ---------------------------------------------------------------------
// Physics
// ---------------------------------------------------------------------

/** Advance the simulated world by $seconds of farm time. Pure physics. */
function advance(float $seconds): void
{
    global $state, $job, $settings;

    $hours = $seconds / 3600.0;

    // The birds eat and drink continuously.
    $state['feed_weight'] = max(0.0, $state['feed_weight'] - $state['feed_rate'] * $hours);
    $state['water_level'] = max(0.0, $state['water_level'] - $state['water_rate'] * $hours);

    // A running actuator adds to the same quantity.
    if ($job !== null) {
        $job['elapsed'] += $seconds;

        if ($job['type'] === 'feed') {
            $state['feed_weight'] = min(
                (float)$settings['feed_capacity'],
                $state['feed_weight'] + $state['auger_rate'] * $seconds
            );
        } else {
            $state['water_level'] = min(
                (float)$settings['water_capacity'],
                $state['water_level'] + $state['valve_rate'] * $seconds
            );
        }
    }
}

/**
 * Run the simulation forward, checking the control logic as we go.
 *
 * The step size matters. At a high --speed one upload cycle can cover more
 * simulated time than a whole dispensing run, and advancing in one jump would
 * blow straight past both the target weight and the safety timeout. The real
 * firmware re-checks its actuators on every pass of loop(), so the simulator
 * steps finely while an actuator is running and coarsely while it is idle.
 */
function simulate(float $totalSeconds): void
{
    global $job;

    $remaining = $totalSeconds;
    $guard = 0;

    while ($remaining > 0 && $guard++ < 20000) {
        $step = ($job !== null)
            ? min(0.25, $remaining)     // fine control while dispensing
            : min(30.0, $remaining);    // coarse while simply draining

        advance($step);
        superviseJob();     // stop at the target or the timeout
        runAutomation();    // may start a cycle part-way through the chunk

        $remaining -= $step;
    }
}

/** A little sensor noise, applied once per reading so it does not accumulate. */
function applySensorNoise(): void
{
    global $state;
    $state['feed_weight'] = max(0.0, $state['feed_weight'] + (mt_rand(-100, 100) / 100.0) * 3);
    $state['water_level'] = max(0.0, $state['water_level'] + (mt_rand(-100, 100) / 100.0) * 0.05);
}

/** Convert the simulated water depth back into what the HC-SR04 would report. */
function distance_cm(): float
{
    global $state, $settings;
    $depth  = (float)$settings['water_capacity'];
    $offset = (float)$settings['sensor_offset_cm'];
    return round($offset + ($depth - $state['water_level']), 2);
}

// ---------------------------------------------------------------------
// Local automation - the same rules as runAutomation() in the firmware
// ---------------------------------------------------------------------
function startJob(string $type, float $target, string $method, ?int $userId, ?int $commandId): void
{
    global $job, $state, $settings;

    if ($job !== null) {
        return;
    }

    $job = [
        'type'       => $type,
        'method'     => $method,
        'user_id'    => $userId,
        'command_id' => $commandId,
        'start'      => $type === 'feed' ? $state['feed_weight'] : $state['water_level'],
        'target'     => $target,
        'elapsed'    => 0.0,
        'max'        => $type === 'feed'
            ? (float)$settings['feed_max_duration']
            : (float)$settings['water_max_duration'],
    ];

    if ($type === 'feed') {
        $state['motor'] = 'running';
        say('ACT', sprintf('Auger ON  %.0f g -> %.0f g (%s)', $job['start'], $target, $method), true);
    } else {
        $state['solenoid'] = 'running';
        say('ACT', sprintf('Valve OPEN %.1f cm -> %.1f cm (%s)', $job['start'], $target, $method), true);
    }
}

function superviseJob(): void
{
    global $job, $state, $settings;

    if ($job === null) {
        return;
    }

    $isFeed  = $job['type'] === 'feed';
    $current = $isFeed ? $state['feed_weight'] : $state['water_level'];
    $tol     = $isFeed ? (float)$settings['feed_tolerance'] : 0.5;

    $status = null;
    if ($current >= $job['target'] - $tol) {
        $status = 'completed';
    } elseif ($job['elapsed'] >= $job['max']) {
        // The safety cut-off that also exists in the firmware.
        $status = 'timeout';
    }

    if ($status === null) {
        return;
    }

    finishJob($status, $current);
}

function finishJob(string $status, float $endValue): void
{
    global $job, $state, $online, $buffer;

    $isFeed = $job['type'] === 'feed';
    $state[$isFeed ? 'motor' : 'solenoid'] = 'idle';

    say('ACT', sprintf(
        '%s %s: %.1f -> %.1f in %.1f s',
        $isFeed ? 'Auger OFF' : 'Valve CLOSED',
        strtoupper($status),
        $job['start'], $endValue, $job['elapsed']
    ), true);

    $record = [
        'type'              => $job['type'],
        'status'            => $status,
        'duration_ms'       => (int)round($job['elapsed'] * 1000),
        'dispensing_method' => $online ? $job['method'] : 'offline',
        'timestamp'         => date('Y-m-d H:i:s'),
    ];
    if ($job['user_id']) {
        $record['user_id'] = $job['user_id'];
    }
    if ($isFeed) {
        $record['start_weight']  = round($job['start'], 2);
        $record['target_weight'] = round($job['target'], 2);
        $record['actual_weight'] = round($endValue, 2);
    } else {
        $record['starting_level'] = round($job['start'], 2);
        $record['target_level']   = round($job['target'], 2);
        $record['ending_level']   = round($endValue, 2);
    }

    $commandId = $job['command_id'];
    $job = null;

    if ($online) {
        if (api('/api/esp32/dispensing.php', identity() + $record) === null) {
            $buffer[] = $record + ['_kind' => 'dispensing'];
        }
        if ($commandId) {
            api('/api/esp32/ack.php', [
                'esp32_uid'  => $GLOBALS['UID'],
                'command_id' => $commandId,
                'status'     => $status === 'completed' ? 'executed' : 'failed',
                'result'     => 'Cycle finished with status: ' . $status,
            ]);
        }
    } else {
        $buffer[] = $record + ['_kind' => 'dispensing'];
        say('BUF', 'Dispensing event buffered (' . count($buffer) . ' waiting)');
    }
}

function runAutomation(): void
{
    global $job, $state, $settings;

    if ($job !== null || $settings === null) {
        return;
    }

    if (!empty($settings['auto_feed']) && $state['feed_weight'] < (float)$settings['feed_threshold']) {
        say('AUT', sprintf('Feed %.0f g below the %.0f g threshold',
            $state['feed_weight'], (float)$settings['feed_threshold']));
        startJob('feed', (float)$settings['target_feed'], 'automatic', null, null);
        return;
    }

    if (!empty($settings['auto_water']) && $state['water_level'] < (float)$settings['water_threshold']) {
        say('AUT', sprintf('Water %.1f cm below the %.1f cm threshold',
            $state['water_level'], (float)$settings['water_threshold']));
        startJob('water', (float)$settings['water_target'], 'automatic', null, null);
    }
}

// ---------------------------------------------------------------------
// Commands
// ---------------------------------------------------------------------
function handleCommands(array $commands): void
{
    global $job, $state, $settings, $UID;

    foreach ($commands as $cmd) {
        $id      = (int)$cmd['command_id'];
        $name    = (string)$cmd['command'];
        $payload = is_array($cmd['payload'] ?? null) ? $cmd['payload'] : [];

        say('CMD', "#{$id} {$name}", true);

        $ack = static function (string $status, string $result) use ($id, $UID): void {
            api('/api/esp32/ack.php', [
                'esp32_uid' => $UID, 'command_id' => $id,
                'status' => $status, 'result' => $result,
            ]);
        };

        switch ($name) {
            case 'dispense_feed':
                if ($job !== null) {
                    $ack('failed', 'An actuator is already running');
                    break;
                }
                startJob('feed', (float)($payload['target_weight'] ?? $settings['target_feed']),
                    'manual', isset($payload['user_id']) ? (int)$payload['user_id'] : null, $id);
                break;

            case 'refill_water':
                if ($job !== null) {
                    $ack('failed', 'An actuator is already running');
                    break;
                }
                startJob('water', (float)($payload['target_level'] ?? $settings['water_target']),
                    'manual', isset($payload['user_id']) ? (int)$payload['user_id'] : null, $id);
                break;

            case 'stop_feed':
                if ($job !== null && $job['type'] === 'feed') {
                    finishJob('aborted', $state['feed_weight']);
                    $ack('executed', 'Auger stopped');
                } else {
                    $state['motor'] = 'idle';
                    $ack('executed', 'Auger was already idle');
                }
                break;

            case 'stop_water':
                if ($job !== null && $job['type'] === 'water') {
                    finishJob('aborted', $state['water_level']);
                    $ack('executed', 'Valve closed');
                } else {
                    $state['solenoid'] = 'idle';
                    $ack('executed', 'Valve was already closed');
                }
                break;

            case 'tare_scale':
                $ack('executed', 'Scale tared. New raw offset: 128340 (simulated)');
                break;

            case 'reload_settings':
                fetchConfig();
                $ack('executed', 'Settings reloaded');
                break;

            case 'emergency_stop':
                if ($job !== null) {
                    finishJob('aborted', $job['type'] === 'feed'
                        ? $state['feed_weight'] : $state['water_level']);
                }
                $state['motor'] = $state['solenoid'] = 'idle';
                $settings['auto_feed'] = false;
                $settings['auto_water'] = false;
                say('!!!', 'EMERGENCY STOP - automation disabled', true);
                $ack('executed', 'All actuators stopped, automation disabled');
                break;

            case 'reboot':
                $ack('executed', 'Rebooting (simulated)');
                break;

            default:
                $ack('failed', 'Unknown command');
        }
    }
}

// ---------------------------------------------------------------------
// Server exchanges
// ---------------------------------------------------------------------
function fetchConfig(): bool
{
    global $settings, $UID;

    $data = api('/api/esp32/config.php', ['esp32_uid' => $UID]);
    if ($data === null) {
        return false;
    }
    $settings = $data['settings'];
    say('CFG', sprintf(
        'Feed: refill below %.0f g up to %.0f g (auto %s) | Water: below %.1f cm up to %.1f cm (auto %s)',
        $settings['feed_threshold'], $settings['target_feed'], $settings['auto_feed'] ? 'ON' : 'OFF',
        $settings['water_threshold'], $settings['water_target'], $settings['auto_water'] ? 'ON' : 'OFF'
    ), true);
    return true;
}

function upload(): bool
{
    global $state, $settings, $buffer;

    $payload = identity() + [
        'feed_weight' => round($state['feed_weight'], 2),
        'raw_value'   => (int)round(128340 + $state['feed_weight'] * 214.75),
        'distance_cm' => distance_cm(),
    ];

    $data = api('/api/esp32/sensor-data.php', $payload);
    if ($data === null) {
        $buffer[] = [
            '_kind'       => 'reading',
            'timestamp'   => date('Y-m-d H:i:s'),
            'feed_weight' => round($state['feed_weight'], 2),
            'distance_cm' => distance_cm(),
        ];
        return false;
    }

    if (!empty($data['settings'])) {
        $settings = $data['settings'];
    }
    if (!empty($data['warnings'])) {
        foreach ($data['warnings'] as $w) {
            say('WRN', $w, true);
        }
    }
    if (!empty($data['pending_commands'])) {
        handleCommands($data['pending_commands']);
    }

    say('NET', sprintf('Uploaded: feed %.0f g (%.1f%%), water %.1f cm',
        $state['feed_weight'],
        $settings ? $state['feed_weight'] / (float)$settings['feed_capacity'] * 100 : 0,
        $state['water_level']));

    return true;
}

function syncBuffer(): void
{
    global $buffer, $UID;

    if ($buffer === []) {
        return;
    }

    $batch = array_splice($buffer, 0, 40);
    $readings = [];
    $dispensing = [];

    foreach ($batch as $rec) {
        $kind = $rec['_kind'];
        unset($rec['_kind']);
        if ($kind === 'dispensing') {
            $dispensing[] = $rec;
        } else {
            $readings[] = $rec;
        }
    }

    say('BUF', sprintf('Syncing %d buffered record(s)...', count($batch)), true);

    $data = api('/api/esp32/sync.php', [
        'esp32_uid'  => $UID,
        'readings'   => $readings,
        'dispensing' => $dispensing,
    ]);

    if ($data === null) {
        // Put them back; nothing is lost on a failed sync.
        $buffer = array_merge($batch, $buffer);
        say('BUF', 'Sync failed; records kept for the next attempt.', true);
        return;
    }

    say('BUF', sprintf('Synced %d record(s); %d still buffered.',
        (int)($data['total'] ?? 0), count($buffer)), true);

    if (!empty($data['rejected'])) {
        foreach ($data['rejected'] as $r) {
            say('BUF', 'Rejected: ' . $r, true);
        }
    }
}

// ---------------------------------------------------------------------
// Main loop
// ---------------------------------------------------------------------
echo "\n";
echo "  PoultryGuard ESP32 device simulator\n";
echo "  ───────────────────────────────────\n";
echo "  Server   : {$BASE}\n";
echo "  Device   : {$UID}\n";
echo "  Speed    : {$SPEED}x  (1 real second = {$SPEED} simulated seconds)\n";
echo "  Interval : every {$INTERVAL} s\n";
if ($OFFLINE_CYCLES > 0) {
    echo "  Offline  : the first {$OFFLINE_CYCLES} cycles run with no server\n";
}
echo "\n";

if (!function_exists('curl_init')) {
    fail('The PHP cURL extension is not enabled. Enable extension=curl in php.ini.');
    exit(1);
}

if (!fetchConfig()) {
    fail("Could not reach {$BASE}.\n"
        . "  - Is Apache running in the XAMPP Control Panel?\n"
        . "  - Is the URL right? Try --url=http://localhost/PoultryGuard\n"
        . "  - Is the device UID registered, with a matching API key?");
    exit(1);
}

// Start the simulation from the tank/hopper state the server last saw, so the
// charts continue smoothly instead of jumping.
$state['feed_weight'] = min($state['feed_weight'], (float)$settings['feed_capacity']);
$state['water_level'] = min($state['water_level'], (float)$settings['water_capacity']);

say('SYS', 'Running. Press Ctrl+C to stop.', true);
echo "\n";

while (true) {
    $cycle++;

    // Was this cycle meant to be offline?
    $wasOnline = $online;
    $online = !($OFFLINE_CYCLES > 0 && $cycle <= $OFFLINE_CYCLES);

    if ($wasOnline && !$online) {
        say('NET', '--- Network DOWN. Local automation continues. ---', true);
    }
    if (!$wasOnline && $online) {
        say('NET', '--- Network RESTORED. Uploading buffered records. ---', true);
        fetchConfig();
        syncBuffer();
    }

    // Advance the world, running the same control logic the firmware runs.
    simulate($INTERVAL * $SPEED);
    applySensorNoise();

    if ($online) {
        upload();
        if ($buffer !== []) {
            syncBuffer();
        }
    } else {
        $buffer[] = [
            '_kind'       => 'reading',
            'timestamp'   => date('Y-m-d H:i:s'),
            'feed_weight' => round($state['feed_weight'], 2),
            'distance_cm' => distance_cm(),
        ];
        say('BUF', sprintf('Offline reading buffered: feed %.0f g, water %.1f cm (%d waiting)',
            $state['feed_weight'], $state['water_level'], count($buffer)));
    }

    if ($ONCE) {
        say('SYS', 'Single reading sent (--once). Exiting.', true);
        break;
    }

    sleep($INTERVAL);
}
