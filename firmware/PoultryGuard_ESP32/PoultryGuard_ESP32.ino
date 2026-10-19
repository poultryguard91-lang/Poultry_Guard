/* =====================================================================
 *  PoultryGuard - AUTO REFILL + MANUAL CONTROL (ESP32)
 *    Relay 1 (IN1 -> K1): water pump    (12V -> buck -> pump)
 *    Relay 2 (IN2 -> K2): feeder motor  (12V windshield wiper motor)
 *    DHT11              : temperature & humidity, heat warning
 *    Water level sensor : in the chickens' WATER TANK. When the water is
 *                         LOW/EMPTY the pump turns ON and refills the tank,
 *                         then turns OFF when the tank is FULL.
 *
 *  Board: "ESP32 Dev Module"
 *  Libraries: "DHT sensor library" by Adafruit (+ Adafruit Unified Sensor)
 *             "ArduinoJson" v7 by Benoit Blanchon
 *  Serial Monitor: 115200 baud, line ending "Newline"
 *
 *  WEB SYNC (see config.h): readings, pump/feeder runs and alerts go to the
 *  PoultryGuard dashboard; dashboard buttons (feed, refill, stop) are polled
 *  every few seconds. All control logic stays LOCAL, so the pump and feeder
 *  keep working if Wi-Fi or the server is down; events are buffered in RAM
 *  and uploaded when the connection returns.
 *
 *  COMMANDS
 *    auto on       automatic refill ON  (default at start-up)
 *    auto off      automatic refill OFF (pump only moves on your command)
 *    pump on | pump off | pump <sec>    (switches water to MANUAL)
 *    feed | feed <sec> | motor on | motor off
 *    status        show everything
 *    test          click each relay for 2 s, one at a time, to check wiring
 *    stop          EMERGENCY: everything OFF
 *    help
 * ===================================================================== */

#include <DHT.h>
#include "config.h"
#if ENABLE_SYNC
#include <WiFi.h>
#include <HTTPClient.h>
#include <WiFiClientSecure.h>
#include <ArduinoJson.h>
#include <time.h>
#endif

/* ---------- Which water level sensor do you have? ----------
 *  1 = analog water level module (strip with S + - pins)
 *  2 = float switch (2 wires)                                  */
#define WATER_SENSOR_TYPE  1

/* ---------- Pins ----------
 * The code only knows GPIO numbers. The relay that clicks is the IN pin
 * the GPIO wire is plugged into. Wire it like this:
 *     ESP32 GPIO 27  ->  relay IN1  ->  K1 = PUMP
 *     ESP32 GPIO 26  ->  relay IN2  ->  K2 = FEEDER MOTOR
 * Type "test" in the Serial Monitor to check.                         */
#define PIN_PUMP        27   // -> relay IN1 -> K1 (pump)
#define PIN_MOTOR       26   // -> relay IN2 -> K2 (feeder motor)
#define PIN_DHT         4    // DHT11 DATA
#define PIN_WATER_S     34   // type 1: sensor S pin (input-only pin, OK)
#define PIN_WATER_VCC   33   // type 1: sensor + pin (powered only when reading)
#define PIN_FLOAT       32   // type 2: float switch, other wire to GND

/* ---------- Settings ---------- */
const bool RELAY_ACTIVE_LOW = true;   // your 4-relay board turns ON with LOW

#define PUMP_MAX_SEC    60   // safety: one refill never runs longer than this.
                             // Time a real refill and set this a bit above it.
#define PUMP_REST_SEC   10   // wait this long before the next automatic refill
#define FEED_SEC        3
#define MOTOR_MAX_SEC   30

/* Analog sensor (type 1). Type "status" with the sensor dry, half in water
 * and fully in water to see the values, then set these two.
 * Mount the strip so its bottom = your LOW mark, top = your FULL mark. */
