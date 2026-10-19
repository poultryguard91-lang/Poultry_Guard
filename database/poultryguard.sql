-- =====================================================================
--  PoultryGuard : Precision IoT Automation for Poultry Feeding & Hydration
--  Complete MySQL 8+ schema, constraints, indexes and demo data.
--
--  Import:   mysql -u root -p < poultryguard.sql
--    or      phpMyAdmin -> Import -> poultryguard.sql
--
--  Units used consistently across the whole system:
--    * feed weight ....... grams (g)
--    * water level ....... centimetres (cm) of water depth in the tank
--    * distance .......... centimetres (cm) from HC-SR04 to water surface
--    * durations ......... milliseconds (ms) for events, seconds for limits
-- =====================================================================

-- Verified against MariaDB 10.4 (XAMPP default) and MySQL 8.0. Only
-- portable syntax is used: no LATERAL joins, no recursive CTEs and no
-- server variables that differ between the two engines.

SET FOREIGN_KEY_CHECKS = 0;
SET NAMES utf8mb4;

DROP DATABASE IF EXISTS `poultryguard`;
CREATE DATABASE `poultryguard`
    DEFAULT CHARACTER SET utf8mb4
    DEFAULT COLLATE utf8mb4_unicode_ci;
USE `poultryguard`;

