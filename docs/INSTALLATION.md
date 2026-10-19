# PoultryGuard — Installation Guide

Everything needed to get from a fresh machine to a running system, then to a
working ESP32 controller.

---

## 1. What you need

**Software**

| | Minimum | Notes |
|---|---|---|
| XAMPP | 8.0+ | Provides Apache, PHP and MariaDB together |
| PHP | 8.0 | 8.2 recommended. Needs `pdo_mysql`; `curl` for the simulator |
| MySQL / MariaDB | MySQL 8.0 or MariaDB 10.4 | The schema is written to run on both |
| Browser | Any current Chrome, Edge, Firefox or Safari | Including mobile |
| Arduino IDE | 2.x (or 1.8.19) | Only if you are flashing real hardware |

**Hardware** (optional — the simulator replaces it for demos)

See [HARDWARE.md](HARDWARE.md) for the full bill of materials and wiring.

---

## 2. Install XAMPP

1. Download XAMPP from <https://www.apachefriends.org> and install it to
   `C:\xampp` (the default).
2. Open the **XAMPP Control Panel**.
3. Press **Start** next to **Apache** and next to **MySQL**. Both should turn
   green.

If Apache will not start, another program is usually holding port 80 — often
Skype, IIS, or Windows' own World Wide Web Publishing Service. Either stop that
program, or click **Config → httpd.conf** in XAMPP and change `Listen 80` to
`Listen 8080` (then use `http://localhost:8080/...` everywhere below).

---

## 3. Put the project in place

Copy the whole `PoultryGuard` folder into `C:\xampp\htdocs` so that this path
exists:

```
C:\xampp\htdocs\PoultryGuard\index.php
```

The application works out its own URL prefix, so it runs equally well from
`htdocs\PoultryGuard`, from a differently named folder, or from the root of a
virtual host.

---

## 4. Import the database

### Using phpMyAdmin (easiest)

1. Open <http://localhost/phpmyadmin>.
2. Click **Import** in the top menu.
3. **Choose File** → `C:\xampp\htdocs\PoultryGuard\database\poultryguard.sql`.
4. Leave everything else as it is and click **Go**.

You should see *"Import has been successfully finished"*. A `poultryguard`
database now appears in the left sidebar with 14 tables.

> The script begins with `DROP DATABASE IF EXISTS poultryguard`. Re-importing
> is how you reset to a clean demo state — but it **erases any real data**, so
> do not re-import a system that is in production.

### Using the command line

```
cd C:\xampp\mysql\bin
mysql.exe -u root -p < C:\xampp\htdocs\PoultryGuard\database\poultryguard.sql
```

Press Enter at the password prompt if your MySQL root account has no password
(the XAMPP default).

### What the import creates

* 14 InnoDB tables with foreign keys and indexes
* 3 user accounts (2 owners, 1 staff)
* 2 demo devices with automation settings and calibration values
* ~1,344 sensor readings covering the last 14 days
* 56 dispensing events, 10 alerts, 8 activity log entries
* 2 flocks with weigh-ins, so the FCR report works immediately

---

## 5. Check the database settings

`config/database.php` defaults to the XAMPP standard: host `127.0.0.1`, user
`root`, empty password. If your MySQL differs, either edit that file or set
environment variables (`PG_DB_HOST`, `PG_DB_USER`, `PG_DB_PASS`, `PG_DB_NAME`),
which take priority and keep credentials out of the source tree.

---

## 6. First sign-in

Open <http://localhost/PoultryGuard>.

| Role | Username | Password |
|---|---|---|
| Farm Owner | `owner` | `Admin@123` |
| Farm Owner | `jdelacruz` | `Owner@123` |
| Farm Staff | `staff` | `Staff@123` |

**Change these immediately.** Sign in as `owner`, go to **Users**, and use
**Password** on each account.

If you instead see a "Database connection failed" page, it lists exactly what to
check — it means Apache is serving the files but MySQL is unreachable or the
schema was never imported.

---

## 7. Run it without hardware

The simulator speaks the same REST protocol as the firmware, so you can
demonstrate and test the entire system before any electronics exist.

```
cd C:\xampp\htdocs\PoultryGuard
php tools\device_simulator.php
```

Useful options:

```
php tools\device_simulator.php --speed=1800      # a day of consumption in ~48 s
php tools\device_simulator.php --device=2        # act as the second demo house
php tools\device_simulator.php --offline=30      # run offline first, then sync
php tools\device_simulator.php --once            # send one reading and exit
php tools\device_simulator.php --help
```

Leave the dashboard open in a browser while it runs. You will see the hopper
drain, the auger cut in when it crosses the threshold, the level jump back to
the target, and alerts appear. With `--offline=30` you can watch it keep feeding
with the server unreachable and then upload everything it buffered.

If it reports that it cannot reach the server, check that Apache is running and
pass the right URL: `--url=http://localhost/PoultryGuard`.

---

## 8. Set up the ESP32

### 8.1 Install the board support and libraries

1. In the Arduino IDE: **File → Preferences → Additional Board Manager URLs**,
   add:
   ```
   https://raw.githubusercontent.com/espressif/arduino-esp32/gh-pages/package_esp32_index.json
   ```
2. **Tools → Board → Boards Manager**, search `esp32`, install
   **esp32 by Espressif Systems**.
3. **Tools → Manage Libraries**, install:
   * **HX711 Arduino Library** by Bogdan Necula
   * **ArduinoJson** by Benoit Blanchon (v6 or v7)

### 8.2 Register the device