#define WATER_LOW_VALUE   300    // at/below this = LOW  -> pump starts
#define WATER_FULL_VALUE  1500   // at/above this = FULL -> pump stops
/* Float switch (type 2): pin reading when the water is LOW */
#define FLOAT_LOW_STATE      LOW
#define FLOAT_EXTRA_FILL_SEC 5    // keep filling this long after the float lifts

/* Heat warning (DHT11 is +/- 2 C). Grown chickens stress above ~32 C. */
#define TEMP_WARN    32.0
#define TEMP_DANGER  35.0

#define REPORT_EVERY_MS  10000   // print a sensor line every 10 s

/* ===================================================================== */
DHT dht(PIN_DHT, DHT11);

/* ---------- Relay channels ---------- */
struct Channel {
  const char*   name;
  uint8_t       pin;
  unsigned long maxSec;
  bool          on;
  unsigned long startedAt;
  unsigned long runMs;
  /* bookkeeping for the web dashboard */
  bool          manual;      // started by a person (serial or dashboard)
  bool          stopAtFull;  // pump only: stop as soon as the tank is FULL
  long          cmdId;       // dashboard command to acknowledge when done
  long          userId;      // dashboard user who pressed the button
  int           startPct;    // water % when the run began
};
Channel pump  = { "PUMP",         PIN_PUMP,  PUMP_MAX_SEC,  false, 0, 0, false, false, 0, 0, 0 };
Channel motor = { "FEEDER MOTOR", PIN_MOTOR, MOTOR_MAX_SEC, false, 0, 0, false, false, 0, 0, 0 };

/* ---------- Sensor state ---------- */
float tempC = NAN, humidity = NAN;
String heatLevel = "--";

bool   autoRefill   = true;           // AUTO at start-up
int    waterValue   = -1;
int    waterPercent = 0;                  // 0-100, sent to the dashboard
String waterLevel   = "--";           // LOW, OK, FULL
int    lowCount     = 0;              // debounce: 3 LOW readings in a row
unsigned long pumpStoppedAt = 0;
unsigned long floatOkSince  = 0;

/* ---------- Web sync state ---------- */
bool   serverOk     = false;              // last request to the server worked
int    sampleCount  = 0, eventCount = 0;  // offline queue fill levels
long   settingsVersion = 0;

/* ================= RELAYS ================= */
void writeRelay(uint8_t pin, bool on) {
  digitalWrite(pin, (on == RELAY_ACTIVE_LOW) ? LOW : HIGH);
}

void reportFinished(Channel& ch, const char* why);   // web sync, defined below

void turnOff(Channel& ch, const char* why) {
  writeRelay(ch.pin, false);
  bool wasOn = ch.on;
  ch.on = false;
  if (wasOn) {
    Serial.print(">> "); Serial.print(ch.name);
    Serial.print(" OFF ("); Serial.print(why); Serial.println(")");
    reportFinished(ch, why);
  }
}

void turnOn(Channel& ch, unsigned long seconds, bool manual = false, long cmdId = 0, long userId = 0) {
  if (seconds < 1) seconds = 1;
  if (seconds > ch.maxSec) seconds = ch.maxSec;
  writeRelay(ch.pin, true);
  ch.on         = true;
  ch.startedAt  = millis();
  ch.runMs      = seconds * 1000UL;
  ch.manual     = manual;
  ch.stopAtFull = false;
  ch.cmdId      = cmdId;
  ch.userId     = userId;
  ch.startPct   = waterPercent;
  Serial.print(">> "); Serial.print(ch.name);
  Serial.print(" ON for "); Serial.print(seconds); Serial.println(" s");
}

void updateChannel(Channel& ch) {
  if (ch.on && millis() - ch.startedAt >= ch.runMs) {
    turnOff(ch, "timer done");
    if (&ch == &pump) {
      pumpStoppedAt = millis();
      if (autoRefill && !pump.manual && waterLevel != "FULL") {
        /* Ran the full safety time and the tank still isn't full:
         * the supply is probably empty or the hose is blocked. */
        autoRefill = false;
        Serial.println("!! REFILL TOOK TOO LONG - tank not full after the safety time.");
        Serial.println("!! Check the water supply / hose / sensor. Auto refill turned OFF.");
        Serial.println("   Type 'auto on' when fixed.");
      }
    }
  }
}

