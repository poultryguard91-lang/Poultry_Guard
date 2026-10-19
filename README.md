# PoultryGuard

**Precision IoT Automation for Poultry Feeding and Hydration**

A web-based IoT management system for small-to-medium poultry farms. An ESP32
controller reads the water tank level and the house temperature/humidity,
refills water automatically, runs a timed feeder, and reports everything to a
PHP + MySQL web application where the farm can monitor, control and analyse it.

```
   Water level sensor ─┐                        ┌─ Web dashboard (Bootstrap + Chart.js)
   DHT11 (temp/hum) ───┼─► ESP32 ─► Wi-Fi ─► PHP REST API ─► MySQL
                       │     │                  ▲
              relay ─► water pump (12 V)         └─ settings and commands pushed to the device
              relay ─► feeder motor (12 V)
```

The single most important property: **the ESP32 keeps feeding and watering when
the network is down.** Every control decision is made on the device from
settings cached in its own flash. The server is where those settings are edited
and where history is analysed — it is never in the control loop.

---

## Contents

| Path | What it is |
|---|---|
| `api/` | REST endpoints — one folder per area, plus `api/esp32/` for the device |
| `assets/` | CSS, JavaScript, and locally vendored Bootstrap 5 + Chart.js |
| `config/` | Database credentials and application bootstrap |
| `database/poultryguard.sql` | Complete schema, constraints, indexes and demo data |
| `docs/` | Installation, API reference, database reference, user manual, testing, hardware |
| `firmware/PoultryGuard_ESP32/` | Arduino sketch and `config.h` for the controller |
| `includes/` | Auth, RBAC, shared helpers, page shell |
| `dashboard/ monitoring/ automation/ reports/ alerts/ devices/ settings/ users/` | The pages |

---

## Quick start (XAMPP on Windows)

1. **Put the project in `htdocs`** so it lives at `C:\xampp\htdocs\PoultryGuard`.

2. **Start Apache and MySQL** from the XAMPP Control Panel.

3. **Import the database.** Open <http://localhost/phpmyadmin> → **Import** →
   choose `database/poultryguard.sql` → **Go**. This creates the `poultryguard`
   database, all eleven tables, the demo devices and two weeks of sample
   readings so the dashboard has something to show immediately.

4. **Open <http://localhost/PoultryGuard>** and sign in:

   | Role | Username | Password |
   |---|---|---|
   | Farm Owner | `owner` | `Admin@123` |
   | Farm Staff | `staff` | `Staff@123` |

   Change both passwords before any real use (Users → Password).

5. **Flash the ESP32** with `firmware/PoultryGuard_ESP32/` after editing `config.h`
   (Wi-Fi, server address, device UID and API key). The demo data lets the
   dashboard show something before the board is connected.

Full instructions, including the ESP32 side: **[docs/INSTALLATION.md](docs/INSTALLATION.md)**

---

## The two roles

Permissions are defined once, in `ROLE_PERMISSIONS` in `includes/auth.php`, and
the same strings guard the pages, the menu items and every API endpoint — so
the interface can never offer something the server would refuse.

| | Farm Owner | Farm Staff |
|---|---|---|
| Dashboard, monitoring, alerts, device status | ✓ | ✓ |
| Manual feed / water control | | ✓ |
| Feeder & water settings | | ✓ |
| Alert thresholds & notifications | ✓ | |
| Reports | ✓ | |
| Devices, users, activity log | ✓ | |

---

## How the important parts work

**Water level.** The level strip (or float switch) is read on the ESP32, converted
to a percentage using the `WATER_LOW_VALUE` / `WATER_FULL_VALUE` calibration at
the top of the sketch, and uploaded. When the tank reads LOW for three readings
in a row the pump starts; it stops when the tank reads FULL, or at the safety
time limit.

**Climate.** The DHT11 reports temperature and humidity every 30 s. The server
raises a warning alert at 32 °C and a critical alert at 35 °C.

**Timed feeder.** There is no feed scale: the feeder motor runs for a set number
of seconds, never longer than the configured safety limit. It can be started
manually from the dashboard, or **on a schedule**: up to 8 daily feeding times are
stored on the ESP32 and run from its own clock, so feeding continues without the
network. If the clock is not set, scheduled feeds are skipped and an alert is raised.

**Water consumption** is a mass balance, not a sum of what was refilled:

```
consumed = (level at start of period − level at end) + (everything added during it)
```

**Manual control never touches the hardware directly.** The web app queues a
command; the ESP32 collects it on its next check-in, runs the pump or
feeder and stops it itself, then acknowledges the outcome. The firmware stays the only
authority over the relays, which is exactly what makes the offline behaviour
safe. Commands older than five minutes expire rather than being delivered.

**Safety.** Every pump and feeder run is bounded by a maximum duration, a minimum
rest between automatic refills, a "refill took too long" check that turns
automation off, and an emergency stop that also switches automation off so
nothing restarts on its own.

---

## Technology

PHP 8+ with PDO prepared statements · MySQL 8 / MariaDB 10.4 (InnoDB) ·
Bootstrap 5 · Chart.js 4 · vanilla JavaScript with `fetch` · Apache · ESP32
(Arduino C++) with the DHT library and ArduinoJson.

Bootstrap and Chart.js are **vendored locally** in `assets/vendor/`, so the
dashboard works on a farm LAN with no internet connection.

## Security

bcrypt password hashing · PHP sessions with idle timeout and fixation
protection · role-based authorisation on every endpoint · PDO prepared
statements throughout · CSRF tokens on every state-changing request · login
throttling · per-device API keys compared in constant time · output escaping ·
technical errors logged rather than displayed.

Details: **[docs/TESTING.md](docs/TESTING.md)** covers the security test cases.

---

## Documentation

| Document | Covers |
|---|---|
| [docs/INSTALLATION.md](docs/INSTALLATION.md) | XAMPP setup, database import, ESP32 flashing, troubleshooting |
| [docs/HARDWARE.md](docs/HARDWARE.md) | Wiring, power, bill of materials |
| [docs/API.md](docs/API.md) | Every endpoint, with request and response examples |
| [docs/DATABASE.md](docs/DATABASE.md) | Tables, relationships, indexes, the calculations |
| [docs/USER_MANUAL.md](docs/USER_MANUAL.md) | How to operate the system, page by page |
| [docs/TESTING.md](docs/TESTING.md) | Test cases and results across all areas |

---

## Licence and intent

Built as a capstone prototype for small-to-medium-scale poultry farms.
The demo data in `poultryguard.sql` is clearly labelled as such in the interface
and can be deleted once real devices are registered.
