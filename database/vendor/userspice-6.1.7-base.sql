-- =============================================================================
-- UserSpice 6.1.4 — stock framework schema (structure only, no data)
-- =============================================================================
--
-- Source:   Official UserSpice 6.1.4 release, installed by its own installer
--           against an empty scratch database (`elanregi_spice614`). Nothing
--           from ElanRegistry was ever applied to that database — this is the
--           pristine framework floor that every ElanRegistry environment is
--           built on top of.
--
-- Captured: 2026-08-07
--
-- Version:  The file is named for 6.1.7 because UserSpice 6.1.5 to 6.1.7
--           change no table structure. The content is the 6.1.4 capture.
--
-- Contents: 58 CREATE TABLE statements. No data, no routines, no triggers,
--           no views — stock UserSpice 6.1.4 defines none of those.
--
-- Applied:  Directly via `mysql < database/vendor/userspice-6.1.7-base.sql`
--           by scripts/provision-schema.sh, BEFORE Phinx runs. It is
--           deliberately not a Phinx migration: it is vendored third-party
--           schema, not a change this project authored.
--
-- Post-processing applied to the raw mysqldump output:
--   * DEFINER= clauses stripped (same sed patterns create-test-schema.sh uses)
--     so loaded objects belong to whichever user runs the import, not to the
--     account that produced the dump. No-op today (stock defines no triggers
--     or views) but kept so a future recapture stays safe.
--   * AUTO_INCREMENT=N table options stripped. Those are counters left over
--     from the installer's own seed rows, not schema — every environment must
--     start its own sequences at 1.
--
-- DO NOT HAND-EDIT THIS FILE. When rebasing onto a newer UserSpice release,
-- install that release fresh against an empty scratch database and regenerate:
--
--   mysqldump --no-data --skip-comments --skip-add-drop-table \
--             --routines --triggers \
--             -h<host> -P<port> -u<user> -p<pass> <scratch_db>
--
-- then re-apply the two post-processing steps above.
-- =============================================================================


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `audit` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user` int NOT NULL,
  `page` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `ip` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `viewed` int NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `crons` (
  `id` int NOT NULL AUTO_INCREMENT,
  `active` int NOT NULL DEFAULT '1',
  `sort` int NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `file` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `createdby` int NOT NULL,
  `created` datetime DEFAULT NULL,
  `modified` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `crons_logs` (
  `id` int NOT NULL AUTO_INCREMENT,
  `cron_id` int NOT NULL,
  `datetime` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `user_id` int NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `email` (
  `id` int NOT NULL AUTO_INCREMENT,
  `website_name` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `smtp_server` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `smtp_port` int NOT NULL,
  `email_login` varchar(150) COLLATE utf8mb4_general_ci NOT NULL,
  `email_pass` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `from_name` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `from_email` varchar(150) COLLATE utf8mb4_general_ci NOT NULL,
  `transport` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `verify_url` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `email_act` int NOT NULL,
  `debug_level` int NOT NULL DEFAULT '0',
  `isSMTP` int NOT NULL DEFAULT '0',
  `isHTML` varchar(5) COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'true',
  `useSMTPauth` varchar(6) COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'true',
  `authtype` varchar(50) COLLATE utf8mb4_general_ci DEFAULT 'CRAM-MD5',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `groups_menus` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `group_id` int unsigned NOT NULL,
  `menu_id` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `group_id` (`group_id`),
  KEY `menu_id` (`menu_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `keys` (
  `id` int NOT NULL AUTO_INCREMENT,
  `stripe_ts` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `stripe_tp` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `stripe_ls` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `stripe_lp` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `recap_pub` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `recap_pri` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `logs` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL DEFAULT '0',
  `cloak_from` int DEFAULT NULL,
  `logdate` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `logtype` varchar(25) COLLATE utf8mb4_general_ci NOT NULL,
  `lognote` mediumtext COLLATE utf8mb4_general_ci NOT NULL,
  `ip` varchar(75) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `metadata` blob,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `menus` (
  `id` int NOT NULL AUTO_INCREMENT,
  `menu_title` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `parent` int NOT NULL,
  `dropdown` int NOT NULL,
  `logged_in` int NOT NULL,
  `display_order` int NOT NULL,
  `label` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `link` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `icon_class` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `message_threads` (
  `id` int NOT NULL AUTO_INCREMENT,
  `msg_to` int NOT NULL,
  `msg_from` int NOT NULL,
  `msg_subject` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `last_update` datetime NOT NULL,
  `last_update_by` int NOT NULL,
  `archive_from` int NOT NULL DEFAULT '0',
  `archive_to` int NOT NULL DEFAULT '0',
  `hidden_from` int NOT NULL DEFAULT '0',
  `hidden_to` int NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `messages` (
  `id` int NOT NULL AUTO_INCREMENT,
  `msg_from` int NOT NULL,
  `msg_to` int NOT NULL,
  `msg_body` mediumtext COLLATE utf8mb4_general_ci NOT NULL,
  `msg_read` int NOT NULL,
  `msg_thread` int NOT NULL,
  `deleted` int NOT NULL,
  `sent_on` datetime NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `pages` (
  `id` int NOT NULL AUTO_INCREMENT,
  `page` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `title` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `private` int NOT NULL DEFAULT '0',
  `re_auth` int NOT NULL DEFAULT '0',
  `core` int DEFAULT '0',
  `lang_key` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_page` (`page`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `permission_page_matches` (
  `id` int NOT NULL AUTO_INCREMENT,
  `permission_id` int DEFAULT NULL,
  `page_id` int DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `permissions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(150) COLLATE utf8mb4_general_ci NOT NULL,
  `descrip` varchar(255) COLLATE utf8mb4_general_ci DEFAULT '',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `plg_social_logins` (
  `id` int NOT NULL AUTO_INCREMENT,
  `plugin` varchar(50) COLLATE utf8mb4_general_ci NOT NULL,
  `provider` varchar(50) COLLATE utf8mb4_general_ci NOT NULL,
  `enabledsetting` varchar(50) COLLATE utf8mb4_general_ci NOT NULL,
  `image` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `link` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `built_in` tinyint(1) DEFAULT '0',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `plg_tags` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tag` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `descrip` varchar(255) COLLATE utf8mb4_general_ci DEFAULT '',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `plg_tags_matches` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tag_id` int unsigned NOT NULL,
  `tag_name` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `user_id` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_tag_user` (`tag_id`,`user_id`),
  KEY `ix_tagname_user` (`tag_name`,`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `profiles` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `bio` mediumtext COLLATE utf8mb4_general_ci NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `settings` (
  `id` int NOT NULL AUTO_INCREMENT,
  `recaptcha` int NOT NULL DEFAULT '0',
  `force_ssl` int NOT NULL,
  `css_sample` int NOT NULL,
  `site_name` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `language` varchar(15) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `site_offline` int NOT NULL,
  `force_pr` int NOT NULL,
  `glogin` int NOT NULL DEFAULT '0',
  `fblogin` int NOT NULL,
  `gid` text COLLATE utf8mb4_general_ci,
  `gsecret` text COLLATE utf8mb4_general_ci,
  `gredirect` text COLLATE utf8mb4_general_ci,
  `ghome` text COLLATE utf8mb4_general_ci,
  `fbid` text COLLATE utf8mb4_general_ci,
  `fbsecret` text COLLATE utf8mb4_general_ci,
  `fbcallback` text COLLATE utf8mb4_general_ci,
  `graph_ver` text COLLATE utf8mb4_general_ci,
  `finalredir` text COLLATE utf8mb4_general_ci,
  `req_cap` int NOT NULL,
  `req_num` int NOT NULL,
  `min_pw` int NOT NULL,
  `max_pw` int NOT NULL,
  `min_un` int NOT NULL,
  `max_un` int NOT NULL,
  `messaging` int NOT NULL,
  `snooping` int NOT NULL,
  `echouser` int NOT NULL,
  `wys` int NOT NULL,
  `change_un` int NOT NULL,
  `backup_dest` text COLLATE utf8mb4_general_ci,
  `backup_source` text COLLATE utf8mb4_general_ci,
  `backup_table` text COLLATE utf8mb4_general_ci,
  `msg_notification` int NOT NULL,
  `permission_restriction` int NOT NULL,
  `auto_assign_un` int NOT NULL,
  `page_permission_restriction` int NOT NULL,
  `msg_blocked_users` int NOT NULL,
  `msg_default_to` int NOT NULL,
  `notifications` int NOT NULL,
  `notif_daylimit` int NOT NULL,
  `recap_public` text COLLATE utf8mb4_general_ci,
  `recap_private` text COLLATE utf8mb4_general_ci,
  `page_default_private` int NOT NULL,
  `navigation_type` tinyint(1) NOT NULL,
  `copyright` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `custom_settings` int NOT NULL,
  `system_announcement` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `twofa` int DEFAULT '0',
  `force_notif` tinyint(1) DEFAULT NULL,
  `cron_ip` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `registration` tinyint(1) DEFAULT NULL,
  `join_vericode_expiry` int unsigned NOT NULL,
  `reset_vericode_expiry` int unsigned NOT NULL,
  `admin_verify` tinyint(1) NOT NULL,
  `admin_verify_timeout` int NOT NULL,
  `session_manager` tinyint(1) NOT NULL,
  `template` varchar(255) COLLATE utf8mb4_general_ci DEFAULT 'standard',
  `saas` tinyint(1) DEFAULT NULL,
  `redirect_uri_after_login` mediumtext COLLATE utf8mb4_general_ci,
  `show_tos` tinyint(1) DEFAULT '1',
  `default_language` varchar(11) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `allow_language` tinyint(1) DEFAULT NULL,
  `spice_api` varchar(75) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `announce` datetime DEFAULT NULL,
  `bleeding_edge` tinyint(1) DEFAULT '0',
  `err_time` int DEFAULT '15',
  `container_open_class` text COLLATE utf8mb4_general_ci,
  `debug` tinyint(1) DEFAULT '0',
  `widgets` text COLLATE utf8mb4_general_ci,
  `no_passwords` tinyint(1) DEFAULT '0',
  `email_login` tinyint(1) DEFAULT '0',
  `pwl_length` int DEFAULT '5',
  `passkeys` tinyint(1) DEFAULT '0',
  `totp` tinyint(1) DEFAULT '0',
  `oauth_server` tinyint(1) DEFAULT '0',
  `oauth` tinyint(1) DEFAULT '0',
  `behind_reverse_proxy` tinyint(1) DEFAULT '0',
  `max_users_dt` int NOT NULL DEFAULT '2000',
  `social_login_location` tinyint(1) DEFAULT '1',
  `reauth_timeout` int NOT NULL DEFAULT '15',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `updates` (
  `id` int NOT NULL AUTO_INCREMENT,
  `migration` varchar(15) COLLATE utf8mb4_general_ci NOT NULL,
  `applied_on` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `update_skipped` tinyint(1) DEFAULT NULL,
  `confirm_skipped` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `us_announcements` (
  `id` int NOT NULL AUTO_INCREMENT,
  `dismissed` int NOT NULL,
  `link` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `title` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `message` text COLLATE utf8mb4_general_ci,
  `ignore` varchar(50) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `class` varchar(50) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `dismissed_by` int DEFAULT '0',
  `update_announcement` tinyint(1) DEFAULT '0',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `us_email_logins` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `vericode` varchar(128) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `success` tinyint(1) DEFAULT '0',
  `login_ip` varchar(50) COLLATE utf8mb4_general_ci NOT NULL,
  `login_date` datetime NOT NULL,
  `expired` tinyint(1) DEFAULT '0',
  `expires` datetime DEFAULT NULL,
  `verification_code` varchar(128) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `invalid_attempts` int DEFAULT '0',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `us_fingerprint_assets` (
  `kFingerprintAssetID` int unsigned NOT NULL AUTO_INCREMENT,
  `fkFingerprintID` int NOT NULL,
  `IP_Address` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `User_Browser` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `User_OS` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  PRIMARY KEY (`kFingerprintAssetID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `us_fingerprints` (
  `kFingerprintID` int unsigned NOT NULL AUTO_INCREMENT,
  `fkUserID` int NOT NULL,
  `Fingerprint` varchar(32) COLLATE utf8mb4_general_ci NOT NULL,
  `Fingerprint_Expiry` datetime NOT NULL,
  `Fingerprint_Added` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`kFingerprintID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `us_form_validation` (
  `id` int NOT NULL AUTO_INCREMENT,
  `value` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `description` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `params` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `us_form_views` (
  `id` int NOT NULL AUTO_INCREMENT,
  `form_name` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `view_name` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `fields` mediumtext COLLATE utf8mb4_general_ci NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `us_forms` (
  `id` int NOT NULL AUTO_INCREMENT,
  `form` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `us_ip_blacklist` (
  `id` int NOT NULL AUTO_INCREMENT,
  `ip` varchar(50) COLLATE utf8mb4_general_ci NOT NULL,
  `last_user` int NOT NULL DEFAULT '0',
  `reason` int NOT NULL DEFAULT '0',
  `expires` datetime DEFAULT NULL,
  `descrip` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `added_by` int DEFAULT NULL,
  `added_on` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_ip` (`ip`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `us_ip_list` (
  `id` int NOT NULL AUTO_INCREMENT,
  `ip` varchar(50) COLLATE utf8mb4_general_ci NOT NULL,
  `user_id` int NOT NULL,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `us_ip_whitelist` (
  `id` int NOT NULL AUTO_INCREMENT,
  `ip` varchar(50) COLLATE utf8mb4_general_ci NOT NULL,
  `descrip` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `added_by` int DEFAULT NULL,
  `added_on` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `us_login_fails` (
  `id` int NOT NULL AUTO_INCREMENT,
  `login_method` varchar(50) COLLATE utf8mb4_general_ci NOT NULL,
  `ip` varchar(50) COLLATE utf8mb4_general_ci NOT NULL,
  `ts` datetime NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `us_management` (
  `id` int NOT NULL AUTO_INCREMENT,
  `page` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `view` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `feature` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `access` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `us_menu_items` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `menu` int unsigned NOT NULL,
  `type` varchar(50) DEFAULT NULL,
  `label` varchar(255) DEFAULT NULL,
  `link` text,
  `icon_class` varchar(255) DEFAULT NULL,
  `li_class` varchar(255) DEFAULT NULL,
  `a_class` varchar(255) DEFAULT NULL,
  `link_target` varchar(50) DEFAULT NULL,
  `parent` int DEFAULT NULL,
  `display_order` int DEFAULT NULL,
  `disabled` tinyint(1) DEFAULT '0',
  `permissions` varchar(1000) DEFAULT NULL,
  `tags` varchar(1000) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `us_menus` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `menu_name` varchar(255) DEFAULT NULL,
  `type` varchar(75) DEFAULT NULL,
  `nav_class` varchar(255) DEFAULT NULL,
  `theme` varchar(25) DEFAULT NULL,
  `z_index` int DEFAULT NULL,
  `brand_html` text,
  `disabled` tinyint(1) DEFAULT '0',
  `justify` varchar(10) DEFAULT 'right',
  `sticky` tinyint(1) NOT NULL DEFAULT '0',
  `show_active` tinyint(1) DEFAULT '0',
  `screen_reader_mode` tinyint(1) DEFAULT '0',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `us_oauth_client_login_options` (
  `id` int NOT NULL AUTO_INCREMENT,
  `oauth` tinyint(1) DEFAULT '0',
  `client_name` varchar(255) DEFAULT 'UserSpice Login',
  `client_icon` varchar(255) DEFAULT 'oauth.png',
  `client_id` varchar(80) DEFAULT NULL,
  `client_secret` varchar(80) DEFAULT NULL,
  `redirect_uri` varchar(200) DEFAULT NULL,
  `server_url` varchar(255) DEFAULT NULL,
  `server_target` varchar(255) DEFAULT 'users/auth/',
  `login_title` varchar(255) DEFAULT 'UserSpice',
  `login_script` varchar(255) DEFAULT 'default_script.php',
  `response_secret` varchar(64) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `client_id` (`client_id`),
  UNIQUE KEY `client_secret` (`client_secret`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `us_oauth_client_login_tokens` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int DEFAULT NULL,
  `access_token` varchar(255) NOT NULL,
  `refresh_token` varchar(255) DEFAULT NULL,
  `expires_at` datetime NOT NULL,
  `scope` varchar(255) DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `us_oauth_client_logins` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int DEFAULT NULL,
  `new_user` tinyint(1) DEFAULT '0',
  `ts` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `us_oauth_server_clients` (
  `id` int NOT NULL AUTO_INCREMENT,
  `client_name` varchar(80) NOT NULL,
  `client_description` varchar(200) DEFAULT NULL,
  `client_enabled` tinyint(1) DEFAULT '1',
  `client_id` varchar(80) NOT NULL,
  `client_secret` varchar(80) NOT NULL,
  `redirect_uri` varchar(200) NOT NULL,
  `ip_restrict` varchar(200) DEFAULT NULL,
  `login_title` varchar(255) DEFAULT 'Login with UserSpice',
  `login_form` varchar(255) DEFAULT 'default_login.php',
  `login_script` varchar(255) DEFAULT 'default_script.php',
  `response_secret` varchar(64) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `client_id` (`client_id`),
  UNIQUE KEY `client_secret` (`client_secret`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `us_oauth_server_codes` (
  `id` int NOT NULL AUTO_INCREMENT,
  `client_id` int DEFAULT NULL,
  `user_id` int DEFAULT NULL,
  `auth_code` varchar(80) NOT NULL,
  `expires_at` datetime NOT NULL,
  `used` tinyint(1) DEFAULT '0',
  `redirect_uri` tinyint(1) DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `auth_code` (`auth_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `us_oauth_server_settings` (
  `id` int NOT NULL AUTO_INCREMENT,
  `other_columns` text,
  `include_tags` tinyint(1) DEFAULT '1',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `us_oauth_server_tokens` (
  `id` int NOT NULL AUTO_INCREMENT,
  `client_id` int DEFAULT NULL,
  `user_id` int DEFAULT NULL,
  `access_token` varchar(80) NOT NULL,
  `refresh_token` varchar(80) DEFAULT NULL,
  `expires_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `access_token` (`access_token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `us_passkeys` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int DEFAULT '0',
  `credential_id` varbinary(255) DEFAULT NULL,
  `credential_public_key` blob,
  `created` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `times_used` int DEFAULT '0',
  `last_used` timestamp NULL DEFAULT NULL,
  `last_ip` varchar(255) DEFAULT NULL,
  `passkey_note` varchar(255) DEFAULT NULL,
  `user_handle` varbinary(64) DEFAULT NULL,
  `transports` text,
  `attestation_type` varchar(32) DEFAULT NULL,
  `trust_path` text,
  `aaguid` varchar(36) DEFAULT NULL,
  `signature_counter` bigint unsigned DEFAULT '0',
  `other_ui_data` text,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uidx_credential_id` (`credential_id`),
  KEY `idx_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `us_password_strength` (
  `id` int NOT NULL AUTO_INCREMENT,
  `enforce_rules` tinyint(1) DEFAULT '1',
  `meter_active` tinyint(1) DEFAULT '0',
  `min_length` int DEFAULT '8',
  `max_length` int DEFAULT '24',
  `require_lowercase` tinyint(1) DEFAULT '1',
  `require_uppercase` tinyint(1) DEFAULT '1',
  `require_numbers` tinyint(1) DEFAULT '1',
  `require_symbols` tinyint(1) DEFAULT '1',
  `min_score` int DEFAULT '5',
  `uppercase_score` int NOT NULL DEFAULT '6',
  `lowercase_score` int NOT NULL DEFAULT '6',
  `number_score` int NOT NULL DEFAULT '6',
  `symbol_score` int NOT NULL DEFAULT '11',
  `greater_eight` int NOT NULL DEFAULT '15',
  `greater_twelve` int NOT NULL DEFAULT '28',
  `greater_sixteen` int NOT NULL DEFAULT '40',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `us_php_eol` (
  `id` int NOT NULL AUTO_INCREMENT,
  `release_version` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `eol_date` date NOT NULL,
  `last_checked` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_release_version` (`release_version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `us_php_known_bad` (
  `id` int NOT NULL AUTO_INCREMENT,
  `version` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `last_checked` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_version` (`version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `us_plugin_hooks` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `page` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `folder` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `position` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `hook` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `disabled` tinyint(1) DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `ix_page_disabled` (`page`,`disabled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `us_plugins` (
  `id` int NOT NULL AUTO_INCREMENT,
  `plugin` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `status` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `updates` mediumtext COLLATE utf8mb4_general_ci,
  `last_check` datetime DEFAULT '2020-01-01 00:00:00',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `us_rate_limit_proxy_settings` (
  `id` int NOT NULL AUTO_INCREMENT,
  `proxy_ip` varchar(45) COLLATE utf8mb4_unicode_ci NOT NULL,
  `header_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `header` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `priority` int DEFAULT '0',
  `enabled` tinyint(1) DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_proxy_ip` (`proxy_ip`),
  KEY `idx_header_name` (`header_name`),
  KEY `idx_enabled_priority` (`enabled`,`priority`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `us_rate_limits` (
  `id` int NOT NULL AUTO_INCREMENT,
  `identifier_key` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `action` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `success` tinyint(1) DEFAULT '0',
  `attempt_time` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `metadata` json DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_identifier_action` (`identifier_key`,`action`),
  KEY `idx_attempt_time` (`attempt_time`),
  KEY `idx_cleanup` (`attempt_time`,`success`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `us_reauth_log` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `purpose` varchar(64) DEFAULT NULL,
  `method` varchar(32) DEFAULT NULL,
  `success` tinyint(1) NOT NULL DEFAULT '0',
  `ip` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `created` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `created` (`created`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `us_saas_levels` (
  `id` int NOT NULL AUTO_INCREMENT,
  `level` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `users` int NOT NULL,
  `details` mediumtext COLLATE utf8mb4_general_ci NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `us_saas_orgs` (
  `id` int NOT NULL AUTO_INCREMENT,
  `org` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `owner` int NOT NULL,
  `level` int NOT NULL,
  `active` int NOT NULL DEFAULT '1',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `us_totp_secrets` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `secret_enc` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `backup_codes_h` text COLLATE utf8mb4_unicode_ci,
  `verified` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `us_user_sessions` (
  `kUserSessionID` int unsigned NOT NULL AUTO_INCREMENT,
  `fkUserID` int unsigned NOT NULL,
  `UserFingerprint` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `UserSessionIP` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `UserSessionOS` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `UserSessionBrowser` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `UserSessionStarted` datetime NOT NULL,
  `UserSessionLastUsed` datetime DEFAULT NULL,
  `UserSessionLastPage` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `UserSessionEnded` tinyint(1) NOT NULL DEFAULT '0',
  `UserSessionEnded_Time` datetime DEFAULT NULL,
  PRIMARY KEY (`kUserSessionID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `us_versions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `release_version` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `bleeding_edge` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `experimental` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `user_permission_matches` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `permission_id` int NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users` (
  `id` int NOT NULL AUTO_INCREMENT,
  `permissions` tinyint(1) NOT NULL,
  `email` varchar(155) COLLATE utf8mb4_general_ci NOT NULL,
  `email_new` varchar(155) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `username` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `password` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `pin` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `fname` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `lname` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `language` varchar(15) COLLATE utf8mb4_general_ci DEFAULT 'en-US',
  `email_verified` tinyint(1) NOT NULL DEFAULT '0',
  `vericode` text COLLATE utf8mb4_general_ci,
  `vericode_expiry` datetime DEFAULT NULL,
  `oauth_provider` text COLLATE utf8mb4_general_ci,
  `oauth_uid` text COLLATE utf8mb4_general_ci,
  `gender` varchar(10) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `locale` varchar(10) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `gpluslink` text COLLATE utf8mb4_general_ci,
  `account_owner` tinyint NOT NULL DEFAULT '1',
  `account_id` int NOT NULL DEFAULT '0',
  `account_mgr` int NOT NULL DEFAULT '0',
  `fb_uid` text COLLATE utf8mb4_general_ci,
  `picture` text COLLATE utf8mb4_general_ci,
  `created` datetime NOT NULL,
  `protected` tinyint(1) NOT NULL DEFAULT '0',
  `msg_exempt` tinyint(1) NOT NULL DEFAULT '0',
  `dev_user` tinyint(1) NOT NULL DEFAULT '0',
  `msg_notification` tinyint(1) NOT NULL DEFAULT '1',
  `cloak_allowed` tinyint(1) NOT NULL DEFAULT '0',
  `oauth_tos_accepted` tinyint(1) DEFAULT NULL,
  `un_changed` tinyint(1) NOT NULL DEFAULT '0',
  `force_pr` tinyint(1) NOT NULL DEFAULT '0',
  `logins` int unsigned NOT NULL DEFAULT '0',
  `last_login` datetime DEFAULT NULL,
  `join_date` datetime DEFAULT NULL,
  `modified` datetime DEFAULT NULL,
  `active` tinyint(1) DEFAULT '1',
  PRIMARY KEY (`id`),
  KEY `EMAIL` (`email`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users_online` (
  `id` int NOT NULL,
  `ip` varchar(15) COLLATE utf8mb4_general_ci NOT NULL,
  `timestamp` varchar(15) COLLATE utf8mb4_general_ci NOT NULL,
  `user_id` int DEFAULT NULL,
  `session` varchar(50) COLLATE utf8mb4_general_ci NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users_session` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `hash` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `uagent` mediumtext COLLATE utf8mb4_general_ci,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

