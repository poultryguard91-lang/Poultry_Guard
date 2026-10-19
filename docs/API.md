# API reference

All endpoints live under `/api/` and return the same JSON envelope:

```json
{ "success": true, "message": "Settings saved successfully", "data": { } }
```

On failure `success` is `false`, `data` is `null`, and `message` is safe to show
to a user.

| HTTP | Meaning |
|---|---|
| 200 | OK |
| 400 | Body is not valid JSON |
| 401 | Not signed in / bad device credentials |
| 403 | Not permitted, bad CSRF token, or device disabled |
| 404 | Device or record not found |
| 405 | Wrong HTTP method (`Allow` header lists the right ones) |
| 413 | Body larger than 256 KB |
| 422 | Validation failed (`message` says which field) |
| 500 / 503 | Server or database problem (details are in `logs/`, never in the response) |

Input may be a JSON body (`Content-Type: application/json`), a form body or the
query string; JSON wins over form, form over query.

## Authentication

**Browser endpoints** use the PHP session cookie from `POST /api/auth/login.php`.
Every state-changing request must also carry the CSRF token returned by login,
either as a `csrf_token` field or an `X-CSRF-Token` header. Each endpoint checks
a permission (below).

**Device endpoints** (`/api/esp32/*`) use the device key:

```
X-API-Key: <key shown when the device was registered>
```

and identify the device by `esp32_uid` in the body. The key is compared in
constant time; a disabled device gets 403.

### Roles and permissions

| Permission | Owner | Staff |
|---|---|---|
| `dashboard.view`, `monitoring.view`, `alerts.view`, `alerts.manage`, `devices.view`, `settings.view` | ✓ | ✓ |
| `automation.control`, `settings.thresholds` | | ✓ |
| `settings.alerts`, `reports.view`, `users.manage`, `devices.manage`, `logs.view` | ✓ | |

---

## Authentication

### `POST /api/auth/login.php`
Body `{ "username", "password" }`. Five failed attempts from one IP/username
lock sign-in for 15 minutes. Returns `user`, `permissions`, `csrf_token`,
`redirect`.

### `POST /api/auth/logout.php`
Requires CSRF. Ends the session.

---

## Dashboard and monitoring (session)

### `GET /api/dashboard/index.php?device_id=1[&charts=1&hours=24]`
`dashboard.view`. One request for the whole dashboard:

* `device` — online state, firmware, IP, Wi-Fi RSSI, water sensor / feeder /
  pump status
* `feed` — `running`, `max_duration`, `auto` (scheduled feeding on), `schedule`
  (`[{time, seconds}]`), `last_run`, `last_run_ago`, `last_run_seconds`,
  `last_run_method`
* `water` — `level`, `percentage`, `liters`, `status` (NORMAL/LOW/CRITICAL),
  thresholds, last refill
* `climate` — `temperature`, `humidity`, `heat_level` (NORMAL/WARNING/DANGER),
  `warn_c`, `danger_c`
* `alerts` — counts and the 8 most recent
* `today` — `feed_runs`, `feed_seconds`, `water_consumed_l`, `water_events`
* with `charts=1`: `charts.water_level`, `charts.climate`, `charts.feed_runs`,
  `charts.water_consumption`

It also raises a `device_offline` alert for any active device that has been silent
longer than its timeout.

### `GET /api/water/read.php?device_id=1&hours=24`
`monitoring.view`. Current water state, trend series and recent refill log.

### `GET /api/devices/status.php[?device_id=1]`
`devices.view`. Per-device status, latest `temperature` / `humidity` /
`water_level` / `water_percentage`, 24 h activity counts, queued commands.

---

## Manual control (session + CSRF, `automation.control`)

Commands are **queued**; the ESP32 collects them (about every 5 s), runs them and
acknowledges. Commands not collected within 5 minutes expire.

### `POST /api/feed/dispense.php`
```json
{ "device_id": 1, "action": "start", "duration_sec": 5 }
```
`duration_sec` is 1 – the device's *Maximum motor run time* (default 3).
`action: "stop"` queues a stop and is allowed even when offline. Start is refused
if the device is offline or the feeder is already running.

### `POST /api/water/dispense.php`
```json
{ "device_id": 1, "action": "start" }
```
The pump runs until the sensor reads FULL or the safety timeout. Refused if
offline, already running, or the tank is already ≥ 99 %. `action: "stop"` always
allowed.

### `POST /api/automation/emergency-stop.php`
`{ "device_id": 1 }`. Queues `emergency_stop` and switches automatic watering off
in the settings so nothing restarts. Accepted while offline.

### `GET /api/automation/commands.php?device_id=1&limit=15`
`devices.view`. Recent commands with `status` (`pending` → `sent` → `executed` /
`failed` / `expired`), result text and the device's current actuator state.

---

## Settings (session)

### `GET /api/settings/get.php?device_id=1`
`settings.view`. Returns `feed`, `water`, `alerts` groups and which groups the
caller may edit.

### `POST /api/settings/update.php` (CSRF)
Send any fields of one or both groups:

