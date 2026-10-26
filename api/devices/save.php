<?php
/**
 * POST /api/devices/save.php
 *
 * Add, edit, enable/disable, delete a device, or roll its API key.
 *
 * Body: { "action": "create"|"update"|"toggle"|"regenerate_key"|"delete", ... }
 *
 * Requires the devices.manage permission (Farm Owner).
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/api.php';

api_require_method('POST');

api_run(function (array $in): ApiResult {
    $user = require_permission('devices.manage');
    require_csrf($in);

    $action = v_enum($in['action'] ?? null, 'Action', ['create', 'update', 'toggle', 'regenerate_key', 'delete']);

    // ------------------------------------------------------------------
    // Create
    // ------------------------------------------------------------------
    if ($action === 'create') {
        $name     = v_string($in['device_name'] ?? null, 'Device name', 2, 100);
        $uid      = v_string($in['esp32_uid'] ?? null, 'ESP32 UID', 4, 64);
        $location = v_string($in['location'] ?? null, 'Location', 0, 150, '');

        if (!preg_match('/^[A-Za-z0-9_:-]+$/', $uid)) {
            throw new ValidationException('The ESP32 UID may only contain letters, digits, hyphens, underscores and colons.');
        }

        $dup = db()->prepare('SELECT device_id FROM devices WHERE esp32_uid = ?');
        $dup->execute([$uid]);
        if ($dup->fetchColumn() !== false) {
            throw new ValidationException('A device with that ESP32 UID is already registered.');
        }

        // The key is shown once, here, and then only ever compared against.
        $apiKey = bin2hex(random_bytes(20));

        $pdo = db();
        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                'INSERT INTO devices (device_name, esp32_uid, api_key, location) VALUES (?, ?, ?, ?)'
            )->execute([$name, $uid, $apiKey, $location !== '' ? $location : null]);
            $deviceId = (int)$pdo->lastInsertId();

            // Every device needs an automation profile from the moment it exists.
            $pdo->prepare('INSERT INTO system_settings (device_id, updated_by) VALUES (?, ?)')
                ->execute([$deviceId, (int)$user['user_id']]);

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        log_activity('DEVICE_REGISTERED', "Device {$name} ({$uid}) registered.", (int)$user['user_id']);

        return api_ok([
            'device_id' => $deviceId,
            'api_key'   => $apiKey,
            'notice'    => 'Copy this API key into the firmware now. It is not shown again.',
        ], 'Device registered successfully', 201);
    }

    // Every remaining action targets an existing device.
    $deviceId = v_int($in['device_id'] ?? null, 'Device', 1, PHP_INT_MAX);
    $device   = get_device($deviceId);
    if (!$device) {
        throw new NotFoundException('That device could not be found.');
    }

    // ------------------------------------------------------------------
    // Update
    // ------------------------------------------------------------------
    if ($action === 'update') {
        $name     = v_string($in['device_name'] ?? null, 'Device name', 2, 100, (string)$device['device_name']);
        $location = v_string($in['location'] ?? null, 'Location', 0, 150, (string)($device['location'] ?? ''));

        db()->prepare('UPDATE devices SET device_name = ?, location = ? WHERE device_id = ?')
            ->execute([$name, $location !== '' ? $location : null, $deviceId]);

        log_activity('DEVICE_UPDATED', "Device {$name} details updated.", (int)$user['user_id']);

        return api_ok(['device_id' => $deviceId], 'Device updated successfully');
    }

    // ------------------------------------------------------------------
    // Enable / disable
    // ------------------------------------------------------------------
    if ($action === 'toggle') {
        $status = $device['status'] === 'active' ? 'disabled' : 'active';

        db()->prepare('UPDATE devices SET status = ? WHERE device_id = ?')->execute([$status, $deviceId]);

        // A disabled device must not act on instructions issued before it was
        // switched off.
        if ($status === 'disabled') {
            db()->prepare(
                "UPDATE device_commands SET status = 'expired'
                 WHERE device_id = ? AND status IN ('pending','sent')"
            )->execute([$deviceId]);
        }

        log_activity('DEVICE_' . strtoupper($status),
            sprintf('Device %s was %s.', $device['device_name'], $status === 'active' ? 'enabled' : 'disabled'),
            (int)$user['user_id']);

        return api_ok(['device_id' => $deviceId, 'status' => $status],
            $status === 'active' ? 'Device enabled' : 'Device disabled');
    }

    // ------------------------------------------------------------------
    // Delete (readings, events, settings, alerts and commands cascade)
    // ------------------------------------------------------------------
    if ($action === 'delete') {
        db()->prepare('DELETE FROM devices WHERE device_id = ?')->execute([$deviceId]);

        log_activity('DEVICE_DELETED',
            sprintf('Device %s (%s) was deleted.', $device['device_name'], $device['esp32_uid']),
            (int)$user['user_id']);

        return api_ok(['device_id' => $deviceId], 'Device deleted');
    }

    // ------------------------------------------------------------------
    // Regenerate API key
    // ------------------------------------------------------------------
    $apiKey = bin2hex(random_bytes(20));
    db()->prepare('UPDATE devices SET api_key = ? WHERE device_id = ?')->execute([$apiKey, $deviceId]);

    log_activity('DEVICE_KEY_ROTATED',
        sprintf('API key regenerated for %s.', $device['device_name']), (int)$user['user_id']);

    return api_ok([
        'device_id' => $deviceId,
        'api_key'   => $apiKey,
        'notice'    => 'Update the firmware with this key. The device cannot report until you do.',
    ], 'API key regenerated successfully');
});
