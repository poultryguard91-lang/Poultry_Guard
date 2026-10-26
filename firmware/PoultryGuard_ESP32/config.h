/* =====================================================================
 *  PoultryGuard - ESP32 network / server configuration
 *
 *  Edit this file, then flash. Pins, sensor calibration and safety timers
 *  stay at the top of PoultryGuard_ESP32.ino; this file only holds what is
 *  needed to sync with the PoultryGuard web system.
 *
 *  Required Arduino libraries:
 *    - DHT sensor library (Adafruit) + Adafruit Unified Sensor
 *    - ArduinoJson (Benoit Blanchon) v7
 * ===================================================================== */

#ifndef POULTRYGUARD_CONFIG_H
#define POULTRYGUARD_CONFIG_H

/* Set to 0 to run fully standalone (serial only), as before. */
#include "secrets.h"   /* WIFI_SSID, WIFI_PASSWORD, API_KEY */

#define ENABLE_SYNC          1

/* ---------------------------------------------------------------------
 *  1. Wi-Fi (2.4 GHz only)
 * ------------------------------------------------------------------- */
/* WIFI_SSID and WIFI_PASSWORD live in secrets.h (git-ignored). */

#define WIFI_CONNECT_TIMEOUT 15000UL   /* wait at boot, then run offline    */
#define WIFI_RETRY_INTERVAL  30000UL   /* retry after a drop                */

/* ---------------------------------------------------------------------
 *  2. Server
 *
 *  Address of the PoultryGuard installation AS SEEN FROM THE ESP32:
 *    - XAMPP on your PC : the PC's LAN IP (ipconfig -> IPv4), NOT localhost
 *                         e.g. "http://192.168.1.10/PoultryGuard"
 *    - Railway / hosted : the https URL, e.g. "https://yourapp.up.railway.app"
 *  No trailing slash.
 * ------------------------------------------------------------------- */
#define SERVER_BASE_URL      "https://poultryguard-production.up.railway.app"

/* Must match the device registered on the Devices page. The API key is
 * shown once when the device is registered. */
#define DEVICE_UID           "ESP32-A1B2C3D4E5F6"
/* API_KEY lives in secrets.h (git-ignored). */

#define FIRMWARE_VERSION     "2.0.0"

/* ---------------------------------------------------------------------
 *  3. Timing (milliseconds)
 * ------------------------------------------------------------------- */
/* One HTTP request blocks the loop for up to this long when the server is
 * unreachable, so keep it short. The pump/feeder safety timers are checked
 * again as soon as the request returns. */
#define HTTP_TIMEOUT         10000   /* https handshake alone takes 2-5 s */

#define UPLOAD_INTERVAL      30000UL   /* sensor reading upload             */
#define COMMAND_POLL_INTERVAL 5000UL   /* collect dashboard commands        */

/* ---------------------------------------------------------------------
 *  4. Offline buffering (kept in RAM; lost on reboot)
 * ------------------------------------------------------------------- */
#define SAMPLE_QUEUE_MAX     60        /* ~30 min at one sample / 30 s      */
#define EVENT_QUEUE_MAX      10        /* pump / feeder runs                */
#define SYNC_BATCH_SIZE      20        /* server accepts at most 250        */

/* ---------------------------------------------------------------------
 *  5. Scheduled feeding
 * ------------------------------------------------------------------- */
#define MAX_FEED_SLOTS       8         /* feeding times per day (match the server) */
#define SCHEDULE_GRACE_MIN   2         /* a slot may start up to this many minutes late */

/* ---------------------------------------------------------------------
 *  6. Time (gives buffered records real timestamps). UTC+8 = Philippines.
 * ------------------------------------------------------------------- */
#define NTP_SERVER           "pool.ntp.org"
#define NTP_GMT_OFFSET_SEC   (8 * 3600)
#define NTP_DST_OFFSET_SEC   0

#endif /* POULTRYGUARD_CONFIG_H */
