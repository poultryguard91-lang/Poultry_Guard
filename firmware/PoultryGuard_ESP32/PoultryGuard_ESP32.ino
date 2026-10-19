/* =====================================================================
 *  PoultryGuard - ESP32 controller firmware
 *  Precision IoT Automation for Poultry Feeding and Hydration
 *
 *  Hardware
 *    ESP32 DevKit V1
 *    Load cell (5/10 kg) + HX711 amplifier  -> feed weight
 *    HC-SR04 ultrasonic sensor              -> water level
 *    2-channel relay module                 -> 12 V auger motor, 12 V solenoid valve
 *
 *  Design principle
 *  ----------------
 *  The birds must be fed and watered whether or not the network is up, so
 *  ALL control decisions are made here, on the device, from settings held in
 *  NVS. The server is where those settings are edited and where history is
 *  reported - it is never in the control loop. If Wi-Fi, the router, the PC
 *  or MySQL goes down, this sketch carries on feeding and watering to the
 *  same rules and buffers what happened in flash until it can upload it.
 *
 *  Required libraries (Arduino IDE -> Tools -> Manage Libraries)
 *    - "HX711 Arduino Library" by Bogdan Necula (bogde)
 *    - "ArduinoJson" by Benoit Blanchon (v6 or v7)
 *  Board package: esp32 by Espressif Systems
 *
 *  Board settings: ESP32 Dev Module, 115200 baud, default partition scheme.
 * ===================================================================== */

#include <WiFi.h>
#include <HTTPClient.h>
#include <ArduinoJson.h>
#include <Preferences.h>
#include <FS.h>
#include <LittleFS.h>
#include <HX711.h>
#include <time.h>

#include "config.h"

/* =====================================================================
 *  Logging
 * ===================================================================== */
#if DEBUG_SERIAL
  #define LOG(...)    Serial.printf(__VA_ARGS__)
  #define LOGLN(x)    Serial.println(x)
#else
  #define LOG(...)
  #define LOGLN(x)
#endif

/* =====================================================================
 *  Runtime settings
 *
 *  Mirrors system_settings on the server. Loaded from NVS at boot so the
 *  device is immediately operational, refreshed from the API when online.
 * ===================================================================== */
struct Settings {
  float    feedCapacity      = DEF_FEED_CAPACITY;
  float    feedThreshold     = DEF_FEED_THRESHOLD;
  float    feedTarget        = DEF_FEED_TARGET;
  uint32_t feedMaxDuration   = DEF_FEED_MAX_DURATION;
  float    feedTolerance     = DEF_FEED_TOLERANCE;
  bool     autoFeed          = DEF_AUTO_FEED;

  float    waterCapacity     = DEF_WATER_CAPACITY;
  float    sensorOffset      = DEF_SENSOR_OFFSET;
  float    waterThreshold    = DEF_WATER_THRESHOLD;
  float    waterTarget       = DEF_WATER_TARGET;
  uint32_t waterMaxDuration  = DEF_WATER_MAX_DURATION;
  bool     autoWater         = DEF_AUTO_WATER;

  float    loadCellFactor    = DEF_LOAD_CELL_FACTOR;
  float    loadCellOffset    = DEF_LOAD_CELL_OFFSET;

  uint32_t readingInterval   = UPLOAD_INTERVAL / 1000;
  uint32_t settingsVersion   = 0;   /* server's updated_at as a unix time */
};

Settings settings;
Preferences prefs;

/* =====================================================================
 *  Sensor state
 * ===================================================================== */
HX711 scale;

struct SensorState {
  float feedWeight     = 0.0f;   /* grams                      */
  float feedPercentage = 0.0f;
  long  feedRaw        = 0;
  bool  feedValid      = false;

  float distanceCm     = 0.0f;
  float waterLevel     = 0.0f;   /* cm of depth                */
  float waterPercentage = 0.0f;
  bool  waterValid     = false;

  uint8_t feedErrorCount  = 0;
  uint8_t waterErrorCount = 0;
};

SensorState sensors;

/* =====================================================================
 *  Actuator state machines
 * ===================================================================== */
enum ActuatorState { ACT_IDLE, ACT_RUNNING };

struct DispensingJob {
  ActuatorState state       = ACT_IDLE;
  bool     isManual         = false;
  uint32_t userId           = 0;     /* operator who requested it, 0 = automatic */
  uint32_t commandId        = 0;     /* command being fulfilled, 0 = automatic   */
  float    startValue       = 0.0f;
  float    targetValue      = 0.0f;
  uint32_t startedAt        = 0;
  uint32_t lastProgressAt   = 0;
  float    lastProgressValue = 0.0f;
  uint32_t maxDurationMs    = 0;
  uint32_t finishedAt       = 0;     /* for the minimum-rest check */
};

DispensingJob feedJob;
DispensingJob waterJob;

bool emergencyStopped = false;

/* =====================================================================
 *  Connectivity state
 * ===================================================================== */
bool     wifiConnected     = false;
bool     serverReachable   = false;
bool     timeSynced        = false;
uint32_t bufferedRecords   = 0;

uint32_t lastSensorRead    = 0;
uint32_t lastUpload        = 0;
uint32_t lastCommandPoll   = 0;
uint32_t lastConfigRefresh = 0;
uint32_t lastSyncAttempt   = 0;
uint32_t lastWifiAttempt   = 0;
uint32_t lastLedBlink      = 0;

/* =====================================================================
 *  Relay helpers
 *
 *  Written once here so the rest of the sketch never has to think about
 *  whether the board is active-high or active-low.
 * ===================================================================== */
inline void relayWrite(uint8_t pin, bool on) {
#if RELAY_ACTIVE_LOW
  digitalWrite(pin, on ? LOW : HIGH);
#else
  digitalWrite(pin, on ? HIGH : LOW);
#endif
}

inline void motorOn()   { relayWrite(PIN_RELAY_MOTOR, true);  }
inline void motorOff()  { relayWrite(PIN_RELAY_MOTOR, false); }
inline void valveOpen() { relayWrite(PIN_RELAY_VALVE, true);  }
inline void valveClose(){ relayWrite(PIN_RELAY_VALVE, false); }

/* =====================================================================
 *  Forward declarations
 * ===================================================================== */
