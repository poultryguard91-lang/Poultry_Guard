# Testing

Two kinds of cases: those **run and passed** during development (marked ✔ with how
they were run), and a **manual checklist** to run on real hardware and a
browser, which has not been executed yet (☐).

Automated run date: 2026-10-19, PHP 8.2 built-in server against a throwaway copy
of the schema + demo data (`pg_test`), driven with `curl`. The firmware has **not**
been compiled or run on a board by the author; hardware cases below are ☐.

## 1. Device API (curl, X-API-Key demo key)

| # | Case | Expected | Result |
|---|---|---|---|
| D1 | `sensor-data` with `water_percentage:22, temperature:33.4, humidity:61` | 200; stores water + climate; `heat_level: warning`; returns settings and pending commands | ✔ |
| D2 | `sensor-data` water 40 % after demo data | Latest reading becomes 40 % / 40 cm | ✔ |
| D3 | `dispensing` water run, `starting/ending_percentage` | 200; converted to cm; refill row created | ✔ |
| D4 | `dispensing` feed run with `duration_ms`, no weights | 200; row created without weight columns | ✔ |
| D5 | `sync` with one reading + one feed event | `synced: water 1, climate 1, dispensing 1`, `rejected: []` | ✔ |
| D6 | `status` heartbeat | 200; returns settings and pending commands | ✔ |
| D7 | `ack` of a delivered command | Command becomes `executed` | ✔ |
| D8 | Request without/with wrong API key | 401 | ☐ |
| D9 | Disabled device | 403 | ☐ |
| D10 | `sync` with 251 records | 422 "too large" | ☐ |
| D11 | Out-of-range water (`150`) or temperature (`90`) | Discarded with a warning, no row stored | ☐ |

## 2. Web API (session)

| # | Case | Expected | Result |
|---|---|---|---|
| W1 | Login as `staff` / `owner` | 200 with CSRF token and permissions | ✔ |
| W2 | Dashboard with `charts=1` | Has `water_level`, `climate`, `feed_runs`, `water_consumption`; feeder + climate objects | ✔ |
| W3 | `devices/status`, `water/read`, `settings/get`, `automation/commands` | 200 | ✔ |
| W4 | Owner: `reports/water`, `reports/alerts` | 200 | ✔ |
| W5 | Staff requests an owner-only report | 403 / page redirect | ✔ |
| W6 | Staff `feed/dispense` start, 5 s | Command queued; device receives `dispense_feed` with `duration_sec: 5` | ✔ |
| W7 | `feed/dispense` with `duration_sec: 99` | 422 "between 1 and 30" | ✔ |
| W8 | Staff `water/dispense` start | Command `refill_water` queued | ✔ |
| W9 | Staff `settings/update` (feed + water group) | Saved; new `settings_version` | ✔ |
| W10 | Staff `emergency-stop` | Command queued; `auto_water: false` | ✔ |
| W11 | Pages load for staff (dashboard, feed/water control, water monitoring, settings, devices, alerts) | HTTP 200, no PHP warnings in server log | ✔ |
| W12 | Staff opens Users page | Redirect | ✔ |
| W13 | State-changing request without CSRF token | 403 | ☐ |
| W14 | Staff sets alert thresholds | 403 | ☐ |
| W15 | Invalid settings (threshold ≥ target) | 422 with explanation | ☐ |
| W16 | Six wrong passwords | Lockout message, then 15 min wait | ☐ |
| W17 | `water/dispense` start while tank ≥ 99 % | 422 "already full" | ☐ |

## 2b. Scheduled feeding (server, curl as staff)

