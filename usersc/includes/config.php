<?php
declare(strict_types=1);

use ElanRegistry\AssetVersionResolver;

/**
 * Global Application Configuration
 *
 * Centralized configuration constants for the Elan Registry application.
 * This file defines paths, directories, retention policies, and other
 * application-wide settings.
 *
 * @version 2.15.1
 */

// ============================================================================
// Backup Configuration
// ============================================================================

/**
 * Base backup directory (relative to $us_url_root)
 * Backups are organized into subdirectories: automated/, manual/, rollback/
 */
define('BACKUP_BASE_DIR', 'backups/');

/**
 * Backup retention policies (in days)
 * Controls how long each type of backup is kept before automatic cleanup
 */
define('BACKUP_RETENTION_AUTOMATED', 7);    // Automated backups: 7 days
define('BACKUP_RETENTION_MANUAL', 30);      // Manual backups: 30 days
define('BACKUP_RETENTION_ROLLBACK', 30);    // Rollback backups: 30 days

/**
 * Backup cleanup warning threshold (in days)
 * Warns admins when backups are approaching expiration
 */
define('BACKUP_WARNING_THRESHOLD_DAYS', 7);

/**
 * Backup failure lookback window (in days)
 * How far back the health check looks for logged backup failures
 */
define('BACKUP_FAILURE_LOOKBACK_DAYS', 7);

/**
 * Backup table subdirectories
 * Organized by backup type for clarity and management
 */
define('BACKUP_DIR_AUTOMATED', BACKUP_BASE_DIR . 'automated/');
define('BACKUP_DIR_MANUAL', BACKUP_BASE_DIR . 'manual/');
define('BACKUP_DIR_ROLLBACK', BACKUP_BASE_DIR . 'rollback/');

// ============================================================================
// Asset Versioning
// ============================================================================

// Appended as ?v=<version> to all first-party .min.js/.min.css URLs for cache-busting.
// Reads the VERSION file written by the post-receive deploy hook.
// Resolution logic lives in AssetVersionResolver so it's unit-testable
// directly (see #1598) — config.php only builds the path and defines the constant.
$_versionFile = $abs_us_root . $us_url_root . 'VERSION';
define('ASSET_VERSION', AssetVersionResolver::resolve($_versionFile));
unset($_versionFile);

// ============================================================================
// Media & Image Configuration
// ============================================================================

/**
 * Directory (relative to $us_url_root) where car images are stored.
 */
define('ELAN_IMAGE_DIR', 'userimages/');

/**
 * Maximum photos allowed per car.
 */
define('ELAN_IMAGE_MAX', 6);

/**
 * Maximum upload file size, in MB.
 */
define('ELAN_IMAGE_UPLOAD_MAX_SIZE', 3.00);

/**
 * Maximum display image width, in pixels.
 */
define('ELAN_IMAGE_DISPLAY_MAX_SIZE', 2048);

/**
 * Comma-separated thumbnail sizes, in pixels.
 */
define('ELAN_IMAGE_THUMBNAIL_SIZES', '100,300,768,1024,2048');

// ============================================================================
// Cron Configuration
// ============================================================================

/**
 * How often (in minutes) the UserSpice cron transport fires on dev, test,
 * and prod. See docs/development/DEPLOYMENT.md, "Cron Transport (UserSpice
 * Cron Manager)" for the operational record of the underlying cPanel/launchd
 * schedule — this constant is the in-code mirror cron jobs can read to
 * self-gate cadence or detect a stalled transport (see #2001).
 */
define('CRON_TRANSPORT_INTERVAL_MINUTES', 10);

// ============================================================================
// Transfer & Email Configuration
// ============================================================================

/**
 * How many days a car ownership transfer request stays valid before expiring.
 */
define('TRANSFER_REQUEST_EXPIRY_DAYS', 30);

/**
 * Bracket prefix prepended to outbound email subjects (contact, feedback,
 * transfer notifications) so recipients can filter/identify registry mail.
 */
define('EMAIL_SUBJECT_PREFIX', '[ELANREGISTRY]');