void loadSettings();
void saveSettings();
void readSensors();
void runAutomation();
void superviseActuators();
void connectWiFi();
bool uploadReading();
void pollCommands();
void refreshConfig();
void syncOfflineBuffer();
void bufferReading();
void bufferDispensing(const char* type, const DispensingJob& job, const char* status,
                      float endValue, uint32_t durationMs);
void reportDispensing(const char* type, const DispensingJob& job, const char* status,
                      float endValue, uint32_t durationMs);
void acknowledgeCommand(uint32_t commandId, const char* status, const char* result);
String isoTimestamp();
void applySettingsJson(JsonObjectConst obj);
void handleCommand(JsonObjectConst cmd);
void startFeedDispensing(float target, bool manual, uint32_t userId, uint32_t commandId);
void startWaterRefill(float target, bool manual, uint32_t userId, uint32_t commandId);
void stopFeed(const char* status, const char* reason);
void stopWater(const char* status, const char* reason);
void doEmergencyStop(const char* reason);

/* =====================================================================
 *  SETUP
 * ===================================================================== */
void setup() {
  Serial.begin(115200);
  delay(300);

  LOGLN(F("\n========================================"));
  LOGLN(F(" PoultryGuard ESP32 Controller"));
  LOG(" Firmware %s\n", FIRMWARE_VERSION);
  LOG(" Device UID: %s\n", DEVICE_UID);
  LOGLN(F("========================================"));

  /* --- Actuators: drive them OFF before anything else, so a reset can
   *     never leave the auger or valve energised. --- */
  pinMode(PIN_RELAY_MOTOR, OUTPUT);
  pinMode(PIN_RELAY_VALVE, OUTPUT);
  motorOff();
  valveClose();

  pinMode(PIN_STATUS_LED, OUTPUT);
  digitalWrite(PIN_STATUS_LED, LOW);

#if PIN_EMERGENCY_STOP >= 0
  pinMode(PIN_EMERGENCY_STOP, INPUT_PULLUP);
#endif

  /* --- Sensors --- */
  pinMode(PIN_ULTRASONIC_TRIG, OUTPUT);
  pinMode(PIN_ULTRASONIC_ECHO, INPUT);
  digitalWrite(PIN_ULTRASONIC_TRIG, LOW);

  scale.begin(PIN_HX711_DOUT, PIN_HX711_SCK);

  /* --- Storage --- */
  if (!LittleFS.begin(true)) {
    LOGLN(F("[FS] LittleFS mount failed. Offline buffering is disabled."));
  } else {
    File f = LittleFS.open(OFFLINE_BUFFER_FILE, FILE_READ);
    if (f) {
      while (f.available()) {
        if (f.read() == '\n') bufferedRecords++;
      }
      f.close();
      LOG("[FS] %u buffered record(s) waiting to upload\n", bufferedRecords);
    }
  }

  loadSettings();
  scale.set_scale(settings.loadCellFactor);
  scale.set_offset((long)settings.loadCellOffset);

  /* Take a first reading immediately so automation has real data to act on
   * even if the network never comes up. */
  readSensors();

  connectWiFi();
  if (wifiConnected) {
    configTime(NTP_GMT_OFFSET_SEC, NTP_DST_OFFSET_SEC, NTP_SERVER);
    refreshConfig();
  }

  LOGLN(F("[SYS] Ready. Local automation is active."));
}

/* =====================================================================
 *  MAIN LOOP
 *
 *  Deliberately non-blocking: no long delay() anywhere, so the safety
 *  timeouts are checked continuously while an actuator is running.
 * ===================================================================== */
void loop() {
  uint32_t now = millis();

#if PIN_EMERGENCY_STOP >= 0
  /* A physical button beats everything else, including the network. */
  if (digitalRead(PIN_EMERGENCY_STOP) == LOW && !emergencyStopped) {
    doEmergencyStop("Physical emergency stop button pressed");
  }
#endif

  /* ---- 1. Sensors ------------------------------------------------- */
  if (now - lastSensorRead >= SENSOR_READ_INTERVAL) {
    lastSensorRead = now;
    readSensors();
  }

  /* ---- 2. Safety supervision (runs every pass, not on a timer) ----- */
  superviseActuators();

  /* ---- 3. Local automation ---------------------------------------- */
  runAutomation();

  /* ---- 4. Network -------------------------------------------------- */
  if (WiFi.status() != WL_CONNECTED) {
    if (wifiConnected) {
      LOGLN(F("[NET] Wi-Fi lost. Continuing on local automation."));
      wifiConnected = false;
      serverReachable = false;
    }
    if (now - lastWifiAttempt >= WIFI_RETRY_INTERVAL) {
      lastWifiAttempt = now;
      connectWiFi();
    }
  } else {
    wifiConnected = true;

    if (now - lastUpload >= (settings.readingInterval * 1000UL)) {
      lastUpload = now;
      if (!uploadReading()) {
        /* The upload failed, so keep the sample rather than losing it. */
        bufferReading();
      }
    }

    if (now - lastCommandPoll >= COMMAND_POLL_INTERVAL) {
      lastCommandPoll = now;
      pollCommands();
    }

    if (now - lastConfigRefresh >= CONFIG_REFRESH_INTERVAL) {
      lastConfigRefresh = now;
      refreshConfig();
    }

    if (bufferedRecords > 0 && now - lastSyncAttempt >= SYNC_RETRY_INTERVAL) {
      lastSyncAttempt = now;
      syncOfflineBuffer();
    }
  }

  /* While offline, still take a sample on the normal cadence and buffer it. */
  if (!wifiConnected && now - lastUpload >= (settings.readingInterval * 1000UL)) {
    lastUpload = now;
    bufferReading();
  }

  /* ---- 5. Status LED ----------------------------------------------
   *  solid  = online and healthy
   *  slow blink = running offline on local automation
   *  fast blink = an actuator is running
   * ------------------------------------------------------------------ */
  uint32_t blinkRate = (feedJob.state == ACT_RUNNING || waterJob.state == ACT_RUNNING) ? 150
                     : (serverReachable ? 0 : 1000);
  if (blinkRate == 0) {
    digitalWrite(PIN_STATUS_LED, HIGH);
  } else if (now - lastLedBlink >= blinkRate) {
    lastLedBlink = now;
    digitalWrite(PIN_STATUS_LED, !digitalRead(PIN_STATUS_LED));
  }
}

