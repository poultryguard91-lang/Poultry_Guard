<?php
/**
 * GET /api/automation/commands.php?device_id=1
 *
 * Recent manual commands and their delivery state. The manual control page
 * polls this after a button press so the operator can see the instruction move
 * from "queued" to "delivered" to "executed" - or find out it was never picked
 * up because the controller went offline.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/api.php';

api_require_method('GET');

api_run(function (array $in): ApiResult {
    require_permission('devices.view');

    $device   = api_resolve_device($in);
    $deviceId = (int)$device['device_id'];
    $limit    = v_int($in['limit'] ?? null, 'Limit', 1, 100, 15);

    // Retire anything the device never collected, so the list reflects reality.
    db()->prepare(
        "UPDATE device_commands SET status = 'expired'
         WHERE device_id = ? AND status = 'pending'
           AND created_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE)"
    )->execute([$deviceId]);

    $st = db()->prepare(
        'SELECT c.command_id, c.command, c.payload, c.status, c.result,
                c.created_at, c.sent_at, c.executed_at, u.name AS issued_by_name
         FROM device_commands c
         LEFT JOIN users u ON u.user_id = c.issued_by
         WHERE c.device_id = ?
         ORDER BY c.created_at DESC
         LIMIT ' . $limit
    );
    $st->execute([$deviceId]);

    $rows = array_map(static function (array $c): array {
        $c['payload'] = $c['payload'] !== null ? json_decode((string)$c['payload'], true) : null;
        $c['ago']     = time_ago($c['created_at']);
        $c['label']   = match ($c['command']) {
            'dispense_feed'   => 'Run feeder',
            'stop_feed'       => 'Stop feeder',
            'refill_water'    => 'Start water refill',
            'stop_water'      => 'Stop water refill',
            'reload_settings' => 'Reload settings',
            'emergency_stop'  => 'Emergency stop',
            'reboot'          => 'Reboot device',
            default           => $c['command'],
        };
        $c['state_label'] = match ($c['status']) {
            'pending'  => 'Queued - waiting for the device',
            'sent'     => 'Delivered to the device',
            'executed' => 'Executed',
            'failed'   => 'Failed',
            'expired'  => 'Expired - the device never collected it',
            default    => $c['status'],
        };
        $c['state_color'] = match ($c['status']) {
            'executed' => 'success',
            'failed'   => 'danger',
            'expired'  => 'secondary',
            'sent'     => 'info',
            default    => 'warning',
        };
        return $c;
    }, $st->fetchAll());

    return api_ok([
        'device' => [
            'device_id'       => $deviceId,
            'device_name'     => $device['device_name'],
            'online'          => device_is_online($device['last_seen'], (int)get_settings($deviceId)['offline_timeout']),
            'motor_status'    => $device['motor_status'],
            'solenoid_status' => $device['solenoid_status'],
            'feed_max_duration' => (int)get_settings($deviceId)['feed_max_duration'],
        ],
        'commands' => $rows,
        'pending'  => count(array_filter($rows, static fn(array $c): bool => in_array($c['status'], ['pending', 'sent'], true))),
    ], 'Commands retrieved successfully');
});