1. Sign in as a Farm Owner → **Devices** → **Add device**.
2. Give it a name and location, and enter the **ESP32 UID**.
   If you have not flashed it yet, invent one now (for example
   `ESP32-KITCHEN-01`) and use the same string in `config.h`.
3. Save. **The API key is shown once.** Copy it — it cannot be displayed again,
   only replaced.

### 8.3 Configure the firmware

Open `firmware/PoultryGuard_ESP32/PoultryGuard_ESP32.ino` in the Arduino IDE,
switch to the `config.h` tab, and edit the first two sections:

```c
#define WIFI_SSID       "YourWiFiName"
#define WIFI_PASSWORD   "YourWiFiPassword"

#define SERVER_BASE_URL "http://192.168.1.10/PoultryGuard"
#define DEVICE_UID      "ESP32-KITCHEN-01"
#define API_KEY         "the key you just copied"
```

`SERVER_BASE_URL` must be the **LAN IP address of the computer running XAMPP**,
not `localhost` — on the ESP32, `localhost` means the ESP32 itself. Find it on
Windows with `ipconfig` and look for the IPv4 Address, e.g. `192.168.1.10`.

The ESP32 and the server must be on the same network, and Windows Firewall must
allow inbound connections to Apache. If the device cannot connect, temporarily
allow port 80 for Private networks and try again.

Also check the pin numbers in section 3 of `config.h` against how you actually
wired the board, and `RELAY_ACTIVE_LOW` against your relay module (most cheap
boards are active-low, which is the default).

### 8.4 Flash

1. Connect the ESP32 over USB.
2. **Tools → Board → ESP32 Arduino → ESP32 Dev Module**.
3. **Tools → Port →** the COM port that appeared.
4. Click **Upload**. Hold the BOOT button if the IDE waits at
   *"Connecting…"*.
5. **Tools → Serial Monitor**, set to **115200 baud**.

A healthy boot looks like this:

```
========================================
 PoultryGuard ESP32 Controller
 Firmware 1.0.0
 Device UID: ESP32-KITCHEN-01
========================================
[CFG] Settings loaded from NVS:
      Feed  : refill below 2500 g, up to 9000 g (auto ON)
      Water : refill below 30.0 cm, up to 90.0 cm (auto ON)
[SEN] Feed 7250 g (72.5%) ok | Water 78.0 cm (78.0%) dist 32.0 cm ok
[NET] Connecting to Wi-Fi "YourWiFiName" ...
[NET] Connected. IP 192.168.1.45, RSSI -58 dBm
[CFG] Settings refreshed from the server.
[SYS] Ready. Local automation is active.
```

Within a minute the device should show as **Online** on the Devices page.

### 8.5 Calibrate

Do this before trusting any reading. The full procedure is in
[HARDWARE.md](HARDWARE.md); briefly:

1. **Sensors → Calibration**, with the device online.
2. Empty the hopper, press **Tare scale**, and copy the new raw offset from the
   serial monitor into **Tare offset**.
3. Put a known mass on the hopper (a 1 kg weight is ideal), read the raw value
   shown on the page, enter it with the reference weight, and save.
4. For water, measure the usable tank depth and the gap between the sensor face
   and the full-water line, enter both, and save. The page previews what the
   current distance reading would become before you commit.

---

## 9. Going to production

Before this leaves a workbench:

- [ ] Change every default password.
- [ ] Delete the demo devices (Devices → Disable, then remove their rows) or
      re-import a schema with the sample data section removed.
- [ ] Set a MySQL root password, or better, create a dedicated user with rights
      only on `poultryguard`, and point `config/database.php` at it.
- [ ] Serve over HTTPS. The session cookie automatically becomes `Secure` when
      it detects HTTPS.
- [ ] Confirm `logs/` and `config/` are not reachable over HTTP — the bundled
      `.htaccess` files deny them, but check that `AllowOverride All` is set for
      `htdocs` in `httpd.conf`, or Apache will ignore them.
- [ ] Give the XAMPP machine a static LAN IP so `SERVER_BASE_URL` stays valid.
- [ ] Set up a routine database backup (`mysqldump poultryguard`).

---

## 10. Troubleshooting

| Symptom | Cause and fix |
|---|---|
| "Database connection failed" page | MySQL is not running, or the schema was never imported. Start MySQL in XAMPP and import `poultryguard.sql`. |
| Blank white page | A PHP fatal error. Look in `logs/error.log` — errors are logged there rather than shown. |
| Login says "Too many failed sign-in attempts" | Throttling after 5 failures. Wait 15 minutes, or clear the `login_attempts` table. |
| Dashboard shows "--" everywhere | No readings for the selected device. Run the simulator, or check the device is online. |
| Charts are blank | No data in the selected window. Widen the range selector. |
| Device shows Offline although it is powered | Check `SERVER_BASE_URL` is the LAN IP, that the UID and API key match the Devices page, and that Windows Firewall allows Apache. |
| Serial shows `[API] ... HTTP 401` | The API key does not match. Regenerate it on the Devices page and reflash. |
| Serial shows `[SEN] HX711 not responding` | Check the HX711 wiring and that DOUT/SCK match `config.h`. |
| Serial shows `HC-SR04 gave too few valid echoes` | Check the ECHO voltage divider, and that the sensor is not aimed at a sloping surface. |
| Weight reads negative | The tare is wrong. Re-tare with an empty hopper. |
| Manual control buttons are disabled | The controller is offline. Automatic feeding continues on the device regardless. |
| Everything works but `.htaccess` seems ignored | Apache needs `AllowOverride All` for the `htdocs` directory in `httpd.conf`. |

Technical detail for anything unexpected is in `logs/error.log`. It is written
by the application and never shown to users.
