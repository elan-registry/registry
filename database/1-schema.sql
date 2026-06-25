-- =============================================================================
-- Database Migration: UserSpice 5.9 to Elan Registry
-- =============================================================================
-- This script transforms a fresh UserSpice 5.9 installation into an Elan
-- Registry database structure.
--
-- Prerequisites:
-- - Fresh UserSpice 5.9 installation
-- - Required plugins: autoassignun, getsettings, hooker, recaptcha, sendinblue
--
-- WARNING: Run once only on a clean UserSpice 5.9 database
-- =============================================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET FOREIGN_KEY_CHECKS = 0;
START TRANSACTION;
SET time_zone = "+00:00";

-- =============================================================================
-- SECTION 1: CREATE NEW TABLES
-- =============================================================================

-- -----------------------------------------------------------------------------
-- Table: country (Reference table for country data)
-- -----------------------------------------------------------------------------
CREATE TABLE `country` (
  `id` int(3) NOT NULL,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Table: elan_factory_info (Factory production/build data)
-- -----------------------------------------------------------------------------
CREATE TABLE `elan_factory_info` (
  `id` int(11) NOT NULL,
  `year` varchar(4) COLLATE utf8mb4_unicode_ci NOT NULL,
  `month` varchar(2) COLLATE utf8mb4_unicode_ci NOT NULL,
  `batch` varchar(4) COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` varchar(2) COLLATE utf8mb4_unicode_ci NOT NULL,
  `serial` varchar(5) COLLATE utf8mb4_unicode_ci NOT NULL,
  `suffix` varchar(1) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'After 1970',
  `engineletter` varchar(3) COLLATE utf8mb4_unicode_ci NOT NULL,
  `enginenumber` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  `gearbox` varchar(1) COLLATE utf8mb4_unicode_ci NOT NULL,
  `color` varchar(256) COLLATE utf8mb4_unicode_ci NOT NULL,
  `builddate` date NOT NULL COMMENT 'BUILT / INVOICED / 1ST REG',
  `note` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Table: car_models (Reference table for Lotus Elan model definitions)
-- -----------------------------------------------------------------------------
CREATE TABLE `car_models` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `year_available_from` int(11) NOT NULL COMMENT 'First production year',
  `year_available_to` int(11) NOT NULL COMMENT 'Last production year',
  `display_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Full display name from cardefinition.js',
  `human_readable_short` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Short name (no parenthetical)',
  `series` varchar(15) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Series (S1, S2, S3, S4, +2, Sprint, etc.)',
  `variant` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Body style (Roadster, FHC, DHC, Federal, Race)',
  `type_code` char(3) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Type code (26, 36, 45, 50, 26R)',
  `model_value` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'series|variant|type composite key',
  `series_normalized` varchar(15) COLLATE utf8mb4_unicode_ci GENERATED ALWAYS AS (
    CASE
      WHEN `series` LIKE '% SE' THEN TRIM(SUBSTRING_INDEX(`series`, ' SE', 1))
      WHEN `series` LIKE '% S/E' THEN TRIM(SUBSTRING_INDEX(`series`, ' S/E', 1))
      WHEN `series` LIKE '%|Race' THEN TRIM(SUBSTRING_INDEX(`series`, '|Race', 1))
      ELSE `series`
    END
  ) STORED COMMENT 'Normalized series for filtering (strips SE/Race suffixes)',
  PRIMARY KEY (`id`),
  UNIQUE KEY `model_value` (`model_value`),
  UNIQUE KEY `unique_model_combo` (`series`,`variant`,`type_code`),
  KEY `idx_year_range` (`year_available_from`,`year_available_to`),
  KEY `idx_series` (`series`),
  KEY `idx_series_normalized` (`series_normalized`),
  KEY `idx_type_code` (`type_code`),
  CONSTRAINT `ck_year_range` CHECK ((`year_available_from` <= `year_available_to`)),
  CONSTRAINT `ck_year_bounds` CHECK (((`year_available_from` >= 1963) and (`year_available_to` <= 1974))),
  CONSTRAINT `ck_type_code` CHECK ((`type_code` in ('26','36','45','50','26R')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Lotus Elan model reference data from cardefinition.js';

-- -----------------------------------------------------------------------------
-- Table: fix_script_runs (Migration tracking)
-- -----------------------------------------------------------------------------
CREATE TABLE `fix_script_runs` (
  `id` int(11) NOT NULL,
  `script_name` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  `completed_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- -----------------------------------------------------------------------------
-- Table: cars (Primary vehicle registry)
-- -----------------------------------------------------------------------------
CREATE TABLE `cars` (
  `id` int(10) UNSIGNED NOT NULL,
  `ctime` timestamp NULL DEFAULT NULL,
  `mtime` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `vericode` varchar(32) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_verified` timestamp NULL DEFAULT NULL,
  `ModifiedBy` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `model` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `series` varchar(12) COLLATE utf8mb4_unicode_ci NOT NULL,
  `variant` varchar(15) COLLATE utf8mb4_unicode_ci NOT NULL,
  `year` varchar(4) COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` char(3) COLLATE utf8mb4_unicode_ci NOT NULL,
  `chassis` varchar(15) COLLATE utf8mb4_unicode_ci NOT NULL,
  `color` varchar(25) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `engine` varchar(15) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `purchasedate` date DEFAULT NULL,
  `solddate` date DEFAULT NULL,
  `comments` mediumtext COLLATE utf8mb4_unicode_ci,
  `image` mediumtext COLLATE utf8mb4_unicode_ci,
  `user_id` int(11) DEFAULT NULL,
  `email` varchar(155) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `fname` varchar(155) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `lname` varchar(155) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `join_date` datetime DEFAULT NULL,
  `city` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `state` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `country` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `lat` float DEFAULT NULL,
  `lon` float DEFAULT NULL,
  `website` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Table: cars_hist (Audit trail for cars table)
-- -----------------------------------------------------------------------------
CREATE TABLE `cars_hist` (
  `id` int(11) NOT NULL,
  `operation` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL,
  `car_id` int(11) UNSIGNED NOT NULL,
  `ctime` timestamp NULL DEFAULT NULL,
  `mtime` timestamp NULL DEFAULT NULL,
  `ModifiedBy` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `model` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `series` varchar(12) COLLATE utf8mb4_unicode_ci NOT NULL,
  `variant` varchar(15) COLLATE utf8mb4_unicode_ci NOT NULL,
  `year` varchar(4) COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` char(3) COLLATE utf8mb4_unicode_ci NOT NULL,
  `chassis` varchar(15) COLLATE utf8mb4_unicode_ci NOT NULL,
  `color` varchar(25) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `engine` varchar(15) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `purchasedate` date DEFAULT NULL,
  `solddate` date DEFAULT NULL,
  `comments` mediumtext COLLATE utf8mb4_unicode_ci,
  `image` mediumtext COLLATE utf8mb4_unicode_ci,
  `user_id` int(11) DEFAULT NULL,
  `email` varchar(155) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `fname` varchar(155) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `lname` varchar(155) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `join_date` datetime DEFAULT NULL,
  `city` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `state` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `country` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `lat` float DEFAULT NULL,
  `lon` float DEFAULT NULL,
  `website` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Table: car_user (Junction table for car sharing)
-- -----------------------------------------------------------------------------
CREATE TABLE `car_user` (
  `id` int(11) NOT NULL,
  `userid` int(11) NOT NULL,
  `car_id` int(11) NOT NULL,
  `mtime` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Table: car_user_hist (Audit trail for car sharing)
-- -----------------------------------------------------------------------------
CREATE TABLE `car_user_hist` (
  `id` int(11) NOT NULL,
  `operation` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL,
  `car_id` int(11) UNSIGNED NOT NULL,
  `userid` int(11) DEFAULT NULL,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Table: car_transfer_requests (Ownership transfer system)
-- -----------------------------------------------------------------------------
CREATE TABLE `car_transfer_requests` (
  `id` int(10) UNSIGNED NOT NULL,
  `existing_car_id` int(10) UNSIGNED NOT NULL,
  `requested_by_user_id` int(11) NOT NULL,
  `request_date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `status` enum('pending','approved','denied','completed','expired') NOT NULL DEFAULT 'pending',
  `security_token` varchar(64) NOT NULL,
  `expires_at` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
  `admin_notes` text,
  `current_owner_response_date` timestamp NULL DEFAULT NULL,
  `completed_date` timestamp NULL DEFAULT NULL,
  `denial_reason` text,
  `submitted_model` varchar(30) NOT NULL,
  `submitted_series` varchar(12) NOT NULL,
  `submitted_variant` varchar(15) NOT NULL,
  `submitted_year` varchar(4) NOT NULL,
  `submitted_type` char(3) NOT NULL,
  `submitted_chassis` varchar(15) NOT NULL,
  `submitted_color` varchar(25) DEFAULT NULL,
  `submitted_engine` varchar(15) DEFAULT NULL,
  `submitted_purchasedate` date DEFAULT NULL,
  `submitted_solddate` date DEFAULT NULL,
  `submitted_comments` text,
  `submitted_image` text,
  `submitted_email` varchar(155) DEFAULT NULL,
  `submitted_fname` varchar(155) DEFAULT NULL,
  `submitted_lname` varchar(155) DEFAULT NULL,
  `submitted_city` varchar(100) DEFAULT NULL,
  `submitted_state` varchar(100) DEFAULT NULL,
  `submitted_country` varchar(100) DEFAULT NULL,
  `submitted_website` varchar(100) DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `modified_date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Self-service car ownership transfer requests - stores pending transfers when duplicate chassis detected during car entry';

-- =============================================================================
-- SECTION 2: MODIFY EXISTING TABLES
-- =============================================================================

-- -----------------------------------------------------------------------------
-- Enhance profiles table with geographic location fields
-- -----------------------------------------------------------------------------
ALTER TABLE `profiles`
  ADD COLUMN `city` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  ADD COLUMN `state` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  ADD COLUMN `country` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  ADD COLUMN `lat` float DEFAULT NULL,
  ADD COLUMN `lon` float DEFAULT NULL,
  ADD COLUMN `website` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL;

-- -----------------------------------------------------------------------------
-- Enhance settings table with Elan Registry configuration fields
-- -----------------------------------------------------------------------------
ALTER TABLE `settings`
  ADD COLUMN `us_css1` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  ADD COLUMN `us_css2` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  ADD COLUMN `us_css3` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  ADD COLUMN `elan_backup_age` int(11) NOT NULL,
  ADD COLUMN `elan_image_dir` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  ADD COLUMN `elan_image_max` int(11) NOT NULL,
  ADD COLUMN `fun` mediumtext COLLATE utf8mb4_unicode_ci,
  ADD COLUMN `elan_spam_cleanup_enabled` tinyint(1) DEFAULT '0' COMMENT 'Enable automated SPAM cleanup',
  ADD COLUMN `elan_spam_cleanup_dry_run` tinyint(1) DEFAULT '1' COMMENT 'Run SPAM cleanup in dry-run mode',
  ADD COLUMN `elan_spam_inactive_days` int(11) DEFAULT '30' COMMENT 'Days before considering user inactive',
  ADD COLUMN `elan_spam_grace_period_days` int(11) DEFAULT '7' COMMENT 'Grace period days before deletion',
  ADD COLUMN `elan_spam_max_deletions` int(11) DEFAULT '50' COMMENT 'Maximum deletions per cleanup run',
  ADD COLUMN `elan_spam_max_percentage` decimal(4,2) DEFAULT '5.00' COMMENT 'Maximum percentage of users to cleanup',
  ADD COLUMN `elan_spam_email_notifications` tinyint(1) DEFAULT '0' COMMENT 'Enable grace period email notifications',
  ADD COLUMN `elan_image_upload_max_size` decimal(4,2) DEFAULT '2.00' COMMENT 'Maximum upload file size in MB',
  ADD COLUMN `elan_image_display_max_size` int(11) DEFAULT '2048' COMMENT 'Maximum display image width in pixels',
  ADD COLUMN `elan_image_thumbnail_sizes` mediumtext COLLATE utf8mb4_unicode_ci COMMENT 'Comma-separated thumbnail sizes in pixels',
  ADD COLUMN `elan_admin_emails` mediumtext COLLATE utf8mb4_unicode_ci COMMENT 'Comma-separated admin email addresses for system notifications and administrative alerts';

-- =============================================================================
-- SECTION 3: ADD INDEXES AND PRIMARY KEYS
-- =============================================================================

-- country table
ALTER TABLE `country`
  ADD PRIMARY KEY (`id`);

-- elan_factory_info table
ALTER TABLE `elan_factory_info`
  ADD PRIMARY KEY (`id`);

-- fix_script_runs table
ALTER TABLE `fix_script_runs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_script_name` (`script_name`);

-- cars table
ALTER TABLE `cars`
  ADD UNIQUE KEY `id` (`id`),
  ADD KEY `idx_cars_chassis` (`chassis`),
  ADD KEY `idx_cars_year` (`year`),
  ADD KEY `idx_cars_series` (`series`),
  ADD KEY `idx_cars_city` (`city`),
  ADD KEY `idx_cars_state` (`state`),
  ADD KEY `idx_cars_country` (`country`);

-- cars_hist table
ALTER TABLE `cars_hist`
  ADD UNIQUE KEY `id` (`id`) USING BTREE,
  ADD KEY `idx_cars_hist_car_id` (`car_id`),
  ADD KEY `idx_cars_hist_timestamp` (`timestamp`);

-- car_user table
ALTER TABLE `car_user`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_car_user_car_id` (`car_id`),
  ADD KEY `idx_car_user_userid` (`userid`);

-- car_user_hist table
ALTER TABLE `car_user_hist`
  ADD UNIQUE KEY `id` (`id`) USING BTREE;

ALTER TABLE `car_user_hist`
  ADD KEY `idx_car_user_hist_car_id` (`car_id`);

ALTER TABLE `car_user_hist`
  ADD KEY `idx_car_user_hist_userid` (`userid`);

-- car_transfer_requests table
ALTER TABLE `car_transfer_requests`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `security_token` (`security_token`),
  ADD KEY `existing_car_id` (`existing_car_id`),
  ADD KEY `requested_by_user_id` (`requested_by_user_id`),
  ADD KEY `status` (`status`),
  ADD KEY `request_date` (`request_date`),
  ADD KEY `expires_at` (`expires_at`),
  ADD KEY `submitted_chassis` (`submitted_chassis`),
  ADD KEY `submitted_type` (`submitted_type`),
  ADD KEY `fk_transfer_created_by` (`created_by`),
  ADD KEY `idx_car_pending_transfers` (`existing_car_id`,`status`),
  ADD KEY `idx_user_transfer_requests` (`requested_by_user_id`,`status`),
  ADD KEY `idx_expired_requests` (`status`,`expires_at`),
  ADD KEY `idx_submitted_chassis_type` (`submitted_type`,`submitted_chassis`);

-- =============================================================================
-- SECTION 4: SET AUTO_INCREMENT VALUES
-- =============================================================================

ALTER TABLE `country`
  MODIFY `id` int(3) NOT NULL AUTO_INCREMENT;

ALTER TABLE `elan_factory_info`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `fix_script_runs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `cars`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

ALTER TABLE `cars_hist`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `car_user`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `car_user_hist`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `car_transfer_requests`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

-- =============================================================================
-- SECTION 5: CREATE TRIGGERS FOR AUDIT TRAILS
-- =============================================================================

-- -----------------------------------------------------------------------------
-- Trigger: cars_delete (Audit trail for car deletions)
-- -----------------------------------------------------------------------------
DELIMITER $$
CREATE TRIGGER `cars_delete` AFTER DELETE ON `cars` FOR EACH ROW BEGIN
    INSERT INTO cars_hist(
        operation, car_id, ctime, mtime, ModifiedBy, model, series, variant,
        year, type, chassis, color, engine, purchasedate, solddate, comments,
        image, user_id, email, fname, lname, join_date, city, state, country,
        lat, lon, website
    )
    VALUES (
        'DELETE', OLD.id, OLD.ctime, OLD.mtime, OLD.ModifiedBy, OLD.model,
        OLD.series, OLD.variant, OLD.year, OLD.type, OLD.chassis, OLD.color,
        OLD.engine, OLD.purchasedate, OLD.solddate, OLD.comments, OLD.image,
        OLD.user_id, OLD.email, OLD.fname, OLD.lname, OLD.join_date, OLD.city,
        OLD.state, OLD.country, OLD.lat, OLD.lon, OLD.website
    );
END$$
DELIMITER ;

-- -----------------------------------------------------------------------------
-- Trigger: cars_insert (Audit trail for car insertions)
-- -----------------------------------------------------------------------------
DELIMITER $$
CREATE TRIGGER `cars_insert` AFTER INSERT ON `cars` FOR EACH ROW BEGIN
    INSERT INTO cars_hist(
        operation, car_id, ctime, mtime, ModifiedBy, model, series, variant,
        year, type, chassis, color, engine, purchasedate, solddate, comments,
        image, user_id, email, fname, lname, join_date, city, state, country,
        lat, lon, website
    )
    VALUES (
        'INSERT', NEW.id, NEW.ctime, NEW.mtime, NEW.ModifiedBy, NEW.model,
        NEW.series, NEW.variant, NEW.year, NEW.type, NEW.chassis, NEW.color,
        NEW.engine, NEW.purchasedate, NEW.solddate, NEW.comments, NEW.image,
        NEW.user_id, NEW.email, NEW.fname, NEW.lname, NEW.join_date, NEW.city,
        NEW.state, NEW.country, NEW.lat, NEW.lon, NEW.website
    );
END$$
DELIMITER ;

-- -----------------------------------------------------------------------------
-- Trigger: cars_update (Audit trail for car updates)
-- -----------------------------------------------------------------------------
DELIMITER $$
CREATE TRIGGER `cars_update` AFTER UPDATE ON `cars` FOR EACH ROW BEGIN
    IF @disable_triggers IS NULL THEN
        INSERT INTO cars_hist(
            operation, car_id, ctime, mtime, ModifiedBy, model, series, variant,
            year, type, chassis, color, engine, purchasedate, solddate, comments,
            image, user_id, email, fname, lname, join_date, city, state, country,
            lat, lon, website
        )
        VALUES (
            'UPDATE', OLD.id, OLD.ctime, OLD.mtime, OLD.ModifiedBy, OLD.model,
            OLD.series, OLD.variant, OLD.year, OLD.type, OLD.chassis, OLD.color,
            OLD.engine, OLD.purchasedate, OLD.solddate, OLD.comments, OLD.image,
            OLD.user_id, OLD.email, OLD.fname, OLD.lname, OLD.join_date, OLD.city,
            OLD.state, OLD.country, OLD.lat, OLD.lon, OLD.website
        );
    END IF;
END$$
DELIMITER ;

-- -----------------------------------------------------------------------------
-- Trigger: car_user_delete (Audit trail for car-user relationship removals)
-- -----------------------------------------------------------------------------
DELIMITER $$
CREATE TRIGGER `car_user_delete` AFTER DELETE ON `car_user` FOR EACH ROW BEGIN
    INSERT INTO car_user_hist (operation, car_id, userid)
    VALUES ('DELETE', OLD.car_id, OLD.userid);
END$$
DELIMITER ;

-- -----------------------------------------------------------------------------
-- Trigger: car_user_insert (Audit trail for car-user relationship additions)
-- -----------------------------------------------------------------------------
DELIMITER $$
CREATE TRIGGER `car_user_insert` AFTER INSERT ON `car_user` FOR EACH ROW BEGIN
    INSERT INTO car_user_hist (operation, car_id, userid)
    VALUES ('INSERT', NEW.car_id, NEW.userid);
END$$
DELIMITER ;

-- -----------------------------------------------------------------------------
-- Trigger: car_user_update (Audit trail for car-user relationship updates)
-- -----------------------------------------------------------------------------
DELIMITER $$
CREATE TRIGGER `car_user_update` AFTER UPDATE ON `car_user` FOR EACH ROW BEGIN
    IF @disable_triggers IS NULL THEN
        INSERT INTO car_user_hist (operation, car_id, userid)
        VALUES ('UPDATE', OLD.car_id, OLD.userid);
    END IF;
END$$
DELIMITER ;

-- =============================================================================
-- SECTION 6: ADD FOREIGN KEY CONSTRAINTS
-- =============================================================================

ALTER TABLE `car_transfer_requests`
  ADD CONSTRAINT `fk_transfer_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_transfer_requested_by` FOREIGN KEY (`requested_by_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

-- =============================================================================
-- FINALIZE
-- =============================================================================

SET FOREIGN_KEY_CHECKS = 1;
COMMIT;

-- =============================================================================
-- MIGRATION COMPLETE
-- =============================================================================
-- This script has successfully:
-- ✅ Created 8 custom tables for car registry functionality
-- ✅ Enhanced profiles table with 6 geographic location fields
-- ✅ Enhanced settings table with 19 Elan Registry configuration fields
-- ✅ Set up audit trail triggers for automatic change tracking
-- ✅ Added foreign key constraints for data integrity
--
-- Next Steps:
-- 1. Run database/2-reference-data.sql to populate country and factory data
-- 2. Run database/3-configuration.sql to configure settings and menus
-- 3. Configure Google API keys via admin interface or update settings table
-- 4. Optional: Run database/4-sample-data.sql for test user and car
-- 5. Test car registration functionality
-- =============================================================================
