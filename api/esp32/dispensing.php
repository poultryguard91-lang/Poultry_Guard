<?php
/**
 * POST /api/esp32/dispensing.php
 *
 * The firmware reports a finished feed or water dispensing cycle here: what it
 * targeted, what it actually achieved, how long the actuator ran and whether
 * the safety timeout cut it short. This is what makes every automatic action
 * auditable from the dashboard.
 *
 * Body: { "type": "feed" | "water", ... }
 * Auth: X-API-Key header (device key).
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/device.php';

api_require_method('POST');

api_run(function (array $in): ApiResult {
    $device   = api_device_auth($in);
    $deviceId = (int)$device['device_id'];

    $type = v_enum($in['type'] ?? null, 'Dispensing type', ['feed', 'water']);

    if ($type === 'feed') {
        $id = record_feed_dispensing($deviceId, $in);
        // The feeder has stopped by the time this report arrives.
        touch_device($deviceId, ['motor_status' => 'idle']);
        $message = 'Feed dispensing record saved successfully';
    } else {
        $id = record_water_dispensing($deviceId, $in);
        touch_device($deviceId, ['solenoid_status' => 'idle']);
        $message = 'Water dispensing record saved successfully';
    }

    return api_ok(['dispensing_id' => $id, 'type' => $type], $message);
});