| # | Case | Expected | Result |
|---|---|---|---|
| F1 | Save `auto_feed:true` + two times out of order | 200; stored sorted (06:00, 17:30) | ✔ |
| F2 | Device `status` after saving | `auto_feed`, `feed_schedule`, `feed_max_duration` match | ✔ |
| F3 | Dashboard feeder object | `auto` and `schedule` shown | ✔ |
| F4 | Same time twice | 422 "listed twice" | ✔ |
| F5 | Time `25:00` | 422 | ✔ |
| F6 | Run time above the maximum | 422 | ✔ |
| F7 | Nine entries | 422 "at most 8" | ✔ |
| F8 | Lower the maximum below an existing entry | 422 "Shorten it first" | ✔ |
| F9 | Save water settings only | Feeding schedule unchanged | ✔ |
| F10 | Device posts the "clock not set" alert via `sync` | 200; alert created | ✔ |
| F11 | `init-db.php` on a database from before this feature | Adds `feed_schedule`; second run changes nothing | ✔ |
| F12 | Schedule editor in the browser: add/remove rows, save, reload | Rows persist | ☐ |

## 3. Database

| # | Case | Result |
|---|---|---|
| S1 | `poultryguard.sql` imports cleanly on MariaDB (XAMPP): 11 tables, 1,344 water + 1,344 climate readings, 28 feeder runs | ✔ |
| S2 | `deploy/init-db.php` on an existing schema creates `climate_readings` | ☐ |
| S3 | Deleting a device cascades to its readings and history | ☐ |

## 4. Static checks

| # | Case | Result |
|---|---|---|
| L1 | `php -l` on every PHP file | ✔ |
| L2 | `node --check` on every JavaScript file | ✔ |

## 5. Firmware and hardware checklist (☐ — run on a board)

1. **Compile** with ArduinoJson v7 and the DHT library, board *ESP32 Dev Module*.
2. `test` command: pump relay = IN1, feeder relay = IN2, both off afterwards.
3. Boot with Wi-Fi: IP printed; device shows **Online** within a minute.
4. DHT11: `status` shows temperature/humidity; dashboard climate card matches.
   Warm the sensor above 32 °C: serial warning and a dashboard alert.
5. Water sensor: dry / half / full readings give 0 % / mid / 100 %; calibrated
   constants set.
6. Automatic refill: lower the water to LOW for 3 readings → pump starts → stops at
   FULL; a refill row appears on the dashboard with method *automatic*.
7. Safety timer: block the supply; the refill stops at `PUMP_MAX_SEC`, auto refill
   switches off, an alert is raised.
8. Dashboard **Run feeder 3 s**: motor runs ~3 s; command history shows *Executed*.
9. Dashboard **Stop feeder** during a 20 s run: motor stops within ~5 s.
10. Dashboard **Emergency stop**: both off, automatic watering off in Settings.
11. Serial `pump on`, `pump 5`, `feed 4`, `stop`, `auto off`, `auto on`.
12. **Offline**: disconnect the router. Auto refill still works; reconnect —
    buffered readings and runs appear with their original times, source
    *offline_sync*, and the "reconnected" alert is raised.
13. Wrong API key in `config.h`: device never goes Online; serial shows HTTP 401.
14. Server stopped while the pump is filling: pump still stops at FULL (allow a few
    seconds of delay while the HTTP request times out).
15. **Scheduled feeding**: set a time 2 minutes ahead (3 s). The feeder runs once at
    that minute, the history shows *automatic* with note "Scheduled feed", and no
    second run happens.
16. Reset the board right after a scheduled feed (inside the 2-minute window): it
    does **not** feed again.
17. Unplug the router, reboot the board (no internet): scheduled feeds are skipped,
    the serial monitor says the clock is not set, and a dashboard alert appears
    once Wi-Fi returns.
18. Start a manual feed a few seconds before a slot: the slot is skipped or runs
    after it, never overlapping.
19. Dashboard Emergency stop: scheduled feeding switches off (Settings shows Off).
20. Power-cycle both relays' loads: neither motor starts at boot.

## 6. Known limitations

* A request to an unreachable or slow server can delay the control loop by about
  4–6 s (HTTP timeout). Retries back off to every 30 s while the server is down.
* The offline buffer is in RAM: a reboot loses unsent readings and runs.
* No automated browser/UI tests; the JavaScript pages are covered only by syntax
  checks and the API cases above.