/* ================= WATER LEVEL SENSOR + AUTO REFILL ================= */
void readWater() {
#if WATER_SENSOR_TYPE == 1
  digitalWrite(PIN_WATER_VCC, HIGH);
  delay(10);
  long sum = 0;
  for (int i = 0; i < 10; i++) sum += analogRead(PIN_WATER_S);
  digitalWrite(PIN_WATER_VCC, LOW);
  waterValue = sum / 10;
  if (waterValue <= WATER_LOW_VALUE)       waterLevel = "LOW";
  else if (waterValue >= WATER_FULL_VALUE) waterLevel = "FULL";
  else                                     waterLevel = "OK";
#else
  waterValue = digitalRead(PIN_FLOAT);
  if (waterValue == FLOAT_LOW_STATE) { waterLevel = "LOW"; floatOkSince = 0; }
  else {
    if (floatOkSince == 0) floatOkSince = millis();
    waterLevel = (millis() - floatOkSince >= FLOAT_EXTRA_FILL_SEC * 1000UL) ? "FULL" : "OK";
  }
#endif
#if WATER_SENSOR_TYPE == 1
  waterPercent = constrain(map(waterValue, WATER_LOW_VALUE, WATER_FULL_VALUE, 0, 100), 0, 100);
#else
  waterPercent = (waterLevel == "LOW") ? 0 : (waterLevel == "FULL") ? 100 : 50;
#endif
  if (waterLevel == "LOW") lowCount++; else lowCount = 0;
}

void autoRefillControl() {
  /* Stop: tank FULL (auto refills and dashboard refills; not 'pump on') */
  if (pump.on && pump.stopAtFull && waterLevel == "FULL") {
    turnOff(pump, "tank FULL");
    pumpStoppedAt = millis();
  }

  if (!autoRefill) return;

  /* Start: water LOW for 3 readings in a row (so a splash can't trigger it) */
  bool rested = (pumpStoppedAt == 0) || (millis() - pumpStoppedAt >= PUMP_REST_SEC * 1000UL);
  if (!pump.on && lowCount >= 3 && rested) {
    Serial.println(">> WATER LOW - refilling tank");
    turnOn(pump, PUMP_MAX_SEC);
    pump.stopAtFull = true;
  }
}

/* ================= DHT11 ================= */
void readClimate() {
  float h = dht.readHumidity();
  float t = dht.readTemperature();
  if (isnan(h) || isnan(t)) {
    tempC = NAN; humidity = NAN;
    heatLevel = "SENSOR ERROR";
    return;
  }
  tempC = t;
  humidity = h;

  String before = heatLevel;
  if (t >= TEMP_DANGER)    heatLevel = "DANGER";
  else if (t >= TEMP_WARN) heatLevel = "WARNING";
  else                     heatLevel = "NORMAL";

  if (heatLevel != before) {
    if (heatLevel == "DANGER")
      Serial.println("!!! HEAT DANGER - too hot for the chickens! Turn on fans, add shade and cool water.");
    else if (heatLevel == "WARNING")
      Serial.println("!! HEAT WARNING - getting hot, chickens may start heat stress.");
    else if (before == "WARNING" || before == "DANGER")
      Serial.println(">> Temperature back to normal.");
  }
}

/* ================= PRINTING ================= */
void printChannel(Channel& ch) {
  Serial.print(ch.name); Serial.print(": ");
  if (ch.on) {
    unsigned long left = (ch.runMs - (millis() - ch.startedAt) + 999) / 1000;
    Serial.print("ON ("); Serial.print(left); Serial.print(" s left)");
  } else {
    Serial.print("OFF");
  }
}