-- ---------------------------------------------------------------------
-- 1. users
-- ---------------------------------------------------------------------
CREATE TABLE `users` (
    `user_id`     INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`        VARCHAR(100)  NOT NULL,
    `username`    VARCHAR(50)   NOT NULL,
    `email`       VARCHAR(120)  DEFAULT NULL,
    -- bcrypt hash produced by PHP password_hash(); never a plain-text password
    `password`    VARCHAR(255)  NOT NULL,
    `role`        ENUM('owner','staff') NOT NULL DEFAULT 'staff',
    `status`      ENUM('active','inactive') NOT NULL DEFAULT 'active',
    `last_login`  DATETIME      DEFAULT NULL,
    `created_at`  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`user_id`),
    UNIQUE KEY `uq_users_username` (`username`),
    UNIQUE KEY `uq_users_email` (`email`),
    KEY `idx_users_role_status` (`role`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 2. devices  (one ESP32 controller per poultry house)
-- ---------------------------------------------------------------------
CREATE TABLE `devices` (
    `device_id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `device_name`          VARCHAR(100) NOT NULL,
    -- ESP32 chip id / MAC based unique identifier reported by the firmware
    `esp32_uid`            VARCHAR(64)  NOT NULL,
    -- shared secret sent by the firmware in the X-API-Key header
    `api_key`              VARCHAR(64)  NOT NULL,
    `location`             VARCHAR(150) DEFAULT NULL,
    `status`               ENUM('active','disabled') NOT NULL DEFAULT 'active',
    `firmware_version`     VARCHAR(20)  DEFAULT NULL,
    `ip_address`           VARCHAR(45)  DEFAULT NULL,
    `wifi_rssi`            SMALLINT     DEFAULT NULL,
    `feed_sensor_status`   ENUM('ok','error','unknown') NOT NULL DEFAULT 'unknown',
    `water_sensor_status`  ENUM('ok','error','unknown') NOT NULL DEFAULT 'unknown',
    `motor_status`         ENUM('idle','running','error') NOT NULL DEFAULT 'idle',
    `solenoid_status`      ENUM('idle','running','error') NOT NULL DEFAULT 'idle',
    `last_seen`            DATETIME     DEFAULT NULL,
    `created_at`           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`device_id`),
    UNIQUE KEY `uq_devices_uid` (`esp32_uid`),
    UNIQUE KEY `uq_devices_apikey` (`api_key`),
    KEY `idx_devices_status_seen` (`status`, `last_seen`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 3. feed_readings  (load cell + HX711 samples)
-- ---------------------------------------------------------------------
CREATE TABLE `feed_readings` (
    `reading_id`      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `device_id`       INT UNSIGNED  NOT NULL,
    `feed_weight`     DECIMAL(10,2) NOT NULL COMMENT 'grams',
    `feed_percentage` DECIMAL(5,2)  NOT NULL COMMENT '0-100',
    `raw_value`       BIGINT        DEFAULT NULL COMMENT 'uncalibrated HX711 count',
    `source`          ENUM('live','offline_sync') NOT NULL DEFAULT 'live',
    `timestamp`       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`reading_id`),
    KEY `idx_feed_readings_device_time` (`device_id`, `timestamp`),
    CONSTRAINT `fk_feed_readings_device` FOREIGN KEY (`device_id`)
        REFERENCES `devices` (`device_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 4. water_readings  (HC-SR04 samples)
-- ---------------------------------------------------------------------
CREATE TABLE `water_readings` (
    `reading_id`       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `device_id`        INT UNSIGNED  NOT NULL,
    `water_level`      DECIMAL(10,2) NOT NULL COMMENT 'cm of water depth',
    `water_percentage` DECIMAL(5,2)  NOT NULL COMMENT '0-100',
    `distance_cm`      DECIMAL(10,2) NOT NULL COMMENT 'sensor to water surface',
    `source`           ENUM('live','offline_sync') NOT NULL DEFAULT 'live',
    `timestamp`        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`reading_id`),
    KEY `idx_water_readings_device_time` (`device_id`, `timestamp`),
    CONSTRAINT `fk_water_readings_device` FOREIGN KEY (`device_id`)
        REFERENCES `devices` (`device_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 5. feed_dispensing  (every auger activation, automatic or manual)
-- ---------------------------------------------------------------------
CREATE TABLE `feed_dispensing` (
    `dispensing_id`     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `device_id`         INT UNSIGNED  NOT NULL,
    `user_id`           INT UNSIGNED  DEFAULT NULL COMMENT 'set for manual actions',
    `start_weight`      DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'grams before dispensing',
    `target_weight`     DECIMAL(10,2) NOT NULL COMMENT 'grams targeted in hopper',
    `actual_weight`     DECIMAL(10,2) DEFAULT NULL COMMENT 'grams measured after dispensing',
    `dispensed_amount`  DECIMAL(10,2) DEFAULT NULL COMMENT 'grams actually added',
    `dispensing_method` ENUM('automatic','manual','offline') NOT NULL DEFAULT 'automatic',
    `status`            ENUM('pending','running','completed','failed','aborted','timeout')
                        NOT NULL DEFAULT 'pending',
    `duration_ms`       INT UNSIGNED  DEFAULT NULL,
    `notes`             VARCHAR(255)  DEFAULT NULL,
    `timestamp`         DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`dispensing_id`),
    KEY `idx_feed_disp_device_time` (`device_id`, `timestamp`),
    KEY `idx_feed_disp_method` (`dispensing_method`, `status`),
    CONSTRAINT `fk_feed_disp_device` FOREIGN KEY (`device_id`)
        REFERENCES `devices` (`device_id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_feed_disp_user` FOREIGN KEY (`user_id`)
        REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 6. water_dispensing  (every solenoid valve activation)
-- ---------------------------------------------------------------------
CREATE TABLE `water_dispensing` (
    `dispensing_id`     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `device_id`         INT UNSIGNED  NOT NULL,
    `user_id`           INT UNSIGNED  DEFAULT NULL,
    `starting_level`    DECIMAL(10,2) NOT NULL COMMENT 'cm before refill',
    `ending_level`      DECIMAL(10,2) DEFAULT NULL COMMENT 'cm after refill',
    `target_level`      DECIMAL(10,2) NOT NULL COMMENT 'cm targeted',
    `dispensing_method` ENUM('automatic','manual','offline') NOT NULL DEFAULT 'automatic',
    `status`            ENUM('pending','running','completed','failed','aborted','timeout')
                        NOT NULL DEFAULT 'pending',
    `duration_ms`       INT UNSIGNED  DEFAULT NULL,
    `notes`             VARCHAR(255)  DEFAULT NULL,
    `timestamp`         DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`dispensing_id`),
    KEY `idx_water_disp_device_time` (`device_id`, `timestamp`),
    KEY `idx_water_disp_method` (`dispensing_method`, `status`),
    CONSTRAINT `fk_water_disp_device` FOREIGN KEY (`device_id`)
        REFERENCES `devices` (`device_id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_water_disp_user` FOREIGN KEY (`user_id`)
        REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 7. system_settings  (one automation profile per device)
--    These rows are the single source of truth; the ESP32 caches a copy
--    in NVS so it can keep running the same rules while offline.
-- ---------------------------------------------------------------------
CREATE TABLE `system_settings` (
    `setting_id`             INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `device_id`              INT UNSIGNED  NOT NULL,
    -- feed
    `feed_capacity`          DECIMAL(10,2) NOT NULL DEFAULT 10000.00 COMMENT 'grams, full hopper',
    `feed_threshold`         DECIMAL(10,2) NOT NULL DEFAULT 2500.00  COMMENT 'grams, refill below this',
    `target_feed`            DECIMAL(10,2) NOT NULL DEFAULT 9000.00  COMMENT 'grams, refill up to this',
    `feed_max_duration`      INT UNSIGNED  NOT NULL DEFAULT 60       COMMENT 'seconds, auger safety cut-off',
    `feed_tolerance`         DECIMAL(10,2) NOT NULL DEFAULT 5.00     COMMENT 'grams, +/- accuracy target',
    `auto_feed`              TINYINT(1)    NOT NULL DEFAULT 1,
    -- water
    `water_capacity`         DECIMAL(10,2) NOT NULL DEFAULT 100.00   COMMENT 'cm, usable tank depth',
    `water_capacity_liters`  DECIMAL(10,2) NOT NULL DEFAULT 200.00   COMMENT 'litres at 100% level',
    `sensor_offset_cm`       DECIMAL(10,2) NOT NULL DEFAULT 10.00    COMMENT 'cm from sensor to full-water line',
    `water_threshold`        DECIMAL(10,2) NOT NULL DEFAULT 30.00    COMMENT 'cm, refill below this',
    `water_target`           DECIMAL(10,2) NOT NULL DEFAULT 90.00    COMMENT 'cm, refill up to this',
    `water_max_duration`     INT UNSIGNED  NOT NULL DEFAULT 300      COMMENT 'seconds, valve safety cut-off',
    `auto_water`             TINYINT(1)    NOT NULL DEFAULT 1,
    -- alert thresholds
    `feed_low_percent`       DECIMAL(5,2)  NOT NULL DEFAULT 30.00,
    `feed_critical_percent`  DECIMAL(5,2)  NOT NULL DEFAULT 15.00,
    `water_low_percent`      DECIMAL(5,2)  NOT NULL DEFAULT 30.00,
    `water_critical_percent` DECIMAL(5,2)  NOT NULL DEFAULT 15.00,
    `offline_timeout`        INT UNSIGNED  NOT NULL DEFAULT 300      COMMENT 'seconds without contact = offline',
    `reading_interval`       INT UNSIGNED  NOT NULL DEFAULT 30       COMMENT 'seconds between ESP32 uploads',
    `notify_in_app`          TINYINT(1)    NOT NULL DEFAULT 1,
    `notify_email`           TINYINT(1)    NOT NULL DEFAULT 0,
    `updated_by`             INT UNSIGNED  DEFAULT NULL,
    `updated_at`             DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`setting_id`),
    UNIQUE KEY `uq_settings_device` (`device_id`),
    CONSTRAINT `fk_settings_device` FOREIGN KEY (`device_id`)
        REFERENCES `devices` (`device_id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_settings_user` FOREIGN KEY (`updated_by`)
        REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 8. alerts
-- ---------------------------------------------------------------------
CREATE TABLE `alerts` (
    `alert_id`    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `device_id`   INT UNSIGNED NOT NULL,
    `alert_type`  ENUM('low_feed','critical_feed','low_water','critical_water',
                       'sensor_failure','device_offline','internet_disconnected',
                       'motor_error','solenoid_error','abnormal_reading','system_error',
                       'dispensing_timeout','info') NOT NULL,
    `severity`    ENUM('INFO','WARNING','CRITICAL') NOT NULL DEFAULT 'INFO',
    `message`     VARCHAR(255) NOT NULL,
    `status`      ENUM('unread','read','resolved') NOT NULL DEFAULT 'unread',
    `resolved_at` DATETIME     DEFAULT NULL,
    `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`alert_id`),
    KEY `idx_alerts_device_created` (`device_id`, `created_at`),
    KEY `idx_alerts_status_sev` (`status`, `severity`),
    KEY `idx_alerts_type_created` (`alert_type`, `created_at`),
    CONSTRAINT `fk_alerts_device` FOREIGN KEY (`device_id`)
        REFERENCES `devices` (`device_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 9. sensor_calibration
--    load_cell  : calibration_factor = HX711 counts per gram
--                 offset_value       = tare offset (raw counts)
--    ultrasonic : calibration_factor = usable tank depth (cm)
--                 offset_value       = sensor-to-full-water distance (cm)
-- ---------------------------------------------------------------------
CREATE TABLE `sensor_calibration` (
    `calibration_id`     INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `device_id`          INT UNSIGNED  NOT NULL,
    `sensor_type`        ENUM('load_cell','ultrasonic') NOT NULL,
    `calibration_factor` DECIMAL(14,4) NOT NULL,
    `offset_value`       DECIMAL(14,4) NOT NULL DEFAULT 0.0000,
    `reference_value`    DECIMAL(14,4) DEFAULT NULL COMMENT 'known weight/distance used',
    `is_active`          TINYINT(1)    NOT NULL DEFAULT 1,
    `notes`              VARCHAR(255)  DEFAULT NULL,
    `calibrated_by`      INT UNSIGNED  DEFAULT NULL,
    `calibrated_at`      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`calibration_id`),
    KEY `idx_calib_device_type_active` (`device_id`, `sensor_type`, `is_active`),
    CONSTRAINT `fk_calib_device` FOREIGN KEY (`device_id`)
        REFERENCES `devices` (`device_id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_calib_user` FOREIGN KEY (`calibrated_by`)
        REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 10. activity_logs
-- ---------------------------------------------------------------------
CREATE TABLE `activity_logs` (
    `log_id`      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`     INT UNSIGNED DEFAULT NULL COMMENT 'NULL for system/device actions',
    `action`      VARCHAR(60)  NOT NULL,
    `description` VARCHAR(255) NOT NULL,
    `ip_address`  VARCHAR(45)  DEFAULT NULL,
    `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`log_id`),
    KEY `idx_logs_user_created` (`user_id`, `created_at`),
    KEY `idx_logs_action` (`action`, `created_at`),
    CONSTRAINT `fk_logs_user` FOREIGN KEY (`user_id`)
        REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 11. device_commands
--     Manual-control queue. The dashboard enqueues a command, the ESP32
--     polls for it and acknowledges the result. This keeps the web app
--     from ever having to reach the device directly.
-- ---------------------------------------------------------------------
CREATE TABLE `device_commands` (
    `command_id`  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `device_id`   INT UNSIGNED NOT NULL,
    `command`     ENUM('dispense_feed','stop_feed','refill_water','stop_water',
                       'tare_scale','reload_settings','emergency_stop','reboot') NOT NULL,
    `payload`     JSON         DEFAULT NULL,
    `status`      ENUM('pending','sent','executed','failed','expired') NOT NULL DEFAULT 'pending',
    `result`      VARCHAR(255) DEFAULT NULL,
    `issued_by`   INT UNSIGNED DEFAULT NULL,
    `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `sent_at`     DATETIME     DEFAULT NULL,
    `executed_at` DATETIME     DEFAULT NULL,
    PRIMARY KEY (`command_id`),
    KEY `idx_cmd_device_status` (`device_id`, `status`, `created_at`),
    CONSTRAINT `fk_cmd_device` FOREIGN KEY (`device_id`)
        REFERENCES `devices` (`device_id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_cmd_user` FOREIGN KEY (`issued_by`)
        REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 12. login_attempts  (brute-force throttling)
-- ---------------------------------------------------------------------
CREATE TABLE `login_attempts` (
    `attempt_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `username`   VARCHAR(50) NOT NULL,
    `ip_address` VARCHAR(45) NOT NULL,
    `success`    TINYINT(1)  NOT NULL DEFAULT 0,
    `created_at` DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`attempt_id`),
    KEY `idx_attempts_lookup` (`ip_address`, `username`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 13. flocks  (a batch of birds in one poultry house -> FCR reporting)
-- ---------------------------------------------------------------------
CREATE TABLE `flocks` (
    `flock_id`        INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `device_id`       INT UNSIGNED  NOT NULL,
    `flock_name`      VARCHAR(100)  NOT NULL,
    `house_name`      VARCHAR(100)  NOT NULL,
    `breed`           VARCHAR(60)   DEFAULT NULL,
    `initial_count`   INT UNSIGNED  NOT NULL,
    `initial_avg_weight` DECIMAL(10,2) NOT NULL COMMENT 'grams per bird at placement',
    `start_date`      DATE          NOT NULL,
    `end_date`        DATE          DEFAULT NULL,
    `status`          ENUM('active','closed') NOT NULL DEFAULT 'active',
    `created_at`      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`flock_id`),
    KEY `idx_flocks_device_status` (`device_id`, `status`),
    CONSTRAINT `fk_flocks_device` FOREIGN KEY (`device_id`)
        REFERENCES `devices` (`device_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 14. production_records  (weekly weigh-in used as the FCR numerator input)
-- ---------------------------------------------------------------------
CREATE TABLE `production_records` (
    `record_id`     INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `flock_id`      INT UNSIGNED  NOT NULL,
    `record_date`   DATE          NOT NULL,
    `bird_count`    INT UNSIGNED  NOT NULL,
    `avg_weight`    DECIMAL(10,2) NOT NULL COMMENT 'grams per bird',
    `mortality`     INT UNSIGNED  NOT NULL DEFAULT 0,
    `notes`         VARCHAR(255)  DEFAULT NULL,
    `recorded_by`   INT UNSIGNED  DEFAULT NULL,
    `created_at`    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`record_id`),
    UNIQUE KEY `uq_production_flock_date` (`flock_id`, `record_date`),
    CONSTRAINT `fk_production_flock` FOREIGN KEY (`flock_id`)
        REFERENCES `flocks` (`flock_id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_production_user` FOREIGN KEY (`recorded_by`)
        REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- =====================================================================
--  DEMO / SEED DATA
--  Every row below is demonstration data so the dashboard is populated
--  immediately after import. Delete the section marked "SAMPLE DATA"
--  before going to production; the users, devices and settings rows are
--  the minimum needed to log in and operate.
-- =====================================================================

-- --- Default accounts -------------------------------------------------
--  owner / Admin@123   -> Farm Owner (full access)
--  jdelacruz / Owner@123 -> Farm Owner
--  staff / Staff@123   -> Farm Staff (operations)
--  CHANGE THESE PASSWORDS IMMEDIATELY AFTER INSTALLATION.
INSERT INTO `users` (`user_id`,`name`,`username`,`email`,`password`,`role`,`status`) VALUES
(1,'Farm Administrator','owner','owner@poultryguard.local',
 '$2y$10$2Rj3jSkmlUbHmkU6RHOxOOKa/EWlZtR9cdQ1F5BAU.RhZijRkb3Tm','owner','active'),
(2,'Juan Dela Cruz','jdelacruz','juan@poultryguard.local',
 '$2y$10$UMkNGPnBuTQhk8gmUqrZmeVShnNNPywJtuf6voX73GHmwBtGPY2Ji','owner','active'),
(3,'Maria Santos','staff','maria@poultryguard.local',
 '$2y$10$pK5qUF/XbX/tL9yh43cWCeuQ9eNp5GMfQqIJSIpyv9Ecp.onmE6OK','staff','active');

-- --- Devices ----------------------------------------------------------
INSERT INTO `devices`
(`device_id`,`device_name`,`esp32_uid`,`api_key`,`location`,`status`,`firmware_version`,
 `ip_address`,`wifi_rssi`,`feed_sensor_status`,`water_sensor_status`,`motor_status`,
 `solenoid_status`,`last_seen`) VALUES
(1,'Broiler House 1 Controller','ESP32-A1B2C3D4E5F6',
 '97161c5b9d4ab435f52f8a8a9b48af25e8c09751','Broiler House 1 - North Wing','active','1.0.0',
 '192.168.1.45',-58,'ok','ok','idle','idle', NOW()),
(2,'Layer House 2 Controller','ESP32-F6E5D4C3B2A1',
 '81a1ca36a1e6116bf35d0a077c9baeb454dd5e80','Layer House 2 - South Wing','active','1.0.0',
 '192.168.1.46',-71,'ok','ok','idle','idle', DATE_SUB(NOW(), INTERVAL 42 MINUTE));

-- --- Default automation settings (one row per device) ------------------
INSERT INTO `system_settings`
(`device_id`,`feed_capacity`,`feed_threshold`,`target_feed`,`feed_max_duration`,`feed_tolerance`,
 `auto_feed`,`water_capacity`,`water_capacity_liters`,`sensor_offset_cm`,`water_threshold`,
 `water_target`,`water_max_duration`,`auto_water`,`feed_low_percent`,`feed_critical_percent`,
 `water_low_percent`,`water_critical_percent`,`offline_timeout`,`reading_interval`,`updated_by`) VALUES
(1,10000.00,2500.00,9000.00,60,5.00,1,100.00,200.00,10.00,30.00,90.00,300,1,
 30.00,15.00,30.00,15.00,300,30,1),
(2,10000.00,3000.00,9500.00,60,5.00,1,100.00,250.00,12.00,35.00,92.00,300,1,
 30.00,15.00,30.00,15.00,300,30,1);

-- --- Active calibration values ----------------------------------------
INSERT INTO `sensor_calibration`
(`device_id`,`sensor_type`,`calibration_factor`,`offset_value`,`reference_value`,
 `is_active`,`notes`,`calibrated_by`) VALUES
(1,'load_cell', 214.7500, 128340.0000, 1000.0000, 1,'Calibrated with a 1 kg reference mass',3),
(1,'ultrasonic',100.0000, 10.0000,    100.0000, 1,'Tank depth 100 cm, sensor 10 cm above full line',3),
(2,'load_cell', 209.1200, 131002.0000, 1000.0000, 1,'Calibrated with a 1 kg reference mass',3),
(2,'ultrasonic',100.0000, 12.0000,    100.0000, 1,'Tank depth 100 cm, sensor 12 cm above full line',3);

-- --- Flocks and production records (FCR inputs) ------------------------
--  Flock sizes are matched to the hardware they are fed by. A 10 kg hopper
--  dispensing roughly 6.5 kg/day supports about 65 growing broilers, so that
--  is the scale used here; the resulting FCR figures (1.5-1.7 for broilers,
--  2.5-2.9 for pullets) are what a real batch of this size would show.
--  The weigh-in window matches the 14 days of sensor history below, so the
--  feed side of the ratio is backed by actual load-cell data.
INSERT INTO `flocks`
(`flock_id`,`device_id`,`flock_name`,`house_name`,`breed`,`initial_count`,
 `initial_avg_weight`,`start_date`,`status`) VALUES
(1,1,'Batch 2026-08 Broilers','Broiler House 1','Cobb 500',70,430.00,
 DATE_SUB(CURDATE(), INTERVAL 14 DAY),'active'),
(2,2,'Batch 2026-08 Pullets','Layer House 2','Lohmann Brown',56,400.00,
 DATE_SUB(CURDATE(), INTERVAL 14 DAY),'active');

INSERT INTO `production_records`
(`flock_id`,`record_date`,`bird_count`,`avg_weight`,`mortality`,`notes`,`recorded_by`) VALUES
(1, DATE_SUB(CURDATE(), INTERVAL 14 DAY), 70,  430.00, 0, 'Day 14 weigh-in, start of the reporting window', 3),
(1, DATE_SUB(CURDATE(), INTERVAL  7 DAY), 69,  840.00, 1, 'Day 21 weigh-in', 3),
(1, CURDATE(),                            68, 1270.00, 1, 'Day 28 weigh-in', 3),
(2, DATE_SUB(CURDATE(), INTERVAL 14 DAY), 56,  400.00, 0, 'Week 6 weigh-in, start of the reporting window', 3),
(2, DATE_SUB(CURDATE(), INTERVAL  7 DAY), 55,  690.00, 1, 'Week 7 weigh-in', 3),
(2, CURDATE(),                            54,  980.00, 1, 'Week 8 weigh-in', 3);

-- =====================================================================
--  SAMPLE DATA : 14 days of sensor history, generated with a recursive
--  CTE so the charts and reports have something to draw immediately.
--  One sample every 30 minutes = 672 rows per device per sensor.
--
--  The feed curve is a saw-tooth: the hopper drains at roughly
--  6.5 kg/day and is topped back up to the target once it drops below
--  the threshold, which is exactly what the automation produces.
-- =====================================================================
-- A throw-away numbers table (0-999) drives all the generators below.
-- Built from three digit tables so it works on every MySQL/MariaDB build.
DROP TABLE IF EXISTS `seq_helper`;
CREATE TABLE `seq_helper` (`n` INT NOT NULL PRIMARY KEY) ENGINE=InnoDB;
INSERT INTO `seq_helper` (`n`)
SELECT a.d + b.d * 10 + c.d * 100
FROM (SELECT 0 d UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4
      UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) a
CROSS JOIN
     (SELECT 0 d UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4
      UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) b
CROSS JOIN
     (SELECT 0 d UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4
      UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) c;

INSERT INTO `feed_readings` (`device_id`,`feed_weight`,`feed_percentage`,`raw_value`,`source`,`timestamp`)
SELECT  g.device_id,
        g.weight,
        ROUND(g.weight / 10000 * 100, 2),
        ROUND(128340 + g.weight * 214.75),
        'live',
        g.ts
FROM (
    SELECT  d.device_id,
            ROUND(
                (CASE d.device_id WHEN 1 THEN 9000 ELSE 9500 END)
                - MOD(s.n * (CASE d.device_id WHEN 1 THEN 135 ELSE 128 END), 6500)
                + (RAND(s.n + d.device_id) * 40 - 20), 2) AS weight,
            DATE_SUB(NOW(), INTERVAL (671 - s.n) * 30 MINUTE) AS ts
    FROM `seq_helper` s
    CROSS JOIN (SELECT 1 AS device_id UNION ALL SELECT 2) d
    WHERE s.n < 672
) g;

INSERT INTO `water_readings` (`device_id`,`water_level`,`water_percentage`,`distance_cm`,`source`,`timestamp`)
SELECT  g.device_id,
        g.lvl,
        ROUND(g.lvl / 100 * 100, 2),
        -- distance = sensor offset + (tank depth - water depth)
        ROUND(g.offset_cm + (100 - g.lvl), 2),
        'live',
        g.ts
FROM (
    SELECT  d.device_id,
            (CASE d.device_id WHEN 1 THEN 10 ELSE 12 END) AS offset_cm,
            ROUND(
                (CASE d.device_id WHEN 1 THEN 90 ELSE 92 END)
                - MOD(s.n * 0.83, (CASE d.device_id WHEN 1 THEN 60 ELSE 57 END))
                + (RAND(s.n * 7 + d.device_id) * 1.0 - 0.5), 2) AS lvl,
            DATE_SUB(NOW(), INTERVAL (671 - s.n) * 30 MINUTE) AS ts
    FROM `seq_helper` s
    CROSS JOIN (SELECT 1 AS device_id UNION ALL SELECT 2) d
    WHERE s.n < 672
) g;

-- --- Sample dispensing history (roughly one refill per day per device) --
INSERT INTO `feed_dispensing`
(`device_id`,`user_id`,`start_weight`,`target_weight`,`actual_weight`,`dispensed_amount`,
 `dispensing_method`,`status`,`duration_ms`,`notes`,`timestamp`)
SELECT  g.device_id,
        g.user_id,
        g.start_w,
        g.target_w,
        g.actual_w,
        ROUND(g.actual_w - g.start_w, 2),
        g.method,
        'completed',
        ROUND((g.actual_w - g.start_w) / 120 * 1000),   -- auger moves ~120 g/s
        g.notes,
        g.ts
FROM (
    SELECT  d.device_id,
            CASE WHEN MOD(s.n, 5) = 0 THEN 3 ELSE NULL END AS user_id,
            CASE WHEN MOD(s.n, 5) = 0 THEN 'manual' ELSE 'automatic' END AS method,
            CASE WHEN MOD(s.n, 5) = 0 THEN 'Manual top-up during morning round'
                 ELSE 'Threshold reached, auger engaged' END AS notes,
            (CASE d.device_id WHEN 1 THEN 9000 ELSE 9500 END) AS target_w,
            ROUND(2400 + RAND(s.n + d.device_id) * 150, 2) AS start_w,
            ROUND((CASE d.device_id WHEN 1 THEN 9000 ELSE 9500 END)
                  + (RAND(s.n * 3 + d.device_id) * 8 - 4), 2) AS actual_w,
            -- Anchored so the newest event is ~18 hours ago; every demo row
            -- must land in the past or the dashboard would show a refill
            -- that has not happened yet.
            DATE_SUB(NOW(), INTERVAL (14 - s.n) DAY) + INTERVAL 6 HOUR AS ts
    FROM `seq_helper` s
    CROSS JOIN (SELECT 1 AS device_id UNION ALL SELECT 2) d
    WHERE s.n < 14
) g;

INSERT INTO `water_dispensing`
(`device_id`,`user_id`,`starting_level`,`ending_level`,`target_level`,
 `dispensing_method`,`status`,`duration_ms`,`notes`,`timestamp`)
SELECT  g.device_id,
        g.user_id,
        g.start_l,
        g.end_l,
        g.target_l,
        g.method,
        'completed',
        ROUND((g.end_l - g.start_l) * 2400),            -- valve raises ~1 cm / 2.4 s
        g.notes,
        g.ts
FROM (
    SELECT  d.device_id,
            CASE WHEN MOD(s.n, 6) = 0 THEN 3 ELSE NULL END AS user_id,
            CASE WHEN MOD(s.n, 6) = 0 THEN 'manual' ELSE 'automatic' END AS method,
            CASE WHEN MOD(s.n, 6) = 0 THEN 'Manual refill requested from dashboard'
                 ELSE 'Level below threshold, valve opened' END AS notes,
            (CASE d.device_id WHEN 1 THEN 90 ELSE 92 END) AS target_l,
            ROUND(29 + RAND(s.n + d.device_id) * 3, 2) AS start_l,
            ROUND((CASE d.device_id WHEN 1 THEN 90 ELSE 92 END)
                  + (RAND(s.n * 5 + d.device_id) * 1.2 - 0.6), 2) AS end_l,
            -- Newest refill lands ~13 hours ago; see the note above.
            DATE_SUB(NOW(), INTERVAL (14 - s.n) DAY) + INTERVAL 11 HOUR AS ts
    FROM `seq_helper` s
    CROSS JOIN (SELECT 1 AS device_id UNION ALL SELECT 2) d
    WHERE s.n < 14
) g;

DROP TABLE `seq_helper`;

-- --- Sample alerts ----------------------------------------------------
INSERT INTO `alerts` (`device_id`,`alert_type`,`severity`,`message`,`status`,`created_at`) VALUES
(1,'low_feed','WARNING','Feed level dropped to 28.4% (2,840 g) — below the 30% low threshold.','unread', DATE_SUB(NOW(), INTERVAL 3 HOUR)),
(1,'low_water','WARNING','Water level at 29.6 cm — below the 30 cm refill threshold.','unread', DATE_SUB(NOW(), INTERVAL 9 HOUR)),
(2,'device_offline','CRITICAL','Layer House 2 Controller has not reported for more than 5 minutes.','unread', DATE_SUB(NOW(), INTERVAL 40 MINUTE)),
(1,'info','INFO','Automatic feed dispensing completed: 6,585 g added in 54.9 s.','read', DATE_SUB(NOW(), INTERVAL 1 DAY)),
(2,'critical_feed','CRITICAL','Feed level at 12.1% — critical. Refill the hopper immediately.','read', DATE_SUB(NOW(), INTERVAL 2 DAY)),
(1,'abnormal_reading','WARNING','Load cell reported a negative weight (-38 g). Check the tare offset.','resolved', DATE_SUB(NOW(), INTERVAL 4 DAY)),
(2,'internet_disconnected','WARNING','Device switched to offline automation; 46 buffered records pending upload.','resolved', DATE_SUB(NOW(), INTERVAL 5 DAY)),
(1,'dispensing_timeout','CRITICAL','Auger stopped by the 60 s safety timeout before reaching the target weight.','resolved', DATE_SUB(NOW(), INTERVAL 6 DAY)),
(2,'sensor_failure','CRITICAL','HC-SR04 returned no echo on 5 consecutive reads.','resolved', DATE_SUB(NOW(), INTERVAL 8 DAY)),
(1,'motor_error','WARNING','Auger drew no measurable weight change during a 20 s run.','resolved', DATE_SUB(NOW(), INTERVAL 10 DAY));

-- --- Sample activity log ---------------------------------------------
INSERT INTO `activity_logs` (`user_id`,`action`,`description`,`ip_address`,`created_at`) VALUES
(1,'LOGIN','User owner signed in.','127.0.0.1', DATE_SUB(NOW(), INTERVAL 2 HOUR)),
(3,'MANUAL_FEED','Manual feed dispensing (target 9,000 g) requested on Broiler House 1 Controller.','127.0.0.1', DATE_SUB(NOW(), INTERVAL 5 HOUR)),
(3,'CALIBRATE_SENSOR','Load cell recalibrated on Broiler House 1 Controller (factor 214.7500).','127.0.0.1', DATE_SUB(NOW(), INTERVAL 1 DAY)),
(1,'UPDATE_SETTINGS','Automation settings updated for Broiler House 1 Controller.','127.0.0.1', DATE_SUB(NOW(), INTERVAL 2 DAY)),
(3,'MANUAL_WATER','Manual water refill (target 90 cm) requested on Layer House 2 Controller.','127.0.0.1', DATE_SUB(NOW(), INTERVAL 3 DAY)),
(NULL,'AUTO_FEED','Automatic feed dispensing completed on Broiler House 1 Controller.','192.168.1.45', DATE_SUB(NOW(), INTERVAL 1 DAY)),
(NULL,'DEVICE_REGISTERED','Device Layer House 2 Controller registered.','192.168.1.46', DATE_SUB(NOW(), INTERVAL 20 DAY)),
(1,'LOGOUT','User owner signed out.','127.0.0.1', DATE_SUB(NOW(), INTERVAL 1 HOUR));

-- =====================================================================
--  END OF SCHEMA
-- =====================================================================
