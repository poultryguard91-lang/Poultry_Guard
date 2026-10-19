<?php
/**
 * GET /api/devices/status.php[?device_id=1]
 *
 * Live status for every registered device (or one), including sensor and
 * actuator state, buffered command queue and recent activity counts.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/api.php';

api_require_method('GET');

api_run(function (array $in): ApiResult {
    require_permission('devices.view');

    if (!empty($in['device_id'])) {
        $device  = api_resolve_device($in);
        $devices = [$device];
    } else {
        $devices = get_devices();
    }

    $rows = [];
    foreach ($devices as $d) {
        $deviceId = (int)$d['device_id'];
        $settings = get_settings($deviceId);
        $online   = device_is_online($d['last_seen'], (int)$settings['offline_timeout']);

        $feed  = latest_feed_reading($deviceId);
        $water = latest_water_reading($deviceId);

        // How busy the device has been in the last day. Positional placeholders
        // are used because a native prepared statement cannot bind one named
        // placeholder to several occurrences.
        $act = db()->prepare(
            'SELECT
                (SELECT COUNT(*) FROM feed_readings    WHERE device_id = ? AND `timestamp` >= DATE_SUB(NOW(), INTERVAL 1 DAY)) AS feed_readings,
                (SELECT COUNT(*) FROM water_readings   WHERE device_id = ? AND `timestamp` >= DATE_SUB(NOW(), INTERVAL 1 DAY)) AS water_readings,
                (SELECT COUNT(*) FROM feed_dispensing  WHERE device_id = ? AND `timestamp` >= DATE_SUB(NOW(), INTERVAL 1 DAY)) AS feed_events,
                (SELECT COUNT(*) FROM water_dispensing WHERE device_id = ? AND `timestamp` >= DATE_SUB(NOW(), INTERVAL 1 DAY)) AS water_events,
                (SELECT COUNT(*) FROM alerts           WHERE device_id = ? AND status = \'unread\') AS unread_alerts,
                (SELECT COUNT(*) FROM device_commands  WHERE device_id = ? AND status IN (\'pending\',\'sent\')) AS queued_commands'
        );
        $act->execute(array_fill(0, 6, $deviceId));
        $activity = $act->fetch() ?: [];

        $rows[] = [
            'device_id'        => $deviceId,
            'device_name'      => $d['device_name'],
            'esp32_uid'        => $d['esp32_uid'],
            'location'         => $d['location'],
            'status'           => $d['status'],
            'online'           => $online,
            'connection'       => $online ? 'ONLINE' : 'OFFLINE',
            'last_seen'        => $d['last_seen'],
            'last_seen_ago'    => time_ago($d['last_seen']),
            'firmware_version' => $d['firmware_version'],
            'ip_address'       => $d['ip_address'],
            'wifi_rssi'        => $d['wifi_rssi'] !== null ? (int)$d['wifi_rssi'] : null,
            'wifi_quality'     => rssi_quality($d['wifi_rssi'] !== null ? (int)$d['wifi_rssi'] : null),
            'feed_sensor'      => $online ? $d['feed_sensor_status']  : 'unknown',
            'water_sensor'     => $online ? $d['water_sensor_status'] : 'unknown',
            'motor_status'     => $online ? $d['motor_status']    : 'unknown',
            'solenoid_status'  => $online ? $d['solenoid_status'] : 'unknown',
            'auto_feed'        => (bool)$settings['auto_feed'],
            'auto_water'       => (bool)$settings['auto_water'],
            'offline_timeout'  => (int)$settings['offline_timeout'],
            'feed_weight'      => $feed  ? (float)$feed['feed_weight'] : null,
            'feed_percentage'  => $feed  ? (float)$feed['feed_percentage'] : null,
            'water_level'      => $water ? (float)$water['water_level'] : null,
            'water_percentage' => $water ? (float)$water['water_percentage'] : null,
            'activity'         => array_map('intval', $activity),
            'created_at'       => $d['created_at'],
        ];
    }

    return api_ok([
        'devices' => $rows,
        'summary' => [
            'total'   => count($rows),
            'online'  => count(array_filter($rows, static fn(array $r): bool => $r['online'])),
            'offline' => count(array_filter($rows, static fn(array $r): bool => !$r['online'])),
        ],
    ], 'Device status retrieved successfully');
});

/** Turn an RSSI figure into a word an operator can act on. */
function rssi_quality(?int $rssi): string
{
    if ($rssi === null) {
        return 'unknown';
    }
    if ($rssi >= -60) {
        return 'excellent';
    }
    if ($rssi >= -70) {
        return 'good';
    }
    if ($rssi >= -80) {
        return 'fair';
    }
    return 'weak';
}