/* =====================================================================
 *  SETTINGS PERSISTENCE (NVS)
 *
 *  This is what makes offline operation real: the rules survive a power
 *  cut, so a controller that reboots with no network still knows exactly
 *  when to feed and how much.
 * ===================================================================== */
void loadSettings() {
  prefs.begin("poultryguard", true);   /* read-only */

  settings.feedCapacity     = prefs.getFloat("fCap",   DEF_FEED_CAPACITY);
  settings.feedThreshold    = prefs.getFloat("fThr",   DEF_FEED_THRESHOLD);
  settings.feedTarget       = prefs.getFloat("fTgt",   DEF_FEED_TARGET);
  settings.feedMaxDuration  = prefs.getUInt("fDur",    DEF_FEED_MAX_DURATION);
  settings.feedTolerance    = prefs.getFloat("fTol",   DEF_FEED_TOLERANCE);
  settings.autoFeed         = prefs.getBool("fAuto",   DEF_AUTO_FEED);

  settings.waterCapacity    = prefs.getFloat("wCap",   DEF_WATER_CAPACITY);
  settings.sensorOffset     = prefs.getFloat("wOff",   DEF_SENSOR_OFFSET);
  settings.waterThreshold   = prefs.getFloat("wThr",   DEF_WATER_THRESHOLD);
  settings.waterTarget      = prefs.getFloat("wTgt",   DEF_WATER_TARGET);
  settings.waterMaxDuration = prefs.getUInt("wDur",    DEF_WATER_MAX_DURATION);
  settings.autoWater        = prefs.getBool("wAuto",   DEF_AUTO_WATER);

  settings.loadCellFactor   = prefs.getFloat("lcFac",  DEF_LOAD_CELL_FACTOR);
  settings.loadCellOffset   = prefs.getFloat("lcOff",  DEF_LOAD_CELL_OFFSET);

  settings.readingInterval  = prefs.getUInt("interval", UPLOAD_INTERVAL / 1000);
  settings.settingsVersion  = prefs.getUInt("sVer",    0);

  prefs.end();

  LOGLN(F("[CFG] Settings loaded from NVS:"));
  LOG("      Feed  : refill below %.0f g, up to %.0f g (auto %s)\n",
      settings.feedThreshold, settings.feedTarget, settings.autoFeed ? "ON" : "OFF");
  LOG("      Water : refill below %.1f cm, up to %.1f cm (auto %s)\n",
      settings.waterThreshold, settings.waterTarget, settings.autoWater ? "ON" : "OFF");
  LOG("      Scale : factor %.4f, offset %.0f\n",
      settings.loadCellFactor, settings.loadCellOffset);
}

void saveSettings() {
  prefs.begin("poultryguard", false);  /* read-write */

  prefs.putFloat("fCap",  settings.feedCapacity);
  prefs.putFloat("fThr",  settings.feedThreshold);
  prefs.putFloat("fTgt",  settings.feedTarget);
  prefs.putUInt("fDur",   settings.feedMaxDuration);
  prefs.putFloat("fTol",  settings.feedTolerance);
  prefs.putBool("fAuto",  settings.autoFeed);

  prefs.putFloat("wCap",  settings.waterCapacity);
  prefs.putFloat("wOff",  settings.sensorOffset);
  prefs.putFloat("wThr",  settings.waterThreshold);
  prefs.putFloat("wTgt",  settings.waterTarget);
  prefs.putUInt("wDur",   settings.waterMaxDuration);
  prefs.putBool("wAuto",  settings.autoWater);

  prefs.putFloat("lcFac", settings.loadCellFactor);
  prefs.putFloat("lcOff", settings.loadCellOffset);

  prefs.putUInt("interval", settings.readingInterval);
  prefs.putUInt("sVer",     settings.settingsVersion);

  prefs.end();
  LOGLN(F("[CFG] Settings saved to NVS."));
}

/* Copy a settings object from the API into RAM and persist it. */
void applySettingsJson(JsonObjectConst s) {
  if (s.isNull()) return;

  uint32_t version = s["settings_version"] | 0;
  bool changed = (version != settings.settingsVersion);

  settings.feedCapacity     = s["feed_capacity"]      | settings.feedCapacity;
  settings.feedThreshold    = s["feed_threshold"]     | settings.feedThreshold;
  settings.feedTarget       = s["target_feed"]        | settings.feedTarget;
  settings.feedMaxDuration  = s["feed_max_duration"]  | settings.feedMaxDuration;
  settings.feedTolerance    = s["feed_tolerance"]     | settings.feedTolerance;
  settings.autoFeed         = s["auto_feed"]          | settings.autoFeed;

  settings.waterCapacity    = s["water_capacity"]     | settings.waterCapacity;
  settings.sensorOffset     = s["sensor_offset_cm"]   | settings.sensorOffset;
  settings.waterThreshold   = s["water_threshold"]    | settings.waterThreshold;
  settings.waterTarget      = s["water_target"]       | settings.waterTarget;
  settings.waterMaxDuration = s["water_max_duration"] | settings.waterMaxDuration;
  settings.autoWater        = s["auto_water"]         | settings.autoWater;

  float newFactor = s["load_cell_factor"] | settings.loadCellFactor;
  float newOffset = s["load_cell_offset"] | settings.loadCellOffset;
  if (newFactor != settings.loadCellFactor || newOffset != settings.loadCellOffset) {
    settings.loadCellFactor = newFactor;
    settings.loadCellOffset = newOffset;
    scale.set_scale(settings.loadCellFactor);
    scale.set_offset((long)settings.loadCellOffset);
    LOGLN(F("[CFG] New calibration applied to the scale."));
  }

  settings.readingInterval = s["reading_interval"] | settings.readingInterval;
  if (settings.readingInterval < 5) settings.readingInterval = 5;

  if (changed) {
    settings.settingsVersion = version;
    saveSettings();
    LOGLN(F("[CFG] Settings updated from the server."));
  }
}

/* =====================================================================
 *  SENSORS
 * ===================================================================== */

