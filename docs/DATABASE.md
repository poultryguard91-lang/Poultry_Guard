# Database reference

MySQL 8 / MariaDB 10.4, InnoDB, `utf8mb4`. The complete schema and demo data are in
`database/poultryguard.sql` (which **drops and recreates** the `poultryguard`
database — never import it over live data).

## Units

| Quantity | Unit |
|---|---|
| Water level | % from the level sensor, stored also as cm of depth (`% × tank depth`) |
| Temperature / humidity | °C / % RH |
| Feeder run | milliseconds (`duration_ms`); limits in seconds |
| Timestamps | server local time (`PG_TIMEZONE`, default `Asia/Manila`) |

## Entity overview

```
users ─┬─< feed_dispensing >─┐
       ├─< water_dispensing >┤
       ├─< device_commands >─┤
       └─< system_settings   │
                             ├─ devices ─┬─< water_readings
                             │           ├─< climate_readings
                             │           └─< alerts
activity_logs, login_attempts (standalone)
```

Every device-owned table has a foreign key to `devices(device_id)` with
`ON DELETE CASCADE`: deleting a device removes its history.

## Tables

### `users`
Accounts. `password` is a bcrypt hash from `password_hash()`. `role` is `owner` or
`staff`; `status` is `active` or `inactive`. Unique `username` and `email`.

### `devices`
One row per ESP32. `esp32_uid` and `api_key` are unique; the key is sent in the
`X-API-Key` header. Status columns updated by the firmware:
`water_sensor_status` (`ok/error/unknown`), `motor_status` (feeder) and
`solenoid_status` (water pump) (`idle/running/error`), plus `firmware_version`,
`ip_address`, `wifi_rssi`, `last_seen`. `status` is `active` or `disabled`.

### `water_readings`
Water level samples: `water_level` (cm), `water_percentage`, `distance_cm`
(derived sensor-to-surface distance, kept so charts and reports share one
pipeline), `source` (`live` or `offline_sync`), `timestamp`. Index on
`(device_id, timestamp)`.

### `climate_readings`
DHT11 samples: `temperature_c`, `humidity_pct`, `heat_level`
(`normal`/`warning`/`danger`), `source`, `timestamp`. Index on
`(device_id, timestamp)`.

### `feed_dispensing`
Every feeder motor run: `dispensing_method` (`automatic/manual/offline`),
`status` (`pending/running/completed/failed/aborted/timeout`), `duration_ms`,
`notes`, `user_id` (set for manual runs). Installs created before the move to a
timed feeder may still carry the old nullable weight columns; they are unused.

### `water_dispensing`
Every pump run: `starting_level`, `ending_level`, `target_level` (cm),
`dispensing_method`, `status`, `duration_ms`, `notes`, `user_id`.

### `system_settings`
One row per device (unique `device_id`). The server is the source of truth; the
firmware applies the relevant values.

| Column | Meaning |
|---|---|
| `feed_max_duration` | Feeder safety cut-off (s) |
| `water_capacity`, `water_capacity_liters` | Tank depth (cm) and volume at full |
| `water_threshold`, `water_target` | Refill starts below / ends at (cm) |
| `water_max_duration` | Pump safety cut-off (s) |
| `auto_water` | Automatic refill on/off |
| `water_low_percent`, `water_critical_percent` | Alert levels |
| `offline_timeout` | Seconds of silence before a device counts as offline |
| `reading_interval` | Expected seconds between uploads |
| `notify_in_app`, `notify_email` | Notification policy |
| `sensor_offset_cm` | Internal; used by the percentage→distance conversion (leave at 0) |
| `updated_at` | Doubles as `settings_version` (its Unix time) |

`auto_feed` and the old feed capacity/threshold/target/tolerance columns may still
exist on older installs and are unused.

### `alerts`
`alert_type` (`low_water`, `critical_water`, `sensor_failure`, `device_offline`,
`internet_disconnected`, `motor_error`, `solenoid_error`, `abnormal_reading`,
`system_error`, `dispensing_timeout`, `info`, plus the legacy `low_feed` /
`critical_feed`), `severity` (`INFO/WARNING/CRITICAL`), `message`, `status`
(`unread/read/resolved`). Heat alerts use `abnormal_reading`. Duplicates of the
same type for the same device are suppressed inside a cooldown window
(30 min by default).

### `device_commands`
The manual-control queue. `command` ∈ `dispense_feed, stop_feed, refill_water,
stop_water, reload_settings, emergency_stop, reboot`; `payload` is JSON;
`status` ∈ `pending → sent → executed | failed | expired`. Commands still pending
after 5 minutes are marked `expired`.

### `activity_logs`
Audit trail: `action`, `description`, `user_id` (NULL for device actions),
`ip_address`.

### `login_attempts`
Failed/successful sign-ins used for throttling (5 failures → 15 minute lockout).

## Calculations

**Percentage → depth.** The firmware sends `water_percentage`. The server stores
`water_level = percentage × water_capacity / 100` and a derived
`distance_cm = sensor_offset_cm + water_capacity − water_level`.

**Water consumption** (reports and dashboard) is a mass balance per period:

```
consumed = (first level in period − last level in period) + (total added by refills in period)
```

floored at 0, converted to litres with `water_capacity_liters / water_capacity`.

**Alert levels.** `level_status(pct, low, critical)` returns CRITICAL below
`critical`, LOW below `low`, otherwise NORMAL.

**Uptime estimate** (system report) = readings received ÷ readings the
`reading_interval` should have produced, capped at 100 %.

## Maintenance

* Back up with `mysqldump poultryguard`.
* `water_readings` and `climate_readings` grow by about 2,900 rows per device per
  day at the default 30 s interval; prune old rows if disk is tight, e.g.
  `DELETE FROM climate_readings WHERE timestamp < NOW() - INTERVAL 1 YEAR;`.
* Existing databases get the `climate_readings` table (and a relaxed
  `feed_dispensing.target_weight`) from `deploy/init-db.php` on the next
  Railway deploy. On XAMPP, run the `CREATE TABLE IF NOT EXISTS climate_readings`
  block from the schema file.
