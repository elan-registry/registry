-- =============================================================================
-- Elan Registry Database Schema Update Script
-- =============================================================================
-- This script transforms a fresh UserSpice 5.9 installation into an Elan Registry
-- 
-- Prerequisites:
-- - Fresh UserSpice 5.9 installation with required plugins:
--   - autoassignun, getsettings, hooker, recaptcha, sendinblue
-- 
-- This script adds:
-- - 8 custom tables for car registry functionality  
-- - Enhanced profiles table with geographic fields
-- - Enhanced settings table with registry-specific fields
-- 
-- Version: v2.9.3
-- Created: September 11, 2025
-- Last Updated: December 23, 2025
--
-- MAJOR CHANGES IN THIS VERSION:
-- - Added car_transfer_requests table (ownership transfer workflow)
-- - Removed parts table (not implemented in production)
-- - Replaced cars_hist with comprehensive 30-column structure
-- - Replaced triggers with comprehensive field tracking
-- - Removed deprecated views (usersview, users_carsview)
-- - Updated settings enhancements (34 custom fields)
-- =============================================================================

-- Disable foreign key checks for the duration of this script
SET FOREIGN_KEY_CHECKS = 0;

-- =============================================================================
-- 1. CORE CAR REGISTRY TABLES
-- =============================================================================