/**
 * Read the load cell.
 *
 * get_units() averages several samples in hardware terms; anything outside
 * a physically plausible range is treated as a fault rather than acted on -
 * dispensing on a bad reading could overfill or run the auger dry.
 */
void readFeedSensor() {
  if (!scale.wait_ready_timeout(300)) {
    sensors.feedValid = false;
    if (sensors.feedErrorCount < 250) sensors.feedErrorCount++;
    LOGLN(F("[SEN] HX711 not responding."));
    return;
  }

  sensors.feedRaw = scale.read_average(5);
  float grams = scale.get_units(5);

  if (isnan(grams) || grams < -500.0f || grams > MAX_PLAUSIBLE_WEIGHT) {
    sensors.feedValid = false;
    if (sensors.feedErrorCount < 250) sensors.feedErrorCount++;
    LOG("[SEN] Implausible weight %.1f g rejected.\n", grams);
    return;
  }

  /* A small negative value is normal drift around zero; clamp it. */
  if (grams < 0) grams = 0;

  sensors.feedWeight     = grams;
  sensors.feedPercentage = settings.feedCapacity > 0
      ? constrain(grams / settings.feedCapacity * 100.0f, 0.0f, 100.0f) : 0.0f;
  sensors.feedValid      = true;
  sensors.feedErrorCount = 0;
}

/**
 * Single HC-SR04 pulse. Returns -1 on no echo.
 */
float pingOnce() {
  digitalWrite(PIN_ULTRASONIC_TRIG, LOW);
  delayMicroseconds(4);
  digitalWrite(PIN_ULTRASONIC_TRIG, HIGH);
  delayMicroseconds(10);
  digitalWrite(PIN_ULTRASONIC_TRIG, LOW);

  /* 30 ms covers the sensor's full 4 m range with margin. */
  unsigned long duration = pulseIn(PIN_ULTRASONIC_ECHO, HIGH, 30000UL);
  if (duration == 0) return -1.0f;

  /* Sound travels ~0.0343 cm/us; halve it for the round trip. */
  return (duration * 0.0343f) / 2.0f;
}

/**
 * Read the water level.
 *
 * Ultrasonic sensors on a water surface produce the occasional wild value,
 * so five pings are taken and the median used - one bad echo cannot move
 * the result.
 */
void readWaterSensor() {
  float samples[5];
  uint8_t good = 0;

  for (uint8_t i = 0; i < 5; i++) {
    float d = pingOnce();
    if (d >= ULTRASONIC_MIN_CM && d <= ULTRASONIC_MAX_CM) {
      samples[good++] = d;
    }
    /* The datasheet asks for at least 60 ms between measurement cycles;
     * this delay plus the pulseIn time above stays within that. */
    delay(30);
  }

  if (good < 3) {
    sensors.waterValid = false;
    if (sensors.waterErrorCount < 250) sensors.waterErrorCount++;
    LOGLN(F("[SEN] HC-SR04 gave too few valid echoes."));
    return;
  }

  /* Insertion sort - only ever up to 5 elements. */
  for (uint8_t i = 1; i < good; i++) {
    float key = samples[i];
    int8_t j = i - 1;
    while (j >= 0 && samples[j] > key) {
      samples[j + 1] = samples[j];
      j--;
    }
    samples[j + 1] = key;
  }
  float distance = samples[good / 2];

  /* depth = tank depth - (distance - sensor offset) */
  float level = settings.waterCapacity - (distance - settings.sensorOffset);
  level = constrain(level, 0.0f, settings.waterCapacity);

  sensors.distanceCm      = distance;
  sensors.waterLevel      = level;
  sensors.waterPercentage = settings.waterCapacity > 0
      ? constrain(level / settings.waterCapacity * 100.0f, 0.0f, 100.0f) : 0.0f;
  sensors.waterValid      = true;
  sensors.waterErrorCount = 0;
}

void readSensors() {
  readFeedSensor();
  readWaterSensor();

#if DEBUG_SERIAL
  LOG("[SEN] Feed %.0f g (%.1f%%) %s | Water %.1f cm (%.1f%%) dist %.1f cm %s\n",
      sensors.feedWeight, sensors.feedPercentage, sensors.feedValid ? "ok" : "FAULT",
      sensors.waterLevel, sensors.waterPercentage, sensors.distanceCm,
      sensors.waterValid ? "ok" : "FAULT");
#endif
}

/* =====================================================================
 *  LOCAL AUTOMATION
 *
 *  This runs identically online and offline. Nothing here consults the
 *  network.
 * ===================================================================== */
void runAutomation() {
  uint32_t now = millis();

  if (emergencyStopped) return;

  /* ---------------- Feed ---------------- */
  if (settings.autoFeed &&
      feedJob.state == ACT_IDLE &&
      sensors.feedValid &&
      sensors.feedWeight < settings.feedThreshold &&
      (feedJob.finishedAt == 0 || now - feedJob.finishedAt >= MIN_CYCLE_REST)) {

    LOG("[AUT] Feed %.0f g is below the %.0f g threshold - dispensing to %.0f g\n",
        sensors.feedWeight, settings.feedThreshold, settings.feedTarget);
    startFeedDispensing(settings.feedTarget, false, 0, 0);
  }

  /* ---------------- Water ---------------- */
  if (settings.autoWater &&
      waterJob.state == ACT_IDLE &&
      sensors.waterValid &&
      sensors.waterLevel < settings.waterThreshold &&
      (waterJob.finishedAt == 0 || now - waterJob.finishedAt >= MIN_CYCLE_REST)) {

    LOG("[AUT] Water %.1f cm is below the %.1f cm threshold - refilling to %.1f cm\n",
        sensors.waterLevel, settings.waterThreshold, settings.waterTarget);
    startWaterRefill(settings.waterTarget, false, 0, 0);
  }
}

