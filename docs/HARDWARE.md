# Hardware guide

Wiring, power and set-up for the PoultryGuard ESP32 controller. The sketch is
`firmware/PoultryGuard_ESP32/PoultryGuard_ESP32.ino`; pin numbers below match it.

> **Mains and 12 V safety.** Switch the supply off before wiring. Keep mains
> voltage out of the control box. This controller only needs 12 V DC for the pump
> and feeder motor. Fuse the 12 V line.

---

## 1. Bill of materials

| Qty | Part | Notes |
|---|---|---|
| 1 | ESP32 DevKit (30/38-pin, "ESP32 Dev Module") | 2.4 GHz Wi-Fi only |
| 1 | Relay module, 2 channels or more (a 4-channel board works) | Opto-isolated, active-LOW inputs (the common blue boards) |
| 1 | DHT11 temperature & humidity sensor (module with pull-up) | Measures 0–50 °C, ±2 °C, 20–90 % RH |
| 1 | **Either** analog water level sensor strip (S, +, −) **or** float switch | See section 4 |
| 1 | 12 V DC pump | Water refill |
| 1 | 12 V DC motor (windshield-wiper type) | Feeder |
| 1 | 12 V DC supply, sized for the larger motor's stall current + margin | Fuse it |
| 1 | Buck converter (12 V → 5 V) | Powers the ESP32 and relay board |
| 2 | Flyback diodes (e.g. 1N5408) | Across each DC motor, band toward +12 V |
| — | Wire, terminal blocks, enclosure, hose, tank fittings | |

---

## 2. Wiring

```
12 V supply (+) ─ fuse ─┬───────────── relay K1 COM ─ relay K1 NO ─ pump (+)
                        ├───────────── relay K2 COM ─ relay K2 NO ─ feeder motor (+)
                        └─ buck 12→5 V ─ 5 V ─► ESP32 VIN / relay VCC
12 V supply (−) ────────┴─ pump (−), feeder motor (−), buck GND, ESP32 GND, relay GND
```

| ESP32 pin | Connects to | Purpose |
|---|---|---|
| GPIO 27 | Relay **IN1** (K1) | Water pump |
| GPIO 26 | Relay **IN2** (K2) | Feeder motor |
| GPIO 4  | DHT11 DATA | Temperature & humidity |
| GPIO 34 | Water sensor **S** | Analog level (input-only pin) |
| GPIO 33 | Water sensor **+** | Powered only while reading, to reduce corrosion |
| GPIO 32 | Float switch (other wire to GND) | Only if you use the float switch |
| 3V3 / GND | DHT11 + / − | |

* The relay that clicks is the **IN pin the GPIO wire is plugged into**. If the
  wrong relay moves, swap the two wires or the `PIN_PUMP` / `PIN_MOTOR` numbers.
* Wire each motor through the relay's **COM** and **NO** contacts so it is off
  when the relay is off or the ESP32 resets.
* All grounds (12 V, buck, ESP32, relay board) must be common.
* Fit a flyback diode directly across each DC motor.
* The relay board draws current from 5 V; power it from the buck converter, not
  from the ESP32's 3V3 pin.

### Relay polarity

The sketch assumes active-LOW relays (`RELAY_ACTIVE_LOW = true`): the relay turns
on when the pin is LOW. If both relays are on at boot and off when running, your
board is active-HIGH: set the constant to `false`.

---

## 3. Flashing

1. Install the ESP32 board package and the libraries listed in
   [INSTALLATION.md](INSTALLATION.md) section 8.1.
2. Board **ESP32 Dev Module**, correct COM port, 115200 baud monitor.
3. Edit `config.h` (Wi-Fi, server URL, device UID, API key).
4. Upload. Open the Serial Monitor (line ending *Newline*).
5. Type `test`. Each relay clicks for 2 s in turn; confirm the pump relay is IN1
   and the feeder relay is IN2.

Serial commands: `auto on|off`, `pump on|off|<sec>`, `feed [sec]`, `motor on|off`,
`sched on|off|show`, `status`, `test`, `stop`, `help`.

---

## 4. Water level sensor

Set `WATER_SENSOR_TYPE` at the top of the sketch.