void printStatus() {
  Serial.print("Temp: ");
  if (isnan(tempC)) Serial.print("--"); else { Serial.print(tempC, 1); Serial.print(" C"); }
  Serial.print(" | Hum: ");
  if (isnan(humidity)) Serial.print("--"); else { Serial.print(humidity, 0); Serial.print(" %"); }
  Serial.print(" ["); Serial.print(heatLevel); Serial.print("]");
  Serial.print(" | Water: "); Serial.print(waterLevel);
  Serial.print(" ("); Serial.print(waterValue); Serial.print(")");
  Serial.print(autoRefill ? " [AUTO]" : " [MANUAL]");
  Serial.print(" | ");
  printChannel(pump);
  Serial.print(" | ");
  printChannel(motor);
#if ENABLE_SYNC
  Serial.print(" | Web: ");
  Serial.print(WiFi.status() == WL_CONNECTED ? (serverOk ? "SYNCED" : "NO SERVER") : "NO WIFI");
  Serial.print(" (queued "); Serial.print(sampleCount + eventCount); Serial.print(")");
#endif
  Serial.println();
}

void printHelp() {
  Serial.println("Commands:");
  Serial.println("  auto on | auto off");
  Serial.println("  pump on | pump off | pump <sec>   (switches water to MANUAL)");
  Serial.println("  feed | feed <sec> | motor on | motor off");
  Serial.println("  status | test | stop | help");
}

/* ================= COMMANDS ================= */
void runCommand(String cmd) {
  cmd.trim();
  cmd.toLowerCase();
  if (cmd.length() == 0) return;

  if (cmd == "help")           printHelp();
  else if (cmd == "status")    printStatus();

  else if (cmd == "auto on")   { autoRefill = true;  pumpStoppedAt = 0; Serial.println(">> AUTO REFILL ON"); }
  else if (cmd == "auto off")  { autoRefill = false; turnOff(pump, "auto off"); Serial.println(">> AUTO REFILL OFF"); }

  else if (cmd == "pump on")   { autoRefill = false; turnOn(pump, PUMP_MAX_SEC, true); }
  else if (cmd == "pump off")  { autoRefill = false; turnOff(pump, "manual"); }
  else if (cmd.startsWith("pump ")) {
    int sec = cmd.substring(5).toInt();
    if (sec > 0) { autoRefill = false; turnOn(pump, sec, true); }
    else Serial.println("Use: pump on | pump off | pump <seconds>");
  }

  else if (cmd == "feed")      turnOn(motor, FEED_SEC, true);
  else if (cmd.startsWith("feed ")) {
    int sec = cmd.substring(5).toInt();
    if (sec > 0) turnOn(motor, sec, true);
    else Serial.println("Use: feed | feed <seconds>");
  }
  else if (cmd == "motor on")  turnOn(motor, MOTOR_MAX_SEC, true);
  else if (cmd == "motor off") turnOff(motor, "manual");

  else if (cmd == "test") {
    autoRefill = false;
    turnOff(pump, "test");
    turnOff(motor, "test");
    Serial.println(">> RELAY TEST (auto refill OFF). Watch which IN LED lights:");
    Serial.print("   1) PUMP relay  = GPIO "); Serial.print(PIN_PUMP);
    Serial.println("  -> should be IN1 / K1 ...");
    writeRelay(PIN_PUMP, true);  delay(2000); writeRelay(PIN_PUMP, false);  delay(1000);
    Serial.print("   2) MOTOR relay = GPIO "); Serial.print(PIN_MOTOR);
    Serial.println("  -> should be IN2 / K2 ...");
    writeRelay(PIN_MOTOR, true); delay(2000); writeRelay(PIN_MOTOR, false);
    Serial.println(">> Test done. If the wrong IN lit, move that GPIO wire to the right IN pin.");
    Serial.println("   Type 'auto on' to resume automatic refill.");
  }
  else if (cmd == "stop") {
    autoRefill = false;
    writeRelay(PIN_PUMP, false);      // both relays off before anything slow
    writeRelay(PIN_MOTOR, false);
    turnOff(pump, "STOP");
    turnOff(motor, "STOP");
    Serial.println(">> EMERGENCY STOP: everything OFF, auto refill OFF ('auto on' to resume)");
  }
  else {
    Serial.print("Unknown command: "); Serial.println(cmd);
    printHelp();
  }
}