void startFeedDispensing(float target, bool manual, uint32_t userId, uint32_t commandId) {
  if (emergencyStopped) {
    LOGLN(F("[ACT] Refused: the system is emergency-stopped."));
    if (commandId) acknowledgeCommand(commandId, "failed", "System is emergency-stopped");
    return;
  }
  if (feedJob.state == ACT_RUNNING) {
    if (commandId) acknowledgeCommand(commandId, "failed", "Auger is already running");
    return;
  }
  if (!sensors.feedValid) {
    LOGLN(F("[ACT] Refused: the load cell is not reporting a valid weight."));
    if (commandId) acknowledgeCommand(commandId, "failed", "Load cell fault - refusing to dispense");
    return;
  }
  if (target > settings.feedCapacity) target = settings.feedCapacity;
  if (target <= sensors.feedWeight + settings.feedTolerance) {
    LOGLN(F("[ACT] Refused: the hopper already holds at least the target weight."));
    if (commandId) acknowledgeCommand(commandId, "failed", "Hopper already at or above the target");
    return;
  }

  feedJob.state             = ACT_RUNNING;
  feedJob.isManual          = manual;
  feedJob.userId            = userId;
  feedJob.commandId         = commandId;
  feedJob.startValue        = sensors.feedWeight;
  feedJob.targetValue       = target;
  feedJob.startedAt         = millis();
  feedJob.lastProgressAt    = millis();
  feedJob.lastProgressValue = sensors.feedWeight;
  feedJob.maxDurationMs     = settings.feedMaxDuration * 1000UL;

  motorOn();
  LOG("[ACT] Auger ON  (%.0f g -> %.0f g, max %lu s)\n",
      feedJob.startValue, feedJob.targetValue, (unsigned long)settings.feedMaxDuration);
}

void startWaterRefill(float target, bool manual, uint32_t userId, uint32_t commandId) {
  if (emergencyStopped) {
    LOGLN(F("[ACT] Refused: the system is emergency-stopped."));
    if (commandId) acknowledgeCommand(commandId, "failed", "System is emergency-stopped");
    return;
  }
  if (waterJob.state == ACT_RUNNING) {
    if (commandId) acknowledgeCommand(commandId, "failed", "Valve is already open");
    return;
  }
  if (!sensors.waterValid) {
    LOGLN(F("[ACT] Refused: the ultrasonic sensor is not reporting a valid level."));
    if (commandId) acknowledgeCommand(commandId, "failed", "Ultrasonic fault - refusing to refill");
    return;
  }
  if (target > settings.waterCapacity) target = settings.waterCapacity;
  if (target <= sensors.waterLevel + 1.0f) {
    LOGLN(F("[ACT] Refused: the tank is already at or above the target level."));
    if (commandId) acknowledgeCommand(commandId, "failed", "Tank already at or above the target");
    return;
  }

  waterJob.state             = ACT_RUNNING;
  waterJob.isManual          = manual;
  waterJob.userId            = userId;
  waterJob.commandId         = commandId;
  waterJob.startValue        = sensors.waterLevel;
  waterJob.targetValue       = target;
  waterJob.startedAt         = millis();
  waterJob.lastProgressAt    = millis();
  waterJob.lastProgressValue = sensors.waterLevel;
  waterJob.maxDurationMs     = settings.waterMaxDuration * 1000UL;

  valveOpen();
  LOG("[ACT] Valve OPEN (%.1f cm -> %.1f cm, max %lu s)\n",
      waterJob.startValue, waterJob.targetValue, (unsigned long)settings.waterMaxDuration);
}

/* =====================================================================
 *  SAFETY SUPERVISION
 *
 *  Called on every pass of loop(). This is the code that guarantees an
 *  actuator can never be left running: target reached, timeout expired,
 *  no measurable progress, or a sensor fault all stop it.
 * ===================================================================== */
void superviseActuators() {
  uint32_t now = millis();

  /* ---------------- Feed auger ---------------- */
  if (feedJob.state == ACT_RUNNING) {
    uint32_t elapsed = now - feedJob.startedAt;

    if (sensors.feedValid &&
        sensors.feedWeight >= feedJob.targetValue - settings.feedTolerance) {
      stopFeed("completed", "Target weight reached");
    }
    else if (elapsed >= feedJob.maxDurationMs) {
      /* Hard safety limit - the single most important rule in the sketch. */
      stopFeed("timeout", "Maximum motor run time reached");
    }
    else if (!sensors.feedValid && sensors.feedErrorCount >= 3) {
      stopFeed("failed", "Load cell stopped reporting during dispensing");
    }
    else if (sensors.feedValid) {
      /* Progress watchdog: the weight must actually be rising. */
      if (sensors.feedWeight >= feedJob.lastProgressValue + MIN_PROGRESS_GRAMS) {
        feedJob.lastProgressAt    = now;
        feedJob.lastProgressValue = sensors.feedWeight;
      } else if (now - feedJob.lastProgressAt >= MOTOR_PROGRESS_WINDOW) {
        stopFeed("failed", "No weight increase - the hopper may be empty or the auger jammed");
      }
    }
  }

  /* ---------------- Water valve ---------------- */
  if (waterJob.state == ACT_RUNNING) {
    uint32_t elapsed = now - waterJob.startedAt;

    if (sensors.waterValid && sensors.waterLevel >= waterJob.targetValue - 0.5f) {
      stopWater("completed", "Target level reached");
    }
    else if (elapsed >= waterJob.maxDurationMs) {
      stopWater("timeout", "Maximum valve open time reached");
    }
    else if (!sensors.waterValid && sensors.waterErrorCount >= 3) {
      stopWater("failed", "Ultrasonic sensor stopped reporting during refill");
    }
    else if (sensors.waterValid) {
      if (sensors.waterLevel >= waterJob.lastProgressValue + MIN_PROGRESS_CM) {
        waterJob.lastProgressAt    = now;
        waterJob.lastProgressValue = sensors.waterLevel;
      } else if (now - waterJob.lastProgressAt >= VALVE_PROGRESS_WINDOW) {
        stopWater("failed", "No level rise - check the supply line and valve");
      }
    }
  }
}

void stopFeed(const char* status, const char* reason) {
  if (feedJob.state != ACT_RUNNING) return;

  motorOff();
  uint32_t duration = millis() - feedJob.startedAt;
  float endWeight = sensors.feedValid ? sensors.feedWeight : feedJob.lastProgressValue;

  LOG("[ACT] Auger OFF (%s): %s | %.0f g -> %.0f g in %.1f s\n",
      status, reason, feedJob.startValue, endWeight, duration / 1000.0f);

  feedJob.state      = ACT_IDLE;
  feedJob.finishedAt = millis();

  reportDispensing("feed", feedJob, status, endWeight, duration);

  if (feedJob.commandId) {
    acknowledgeCommand(feedJob.commandId,
                       strcmp(status, "completed") == 0 ? "executed" : "failed",
                       reason);
    feedJob.commandId = 0;
  }
}

