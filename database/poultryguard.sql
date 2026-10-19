-- =====================================================================
--  PoultryGuard : Precision IoT Automation for Poultry Feeding & Hydration
--  Complete MySQL 8+ schema, constraints, indexes and demo data.
--
--  Import:   mysql -u root -p < poultryguard.sql
--    or      phpMyAdmin -> Import -> poultryguard.sql
--
--  Units used consistently across the whole system:
--    * feeder runs ....... seconds the motor ran
--    * water level ....... centimetres (cm) of water depth in the tank
--    * water level ....... % from the level sensor, stored with a derived distance
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
-- 4. water_readings  (water level sensor samples)
-- ---------------------------------------------------------------------
CREATE TABLE `water_readings` (
    `reading_id`       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `device_id`        INT UNSIGNED  NOT NULL,
    `water_level`      DECIMAL(10,2) NOT NULL COMMENT 'cm of water depth',
    `water_percentage` DECIMAL(5,2)  NOT NULL COMMENT '0-100',
    `distance_cm`      DECIMAL(10,2) NOT NULL COMMENT 'derived sensor-to-surface distance',
    `source`           ENUM('live','offline_sync') NOT NULL DEFAULT 'live',
    `timestamp`        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`reading_id`),
    KEY `idx_water_readings_device_time` (`device_id`, `timestamp`),
    CONSTRAINT `fk_water_readings_device` FOREIGN KEY (`device_id`)
        REFERENCES `devices` (`device_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 5. feed_dispensing  (every feeder motor run, automatic or manual)
-- ---------------------------------------------------------------------
CREATE TABLE `feed_dispensing` (
    `dispensing_id`     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `device_id`         INT UNSIGNED  NOT NULL,
    `user_id`           INT UNSIGNED  DEFAULT NULL COMMENT 'set for manual actions',
    `dispensing_method` ENUM('automatic','manual','offline') NOT NULL DEFAULT 'automatic',
    `status`            ENUM('pending','running','completed','failed','aborted','timeout')
                        NOT NULL DEFAULT 'pending',
    `duration_ms`       INT UNSIGNED  DEFAULT NULL COMMENT 'how long the feeder motor ran',
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
-- 6. water_dispensing  (every pump run)
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
    -- feed (timed feeder)
    `feed_max_duration`      INT UNSIGNED  NOT NULL DEFAULT 30       COMMENT 'seconds, feeder motor safety cut-off',
    `auto_feed`              TINYINT(1)    NOT NULL DEFAULT 1,
    -- water
    `water_capacity`         DECIMAL(10,2) NOT NULL DEFAULT 100.00   COMMENT 'cm, usable tank depth',
    `water_capacity_liters`  DECIMAL(10,2) NOT NULL DEFAULT 200.00   COMMENT 'litres at 100% level',
    `sensor_offset_cm`       DECIMAL(10,2) NOT NULL DEFAULT 0.00     COMMENT 'internal: distance conversion offset',
    `water_threshold`        DECIMAL(10,2) NOT NULL DEFAULT 30.00    COMMENT 'cm, refill below this',
    `water_target`           DECIMAL(10,2) NOT NULL DEFAULT 90.00    COMMENT 'cm, refill up to this',
    `water_max_duration`     INT UNSIGNED  NOT NULL DEFAULT 300      COMMENT 'seconds, pump safety cut-off',
    `auto_water`             TINYINT(1)    NOT NULL DEFAULT 1,
    -- alert thresholds
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
                       'reload_settings','emergency_stop','reboot') NOT NULL,
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
-- 15. climate_readings  (DHT11 temperature & humidity)
--     Existing installs: deploy/init-db.php also creates this table
--     (CREATE TABLE IF NOT EXISTS), so no manual migration is needed.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `climate_readings` (
    `reading_id`    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `device_id`     INT UNSIGNED  NOT NULL,
    `temperature_c` DECIMAL(5,2)  NOT NULL,
    `humidity_pct`  DECIMAL(5,2)  NOT NULL,
    `heat_level`    ENUM('normal','warning','danger') NOT NULL DEFAULT 'normal',
    `source`        ENUM('live','offline_sync') NOT NULL DEFAULT 'live',
    `timestamp`     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`reading_id`),
    KEY `idx_climate_device_time` (`device_id`, `timestamp`),
    CONSTRAINT `fk_climate_device` FOREIGN KEY (`device_id`)
        REFERENCES `devices` (`device_id`) ON DELETE CASCADE ON UPDATE CASCADE
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
 `ip_address`,`wifi_rssi`,`water_sensor_status`,`motor_status`,
 `solenoid_status`,`last_seen`) VALUES
(1,'Broiler House 1 Controller','ESP32-A1B2C3D4E5F6',
 '97161c5b9d4ab435f52f8a8a9b48af25e8c09751','Broiler House 1 - North Wing','active','2.0.0',
 '192.168.1.45',-58,'ok','idle','idle', NOW()),
(2,'Layer House 2 Controller','ESP32-F6E5D4C3B2A1',
 '81a1ca36a1e6116bf35d0a077c9baeb454dd5e80','Layer House 2 - South Wing','active','2.0.0',
 '192.168.1.46',-71,'ok','idle','idle', DATE_SUB(NOW(), INTERVAL 42 MINUTE));

-- --- Default automation settings (one row per device) ------------------
INSERT INTO `system_settings`
(`device_id`,`feed_max_duration`,`auto_feed`,`water_capacity`,`water_capacity_liters`,`sensor_offset_cm`,
 `water_threshold`,`water_target`,`water_max_duration`,`auto_water`,
 `water_low_percent`,`water_critical_percent`,`offline_timeout`,`reading_interval`,`updated_by`) VALUES
(1,30,1,100.00,200.00,0.00,30.00,90.00,60,1,30.00,15.00,300,30,1),
(2,30,1,100.00,250.00,0.00,35.00,92.00,60,1,30.00,15.00,300,30,1);

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
            0 AS offset_cm,
            ROUND(
                (CASE d.device_id WHEN 1 THEN 90 ELSE 92 END)
                - MOD(s.n * 0.83, (CASE d.device_id WHEN 1 THEN 60 ELSE 57 END))
                + (RAND(s.n * 7 + d.device_id) * 1.0 - 0.5), 2) AS lvl,
            DATE_SUB(NOW(), INTERVAL (671 - s.n) * 30 MINUTE) AS ts
    FROM `seq_helper` s
    CROSS JOIN (SELECT 1 AS device_id UNION ALL SELECT 2) d
    WHERE s.n < 672
) g;

-- --- Sample climate history (DHT11): a daily temperature swing ---------
INSERT INTO `climate_readings` (`device_id`,`temperature_c`,`humidity_pct`,`heat_level`,`source`,`timestamp`)
SELECT  g.device_id, g.t, g.h,
        CASE WHEN g.t >= 35 THEN 'danger' WHEN g.t >= 32 THEN 'warning' ELSE 'normal' END,
        'live', g.ts
FROM (
    SELECT  d.device_id,
            ROUND(28 + 5 * SIN((MOD(s.n, 48) - 20) / 48 * 2 * PI()) + (RAND(s.n + d.device_id) - 0.5), 1) AS t,
            ROUND(68 - 8 * SIN((MOD(s.n, 48) - 20) / 48 * 2 * PI()) + (RAND(s.n * 3 + d.device_id) * 2 - 1), 0) AS h,
            DATE_SUB(NOW(), INTERVAL (671 - s.n) * 30 MINUTE) AS ts
    FROM `seq_helper` s
    CROSS JOIN (SELECT 1 AS device_id UNION ALL SELECT 2) d
    WHERE s.n < 672
) g;

-- --- Sample feeder runs (timed, a few per day) -------------------------
INSERT INTO `feed_dispensing`
(`device_id`,`user_id`,`dispensing_method`,`status`,`duration_ms`,`notes`,`timestamp`)
SELECT  d.device_id,
        CASE WHEN MOD(s.n, 5) = 0 THEN 3 ELSE NULL END,
        CASE WHEN MOD(s.n, 5) = 0 THEN 'manual' ELSE 'automatic' END,
        'completed',
        3000 + MOD(s.n, 3) * 1000,
        CASE WHEN MOD(s.n, 5) = 0 THEN 'Manual feeding during morning round' ELSE 'Timed feed' END,
        DATE_SUB(NOW(), INTERVAL (14 - s.n) DAY) + INTERVAL 6 HOUR
FROM `seq_helper` s
CROSS JOIN (SELECT 1 AS device_id UNION ALL SELECT 2) d
WHERE s.n < 14;

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
(1,'abnormal_reading','WARNING','Heat warning: 32.6 C in the house. Chickens may start heat stress.','unread', DATE_SUB(NOW(), INTERVAL 3 HOUR)),
(1,'low_water','WARNING','Water level at 29.6 cm — below the 30 cm refill threshold.','unread', DATE_SUB(NOW(), INTERVAL 9 HOUR)),
(2,'device_offline','CRITICAL','Layer House 2 Controller has not reported for more than 5 minutes.','unread', DATE_SUB(NOW(), INTERVAL 40 MINUTE)),
(1,'info','INFO','Automatic feeding completed: feeder ran 3.0 s.','read', DATE_SUB(NOW(), INTERVAL 1 DAY)),
(2,'abnormal_reading','CRITICAL','Heat danger: 35.4 C in the house. Turn on fans, add shade and cool water.','read', DATE_SUB(NOW(), INTERVAL 2 DAY)),
(1,'sensor_failure','WARNING','Water level sensor sent an invalid value. Check the wiring.','resolved', DATE_SUB(NOW(), INTERVAL 4 DAY)),
(2,'internet_disconnected','WARNING','Device switched to offline automation; 46 buffered records pending upload.','resolved', DATE_SUB(NOW(), INTERVAL 5 DAY)),
(1,'dispensing_timeout','CRITICAL','Feeder motor stopped by the 30 s safety timeout.','resolved', DATE_SUB(NOW(), INTERVAL 6 DAY)),
(2,'solenoid_error','WARNING','Water refill failed. Check the pump and water supply.','resolved', DATE_SUB(NOW(), INTERVAL 8 DAY)),
(1,'motor_error','WARNING','Feeder run failed. Check the feeder motor and relay.','resolved', DATE_SUB(NOW(), INTERVAL 10 DAY));

-- --- Sample activity log ---------------------------------------------
INSERT INTO `activity_logs` (`user_id`,`action`,`description`,`ip_address`,`created_at`) VALUES
(1,'LOGIN','User owner signed in.','127.0.0.1', DATE_SUB(NOW(), INTERVAL 2 HOUR)),
(3,'MANUAL_FEED','Manual feeding (3 s) requested on Broiler House 1 Controller.','127.0.0.1', DATE_SUB(NOW(), INTERVAL 5 HOUR)),
(1,'UPDATE_SETTINGS','Automation settings updated for Broiler House 1 Controller.','127.0.0.1', DATE_SUB(NOW(), INTERVAL 2 DAY)),
(3,'MANUAL_WATER','Manual water refill (target 90 cm) requested on Layer House 2 Controller.','127.0.0.1', DATE_SUB(NOW(), INTERVAL 3 DAY)),
(NULL,'AUTO_FEED','Automatic feeding completed on Broiler House 1 Controller (feeder ran 3.0 s).','192.168.1.45', DATE_SUB(NOW(), INTERVAL 1 DAY)),
(NULL,'DEVICE_REGISTERED','Device Layer House 2 Controller registered.','192.168.1.46', DATE_SUB(NOW(), INTERVAL 20 DAY)),
(1,'LOGOUT','User owner signed out.','127.0.0.1', DATE_SUB(NOW(), INTERVAL 1 HOUR));

-- =====================================================================
--  END OF SCHEMA
-- =====================================================================