/* ================= WEB SYNC ================= */
#if ENABLE_SYNC

String sampleQueue[SAMPLE_QUEUE_MAX];   // buffered readings (JSON objects)
String eventQueue[EVENT_QUEUE_MAX];     // finished pump / feeder runs waiting to be sent (JSON objects)
long   eventCmd[EVENT_QUEUE_MAX];       // dashboard command to acknowledge per event (0 = none)
bool   eventFailed[EVENT_QUEUE_MAX];    // true = the run ended in a safety timeout
unsigned long wifiLastTry = 0;

bool wifiUp() { return WiFi.status() == WL_CONNECTED; }

/* "YYYY-MM-DD HH:MM:SS", or "" until NTP has set the clock. */
String nowTs() {
  time_t t = time(nullptr);
  if (t < 1700000000) return "";
  struct tm tmv;
  localtime_r(&t, &tmv);
  char b[20];
  strftime(b, sizeof(b), "%Y-%m-%d %H:%M:%S", &tmv);
  return String(b);
}

String idJson() {
  String s = "\"esp32_uid\":\"" DEVICE_UID "\",\"firmware_version\":\"" FIRMWARE_VERSION "\"";
  if (wifiUp()) {
    s += ",\"ip_address\":\"" + WiFi.localIP().toString() + "\"";
    s += ",\"wifi_rssi\":" + String(WiFi.RSSI());
  }
  s += ",\"water_sensor_status\":\"ok\"";
  s += String(",\"motor_status\":\"") + (motor.on ? "running" : "idle") + "\"";
  s += String(",\"solenoid_status\":\"") + (pump.on ? "running" : "idle") + "\"";
  return s;
}

/* One reading as a JSON object (used live and for the offline queue). */
String sampleJson(bool withTs) {
  String s = "\"water_percentage\":" + String(waterPercent);
  if (!isnan(tempC) && !isnan(humidity)) {
    s += ",\"temperature\":" + String(tempC, 1) + ",\"humidity\":" + String(humidity, 0);
  }
  if (withTs) {
    String ts = nowTs();
    if (ts.length()) s += ",\"timestamp\":\"" + ts + "\"";
  }
  return s;
}

void queuePush(String* q, int& count, int maxN, const String& json) {
  if (count == maxN) {                       // full: drop the oldest
    for (int i = 1; i < maxN; i++) q[i - 1] = q[i];
    count--;
  }
  q[count++] = json;
}

void queueDrop(String* q, int& count, int n) {
  if (n > count) n = count;
  for (int i = n; i < count; i++) q[i - n] = q[i];
  for (int i = count - n; i < count; i++) q[i] = "";
  count -= n;
}

void eventPop() {                            // remove the oldest event
  for (int i = 1; i < eventCount; i++) { eventCmd[i - 1] = eventCmd[i]; eventFailed[i - 1] = eventFailed[i]; }
  queueDrop(eventQueue, eventCount, 1);
}

void eventPush(const String& json, long cmd, bool failed) {
  if (eventCount == EVENT_QUEUE_MAX) eventPop();   // full: drop the oldest
  eventQueue[eventCount]  = json;
  eventCmd[eventCount]    = cmd;
  eventFailed[eventCount] = failed;
  eventCount++;
}

/* POST JSON to the server. Returns the HTTP status (<=0 = no connection). */
int postJson(const char* path, const String& body, String& out) {
  if (!wifiUp()) return -1;
  String url = String(SERVER_BASE_URL) + path;
  HTTPClient http;
  WiFiClient plain;
  WiFiClientSecure secure;
  if (url.startsWith("https")) { secure.setInsecure(); http.begin(secure, url); }   // no certificate pinning
  else                         { http.begin(plain, url); }
  http.setTimeout(HTTP_TIMEOUT);
  http.setConnectTimeout(HTTP_TIMEOUT / 2);
  http.setFollowRedirects(HTTPC_STRICT_FOLLOW_REDIRECTS);
  http.addHeader("Content-Type", "application/json");
  http.addHeader("X-API-Key", API_KEY);
  int code = http.POST(body);
  if (code > 0) out = http.getString();
  http.end();
  serverOk = (code >= 200 && code < 300);
  return code;
}

