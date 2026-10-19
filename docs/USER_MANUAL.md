# User manual

PoultryGuard shows the water level and house temperature of your poultry house,
refills the water tank automatically, and lets you run the feeder and pump from
a browser.

## Signing in

Open the site and sign in. After 5 wrong attempts sign-in is locked for 15
minutes. You are signed out after 30 minutes of inactivity.

| Role | What it can do |
|---|---|
| **Farm Owner** | View everything, manage devices and users, set alert levels, see reports |
| **Farm Staff** | View everything, run the feeder and pump, change feeder/water settings |

The menu only shows pages your role may use. If you have more than one device,
pick it at the top of the page; the choice is kept as you move around.

## Farm clock

The top bar shows the current date and time in the farm's timezone (Asia/Manila by default, set with `PG_TIMEZONE`). It is taken from the server, not from your phone or PC, so it is the same time the feeding schedule and alerts use. It is hidden on narrow phone screens.

## Dashboard

Updates by itself every few seconds.

* **Tiles** — temperature, water level, controller online/offline, unread alerts.
* **Feeder** — whether the feeder is running, when it last ran and for how long,
  whether scheduled feeding is on, the feeding times, and how many runs today.
* **Water monitoring** — level in cm and %, litres, the *Refill* threshold marker,
  last refill, consumed today.
* **House climate** — temperature, humidity and heat status. *Normal* below 32 °C,
  *Warning* from 32 °C, *Danger* from 35 °C.
* **Charts** — temperature and water level over the chosen time range, daily
  feeder run time and daily water consumption.
* **Recent alerts** and **System status** (controller, internet, water sensor,
  feeder motor, water pump, last contact).

If the controller is **offline** a notice appears. The ESP32 keeps refilling water
on its own using its stored settings, and uploads what it missed when it
reconnects.

## Monitoring → Water

Detailed level, tank figures, a trend chart and the refill history (when, how it
started, start/end level, duration, outcome, who).

## Automation → Feed Control (Farm Staff)

1. Enter how many **seconds** to run the feeder (1 up to the safety limit).
2. Press **Run feeder** and confirm.
3. Use **Stop feeder** at any time.

The command is queued and picked up by the controller within a few seconds. The
*Command history* table shows it move from *Queued* → *Delivered* → *Executed*.
The buttons are disabled while the controller is offline (Stop is still sent).

## Scheduled feeding (Farm Staff)

Open **Settings → Feed settings**.

1. Turn **Scheduled feeding** on.
2. Press **+ Add time**, pick the time (for example 06:00) and how many seconds the
   feeder runs. You can add up to 8 times a day.
3. Press **Save feed settings**.

The controller stores the schedule and feeds at those times on its own, even if the
internet is down. Feeds that were scheduled appear in the feed history as
*automatic*. Manual feeding keeps working alongside the schedule.

Good to know:

* Each run is limited by *Maximum motor run time*. Lower that limit and you must
  shorten any longer feeding first.
* Changes reach the controller within about a minute of saving.
* If the controller has no correct clock (it needs internet after power-up to set
  it), it **skips** scheduled feeds and shows a warning alert instead of feeding at
  the wrong time. Feed manually until the alert clears.
* **Emergency stop** switches scheduled feeding off. Turn it on again in Settings.

## Automation → Water Control (Farm Staff)

**Start refill** runs the pump until the sensor reads *full* (or the safety time
limit). **Stop refill** halts it.

**Emergency stop** (both pages) stops the feeder and pump and **switches automatic
watering off**. Turn it back on in *Settings → Water settings → Automatic watering*
when it is safe.

## Reports (Farm Owner)

* **Water** — consumption per day/week/month, refills by method, and the event log.
* **System** — alerts over time, estimated device uptime, sensor activity,
  manual vs automatic actions and the operator activity log.

Choose the device, date range and grouping, then **Generate**. Uptime is an
estimate based on how many readings arrived.

## Alerts

Warnings appear on the dashboard and under **Alerts**. Filter by severity,
status, type or date. *Mark read* acknowledges; *Resolve* closes. Typical alerts:

| Alert | Meaning | What to do |
|---|---|---|
| Low / critical water | Level under the alert percentage | Check the supply; the pump may already be refilling |
| Heat warning / danger | Over 32 °C / 35 °C | Fans, shade, cool water |
| Device offline | No contact for longer than the offline timeout | Check power and Wi-Fi |
| Clock not set | Scheduled feeding is on but the controller cannot tell the time | Check Wi-Fi/internet; feed manually meanwhile |
| Dispensing timeout | The pump or feeder hit its safety time limit | Check supply, hose, hopper |
| Sensor failure | Invalid water reading | Check the sensor wiring |

## Settings

* **Feed settings** (Staff) — maximum feeder run time, scheduled feeding on/off and
  the feeding times.
* **Water settings** (Staff) — tank depth and volume, refill start/stop levels,
  maximum pump run time, automatic watering.
* **Alert & notification settings** (Owner) — low/critical water percentages,
  offline timeout, reading interval, notifications.

Invalid combinations (for example a refill start level above the stop level) are
rejected with an explanation. Changes reach the controller on its next check-in.

## Devices and users (Farm Owner)

* **Devices** — status, signal, firmware, queued commands. *Add device* shows its
  **API key once**; copy it into the firmware's `config.h`. *Regenerate key*
  immediately disconnects the old key. *Disable* blocks a device.
* **Users** — add staff or owners, change roles, deactivate accounts, reset
  passwords. Change the default passwords on first use.

## Good practice

* Use *Emergency stop* first and ask questions afterwards.
* Check the water and temperature trend once a day; check the sensor strip every
  few months (see [HARDWARE.md](HARDWARE.md)).
* Keep the controller on a stable Wi-Fi signal (the Devices page shows quality).
