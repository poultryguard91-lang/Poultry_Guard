<?php
/**
 * POST /api/automation/emergency-stop.php
 *
 * Cuts both actuators at once and turns off the automation flags, so the
 * device will not immediately restart what it was doing. This is the control
 * an operator reaches for when something is going wrong at the hopper.
 *
 * Unlike the other manual controls this is accepted even while the device is
 * offline: the command waits in the queue and the automation flags are already
 * cleared, so the moment the controller reconnects it stops and stays stopped.
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

    $pdo = db();
    $pdo->beginTransaction();

    try {
        // Drop anything still waiting - none of it should run now.
        $pdo->prepare(
            "UPDATE device_commands SET status = 'expired'
             WHERE device_id = ? AND status IN ('pending','sent')"
        )->execute([$deviceId]);

        // Disable both automations so the device does not resume on its own.
        $pdo->prepare(
            'UPDATE system_settings SET auto_feed = 0, auto_water = 0, updated_by = ? WHERE device_id = ?'
        )->execute([(int)$user['user_id'], $deviceId]);

        $st = $pdo->prepare(
            'INSERT INTO device_commands (device_id, command, issued_by) VALUES (?, ?, ?)'
        );
        $st->execute([$deviceId, 'emergency_stop', (int)$user['user_id']]);
        $commandId = (int)$pdo->lastInsertId();

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    create_alert($deviceId, 'system_error', 'CRITICAL', sprintf(
        'Emergency stop triggered by %s. Both automations are now switched off.',
        $user['name']
    ), 0);

    log_activity('EMERGENCY_STOP', sprintf(
        'Emergency stop issued for %s; automatic feeding and watering disabled.',
        $device['device_name']
    ), (int)$user['user_id']);

    return api_ok([
        'command_id' => $commandId,
        'auto_feed'  => false,
        'auto_water' => false,
    ], 'Emergency stop sent. Automatic feeding and watering have been switched off — re-enable them in Settings when it is safe.');
});
