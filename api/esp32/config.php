<?php
/**
 * GET|POST /api/esp32/config.php
 *
 * Returns the automation profile the firmware stores in NVS. The device calls
 * this once at boot (and whenever `settings_version` changes) so that it holds
 * a complete copy of every rule it needs to run feeding and watering with no
 * network connection at all.
 *
 * Auth: X-API-Key header (device key).
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/device.php';

api_require_method('GET', 'POST');

api_run(function (array $in): ApiResult {
    $device   = api_device_auth($in);
    $deviceId = (int)$device['device_id'];

    touch_device($deviceId);

    return api_ok([
        'device_id'   => $deviceId,
        'device_name' => $device['device_name'],
        'location'    => $device['location'],
        'server_time' => date('Y-m-d H:i:s'),
        'settings'    => device_config_payload($deviceId),
    ], 'Configuration retrieved successfully');
});
