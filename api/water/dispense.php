<?php
/**
 * POST /api/water/dispense.php
 *
 * Manual water control. Body: { "action": "start"|"stop", "device_id": 1, "target_level": 90 }
 *
 * Like the feed control, this queues a command for the ESP32 rather than
 * touching the solenoid relay from the web tier.
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
            sprintf('Stop requested for the solenoid valve on %s.', $device['device_name']),
            (int)$user['user_id']);

        return api_ok([
            'command_id' => $commandId,
            'action'     => 'stop',
        ], 'Stop command sent. The valve will close on the next device poll.');
    }

    if (!device_is_online($device['last_seen'], (int)$settings['offline_timeout'])) {
        throw new ValidationException(
            'ESP32 device is offline. It cannot receive manual commands until it reconnects. '
            . 'Automatic watering continues locally on the device.'
        );
    }

    $depth   = (float)$settings['water_capacity'];
    $reading = latest_water_reading($deviceId);
    $current = $reading ? (float)$reading['water_level'] : 0.0;

    $target = v_float(
        $in['target_level'] ?? null,
        'Target level',
        0,
        $depth,
        (float)$settings['water_target']
    );

    // Refilling can only raise the level; ±1 cm is the sensor's own accuracy,
    // so anything inside that band is not a meaningful request.
    if ($target <= $current + 1.0) {
        throw new ValidationException(sprintf(
            'The tank is already at %.1f cm. Choose a target above %.1f cm.',
            $current,
            $current + 1.0
        ));
    }

    if ($device['solenoid_status'] === 'running') {
        throw new ValidationException('The valve is already open. Stop the refill before starting a new one.');
    }

    $commandId = queue_command($deviceId, 'refill_water', [
        'target_level'  => $target,
        'starting_level'=> $current,
        'user_id'       => (int)$user['user_id'],
        'max_duration'  => (int)$settings['water_max_duration'],
    ], (int)$user['user_id']);

    log_activity('MANUAL_WATER', sprintf(
        'Manual water refill (target %.1f cm) requested on %s.',
        $target, $device['device_name']
    ), (int)$user['user_id']);

    return api_ok([
        'command_id'    => $commandId,
        'action'        => 'start',
        'target_level'  => $target,
        'current_level' => $current,
        'expected_rise' => round($target - $current, 2),
        'max_duration'  => (int)$settings['water_max_duration'],
    ], sprintf('Refill command sent. The device will raise the level by about %.1f cm.', $target - $current));
});
