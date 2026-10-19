<?php
/**
 * POST /api/feed/dispense.php
 *
 * Manual feed control. Body: { "action": "start"|"stop", "device_id": 1, "target_weight": 9000 }
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
            sprintf('Stop requested for the feed auger on %s.', $device['device_name']),
            (int)$user['user_id']);

        return api_ok([
            'command_id' => $commandId,
            'action'     => 'stop',
        ], 'Stop command sent. The auger will halt on the next device poll.');
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

    $capacity = (float)$settings['feed_capacity'];
    $reading  = latest_feed_reading($deviceId);
    $current  = $reading ? (float)$reading['feed_weight'] : 0.0;

    $target = v_float(
        $in['target_weight'] ?? null,
        'Target weight',
        0,
        $capacity,
        (float)$settings['target_feed']
    );

    // Dispensing can only add feed, so a target at or below the current level
    // is a mistake worth catching before the auger runs for nothing.
    $tolerance = (float)$settings['feed_tolerance'];
    if ($target <= $current + $tolerance) {
        throw new ValidationException(sprintf(
            'The hopper already holds %s. Choose a target above %s.',
            format_weight($current),
            format_weight($current + $tolerance)
        ));
    }

    if ($device['motor_status'] === 'running') {
        throw new ValidationException('The auger is already running. Stop it before starting a new cycle.');
    }

    $commandId = queue_command($deviceId, 'dispense_feed', [
        'target_weight' => $target,
        'start_weight'  => $current,
        // Echoed back by the firmware so the resulting dispensing record is
        // attributed to the operator who pressed the button.
        'user_id'       => (int)$user['user_id'],
        'max_duration'  => (int)$settings['feed_max_duration'],
    ], (int)$user['user_id']);

    log_activity('MANUAL_FEED', sprintf(
        'Manual feed dispensing (target %s) requested on %s.',
        format_weight($target), $device['device_name']
    ), (int)$user['user_id']);

    return api_ok([
        'command_id'     => $commandId,
        'action'         => 'start',
        'target_weight'  => $target,
        'current_weight' => $current,
        'expected_add'   => round($target - $current, 2),
        'max_duration'   => (int)$settings['feed_max_duration'],
    ], sprintf('Dispensing command sent. The device will add about %s.', format_weight($target - $current)));
});
