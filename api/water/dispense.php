<?php
/**
 * POST /api/water/dispense.php
 *
 * Manual water control. Body: { "action": "start"|"stop", "device_id": 1 }  (the pump runs until the tank reads FULL)
 *
 * Like the feed control, this queues a command for the ESP32 rather than
 * touching the pump relay from the web tier.
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

    if ($action === 'stop') {
        $commandId = queue_command($deviceId, 'stop_water', null, (int)$user['user_id']);

        log_activity('MANUAL_WATER_STOP',
            sprintf('Stop requested for the water pump on %s.', $device['device_name']),
            (int)$user['user_id']);

        return api_ok([
            'command_id' => $commandId,
            'action'     => 'stop',
        ], 'Stop command sent. The pump will stop on the next device poll.');
    }

    if (!device_is_online($device['last_seen'], (int)$settings['offline_timeout'])) {
        throw new ValidationException(
            'ESP32 device is offline. It cannot receive manual commands until it reconnects. '
            . 'Automatic watering continues locally on the device.'
        );
    }

    $reading = latest_water_reading($deviceId);
    $current = $reading ? (float)$reading['water_percentage'] : 0.0;

    // The pump runs until the level sensor reads FULL (or the safety timeout),
    // so there is no target to choose - only whether a refill makes sense.
    if ($reading !== null && $current >= 99.0) {
        throw new ValidationException('The tank is already full.');
    }

    if ($device['solenoid_status'] === 'running') {
        throw new ValidationException('The pump is already running. Stop the refill before starting a new one.');
    }

    $commandId = queue_command($deviceId, 'refill_water', [
        'user_id'      => (int)$user['user_id'],
        'max_duration' => (int)$settings['water_max_duration'],
    ], (int)$user['user_id']);

    log_activity('MANUAL_WATER', sprintf(
        'Manual water refill requested on %s.', $device['device_name']
    ), (int)$user['user_id']);

    return api_ok([
        'command_id'   => $commandId,
        'action'       => 'start',
        'max_duration' => (int)$settings['water_max_duration'],
    ], 'Refill command sent. The pump runs until the tank is full.');
});