void stopWater(const char* status, const char* reason) {
  if (waterJob.state != ACT_RUNNING) return;

  valveClose();
  uint32_t duration = millis() - waterJob.startedAt;
  float endLevel = sensors.waterValid ? sensors.waterLevel : waterJob.lastProgressValue;

  LOG("[ACT] Valve CLOSED (%s): %s | %.1f cm -> %.1f cm in %.1f s\n",
      status, reason, waterJob.startValue, endLevel, duration / 1000.0f);

  waterJob.state      = ACT_IDLE;
  waterJob.finishedAt = millis();

  reportDispensing("water", waterJob, status, endLevel, duration);

  if (waterJob.commandId) {
    acknowledgeCommand(waterJob.commandId,
                       strcmp(status, "completed") == 0 ? "executed" : "failed",
                       reason);
    waterJob.commandId = 0;
  }
}

void doEmergencyStop(const char* reason) {
  LOG("[!!!] EMERGENCY STOP: %s\n", reason);

  if (feedJob.state == ACT_RUNNING)  stopFeed("aborted", reason);
  if (waterJob.state == ACT_RUNNING) stopWater("aborted", reason);

  motorOff();
  valveClose();

  emergencyStopped   = true;
  settings.autoFeed  = false;
  settings.autoWater = false;
  saveSettings();

  LOGLN(F("[!!!] Automation disabled. Re-enable it in Settings on the dashboard."));
}

/* =====================================================================
 *  NETWORK
 * ===================================================================== */
void connectWiFi() {
  LOG("[NET] Connecting to Wi-Fi \"%s\" ...\n", WIFI_SSID);

  WiFi.mode(WIFI_STA);
  WiFi.setSleep(false);          /* keeps the connection responsive */
  WiFi.begin(WIFI_SSID, WIFI_PASSWORD);

  uint32_t start = millis();
  while (WiFi.status() != WL_CONNECTED && millis() - start < WIFI_CONNECT_TIMEOUT) {
    /* Short waits only: the safety supervisor still needs to run if an
     * actuator happens to be active while we are connecting. */
    superviseActuators();
    delay(100);
  }

  if (WiFi.status() == WL_CONNECTED) {
    wifiConnected = true;
    LOG("[NET] Connected. IP %s, RSSI %d dBm\n",
        WiFi.localIP().toString().c_str(), WiFi.RSSI());
    if (!timeSynced) {
      configTime(NTP_GMT_OFFSET_SEC, NTP_DST_OFFSET_SEC, NTP_SERVER);
    }
  } else {
    wifiConnected = false;
    LOGLN(F("[NET] Connection failed. Running on local automation."));
  }
}

/** ISO-ish timestamp for buffered records; empty until NTP has synced. */
String isoTimestamp() {
  struct tm t;
  if (!getLocalTime(&t, 100)) return String("");
  timeSynced = true;
  char buf[24];
  strftime(buf, sizeof(buf), "%Y-%m-%d %H:%M:%S", &t);
  return String(buf);
}

/** Build the URL for an API endpoint. */
String apiUrl(const char* path) {
  return String(SERVER_BASE_URL) + path;
}

/**
 * POST a JSON document and parse the reply.
 * Returns true only on HTTP 2xx with success:true.
 */
bool postJson(const char* path, JsonDocument& request, JsonDocument& response) {
  if (WiFi.status() != WL_CONNECTED) return false;

  HTTPClient http;
  http.setTimeout(HTTP_TIMEOUT);
  http.setConnectTimeout(HTTP_TIMEOUT);

  if (!http.begin(apiUrl(path))) {
    LOGLN(F("[API] Could not open the connection."));
    return false;
  }

  http.addHeader("Content-Type", "application/json");
  http.addHeader("X-API-Key", API_KEY);

  String body;
  serializeJson(request, body);

  int code = http.POST(body);
  bool ok = false;

  if (code > 0) {
    String payload = http.getString();
    DeserializationError err = deserializeJson(response, payload);
    if (err) {
      LOG("[API] %s: bad JSON reply (%s)\n", path, err.c_str());
    } else {
      ok = (code >= 200 && code < 300) && (response["success"] | false);
      if (!ok) {
        LOG("[API] %s: HTTP %d - %s\n", path, code,
            (const char*)(response["message"] | "no message"));
      }
    }
  } else {
    LOG("[API] %s: transport error %s\n", path, http.errorToString(code).c_str());
  }

  http.end();
  serverReachable = ok;
  return ok;
}

/** Fill in the fields every request shares. */
void addIdentity(JsonDocument& doc) {
  doc["esp32_uid"]        = DEVICE_UID;
  doc["firmware_version"] = FIRMWARE_VERSION;
  doc["ip_address"]       = WiFi.localIP().toString();
  doc["wifi_rssi"]        = WiFi.RSSI();
  doc["feed_sensor_status"]  = sensors.feedValid  ? "ok" : "error";
  doc["water_sensor_status"] = sensors.waterValid ? "ok" : "error";
  doc["motor_status"]     = feedJob.state  == ACT_RUNNING ? "running" : "idle";
  doc["solenoid_status"]  = waterJob.state == ACT_RUNNING ? "running" : "idle";
}

/**
 * Upload the current reading. The reply carries the latest settings and any
 * queued manual commands, so one request per cycle keeps everything in sync.
 */