void ackCommand(long id, bool ok, const String& result) {
  if (id <= 0) return;
  String r;
  String body = "{\"esp32_uid\":\"" DEVICE_UID "\",\"command_id\":" + String(id) +
                ",\"status\":\"" + (ok ? "executed" : "failed") +
                "\",\"result\":\"" + result.substring(0, 120) + "\"}";
  postJson("/api/esp32/ack.php", body, r);
}

/* A pump or feeder run finished. This runs in the control path, so it only
 * records the event; syncLoop() sends it (and acknowledges the dashboard
 * command) when it is safe to spend time on the network. */
void reportFinished(Channel& ch, const char* why) {
  bool isPump = (&ch == &pump);
  const char* status;
  if (!strcmp(why, "timer done"))      status = (ch.manual || !isPump || waterLevel == "FULL") ? "completed" : "timeout";
  else if (!strcmp(why, "tank FULL"))  status = "completed";
  else                                 status = "aborted";

  unsigned long ms = millis() - ch.startedAt;
  const char* method = wifiUp() ? (ch.manual ? "manual" : "automatic") : "offline";

  String j = String("\"type\":\"") + (isPump ? "water" : "feed") + "\"" +
             ",\"status\":\"" + status + "\"" +
             ",\"dispensing_method\":\"" + method + "\"" +
             ",\"duration_ms\":" + String(ms);
  if (ch.userId > 0) j += ",\"user_id\":" + String(ch.userId);
  if (isPump) {
    j += ",\"starting_percentage\":" + String(ch.startPct) +
         ",\"ending_percentage\":" + String(waterPercent) +
         ",\"target_percentage\":100";
  } else {
    j += ",\"notes\":\"Timed feed, " + String((ms + 500) / 1000) + " s\"";
  }
  String ts = nowTs();
  if (ts.length()) j += ",\"timestamp\":\"" + ts + "\"";

  eventPush("{" + j + "}", ch.cmdId, !strcmp(status, "timeout"));
  ch.cmdId = 0;
}

/* Send finished runs one at a time; acknowledge their dashboard command. */
void sendEvents() {
  static unsigned long lastTry = 0;
  if (eventCount == 0 || !wifiUp()) return;
  if (millis() - lastTry < (serverOk ? 1000UL : 30000UL)) return;
  lastTry = millis();

  String body = eventQueue[0];
  body.remove(0, 1);                              // drop the leading '{'
  String r;
  int code = postJson("/api/esp32/dispensing.php", "{" + idJson() + "," + body, r);
  /* 2xx = stored. 4xx = the server refuses this record for good, so drop it
   * rather than retry it forever. 5xx / no connection = keep it and retry. */
  if ((code >= 200 && code < 300) || (code >= 400 && code < 500)) {
    long cmd = eventCmd[0];
    bool failed = eventFailed[0];
    eventPop();
    if (cmd > 0) ackCommand(cmd, !failed, failed ? "Safety timeout reached" : "Run finished");
  }
}

/* Settings the server pushes. Safety timers and calibration stay local. */
void applySettings(JsonObject s) {
  if (s.isNull()) return;
  long v = s["settings_version"] | 0L;
  if (v == settingsVersion) return;
  settingsVersion = v;
  long fm = s["feed_max_duration"] | 0L;
  long wm = s["water_max_duration"] | 0L;
  if (fm > 0) motor.maxSec = min((long)MOTOR_MAX_SEC, fm);
  if (wm > 0) pump.maxSec  = min((long)PUMP_MAX_SEC, wm);
  if (!s["auto_water"].isNull()) {
    autoRefill = s["auto_water"].as<bool>();
    pumpStoppedAt = 0;
    Serial.print(">> Dashboard setting: auto refill "); Serial.println(autoRefill ? "ON" : "OFF");
  }
}