CREATE TABLE IF NOT EXISTS `cars` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
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
  `website` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  UNIQUE KEY `id` (`id`),
  KEY `idx_cars_chassis` (`chassis`),
  KEY `idx_cars_year` (`year`),
  KEY `idx_cars_series` (`series`),
  KEY `idx_cars_city` (`city`),
  KEY `idx_cars_state` (`state`),
  KEY `idx_cars_country` (`country`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
DELIMITER $$
DROP TRIGGER IF EXISTS `cars_insert`$$
CREATE TRIGGER cars_insert
                                        AFTER INSERT ON cars
                                        FOR EACH ROW
                                        BEGIN
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
                                        END;
$$
DELIMITER ;
DELIMITER $$
DROP TRIGGER IF EXISTS `cars_update`$$
CREATE TRIGGER cars_update
                                        AFTER UPDATE ON cars
                                        FOR EACH ROW
                                        BEGIN
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
                                        END;
$$
DELIMITER ;
DELIMITER $$
DROP TRIGGER IF EXISTS `cars_delete`$$
CREATE TRIGGER cars_delete
                                        AFTER DELETE ON cars
                                        FOR EACH ROW
                                        BEGIN
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
                                        END;
$$
DELIMITER ;
CREATE TABLE IF NOT EXISTS `cars_hist` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `operation` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL,
  `car_id` int(11) unsigned NOT NULL,
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
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `id` (`id`) USING BTREE,
  KEY `idx_cars_hist_car_id` (`car_id`),
  KEY `idx_cars_hist_timestamp` (`timestamp`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `car_user` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `userid` int(11) NOT NULL,
  `car_id` int(11) NOT NULL,
  `mtime` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_car_user_car_id` (`car_id`),
  KEY `idx_car_user_userid` (`userid`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `car_user_hist` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `operation` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL,
  `car_id` int(11) unsigned NOT NULL,
  `userid` int(11) DEFAULT NULL,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `id` (`id`) USING BTREE
) ENGINE=InnoDB  DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `elan_factory_info` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
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
  `note` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `car_transfer_requests` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `existing_car_id` int(10) unsigned NOT NULL,
  `requested_by_user_id` int(11) NOT NULL,
  `request_date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `status` enum('pending','approved','denied','completed','expired') NOT NULL DEFAULT 'pending',
  `security_token` varchar(64) NOT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
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
  `modified_date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `security_token` (`security_token`),
  KEY `existing_car_id` (`existing_car_id`),
  KEY `requested_by_user_id` (`requested_by_user_id`),
  KEY `status` (`status`),
  KEY `request_date` (`request_date`),
  KEY `expires_at` (`expires_at`),
  KEY `submitted_chassis` (`submitted_chassis`),
  KEY `submitted_type` (`submitted_type`),
  KEY `fk_transfer_created_by` (`created_by`),
  KEY `idx_car_pending_transfers` (`existing_car_id`,`status`),
  KEY `idx_user_transfer_requests` (`requested_by_user_id`,`status`),
  KEY `idx_expired_requests` (`status`,`expires_at`),
  KEY `idx_submitted_chassis_type` (`submitted_type`,`submitted_chassis`),
  CONSTRAINT `fk_transfer_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_transfer_requested_by` FOREIGN KEY (`requested_by_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB  DEFAULT CHARSET=utf8mb4 COMMENT='Self-service car ownership transfer requests - stores pending transfers when duplicate chassis detected during car entry';
CREATE TABLE IF NOT EXISTS `country` (
  `id` int(3) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `fix_script_runs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `script_name` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  `completed_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_script_name` (`script_name`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;



-- =============================================================================
-- 2. REGISTRY SUPPORT TABLES  
-- =============================================================================
-- (Already included above: country, fix_script_runs)

-- =============================================================================
-- 3. ENHANCE EXISTING TABLES
-- =============================================================================

-- Enhance profiles table with geographic location fields
ALTER TABLE `profiles` 
ADD COLUMN `city` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
ADD COLUMN `state` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
ADD COLUMN `country` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
ADD COLUMN `lat` float DEFAULT NULL,
ADD COLUMN `lon` float DEFAULT NULL,
ADD COLUMN `website` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL;

-- Add indexes for geographic fields
ALTER TABLE `profiles` 
ADD INDEX `idx_city` (`city`),
ADD INDEX `idx_state` (`state`),
ADD INDEX `idx_country` (`country`);

-- Enhance settings table with Elan Registry specific fields
ALTER TABLE `settings`
ADD COLUMN `us_css1` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
ADD COLUMN `us_css2` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
ADD COLUMN `us_css3` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
ADD COLUMN `elan_backup_age` int(11) NOT NULL DEFAULT 30,
ADD COLUMN `elan_google_maps_key` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
ADD COLUMN `elan_google_geo_key` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
ADD COLUMN `elan_image_dir` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
ADD COLUMN `elan_image_max` int(11) NOT NULL DEFAULT 10,
ADD COLUMN `elan_jquery_cdn` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
ADD COLUMN `elan_bootstrap_js_cdn` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
ADD COLUMN `elan_bootstrap_css_cdn` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
ADD COLUMN `elan_popper_cdn` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
ADD COLUMN `elan_fontawesome_cdn` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
ADD COLUMN `elan_bootswatch_cdn` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
ADD COLUMN `elan_datatables_js_cdn` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
ADD COLUMN `elan_datatables_css_cdn` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
ADD COLUMN `elan_datepicker_js_cdn` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
ADD COLUMN `elan_datepicker_css_cdn` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
ADD COLUMN `elan_jquery_ui_cdn` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
ADD COLUMN `elan_dropzone_js_cdn` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
ADD COLUMN `elan_dropzone_css_cdn` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
ADD COLUMN `fun` mediumtext COLLATE utf8mb4_unicode_ci,
ADD COLUMN `elan_spam_cleanup_enabled` tinyint(1) DEFAULT 0 COMMENT 'Enable automated SPAM cleanup',
ADD COLUMN `elan_spam_cleanup_dry_run` tinyint(1) DEFAULT 1 COMMENT 'Run SPAM cleanup in dry-run mode',
ADD COLUMN `elan_spam_inactive_days` int(11) DEFAULT 30 COMMENT 'Days before considering user inactive',
ADD COLUMN `elan_spam_grace_period_days` int(11) DEFAULT 7 COMMENT 'Grace period days before deletion',
ADD COLUMN `elan_spam_max_deletions` int(11) DEFAULT 50 COMMENT 'Maximum deletions per cleanup run',
ADD COLUMN `elan_spam_max_percentage` decimal(4,2) DEFAULT 5.00 COMMENT 'Maximum percentage of users to cleanup',
ADD COLUMN `elan_spam_email_notifications` tinyint(1) DEFAULT 0 COMMENT 'Enable grace period email notifications',
ADD COLUMN `elan_image_upload_max_size` decimal(4,2) DEFAULT 2.00 COMMENT 'Maximum upload file size in MB',
ADD COLUMN `elan_image_display_max_size` int(11) DEFAULT 2048 COMMENT 'Maximum display image width in pixels',
ADD COLUMN `elan_image_thumbnail_sizes` mediumtext COLLATE utf8mb4_unicode_ci COMMENT 'Comma-separated thumbnail sizes in pixels',
ADD COLUMN `elan_chartjs_cdn` mediumtext COLLATE utf8mb4_unicode_ci COMMENT 'Chart.js CDN URL for statistics charts',
ADD COLUMN `elan_admin_emails` mediumtext COLLATE utf8mb4_unicode_ci COMMENT 'Comma-separated admin email addresses for system notifications';

-- Modify existing settings fields for compatibility
ALTER TABLE `settings` 
MODIFY COLUMN `container_open_class` mediumtext COLLATE utf8mb4_unicode_ci DEFAULT NULL,
MODIFY COLUMN `redirect_uri_after_login` mediumtext COLLATE utf8mb4_unicode_ci DEFAULT NULL;


-- =============================================================================
-- 4. DATABASE TRIGGERS FOR AUDIT TRAILS
-- =============================================================================
-- (Triggers are included with their respective tables above)

-- Re-enable foreign key checks
SET FOREIGN_KEY_CHECKS = 1;

-- =============================================================================
-- SCHEMA UPDATE COMPLETE
-- =============================================================================
-- 
-- This script has successfully:
-- ✅ Created 8 custom tables for car registry functionality
-- ✅ Enhanced profiles table with 6 geographic location fields  
-- ✅ Enhanced settings table with 34 Elan Registry configuration fields
-- ✅ Set up comprehensive audit trail triggers for automatic change tracking
-- ✅ Added car_transfer_requests table for ownership transfers
-- ✅ Replaced simplified audit tracking with comprehensive field-level tracking
-- 
-- Next Steps:
-- 1. Import reference data using database/5.2-import_reference_data.sql
-- 2. Configure essential settings using database/5.3-essential_config.sql
-- 3. Configure Google API keys in settings table via admin interface
-- 4. Set up CDN URLs in settings table for performance optimization  
-- 5. Configure SPAM cleanup settings if automated maintenance is desired
-- 6. Test car registration and sharing functionality
-- 
-- =============================================================================