**Type 1 — analog strip (default).**
1. Mount the strip so its **bottom is your LOW mark and its top is your FULL
   mark** (the pump starts at LOW and stops at FULL).
2. Type `status` with the strip dry, then half in water, then fully in water,
   and note the number in brackets after `Water:`.
3. Set `WATER_LOW_VALUE` (at or below = LOW → pump starts) and
   `WATER_FULL_VALUE` (at or above = FULL → pump stops).
4. The strip's reading drifts as it corrodes. Recheck every few months; the
   sketch powers it only during a reading to slow this.

**Type 2 — float switch.** Wire it between GPIO 32 and GND. Set
`FLOAT_LOW_STATE` to the pin level when the water is low. The pump keeps filling
`FLOAT_EXTRA_FILL_SEC` seconds after the float lifts.

### Safety timers (top of the sketch)

| Constant | Default | Meaning |
|---|---|---|
| `PUMP_MAX_SEC` | 60 | A refill never runs longer than this. Time a real refill and set it a little above. |
| `PUMP_REST_SEC` | 10 | Minimum rest before the next automatic refill |
| `FEED_SEC` | 3 | Default feeder run |
| `MOTOR_MAX_SEC` | 30 | Feeder never runs longer than this |

If an automatic refill reaches `PUMP_MAX_SEC` without the tank reading FULL, the
sketch assumes the supply is empty or the hose is blocked and **turns automatic
refill off** until you type `auto on` or re-enable it from the dashboard. The
dashboard's *Maximum pump/motor run time* settings can only lower these limits.

---

## 5. Scheduled feeding

Feeding times (up to 8 a day, each with its own run seconds) are set on the web
**Scheduled Feeding** page and sent to the board, which saves them in its flash memory and
runs them from its own clock. The clock comes from NTP over Wi-Fi
(`NTP_SERVER`, UTC+8 by default in `config.h`).

* A scheduled feed fires once, up to `SCHEDULE_GRACE_MIN` (2) minutes after its
  time, so a slow network request cannot make it miss. The time of the last feed
  is stored in flash, so a reboot inside that window does **not** feed twice.
* Feeds are capped by `MOTOR_MAX_SEC` and the dashboard's maximum run time.
* **If the clock is not set** (no internet since boot) the feed is skipped, never
  guessed, and after 3 minutes a warning alert is raised on the dashboard.
* If the feeder is already running (for example a manual feed) when a slot
  starts, the slot waits inside its grace window and is skipped if the feeder is
  still busy.
* A freshly flashed board has scheduled feeding **off** until it first reaches the
  server. After a reboot it uses the schedule saved in flash, so feeding does not
  depend on the network being up at boot, only on the clock.
* `stop` (serial) and the dashboard's emergency stop switch scheduled feeding
  off; turn it back on with `sched on` or in Settings.

---

## 6. Heat warning

`TEMP_WARN` (32 °C) and `TEMP_DANGER` (35 °C) set the serial warnings; the server
raises the matching alerts at the same thresholds (`CLIMATE_WARN_C` /
`CLIMATE_DANGER_C` in `includes/device.php`). Change both if you change one.

---

## 7. Troubleshooting

| Symptom | Check |
|---|---|
| Both relays click at power-up | Board is active-HIGH: set `RELAY_ACTIVE_LOW = false` |
| Wrong relay moves | Swap the IN wires or the GPIO constants |
| `Temp: --`, heat level `SENSOR ERROR` | DHT11 DATA on GPIO 4, power, pull-up resistor |
| Water reading stuck near 0 or 4095 | Sensor wiring; S on GPIO 34, + on GPIO 33 |
| Pump never stops | `WATER_FULL_VALUE` too high for your strip; the safety timer will stop it |
| Pump never starts | `WATER_LOW_VALUE` too low; or auto refill was turned off after a timeout (`auto on`) |
| ESP32 resets when a motor starts | Supply too small or no flyback diode; power the ESP32 from its own buck output |
| Scheduled feed did not run | `sched show` (is it ON, are the times there?); Scheduled Feeding page → switch on; clock set (a dashboard alert says if not); feeder not busy; a *stop* since boot turned it off |
| Dashboard shows Offline | See INSTALLATION.md section 10 |