| Group (permission) | Fields |
|---|---|
| thresholds (`settings.thresholds`) | `feed_max_duration`, `auto_feed` (scheduled feeding), `feed_schedule`, `water_capacity`, `water_capacity_liters`, `water_threshold`, `water_target`, `water_max_duration`, `auto_water` |
| alerts (`settings.alerts`) | `water_low_percent`, `water_critical_percent`, `offline_timeout`, `reading_interval`, `notify_in_app`, `notify_email` |

Cross-field rules are enforced (threshold < target ≤ depth; critical < low;
offline timeout ≥ 2 × reading interval). `feed_schedule` is a list of `{"time": "HH:MM", "seconds": n}`: at most 8 entries,
unique times, each `seconds` from 1 to `feed_max_duration` (the list replaces the
stored one; omit it to keep the current schedule; lowering `feed_max_duration`
below an existing entry is refused). A request touching a group the caller
does not hold is refused as a whole. The device picks changes up through
`settings_version`.

---

## Alerts, devices, users, reports (session)

| Endpoint | Permission | Notes |
|---|---|---|
| `GET /api/alerts/get.php` | `alerts.view` | Filters `device_id, severity, status, alert_type, from, to, page, per_page` |
| `POST /api/alerts/read.php` | `alerts.manage` + CSRF | `{alert_id, status}` or `{action:"read_all"\|"resolve_all", device_id}` |
| `POST /api/devices/save.php` | `devices.manage` + CSRF | `action`: `create`, `update`, `toggle`, `regenerate_key`. The API key is returned **once** |
| `GET /api/users/get.php` | `users.manage` | Users and recent activity log (no password hashes) |
| `POST /api/users/save.php` | `users.manage` + CSRF | `action`: `create`, `update`, `toggle`, `password` |
| `GET /api/reports/water.php` | `reports.view` | `device_id, from, to, period=daily\|weekly\|monthly` |
| `GET /api/reports/alerts.php` | `reports.view` | System report: alert frequency, uptime estimate, sensor activity, manual vs automatic, activity log |

---

## Device endpoints (`X-API-Key`)

### `POST /api/esp32/sensor-data.php`
Telemetry (every 30 s).
```json
{
  "esp32_uid": "ESP32-A1B2C3D4E5F6",
  "firmware_version": "2.0.0", "ip_address": "192.168.1.45", "wifi_rssi": -58,
  "water_percentage": 62, "temperature": 29.5, "humidity": 70,
  "water_sensor_status": "ok", "motor_status": "idle", "solenoid_status": "running"
}
```
`solenoid_status` is the water pump. The `settings` object also carries `auto_feed`, `feed_schedule`, `feed_max_duration`,
`water_*` limits and `auto_water`. Returns `stored` (water level and %,
temperature, humidity, heat level), `warnings`, `server_time`, `settings`
(including `settings_version`) and `pending_commands`. Invalid values are
discarded with a warning rather than failing the request; a temperature ≥ 32 °C /
35 °C raises a WARNING / CRITICAL alert.

### `POST /api/esp32/status.php`
Lightweight heartbeat (every 5 s): same identity/status fields, returns `settings`
and `pending_commands`. Also records "device reconnected" after an outage.

### `POST /api/esp32/dispensing.php`
A finished run.
```json
{ "esp32_uid": "…", "type": "water", "status": "completed",
  "dispensing_method": "automatic", "duration_ms": 20000,
  "starting_percentage": 20, "ending_percentage": 100, "target_percentage": 100 }
{ "esp32_uid": "…", "type": "feed", "status": "completed",
  "dispensing_method": "manual", "duration_ms": 3000, "user_id": 3, "notes": "Timed feed, 3 s" }
```
A scheduled feed is reported as `dispensing_method: "automatic"` with notes
"Scheduled feed, N s". `status`: `completed`, `failed`, `aborted`, `timeout`. `dispensing_method`:
`automatic`, `manual`, `offline`. Optional `timestamp` (`Y-m-d H:i:s`, accepted if
within the last 30 days and not more than 5 min in the future, else server time).
`timeout` and `failed` raise alerts.

### `POST /api/esp32/ack.php`
`{ "command_id": 12, "status": "executed"|"failed", "result": "…" }`. Scoped to the
calling device; repeating an acknowledgement is harmless. A failure raises an alert.

### `POST /api/esp32/sync.php`
Offline catch-up, at most 250 records, written in **one transaction**:
```json
{ "esp32_uid": "…",
  "readings": [ { "timestamp": "2026-10-19 09:30:00", "water_percentage": 55,
                  "temperature": 30.1, "humidity": 70 } ],
  "dispensing": [ { "type": "feed", "status": "completed", "duration_ms": 3000 } ],
  "alerts": [ { "alert_type": "info", "severity": "INFO", "message": "…" } ] }
```
The firmware also uses `alerts` to report that scheduled feeding is on but its
clock is not set (`system_error`, WARNING). Returns per-type counts and any `rejected` records with the reason. Replayed
readings are stored with `source = offline_sync`.

### `GET|POST /api/esp32/config.php`
Returns the device profile (`settings`, `server_time`, name, location).

### Commands delivered to the device

`dispense_feed` (`payload.duration_sec`, `payload.user_id`), `stop_feed`,
`refill_water` (`payload.user_id`), `stop_water`, `emergency_stop`,
`reload_settings`, `reboot`.
