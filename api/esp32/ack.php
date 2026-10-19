<?php
/**
 * POST /api/esp32/ack.php
 *
 * The firmware confirms what it did with a manual command it was handed, so
 * the dashboard can show the operator whether their button press actually
 * reached the hardware and what the outcome was.
 *
 * Body: { "command_id": 12, "status": "executed" | "failed", "result": "..." }
 * Auth: X-API-Key header (device key).
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/device.php';

api_require_method('POST');

api_run(function (array $in): ApiResult {
    $device   = api_device_auth($in);
    $deviceId = (int)$device['device_id'];

    $commandId = v_int($in['command_id'] ?? null, 'Command', 1, PHP_INT_MAX);
    $status    = v_enum($in['status'] ?? null, 'Status', ['executed', 'failed']);
    $result    = v_string($in['result'] ?? null, 'Result', 0, 255, '');

    // Scope the lookup to the calling device so one device can never
    // acknowledge - or discover - another device's commands.
    $st = db()->prepare('SELECT * FROM device_commands WHERE command_id = ? AND device_id = ?');
    $st->execute([$commandId, $deviceId]);
    $command = $st->fetch();

    if (!$command) {
        throw new NotFoundException('That command does not exist for this device.');
    }
    if (in_array($command['status'], ['executed', 'failed'], true)) {
        // The device retried an acknowledgement it already delivered.
        return api_ok(['command_id' => $commandId, 'status' => $command['status']],
            'Command was already acknowledged');
    }

    db()->prepare(
        'UPDATE device_commands SET status = ?, result = ?, executed_at = NOW() WHERE command_id = ?'
    )->execute([$status, $result !== '' ? $result : null, $commandId]);

    if ($status === 'failed') {
        create_alert($deviceId, 'system_error', 'WARNING', sprintf(
            'Manual command "%s" failed on the device: %s',
            $command['command'],
            $result !== '' ? $result : 'no reason reported'
        ), 300);
    }

    log_activity('COMMAND_ACK', sprintf(
        'Device %s reported command "%s" as %s.',
        $device['device_name'], $command['command'], $status
    ), $command['issued_by'] !== null ? (int)$command['issued_by'] : null, client_ip());

    return api_ok(['command_id' => $commandId, 'status' => $status], 'Command acknowledged');
});