bool uploadReading() {
  if (!sensors.feedValid && !sensors.waterValid) {
    LOGLN(F("[API] Nothing valid to upload - both sensors are faulted."));
    return true;   /* nothing to buffer either */
  }

  JsonDocument req;
  addIdentity(req);

  if (sensors.feedValid) {
    req["feed_weight"] = round(sensors.feedWeight * 100) / 100.0;
    req["raw_value"]   = sensors.feedRaw;
  }
  if (sensors.waterValid) {
    req["distance_cm"] = round(sensors.distanceCm * 100) / 100.0;
  }

  JsonDocument res;
  if (!postJson("/api/esp32/sensor-data.php", req, res)) {
    return false;
  }

  applySettingsJson(res["data"]["settings"].as<JsonObjectConst>());

  JsonArrayConst commands = res["data"]["pending_commands"].as<JsonArrayConst>();
  for (JsonObjectConst cmd : commands) {
    handleCommand(cmd);
  }

  LOGLN(F("[API] Reading uploaded."));
  return true;
}

/** Heartbeat + command collection, cheaper than a full telemetry post. */
void pollCommands() {
  JsonDocument req;
  addIdentity(req);
  req["buffered_records"] = bufferedRecords;

  JsonDocument res;
  if (!postJson("/api/esp32/status.php", req, res)) return;

  applySettingsJson(res["data"]["settings"].as<JsonObjectConst>());

  JsonArrayConst commands = res["data"]["pending_commands"].as<JsonArrayConst>();
  for (JsonObjectConst cmd : commands) {
    handleCommand(cmd);
  }
}

/** Pull the full configuration (used at boot and periodically). */
void refreshConfig() {
  JsonDocument req;
  req["esp32_uid"] = DEVICE_UID;

  JsonDocument res;
  if (!postJson("/api/esp32/config.php", req, res)) {
    LOGLN(F("[CFG] Could not fetch settings - using the values stored in NVS."));
    return;
  }

  applySettingsJson(res["data"]["settings"].as<JsonObjectConst>());
  LOGLN(F("[CFG] Settings refreshed from the server."));
}

/* =====================================================================
 *  COMMANDS
 * ===================================================================== */
void handleCommand(JsonObjectConst cmd) {
  uint32_t id = cmd["command_id"] | 0;
  const char* name = cmd["command"] | "";
  JsonObjectConst payload = cmd["payload"].as<JsonObjectConst>();

  LOG("[CMD] #%lu %s\n", (unsigned long)id, name);

  if (strcmp(name, "dispense_feed") == 0) {
    float target = payload["target_weight"] | settings.feedTarget;
    uint32_t user = payload["user_id"] | 0;
    startFeedDispensing(target, true, user, id);
    /* No ack here - it is sent when the cycle finishes, with the outcome. */
  }
  else if (strcmp(name, "stop_feed") == 0) {
    if (feedJob.state == ACT_RUNNING) {
      stopFeed("aborted", "Stopped by an operator");
      acknowledgeCommand(id, "executed", "Auger stopped");
    } else {
      motorOff();
      acknowledgeCommand(id, "executed", "Auger was already idle");
    }
  }
  else if (strcmp(name, "refill_water") == 0) {
    float target = payload["target_level"] | settings.waterTarget;
    uint32_t user = payload["user_id"] | 0;
    startWaterRefill(target, true, user, id);
  }
  else if (strcmp(name, "stop_water") == 0) {
    if (waterJob.state == ACT_RUNNING) {
      stopWater("aborted", "Stopped by an operator");
      acknowledgeCommand(id, "executed", "Valve closed");
    } else {
      valveClose();
      acknowledgeCommand(id, "executed", "Valve was already closed");
    }
  }
  else if (strcmp(name, "tare_scale") == 0) {
    if (feedJob.state == ACT_RUNNING) {
      acknowledgeCommand(id, "failed", "Cannot tare while the auger is running");
    } else {
      scale.tare(20);
      settings.loadCellOffset = (float)scale.get_offset();
      saveSettings();
      char msg[80];
      snprintf(msg, sizeof(msg), "Scale tared. New raw offset: %.0f", settings.loadCellOffset);
      LOG("[CMD] %s\n", msg);
      acknowledgeCommand(id, "executed", msg);
    }
  }
  else if (strcmp(name, "reload_settings") == 0) {
    refreshConfig();
    acknowledgeCommand(id, "executed", "Settings reloaded");
    /* A settings change may have re-enabled automation after a stop. */
    if (settings.autoFeed || settings.autoWater) emergencyStopped = false;
  }
  else if (strcmp(name, "emergency_stop") == 0) {
    doEmergencyStop("Emergency stop requested from the dashboard");
    acknowledgeCommand(id, "executed", "All actuators stopped, automation disabled");
  }
  else if (strcmp(name, "reboot") == 0) {
    acknowledgeCommand(id, "executed", "Rebooting");
    motorOff();
    valveClose();
    delay(400);
    ESP.restart();
  }
  else {
    acknowledgeCommand(id, "failed", "Unknown command");
  }
}

void acknowledgeCommand(uint32_t commandId, const char* status, const char* result) {
  if (commandId == 0 || WiFi.status() != WL_CONNECTED) return;

  JsonDocument req;
  req["esp32_uid"]  = DEVICE_UID;
  req["command_id"] = commandId;
  req["status"]     = status;
  req["result"]     = result;

  JsonDocument res;
  postJson("/api/esp32/ack.php", req, res);
}

/* =====================================================================
 *  DISPENSING REPORTS
 * ===================================================================== */
void reportDispensing(const char* type, const DispensingJob& job, const char* status,
                      float endValue, uint32_t durationMs) {
  bool isFeed = (strcmp(type, "feed") == 0);

  JsonDocument req;
  req["esp32_uid"]   = DEVICE_UID;
  req["type"]        = type;
  req["status"]      = status;
  req["duration_ms"] = durationMs;
  /* An action taken with no network is recorded as an offline action, which
   * is what the reports use to show what happened during an outage. */
  req["dispensing_method"] = job.isManual ? "manual"
                            : (wifiConnected ? "automatic" : "offline");
  if (job.userId) req["user_id"] = job.userId;

  String ts = isoTimestamp();
  if (ts.length()) req["timestamp"] = ts;

  if (isFeed) {
    req["start_weight"]  = round(job.startValue * 100) / 100.0;
    req["target_weight"] = round(job.targetValue * 100) / 100.0;
    req["actual_weight"] = round(endValue * 100) / 100.0;
  } else {
    req["starting_level"] = round(job.startValue * 100) / 100.0;
    req["target_level"]   = round(job.targetValue * 100) / 100.0;
    req["ending_level"]   = round(endValue * 100) / 100.0;
  }

  if (WiFi.status() == WL_CONNECTED) {
    JsonDocument res;
    if (postJson("/api/esp32/dispensing.php", req, res)) {
      return;
    }
  }

  /* Offline, or the upload failed: keep it for the sync. */
  bufferDispensing(type, job, status, endValue, durationMs);
}