void handleCommand(JsonObject c) {
  long id = c["command_id"] | 0L;
  String name = c["command"] | "";
  JsonObject p = c["payload"].as<JsonObject>();
  long userId = p.isNull() ? 0L : (p["user_id"] | 0L);
  Serial.print(">> Dashboard command: "); Serial.println(name);

  if (name == "dispense_feed") {
    if (motor.on) { ackCommand(id, false, "Feeder already running"); return; }
    unsigned long sec = p.isNull() ? FEED_SEC : (p["duration_sec"] | (long)FEED_SEC);
    turnOn(motor, sec, true, id, userId);          // acknowledged when the run ends
  } else if (name == "stop_feed") {
    turnOff(motor, "dashboard");
    ackCommand(id, true, "Feeder stopped");
  } else if (name == "refill_water") {
    if (pump.on) { ackCommand(id, false, "Pump already running"); return; }
    if (waterLevel == "FULL") { ackCommand(id, true, "Tank already full"); return; }
    turnOn(pump, PUMP_MAX_SEC, true, id, userId);
    pump.stopAtFull = true;                        // fill until the sensor says FULL
  } else if (name == "stop_water") {
    turnOff(pump, "dashboard");
    ackCommand(id, true, "Pump stopped");
  } else if (name == "emergency_stop") {
    runCommand("stop");
    ackCommand(id, true, "Everything OFF, auto refill OFF");
  } else if (name == "reload_settings") {
    settingsVersion = 0;                           // re-apply on the next reply
    ackCommand(id, true, "Settings will reload");
  } else if (name == "reboot") {
    ackCommand(id, true, "Rebooting");
    delay(300);
    ESP.restart();
  } else {
    ackCommand(id, false, "Unknown command");
  }
}

/* Handle {"data":{"settings":{...},"pending_commands":[...]}}. */
void handleReply(const String& resp) {
  JsonDocument doc;
  if (deserializeJson(doc, resp)) return;
  JsonObject data = doc["data"].as<JsonObject>();
  applySettings(data["settings"].as<JsonObject>());
  JsonArray cmds = data["pending_commands"].as<JsonArray>();
  for (JsonObject c : cmds) handleCommand(c);
}

/* Replay readings buffered while the server was unreachable. */
void flushQueues() {
  if (!serverOk || sampleCount == 0) return;
  int ns = min(sampleCount, (int)SYNC_BATCH_SIZE);

  String body = "{\"esp32_uid\":\"" DEVICE_UID "\",\"readings\":[";
  for (int i = 0; i < ns; i++) { if (i) body += ","; body += sampleQueue[i]; }
  body += "]}";

  String r;
  int code = postJson("/api/esp32/sync.php", body, r);
  if (code >= 200 && code < 300) {
    queueDrop(sampleQueue, sampleCount, ns);
    Serial.print(">> Synced buffered readings. Still queued: "); Serial.println(sampleCount);
  }
}

void startWifi() {
  WiFi.mode(WIFI_STA);
  WiFi.setAutoReconnect(true);
  WiFi.begin(WIFI_SSID, WIFI_PASSWORD);
  wifiLastTry = millis();
}

void syncSetup() {
  startWifi();
  Serial.print("Connecting to Wi-Fi '" WIFI_SSID "' ");
  unsigned long t0 = millis();
  while (!wifiUp() && millis() - t0 < WIFI_CONNECT_TIMEOUT) { delay(300); Serial.print("."); }
  Serial.println();
  if (wifiUp()) {
    Serial.print("Wi-Fi OK, IP "); Serial.println(WiFi.localIP());
    configTime(NTP_GMT_OFFSET_SEC, NTP_DST_OFFSET_SEC, NTP_SERVER);
  } else {
    Serial.println("Wi-Fi not connected - running offline, will keep retrying.");
  }
}

/* Called from loop(). Does nothing slow unless an interval has elapsed.
 * While the server is unreachable each attempt can block for a few seconds,
 * so retries back off to every 30 s to keep the control loop responsive. */
