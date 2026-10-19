/* =====================================================================
 *  PoultryGuard - ESP32 firmware configuration
 *
 *  Edit this file, then flash. Everything the installer needs to change
 *  lives here; the sketch itself should not need editing.
 * ===================================================================== */

#ifndef POULTRYGUARD_CONFIG_H
#define POULTRYGUARD_CONFIG_H

/* ---------------------------------------------------------------------
 *  1. Wi-Fi
 * ------------------------------------------------------------------- */
#define WIFI_SSID           "YourWiFiName"
#define WIFI_PASSWORD       "YourWiFiPassword"

/* How long to wait for a connection before giving up and running
 * offline for this cycle (milliseconds). */
#define WIFI_CONNECT_TIMEOUT 15000UL

/* How often to retry the connection once it has dropped (milliseconds). */
#define WIFI_RETRY_INTERVAL  30000UL

/* ---------------------------------------------------------------------
 *  2. Server
 *
 *  SERVER_BASE_URL is the address of the PoultryGuard installation as seen
 *  from the ESP32 - use the LAN IP of the machine running XAMPP, not
 *  "localhost", which on the ESP32 would mean the ESP32 itself.
 *
 *  Find it on Windows with:  ipconfig     (look for IPv4 Address)
 *  Example: http://192.168.1.10/PoultryGuard
 * ------------------------------------------------------------------- */
#define SERVER_BASE_URL     "http://192.168.1.10/PoultryGuard"

/* Identity. DEVICE_UID must match the esp32_uid registered on the Devices
 * page; API_KEY is the key shown once when the device was registered. */
#define DEVICE_UID          "ESP32-A1B2C3D4E5F6"
#define API_KEY             "97161c5b9d4ab435f52f8a8a9b48af25e8c09751"

#define FIRMWARE_VERSION    "1.0.0"

/* HTTP timeout for a single request (milliseconds). Kept short so a dead
 * server never stalls the control loop. */
#define HTTP_TIMEOUT        8000

/* ---------------------------------------------------------------------
 *  3. Pin assignments (ESP32 DevKit V1)
 *
 *  Avoid GPIO 6-11 (flash) and note that GPIO 34-39 are input-only.
 * ------------------------------------------------------------------- */

/* HX711 load cell amplifier */
#define PIN_HX711_DOUT      16
#define PIN_HX711_SCK       4

/* HC-SR04 ultrasonic sensor.
 * ECHO outputs 5 V - use a divider (1 kO + 2 kO) down to 3.3 V. */
#define PIN_ULTRASONIC_TRIG 5
#define PIN_ULTRASONIC_ECHO 18

/* Relay module channels */
#define PIN_RELAY_MOTOR     26   /* 12 V DC auger motor  */
#define PIN_RELAY_VALVE     27   /* 12 V solenoid valve  */

/* Status LED (the on-board LED on most DevKit V1 boards) */
#define PIN_STATUS_LED      2

/* Optional physical emergency-stop button to GND. Set to -1 if not fitted. */
#define PIN_EMERGENCY_STOP  15

/* Most cheap relay boards switch ON when the input is pulled LOW.
 * Set to 0 if yours is active-high. */
#define RELAY_ACTIVE_LOW    1

/* ---------------------------------------------------------------------
 *  4. Timing (milliseconds)
 * ------------------------------------------------------------------- */
#define SENSOR_READ_INTERVAL   2000UL    /* how often sensors are sampled     */
#define UPLOAD_INTERVAL        30000UL   /* how often a reading is uploaded   */
#define COMMAND_POLL_INTERVAL  10000UL   /* how often commands are collected  */
#define CONFIG_REFRESH_INTERVAL 300000UL /* how often settings are re-fetched */
#define SYNC_RETRY_INTERVAL    60000UL   /* retry an offline upload this often */

/* ---------------------------------------------------------------------
 *  5. Fallback automation defaults
 *
 *  Used only on a factory-fresh device that has never reached the server.
 *  Once it does, the server's settings are stored in NVS and take over -
 *  including across reboots and while offline.
 * ------------------------------------------------------------------- */
#define DEF_FEED_CAPACITY      10000.0f  /* grams, full hopper                */
#define DEF_FEED_THRESHOLD      2500.0f  /* grams, dispense below this        */
#define DEF_FEED_TARGET         9000.0f  /* grams, dispense up to this        */
#define DEF_FEED_MAX_DURATION      60    /* seconds, auger safety cut-off     */
#define DEF_FEED_TOLERANCE         5.0f  /* grams, +/- accuracy target        */
#define DEF_AUTO_FEED              1

#define DEF_WATER_CAPACITY       100.0f  /* cm, usable tank depth             */
#define DEF_SENSOR_OFFSET         10.0f  /* cm, sensor face to full-water line */
#define DEF_WATER_THRESHOLD       30.0f  /* cm, refill below this             */
#define DEF_WATER_TARGET          90.0f  /* cm, refill up to this             */
#define DEF_WATER_MAX_DURATION    300    /* seconds, valve safety cut-off     */
#define DEF_AUTO_WATER             1

/* Load cell: counts per gram, and the raw reading with an empty hopper.
 * Both are replaced by the values saved on the Calibration page. */
#define DEF_LOAD_CELL_FACTOR     214.75f
#define DEF_LOAD_CELL_OFFSET  128340.0f

/* ---------------------------------------------------------------------
 *  6. Safety limits
 * ------------------------------------------------------------------- */

/* If the auger runs this long (ms) without the weight rising by
 * MIN_PROGRESS_GRAMS, the hopper is empty or the auger is jammed. */
#define MOTOR_PROGRESS_WINDOW   15000UL
#define MIN_PROGRESS_GRAMS         20.0f

/* Same idea for the valve: no measurable rise means no supply pressure. */
#define VALVE_PROGRESS_WINDOW   45000UL
#define MIN_PROGRESS_CM             1.0f

/* Minimum rest between two automatic cycles (ms). Stops the system
 * short-cycling the hardware if a sensor sits right on the threshold. */
#define MIN_CYCLE_REST          60000UL

/* Readings outside these bounds are treated as sensor faults. */
#define MAX_PLAUSIBLE_WEIGHT   50000.0f  /* grams */
#define ULTRASONIC_MIN_CM           2.0f
#define ULTRASONIC_MAX_CM         400.0f

/* ---------------------------------------------------------------------
 *  7. Offline buffering
 * ------------------------------------------------------------------- */

/* Maximum buffered records held in flash while the network is down.
 * At one record per upload interval, 500 records is roughly four hours. */
#define OFFLINE_MAX_RECORDS      500
#define OFFLINE_BUFFER_FILE      "/buffer.jsonl"

/* Records sent per sync request. Must not exceed the server's limit (250). */
#define SYNC_BATCH_SIZE           40

/* ---------------------------------------------------------------------
 *  8. Time
 * ------------------------------------------------------------------- */

/* NTP gives buffered records real timestamps so an outage replays with
 * the correct times. GMT offset is in seconds (Philippines = UTC+8). */
#define NTP_SERVER          "pool.ntp.org"
#define NTP_GMT_OFFSET_SEC  (8 * 3600)
#define NTP_DST_OFFSET_SEC  0

/* Print verbose diagnostics to the serial monitor at 115200 baud. */
#define DEBUG_SERIAL        1

#endif /* POULTRYGUARD_CONFIG_H */