/* =====================================================================
 *  OFFLINE BUFFER
 *
 *  Records are appended to LittleFS as one JSON object per line. Nothing
 *  that happens during an outage is lost, and every record keeps its real
 *  timestamp so the history is correct once it uploads.
 * ===================================================================== */
void appendBufferLine(JsonDocument& doc) {
  if (bufferedRecords >= OFFLINE_MAX_RECORDS) {
    /* Full. Newer data is more useful than the oldest sample, but rewriting
     * the whole file on every reading would wear the flash - so simply stop
     * accepting readings and keep what we have. */
    static uint32_t lastWarn = 0;
    if (millis() - lastWarn > 60000UL) {
      lastWarn = millis();
      LOG("[BUF] Buffer is full at %u records; new readings are being dropped.\n",
          bufferedRecords);
    }
    return;
  }

  File f = LittleFS.open(OFFLINE_BUFFER_FILE, FILE_APPEND);
  if (!f) {
    LOGLN(F("[BUF] Could not open the buffer file."));
    return;
  }
  serializeJson(doc, f);
  f.print('\n');
  f.close();
  bufferedRecords++;
}

void bufferReading() {
  if (!sensors.feedValid && !sensors.waterValid) return;

  JsonDocument doc;
  doc["k"] = "r";                       /* record kind: reading */
  String ts = isoTimestamp();
  if (ts.length()) doc["timestamp"] = ts;
  if (sensors.feedValid) {
    doc["feed_weight"] = round(sensors.feedWeight * 100) / 100.0;
    doc["raw_value"]   = sensors.feedRaw;
  }
  if (sensors.waterValid) {
    doc["distance_cm"] = round(sensors.distanceCm * 100) / 100.0;
  }

  appendBufferLine(doc);
  LOG("[BUF] Reading buffered (%u waiting).\n", bufferedRecords);
}

void bufferDispensing(const char* type, const DispensingJob& job, const char* status,
                      float endValue, uint32_t durationMs) {
  JsonDocument doc;
  doc["k"]    = "d";                    /* record kind: dispensing */
  doc["type"] = type;
  doc["status"] = status;
  doc["duration_ms"] = durationMs;
  doc["dispensing_method"] = job.isManual ? "manual" : "offline";
  if (job.userId) doc["user_id"] = job.userId;

  String ts = isoTimestamp();
  if (ts.length()) doc["timestamp"] = ts;

  if (strcmp(type, "feed") == 0) {
    doc["start_weight"]  = round(job.startValue * 100) / 100.0;
    doc["target_weight"] = round(job.targetValue * 100) / 100.0;
    doc["actual_weight"] = round(endValue * 100) / 100.0;
  } else {
    doc["starting_level"] = round(job.startValue * 100) / 100.0;
    doc["target_level"]   = round(job.targetValue * 100) / 100.0;
    doc["ending_level"]   = round(endValue * 100) / 100.0;
  }

  appendBufferLine(doc);
  LOG("[BUF] Dispensing event buffered (%u waiting).\n", bufferedRecords);
}

/**
 * Upload one batch of buffered records.
 *
 * The batch is only removed from flash after the server confirms it, so a
 * failure part-way through costs a retry, never data.
 */
void syncOfflineBuffer() {
  if (bufferedRecords == 0) return;
  if (!LittleFS.exists(OFFLINE_BUFFER_FILE)) {
    bufferedRecords = 0;
    return;
  }

  LOG("[BUF] Syncing up to %d of %u buffered records...\n", SYNC_BATCH_SIZE, bufferedRecords);

  File f = LittleFS.open(OFFLINE_BUFFER_FILE, FILE_READ);
  if (!f) return;

  JsonDocument req;
  req["esp32_uid"] = DEVICE_UID;
  JsonArray readings   = req["readings"].to<JsonArray>();
  JsonArray dispensing = req["dispensing"].to<JsonArray>();

  uint16_t taken = 0;
  size_t consumedBytes = 0;

  while (f.available() && taken < SYNC_BATCH_SIZE) {
    String line = f.readStringUntil('\n');
    consumedBytes += line.length() + 1;
    line.trim();
    if (line.length() == 0) continue;

    JsonDocument rec;
    if (deserializeJson(rec, line)) continue;   /* skip a corrupted line */

    const char* kind = rec["k"] | "r";
    JsonObject dest = (strcmp(kind, "d") == 0) ? dispensing.add<JsonObject>()
                                               : readings.add<JsonObject>();
    for (JsonPairConst kv : rec.as<JsonObjectConst>()) {
      if (strcmp(kv.key().c_str(), "k") == 0) continue;
      dest[kv.key()] = kv.value();
    }
    taken++;
  }

  /* Keep whatever we did not send in this batch. */
  String remainder;
  while (f.available()) remainder += (char)f.read();
  f.close();

  if (taken == 0) {
    LittleFS.remove(OFFLINE_BUFFER_FILE);
    bufferedRecords = 0;
    return;
  }

  JsonDocument res;
  if (!postJson("/api/esp32/sync.php", req, res)) {
    LOGLN(F("[BUF] Sync failed; the batch is kept for the next attempt."));
    return;
  }

  /* Confirmed - rewrite the file with only what is left. */
  File out = LittleFS.open(OFFLINE_BUFFER_FILE, FILE_WRITE);
  if (out) {
    out.print(remainder);
    out.close();
  }

  uint32_t remaining = 0;
  for (size_t i = 0; i < remainder.length(); i++) {
    if (remainder[i] == '\n') remaining++;
  }
  bufferedRecords = remaining;

  LOG("[BUF] Synced %u record(s); %u still waiting.\n",
      (unsigned)(res["data"]["total"] | taken), bufferedRecords);

  if (bufferedRecords == 0) {
    LittleFS.remove(OFFLINE_BUFFER_FILE);
    LOGLN(F("[BUF] Buffer emptied."));
  }
}