void syncLoop() {
  static unsigned long lastUpload = 0, lastPoll = 0;
  static bool ntpStarted = false;

  if (!wifiUp()) {
    serverOk = false;
    if (millis() - wifiLastTry >= WIFI_RETRY_INTERVAL) { WiFi.disconnect(); startWifi(); }
    return;
  }
  if (!ntpStarted) {
    configTime(NTP_GMT_OFFSET_SEC, NTP_DST_OFFSET_SEC, NTP_SERVER);
    ntpStarted = true;
  }

  sendEvents();

  /* Dashboard commands (lightweight heartbeat) - keeps the stop buttons responsive */
  unsigned long pollEvery = serverOk ? COMMAND_POLL_INTERVAL : 30000UL;
  if (millis() - lastPoll >= pollEvery) {
    lastPoll = millis();
    String r;
    int code = postJson("/api/esp32/status.php", "{" + idJson() + "}", r);
    if (code >= 200 && code < 300) handleReply(r);
  }

  /* Telemetry. Skipped while the pump is filling to FULL: the stop check must
   * not wait behind a network request, so the reading is buffered instead. */
  if (millis() - lastUpload >= UPLOAD_INTERVAL) {
    lastUpload = millis();
    if (pump.on && pump.stopAtFull) {
      queuePush(sampleQueue, sampleCount, SAMPLE_QUEUE_MAX, "{" + sampleJson(true) + "}");
      return;
    }
    String r;
    int code = postJson("/api/esp32/sensor-data.php", "{" + idJson() + "," + sampleJson(false) + "}", r);
    if (code >= 200 && code < 300) {
      handleReply(r);
      flushQueues();
    } else {
      queuePush(sampleQueue, sampleCount, SAMPLE_QUEUE_MAX, "{" + sampleJson(true) + "}");
    }
  }
}

#else  /* sync disabled */
void reportFinished(Channel&, const char*) {}
#endif

/* ================= SETUP & LOOP ================= */
void setup() {
  writeRelay(PIN_PUMP, false);
  writeRelay(PIN_MOTOR, false);
  pinMode(PIN_PUMP, OUTPUT);
  pinMode(PIN_MOTOR, OUTPUT);

#if WATER_SENSOR_TYPE == 1
  pinMode(PIN_WATER_VCC, OUTPUT);
  digitalWrite(PIN_WATER_VCC, LOW);
#else
  pinMode(PIN_FLOAT, INPUT_PULLUP);
#endif

  Serial.begin(115200);
  delay(300);
  Serial.println();
  Serial.println("=== PoultryGuard - auto refill + manual control ===");
  Serial.print("Pump  relay on GPIO "); Serial.print(PIN_PUMP);  Serial.println(" (wire to IN1)");
  Serial.print("Motor relay on GPIO "); Serial.print(PIN_MOTOR); Serial.println(" (wire to IN2)");
  Serial.println("Auto refill is ON: if the water sensor reads LOW, the pump starts.");

  dht.begin();
  printHelp();

#if ENABLE_SYNC
  syncSetup();
#endif
}

void loop() {
  static String line = "";
  static unsigned long lastWater = 0, lastDht = 0, lastReport = 0;

  while (Serial.available()) {
    char c = Serial.read();
    if (c == '\n' || c == '\r') { runCommand(line); line = ""; }
    else if (line.length() < 40) line += c;
  }

  if (millis() - lastWater  >= 1000) { lastWater  = millis(); readWater(); autoRefillControl(); }
  if (millis() - lastDht    >= 2500) { lastDht    = millis(); readClimate(); }  // DHT11 is slow

  updateChannel(pump);
  updateChannel(motor);

#if ENABLE_SYNC
  syncLoop();
  updateChannel(pump);      // a slow request must not delay the safety timers
  updateChannel(motor);
#endif

  if (millis() - lastReport >= REPORT_EVERY_MS) { lastReport = millis(); printStatus(); }
}
