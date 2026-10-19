<?php
/**
 * POST /api/esp32/status.php
 *
 * Lightweight heartbeat. The firmware calls this between telemetry uploads to
 * report hardware state and to collect any queued manual command, without the
 * cost of a full sensor payload.
 *
 * Auth: X-API-Key header (device key).
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/device.php';

api_require_method('POST', 'GET');

api_run(function (array $in): ApiResult {
    $device   = api_device_auth($in);
    $deviceId = (int)$device['device_id'];
    $settings = get_settings($deviceId);

    // A device that reconnects after an outage tells us how many records it
    // buffered locally; that is worth surfacing to the operator.
    $buffered = isset($in['buffered_records']) && is_numeric($in['buffered_records'])
        ? max(0, (int)$in['buffered_records']) : 0;

    $wasOffline = !device_is_online($device['last_seen'], (int)$settings['offline_timeout']);

    touch_device($deviceId, [
        'firmware_version'    => isset($in['firmware_version']) ? v_string($in['firmware_version'], 'Firmware version', 0, 20, '') : null,
        'ip_address'          => isset($in['ip_address']) ? v_string($in['ip_address'], 'IP address', 0, 45, '') : null,
        'wifi_rssi'           => isset($in['wifi_rssi']) && is_numeric($in['wifi_rssi']) ? (int)$in['wifi_rssi'] : null,
        'feed_sensor_status'  => isset($in['feed_sensor_status'])  ? v_enum($in['feed_sensor_status'], 'Feed sensor status', ['ok','error','unknown']) : null,
        'water_sensor_status' => isset($in['water_sensor_status']) ? v_enum($in['water_sensor_status'], 'Water sensor status', ['ok','error','unknown']) : null,
        'motor_status'        => isset($in['motor_status'])    ? v_enum($in['motor_status'], 'Motor status', ['idle','running','error']) : null,
        'solenoid_status'     => isset($in['solenoid_status']) ? v_enum($in['solenoid_status'], 'Solenoid status', ['idle','running','error']) : null,
    ]);

    if ($wasOffline && $device['last_seen'] !== null) {
        create_alert($deviceId, 'info', 'INFO',
            $buffered > 0
                ? sprintf('Device reconnected after an outage; %d buffered records pending upload.', $buffered)
                : 'Device reconnected and is reporting normally again.',
            600
        );
        log_activity('DEVICE_RECONNECTED',
            sprintf('Device %s came back online.', $device['device_name']), null, client_ip());
    }

    return api_ok([
        'device_id'        => $deviceId,
        'server_time'      => date('Y-m-d H:i:s'),
        'settings_version' => (int)strtotime((string)$settings['updated_at']),
        'settings'         => device_config_payload($deviceId, $settings),
        'pending_commands' => take_pending_commands($deviceId),
    ], 'Status received');
});
