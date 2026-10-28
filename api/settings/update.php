<?php
/**
 * POST /api/settings/update.php
 *
 * Updates a device's automation profile. Fields are split into two groups
 * guarded by different permissions:
 *
 *   group "thresholds" - feed and water automation (Farm Staff)
 *   group "alerts"     - alert levels and notification policy (Farm Owner)
 *
 * A request that touches a group the user does not hold is refused outright
 * rather than silently ignored, so nobody is left thinking a change was saved.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/device.php';

api_require_method('POST');

/** Fields that belong to each permission group. */
const SETTING_GROUPS = [
    'thresholds' => [
        'feed_max_duration', 'auto_feed', 'feed_schedule',
        'water_capacity', 'water_capacity_liters',
        'water_threshold', 'water_target', 'water_max_duration', 'auto_water',
    ],
    'alerts' => [
        'water_low_percent', 'water_critical_percent',
        'offline_timeout', 'reading_interval', 'notify_in_app', 'notify_email',
    ],
];

api_run(function (array $in): ApiResult {
    $user = require_permission('settings.view');
    require_csrf($in);

    $device   = api_resolve_device($in);
    $deviceId = (int)$device['device_id'];
    $current  = get_settings($deviceId);

    // Which groups does this request actually try to change?
    $touched = [];
    foreach (SETTING_GROUPS as $group => $fields) {
        foreach ($fields as $f) {
            if (array_key_exists($f, $in)) {
                $touched[$group] = true;
                break;
            }
        }
    }
    if ($touched === []) {
        throw new ValidationException('No settings were supplied.');
    }

    foreach (array_keys($touched) as $group) {
        $permission = $group === 'thresholds' ? 'settings.thresholds' : 'settings.alerts';
        if (!user_can($permission)) {
            throw new AuthorizationException(
                $group === 'thresholds'
                    ? 'Only Farm Staff can change feed and water automation settings.'
                    : 'Only the Farm Owner can change alert and notification settings.'
            );
        }
    }

    // ------------------------------------------------------------------
    // Validate. Each value is checked on its own, then the combinations
    // that must hold between them are checked together.
    // ------------------------------------------------------------------
    $new = [];

    if (isset($touched['thresholds'])) {
        $new['feed_max_duration'] = v_int($in['feed_max_duration']   ?? null, 'Maximum feeder run time', 1, FEED_MAX_SEC_LIMIT, (int)$current['feed_max_duration']);

        $new['auto_feed'] = to_bool($in['auto_feed'] ?? $current['auto_feed']);
        if (array_key_exists('feed_schedule', $in)) {
            $new['feed_schedule'] = json_encode(
                parse_feed_schedule($in['feed_schedule'], $new['feed_max_duration']),
                JSON_UNESCAPED_SLASHES
            );
        } elseif (!empty($current['feed_schedule'])) {
            // Shortening the maximum run time must not leave an invalid schedule behind.
            foreach (feed_schedule_decode($current['feed_schedule']) as $slot) {
                if ($slot['seconds'] > $new['feed_max_duration']) {
                    throw new ValidationException(sprintf(
                        'The %s feeding runs %d s, longer than the new maximum of %d s. Shorten it first.',
                        $slot['time'], $slot['seconds'], $new['feed_max_duration']));
                }
            }
        }

        $new['water_capacity']        = v_float($in['water_capacity']        ?? null, 'Maximum water capacity', 1, 400, (float)$current['water_capacity']);
        $new['water_capacity_liters'] = v_float($in['water_capacity_liters'] ?? null, 'Tank volume', 1, 100000, (float)$current['water_capacity_liters']);
        $new['water_threshold']       = v_float($in['water_threshold']       ?? null, 'Minimum water threshold', 0, 400, (float)$current['water_threshold']);
        $new['water_target']          = v_float($in['water_target']          ?? null, 'Target water level', 0, 400, (float)$current['water_target']);
        $new['water_max_duration']    = v_int($in['water_max_duration']      ?? null, 'Maximum refill duration', 1, WATER_MAX_SEC_LIMIT, (int)$current['water_max_duration']);
        $new['auto_water']            = to_bool($in['auto_water'] ?? $current['auto_water']);

        // --- cross-field rules ---
        if ($new['water_threshold'] >= $new['water_target']) {
            throw new ValidationException('The water threshold must be lower than the target water level, otherwise the pump would never stop.');
        }
        if ($new['water_target'] > $new['water_capacity']) {
            throw new ValidationException('The target water level cannot exceed the tank depth.');
        }
    }

    if (isset($touched['alerts'])) {
        $new['water_low_percent']      = v_float($in['water_low_percent']      ?? null, 'Low water threshold', 0, 100, (float)$current['water_low_percent']);
        $new['water_critical_percent'] = v_float($in['water_critical_percent'] ?? null, 'Critical water threshold', 0, 100, (float)$current['water_critical_percent']);
        $new['offline_timeout']        = v_int($in['offline_timeout']          ?? null, 'Device offline timeout', 30, 86400, (int)$current['offline_timeout']);
        $new['reading_interval']       = v_int($in['reading_interval']         ?? null, 'Reading interval', 5, 3600, (int)$current['reading_interval']);
        $new['notify_in_app']          = to_bool($in['notify_in_app'] ?? $current['notify_in_app']);
        $new['notify_email']           = to_bool($in['notify_email']  ?? $current['notify_email']);

        if ($new['water_critical_percent'] >= $new['water_low_percent']) {
            throw new ValidationException('The critical water threshold must be lower than the low water threshold.');
        }
        if ($new['offline_timeout'] < $new['reading_interval'] * 2) {
            throw new ValidationException('The offline timeout must be at least twice the reading interval, or the device would be flagged offline between normal reports.');
        }
    }

    // ------------------------------------------------------------------
    // Persist
    // ------------------------------------------------------------------
    $assignments = [];
    $params      = [];
    foreach ($new as $col => $val) {
        $assignments[] = "`{$col}` = ?";
        $params[]      = is_bool($val) ? ($val ? 1 : 0) : $val;
    }
    $assignments[] = '`updated_by` = ?';
    $params[]      = (int)$user['user_id'];
    $params[]      = $deviceId;

    db()->prepare(
        'UPDATE system_settings SET ' . implode(', ', $assignments) . ' WHERE device_id = ?'
    )->execute($params);

    log_activity('UPDATE_SETTINGS', sprintf(
        'Automation settings updated for %s (%s).',
        $device['device_name'],
        implode(', ', array_keys($touched))
    ), (int)$user['user_id']);

    // Push the change out to the device promptly instead of waiting for it to
    // notice the new settings_version on its own schedule.
    $settings = get_settings($deviceId);
    if (device_is_online($device['last_seen'], (int)$settings['offline_timeout'])) {
        try {
            queue_command($deviceId, 'reload_settings', null, (int)$user['user_id']);
        } catch (ValidationException) {
            // Already queued.
        }
    }

    return api_ok([
        'updated'          => array_keys($new),
        'settings_version' => (int)strtotime((string)$settings['updated_at']),
        'settings'         => device_config_payload($deviceId, $settings),
    ], 'Settings saved successfully');
});

/** Interpret checkbox-ish input consistently. */
function to_bool(mixed $v): bool
{
    if (is_bool($v)) {
        return $v;
    }
    return in_array(strtolower((string)$v), ['1', 'true', 'on', 'yes'], true);
}
