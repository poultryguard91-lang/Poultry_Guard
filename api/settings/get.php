<?php
/**
 * GET /api/settings/get.php?device_id=1
 *
 * The automation profile for a device, plus which groups of it the signed-in
 * user is allowed to change - the settings page uses that to decide what to
 * render as editable.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/api.php';

api_require_method('GET');

api_run(function (array $in): ApiResult {
    require_permission('settings.view');

    $device   = api_resolve_device($in);
    $deviceId = (int)$device['device_id'];
    $settings = get_settings($deviceId);

    $updatedBy = null;
    if ($settings['updated_by'] !== null) {
        $st = db()->prepare('SELECT name FROM users WHERE user_id = ?');
        $st->execute([(int)$settings['updated_by']]);
        $updatedBy = $st->fetchColumn() ?: null;
    }

    return api_ok([
        'device' => [
            'device_id'   => $deviceId,
            'device_name' => $device['device_name'],
            'location'    => $device['location'],
            'online'      => device_is_online($device['last_seen'], (int)$settings['offline_timeout']),
        ],
        'feed' => [
            'feed_capacity'     => (float)$settings['feed_capacity'],
            'feed_threshold'    => (float)$settings['feed_threshold'],
            'target_feed'       => (float)$settings['target_feed'],
            'feed_max_duration' => (int)$settings['feed_max_duration'],
            'feed_tolerance'    => (float)$settings['feed_tolerance'],
            'auto_feed'         => (bool)$settings['auto_feed'],
        ],
        'water' => [
            'water_capacity'        => (float)$settings['water_capacity'],
            'water_capacity_liters' => (float)$settings['water_capacity_liters'],
            'sensor_offset_cm'      => (float)$settings['sensor_offset_cm'],
            'water_threshold'       => (float)$settings['water_threshold'],
            'water_target'          => (float)$settings['water_target'],
            'water_max_duration'    => (int)$settings['water_max_duration'],
            'auto_water'            => (bool)$settings['auto_water'],
        ],
        'alerts' => [
            'feed_low_percent'       => (float)$settings['feed_low_percent'],
            'feed_critical_percent'  => (float)$settings['feed_critical_percent'],
            'water_low_percent'      => (float)$settings['water_low_percent'],
            'water_critical_percent' => (float)$settings['water_critical_percent'],
            'offline_timeout'        => (int)$settings['offline_timeout'],
            'reading_interval'       => (int)$settings['reading_interval'],
            'notify_in_app'          => (bool)$settings['notify_in_app'],
            'notify_email'           => (bool)$settings['notify_email'],
        ],
        'permissions' => [
            'thresholds' => user_can('settings.thresholds'),
            'alerts'     => user_can('settings.alerts'),
        ],
        'updated_at'      => $settings['updated_at'],
        'updated_by_name' => $updatedBy,
    ], 'Settings retrieved successfully');
});
