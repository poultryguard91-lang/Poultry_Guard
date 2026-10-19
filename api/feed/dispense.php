<?php
/**
 * POST /api/feed/dispense.php
 *
 * Manual feed control. Body: { "action": "start"|"stop", "device_id": 1, "duration_sec": 3 }
 *
 * The web app never drives the relay directly. It queues a command that the
 * ESP32 collects on its next poll and then acknowledges - so the firmware
 * stays the single authority over the hardware, exactly as it must be for the
 * offline automation to remain safe.
 *
 * Requires the automation.control permission (Farm Staff).
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/device.php';

api_require_method('POST');

api_run(function (array $in): ApiResult {
    $user = require_permission('automation.control');
    require_csrf($in);

    $device   = api_resolve_device($in);
    $deviceId = (int)$device['device_id'];
    $settings = get_settings($deviceId);
    $action   = v_enum($in['action'] ?? null, 'Action', ['start', 'stop']);

    if ($device['status'] !== 'active') {
        throw new ValidationException('This device is disabled. Enable it before sending commands.');
    }

    // ------------------------------------------------------------------
    // Stop is always allowed - it is the safety action.
    // ------------------------------------------------------------------
    if ($action === 'stop') {
        $commandId = queue_command($deviceId, 'stop_feed', null, (int)$user['user_id']);

        log_activity('MANUAL_FEED_STOP',
            sprintf('Stop requested for the feeder on %s.', $device['device_name']),
            (int)$user['user_id']);

        return api_ok([
            'command_id' => $commandId,
            'action'     => 'stop',
        ], 'Stop command sent. The feeder will halt on the next device poll.');
    }

    // ------------------------------------------------------------------
    // Start: only worth sending if the device can actually receive it.
    // ------------------------------------------------------------------
    if (!device_is_online($device['last_seen'], (int)$settings['offline_timeout'])) {
        throw new ValidationException(
            'ESP32 device is offline. It cannot receive manual commands until it reconnects. '
            . 'Automatic feeding continues locally on the device.'
        );
    }

    if ($device['motor_status'] === 'running') {
        throw new ValidationException('The feeder is already running. Stop it before starting a new run.');
    }

    $maxSec   = (int)$settings['feed_max_duration'];
    $duration = v_int($in['duration_sec'] ?? null, 'Run time (seconds)', 1, $maxSec, 3);

    $commandId = queue_command($deviceId, 'dispense_feed', [
        // Echoed back by the firmware so the resulting record is attributed
        // to the operator who pressed the button.
        'user_id'      => (int)$user['user_id'],
        'duration_sec' => $duration,
    ], (int)$user['user_id']);

    log_activity('MANUAL_FEED', sprintf(
        'Manual feeding (%d s) requested on %s.', $duration, $device['device_name']
    ), (int)$user['user_id']);

    return api_ok([
        'command_id'   => $commandId,
        'action'       => 'start',
        'duration_sec' => $duration,
        'max_duration' => $maxSec,
    ], sprintf('Feed command sent. The feeder will run for %d s.', $duration));
});
