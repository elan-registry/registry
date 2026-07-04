<?php

declare(strict_types=1);

/**
 * Centralized Log Category Constants
 *
 * This class provides standardized log category constants for use with the UserSpice
 * logger() function throughout the application. All logger() calls MUST use these
 * constants instead of hardcoded strings to ensure consistency and maintainability.
 *
 * Usage:
 *   logger($userId, LogCategories::LOG_CATEGORY_CAR_ACTIONS, 'Car created successfully');
 *
 * Organization:
 *   Categories are organized by functional domain for easy discovery and maintenance.
 *   All constant names use UPPER_SNAKE_CASE.
 *   All constant values use PascalCase (e.g., 'CarActions', 'AdminVerification').
 *
 * @package    ElanRegistry
 * @subpackage Classes
 * @version    1.0.0
 * @since      v2.12.0
 */
class LogCategories
{
    // ========== CAR MANAGEMENT CATEGORIES ==========

    /**
     * Car-related actions (create, update, ownership changes)
     * Used for general car operation tracking and user actions
     */
    public const LOG_CATEGORY_CAR_ACTIONS = 'CarActions';

    /**
     * Car creation operations
     * Used when a new car is added to the registry
     */
    public const LOG_CATEGORY_CAR_CREATION = 'CarCreation';

    /**
     * Car update/modification operations
     * Used when existing car data is modified
     */
    public const LOG_CATEGORY_CAR_UPDATE = 'CarUpdate';

    /**
     * Car deletion operations
     * Used when cars are removed from the registry
     */
    public const LOG_CATEGORY_CAR_DELETION = 'CarDeletion';

    /**
     * Car merge operations
     * Used when duplicate or related cars are merged
     */
    public const LOG_CATEGORY_CAR_MERGE = 'CarMerge';

    /**
     * Car ownership transfer operations
     * Used for successful ownership transfers
     */
    public const LOG_CATEGORY_CAR_TRANSFER = 'CarTransfer';

    /**
     * Car transfer errors
     * Used when car ownership transfer fails
     */
    public const LOG_CATEGORY_CAR_TRANSFER_ERROR = 'CarTransferError';

    /**
     * Car verification operations
     * Used when cars are verified or validation checks occur
     */
    public const LOG_CATEGORY_CAR_VERIFICATION = 'CarVerification';

    /**
     * Car sold/deactivated operations
     * Used when cars are marked as sold or removed from active inventory
     */
    public const LOG_CATEGORY_CAR_SOLD = 'CarSold';

    /**
     * Car operation errors
     * Used for general car operation failures
     */
    public const LOG_CATEGORY_CAR_ERRORS = 'CarErrors';

    // ========== OWNER/USER MANAGEMENT CATEGORIES ==========

    /**
     * Owner profile actions
     * Used for owner profile updates and management operations
     */
    public const LOG_CATEGORY_OWNER_ACTIONS = 'OwnerActions';

    /**
     * User deletion operations
     * Used when user accounts are deleted from the system
     */
    public const LOG_CATEGORY_USER_DELETION = 'UserDeletion';

    /**
     * User-related operations
     * Used for general user management activities
     */
    public const LOG_CATEGORY_USER = 'User';

    /**
     * User creation operations
     * Used when new user accounts are created
     */
    public const LOG_CATEGORY_USER_CREATION = 'UserCreation';

    /**
     * Inactive user cleanup operations
     * Used for identifying and processing inactive accounts
     */
    public const LOG_CATEGORY_INACTIVE_CLEANUP = 'InactiveCleanup';

    /**
     * Inactive user deletion operations
     * Used when inactive accounts are deleted
     */
    public const LOG_CATEGORY_INACTIVE_DELETION = 'InactiveDeletion';

    /**
     * Inactive user notification
     * Used when notifying users about account inactivity
     */
    public const LOG_CATEGORY_INACTIVE_USER_NOTIFICATION = 'InactiveUserNotification';

    /**
     * Inactive user notification errors
     * Used when notification sending fails
     */
    public const LOG_CATEGORY_INACTIVE_USER_NOTIFICATION_ERROR = 'InactiveUserNotificationError';

    // ========== EMAIL & COMMUNICATIONS CATEGORIES ==========

    /**
     * Successful email operations
     * Used when emails are sent successfully
     */
    public const LOG_CATEGORY_EMAIL_SUCCESS = 'EmailSuccess';

    /**
     * Email operation errors
     * Used when email sending or processing fails
     */
    public const LOG_CATEGORY_EMAIL_ERROR = 'EmailError';

    /**
     * Email settings modifications
     * Used when email configuration is changed
     */
    public const LOG_CATEGORY_EMAIL_SETTINGS = 'EmailSettings';

    /**
     * Feedback form submissions
     * Used to track form submissions and user feedback
     */
    public const LOG_CATEGORY_FEEDBACK_FORM = 'FeedbackForm';

    /**
     * Sendinblue email service debug logging
     * Used for third-party email service debugging
     */
    public const LOG_CATEGORY_SENDINBLUE = 'SendinblueDebug';

    // ========== AUTHENTICATION CATEGORIES ==========

    /**
     * Login operations
     * Used for successful user logins
     */
    public const LOG_CATEGORY_LOGIN = 'Login';

    /**
     * Failed login attempts
     * Used when login authentication fails
     */
    public const LOG_CATEGORY_LOGIN_FAIL = 'LoginFail';

    /**
     * Login method selection/tracking
     * Used when tracking which authentication method is used
     */
    public const LOG_CATEGORY_LOGIN_METHOD = 'LoginMethod';

    // ========== PASSKEY AUTHENTICATION CATEGORIES ==========

    /**
     * Passkey handler operations
     * Used for core passkey functionality
     */
    public const LOG_CATEGORY_PASSKEY_HANDLER = 'PasskeyHandler';

    /**
     * Passkey authentication attempt
     * Used when a passkey login attempt is initiated
     */
    public const LOG_CATEGORY_PASSKEY_AUTH_ATTEMPT = 'PasskeyAuthAttempt';

    /**
     * Passkey authentication challenge operations
     * Used for challenge generation and validation
     */
    public const LOG_CATEGORY_PASSKEY_AUTH_CHALLENGE = 'PasskeyAuthChallenge';

    /**
     * Passkey authentication challenge generation
     * Used when creating new authentication challenges
     */
    public const LOG_CATEGORY_PASSKEY_AUTH_CHALLENGE_GENERATED = 'PasskeyAuthChallengeGenerated';

    /**
     * Passkey authentication challenge information
     * Used for challenge metadata and details
     */
    public const LOG_CATEGORY_PASSKEY_AUTH_CHALLENGE_INFO = 'PasskeyAuthChallengeInfo';

    /**
     * Passkey authentication debug logging
     * Used for detailed debugging of passkey operations
     */
    public const LOG_CATEGORY_PASSKEY_AUTH_DEBUG = 'PasskeyAuthDebug';

    /**
     * Passkey authentication failures
     * Used when passkey authentication fails
     */
    public const LOG_CATEGORY_PASSKEY_AUTH_FAIL = 'PasskeyAuthFail';

    /**
     * Passkey found during lookup
     * Used when a passkey is successfully located
     */
    public const LOG_CATEGORY_PASSKEY_AUTH_FOUND = 'PasskeyAuthFound';

    /**
     * Passkey authentication lookup operations
     * Used when searching for passkeys
     */
    public const LOG_CATEGORY_PASSKEY_AUTH_LOOKUP = 'PasskeyAuthLookup';

    /**
     * Successful passkey authentication
     * Used when passkey login succeeds
     */
    public const LOG_CATEGORY_PASSKEY_AUTH_SUCCESS = 'PasskeyAuthSuccess';

    /**
     * Passkey authentication challenge validation
     * Used when validating challenges during auth
     */
    public const LOG_CATEGORY_PASSKEY_AUTH_VALIDATE_CHALLENGE = 'PasskeyAuthValidateChallenge';

    /**
     * Passkey authentication validated
     * Used when authentication is confirmed valid
     */
    public const LOG_CATEGORY_PASSKEY_AUTH_VALIDATED = 'PasskeyAuthValidated';

    /**
     * Passkey authentication warnings
     * Used for non-critical issues during passkey auth
     */
    public const LOG_CATEGORY_PASSKEY_AUTH_WARNING = 'PasskeyAuthWarning';

    /**
     * Passkey configuration warnings
     * Used for configuration-related warnings
     */
    public const LOG_CATEGORY_PASSKEY_CONFIG_WARNING = 'PasskeyConfigWarning';

    /**
     * Passkey iOS-specific debug logging
     * Used for iOS device troubleshooting
     */
    public const LOG_CATEGORY_PASSKEY_DEBUG_IOS = 'PasskeyDebugIOS';

    /**
     * Passkey deletion operations
     * Used when passkeys are removed
     */
    public const LOG_CATEGORY_PASSKEY_DELETED = 'PasskeyDeleted';

    /**
     * Passkey operation errors
     * Used for general passkey operation failures
     */
    public const LOG_CATEGORY_PASSKEY_ERROR = 'PasskeyError';

    /**
     * Passkey initialization
     * Used when passkey system is initialized
     */
    public const LOG_CATEGORY_PASSKEY_INIT = 'PasskeyInit';

    /**
     * Passkey JavaScript operations
     * Used for client-side passkey operations
     */
    public const LOG_CATEGORY_PASSKEY_JS = 'PasskeyJS';

    /**
     * Passkey login operations
     * Used for passkey-based login flows
     */
    public const LOG_CATEGORY_PASSKEY_LOGIN = 'PasskeyLogin';

    /**
     * Passkey login failures
     * Used when passkey login fails
     */
    public const LOG_CATEGORY_PASSKEY_LOGIN_FAIL = 'PasskeyLoginFail';

    /**
     * Passkey data parsing
     * Used when parsing passkey response data
     */
    public const LOG_CATEGORY_PASSKEY_PARSER = 'PasskeyParser';

    // ========== PASSWORD & TOTP MANAGEMENT CATEGORIES ==========

    /**
     * Password reset operations
     * Used for password reset workflows
     */
    public const LOG_CATEGORY_PASSWORD_RESET = 'PasswordReset';

    /**
     * Passwordless authentication debug logging
     * Used for passwordless auth troubleshooting
     */
    public const LOG_CATEGORY_PASSWORDLESS_DEBUG = 'PasswordlessDebug';

    /**
     * Passwordless authentication user agent debug logging
     * Used for device/browser related debugging
     */
    public const LOG_CATEGORY_PASSWORDLESS_DEBUG_UA = 'PasswordlessDebugUA';

    /**
     * TOTP enforcement operations
     * Used when enforcing two-factor authentication
     */
    public const LOG_CATEGORY_TOTP_ENFORCEMENT = 'TOTPEnforcement';

    /**
     * TOTP operation errors
     * Used when TOTP operations fail
     */
    public const LOG_CATEGORY_TOTP_ERROR = 'TOTPError';

    /**
     * TOTP security operations
     * Used for security-related TOTP events
     */
    public const LOG_CATEGORY_TOTP_SECURITY = 'TOTPSecurity';

    /**
     * TOTP setup operations
     * Used when users set up two-factor authentication
     */
    public const LOG_CATEGORY_TOTP_SETUP = 'TOTPSetup';

    /**
     * TOTP verification operations
     * Used when verifying TOTP codes
     */
    public const LOG_CATEGORY_TOTP_VERIFICATION = 'TOTPVerification';

    /**
     * TOTP warnings
     * Used for non-critical TOTP issues
     */
    public const LOG_CATEGORY_TOTP_WARNING = 'TOTPWarning';

    /**
     * TOTP verification (alternate category)
     * Used for additional TOTP verification tracking
     */
    public const LOG_CATEGORY_VERIFY_TOTP = 'VerifyTOTP';

    // ========== DATABASE OPERATION CATEGORIES ==========

    /**
     * Database operation errors
     * Used when database queries or operations fail
     */
    public const LOG_CATEGORY_DATABASE_ERROR = 'DatabaseError';

    /**
     * Database maintenance operations
     * Used for schema changes, migrations, and maintenance tasks
     */
    public const LOG_CATEGORY_DATABASE_MAINTENANCE = 'DatabaseMaintenance';

    /**
     * Database migration operations
     * Used when running database migrations
     */
    public const LOG_CATEGORY_DATABASE_MIGRATION = 'DatabaseMigration';

    /**
     * Database optimization operations
     * Used when optimizing database performance
     */
    public const LOG_CATEGORY_DATABASE_OPTIMIZATION = 'DatabaseOptimization';

    /**
     * Table conversion operations
     * Used when converting table structure or storage engines
     */
    public const LOG_CATEGORY_TABLE_CONVERSION = 'TableConversion';

    /**
     * BackupManager operations
     * Used for database backup creation and management
     */
    public const LOG_CATEGORY_BACKUP_MANAGER = 'BackupManager';

    /**
     * Backup operation errors
     * Used when backup operations fail
     */
    public const LOG_CATEGORY_BACKUP_ERROR = 'BackupError';

    // ========== SYSTEM & FILE OPERATION CATEGORIES ==========

    /**
     * System error logging
     * Used for general system failures and exceptions
     */
    public const LOG_CATEGORY_SYSTEM_ERROR = 'SystemError';

    /**
     * File operation errors
     * Used when file upload, processing, or deletion fails
     */
    public const LOG_CATEGORY_FILE_ERROR = 'FileError';

    /**
     * Input validation errors
     * Used when user input fails validation
     */
    public const LOG_CATEGORY_VALIDATION_ERROR = 'ValidationError';

    /**
     * Image removal operations
     * Used when images are deleted
     */
    public const LOG_CATEGORY_IMAGE_REMOVAL = 'ImageRemoval';

    /**
     * Thumbnail optimization operations
     * Used when optimizing image thumbnails
     */
    public const LOG_CATEGORY_THUMBNAIL_OPTIMIZATION = 'ThumbnailOptimization';

    /**
     * FIX script operations
     * Used for administrative maintenance scripts
     */
    public const LOG_CATEGORY_FIX_SCRIPT = 'FIXScript';

    /**
     * FIX script debug logging
     * Used for FIX script troubleshooting
     */
    public const LOG_CATEGORY_FIX_SCRIPT_DEBUG = 'FIXScriptDebug';

    /**
     * FIX script errors
     * Used when FIX script operations fail
     */
    public const LOG_CATEGORY_FIX_SCRIPT_ERROR = 'FIXScriptError';

    // ========== ADMIN & MANAGEMENT CATEGORIES ==========

    /**
     * Admin verification operations
     * Used for administrative access verification
     */
    public const LOG_CATEGORY_ADMIN_VERIFICATION = 'AdminVerification';

    /**
     * Admin announcements
     * Used for system-wide announcements and notifications
     */
    public const LOG_CATEGORY_ADMIN_ANNOUNCEMENTS = 'AdminAnnouncements';

    /**
     * Admin template operations
     * Used for managing email and document templates
     */
    public const LOG_CATEGORY_ADMIN_TEMPLATES = 'AdminTemplates';

    /**
     * Pages manager operations
     * Used for dynamic page management
     */
    public const LOG_CATEGORY_PAGES_MANAGER = 'PagesManager';

    /**
     * Menu manager operations
     * Used for navigation menu management
     */
    public const LOG_CATEGORY_MENU_MANAGER = 'MenuManager';

    /**
     * Permissions manager operations
     * Used for role and permission management
     */
    public const LOG_CATEGORY_PERMISSIONS_MANAGER = 'PermissionsManager';

    /**
     * Settings update operations
     * Used when system settings are modified
     */
    public const LOG_CATEGORY_SETTINGS_UPDATE = 'SettingsUpdate';

    /**
     * System maintenance operations
     * Used for general system maintenance tasks
     */
    public const LOG_CATEGORY_SYSTEM_MAINTENANCE = 'SystemMaintenance';

    /**
     * System update operations
     * Used when system updates are applied
     */
    public const LOG_CATEGORY_SYSTEM_UPDATES = 'SystemUpdates';

    /**
     * Logging system operations
     * Used for managing logs and logging configuration
     */
    public const LOG_CATEGORY_LOGS = 'Logs';

    /**
     * Cron request operations
     * Used for scheduled task execution
     */
    public const LOG_CATEGORY_CRON_REQUEST = 'CronRequest';

    /**
     * Migration operations
     * Used for system migrations and upgrades
     */
    public const LOG_CATEGORY_MIGRATIONS = 'Migrations';

    /**
     * User manager operations
     * Used for administrative user management
     */
    public const LOG_CATEGORY_USER_MANAGER = 'UserManager';

    /**
     * Spam cleanup operations
     * Used when removing spam accounts or content
     */
    public const LOG_CATEGORY_SPAM_CLEANUP = 'SpamCleanup';

    /**
     * Spam cleanup errors
     * Used when spam cleanup fails
     */
    public const LOG_CATEGORY_SPAM_CLEANUP_ERROR = 'SpamCleanupError';

    // ========== OAUTH & EXTERNAL AUTH CATEGORIES ==========

    /**
     * OAuth client operations
     * Used for OAuth application management
     */
    public const LOG_CATEGORY_OAUTH_CLIENT = 'OAuthClient';

    /**
     * OAuth client operation errors
     * Used when OAuth client operations fail
     */
    public const LOG_CATEGORY_OAUTH_CLIENT_ERROR = 'OAuthClientError';

    /**
     * OAuth client login operations
     * Used for OAuth-based user logins
     */
    public const LOG_CATEGORY_OAUTH_CLIENT_LOGIN = 'OAuthClientLogin';

    /**
     * OAuth client registration
     * Used when registering OAuth applications
     */
    public const LOG_CATEGORY_OAUTH_CLIENT_REGISTRATION = 'OAuthClientRegistration';

    /**
     * OAuth client tags management
     * Used for OAuth client categorization
     */
    public const LOG_CATEGORY_OAUTH_CLIENT_TAGS = 'OAuthClientTags';

    /**
     * OAuth client update operations
     * Used when modifying OAuth client configuration
     */
    public const LOG_CATEGORY_OAUTH_CLIENT_UPDATE = 'OAuthClientUpdate';

    /**
     * OAuth client warnings
     * Used for non-critical OAuth client issues
     */
    public const LOG_CATEGORY_OAUTH_CLIENT_WARNING = 'OAuthClientWarning';

    /**
     * OAuth custom data handling
     * Used for custom OAuth data processing
     */
    public const LOG_CATEGORY_OAUTH_CUSTOM_DATA = 'OAuthCustomData';

    /**
     * OAuth login script operations
     * Used for OAuth login flow scripting
     */
    public const LOG_CATEGORY_OAUTH_LOGIN_SCRIPT = 'OAuthLoginScript';

    /**
     * OAuth server operations
     * Used for OAuth server-side operations
     */
    public const LOG_CATEGORY_OAUTH_SERVER = 'OAuthServer';

    /**
     * OAuth server operation errors
     * Used when OAuth server operations fail
     */
    public const LOG_CATEGORY_OAUTH_SERVER_ERROR = 'OAuthServerError';

    /**
     * OAuth server security operations
     * Used for OAuth security-related events
     */
    public const LOG_CATEGORY_OAUTH_SERVER_SECURITY = 'OAuthServerSecurity';

    // ========== LOCATION & GEOCODING CATEGORIES ==========

    /**
     * Geocoding operations
     * Used when converting addresses to coordinates
     */
    public const LOG_CATEGORY_GEOCODE = 'Geocode';

    /**
     * Location service operations
     * Used for location-based functionality
     */
    public const LOG_CATEGORY_LOCATION_SERVICE = 'LocationService';

    /**
     * Location reverse geocoding operations
     * Used when converting coordinates to addresses
     */
    public const LOG_CATEGORY_LOCATION_REVERSE = 'LocationReverse';

    // ========== ACCESS CONTROL & DIAGNOSTICS CATEGORIES ==========

    /**
     * Security operations
     * Used for general security-related events and checks
     */
    public const LOG_CATEGORY_SECURITY = 'Security';

    /**
     * Access denied operations
     * Used when access is denied to protected resources
     */
    public const LOG_CATEGORY_ACCESS_DENIED = 'AccessDenied';

    /**
     * Access check operations
     * Used when verifying user access permissions
     */
    public const LOG_CATEGORY_CHECK_ACCESS = 'CheckAccess';

    /**
     * Permission check operations
     * Used when checking specific permissions
     */
    public const LOG_CATEGORY_HAS_PERM = 'HasPerm';

    /**
     * Secure page operations
     * Used for protected page access verification
     */
    public const LOG_CATEGORY_SECURE_PAGE = 'SecurePage';

    /**
     * IP address logging
     * Used for IP tracking and analysis
     */
    public const LOG_CATEGORY_IP_LOGGING = 'IPLogging';

    /**
     * IP blacklist clearing
     * Used when clearing blacklisted IP addresses
     */
    public const LOG_CATEGORY_BLACKLIST_CLEAR = 'BlacklistClear';

    /**
     * Page not found operations
     * Used for 404 error tracking
     */
    public const LOG_CATEGORY_PAGE_NOT_FOUND = 'PageNotFound';

    /**
     * Diagnostic operations
     * Used for system diagnostics and health checks
     */
    public const LOG_CATEGORY_DIAGNOSTICS = 'Diagnostics';

    // ========== APPLICATION-SPECIFIC CATEGORIES ==========

    /**
     * Elan Registry specific operations
     * Used for features specific to the car registry
     */
    public const LOG_CATEGORY_ELAN_REGISTRY = 'ElanRegistry';

    /**
     * Cloaking operations
     * Used for device/fingerprint cloaking detection
     */
    public const LOG_CATEGORY_CLOAKING = 'Cloaking';

    /**
     * Cross-device validation operations
     * Used when validating users across devices
     */
    public const LOG_CATEGORY_CROSS_DEVICE_VALIDATION = 'CrossDeviceValidation';

    /**
     * Rate limiting operations
     * Used for tracking and enforcing rate limits
     */
    public const LOG_CATEGORY_RATE_LIMIT = 'RateLimit';

    // ========== BACKUP OPERATIONS CATEGORIES ==========

    /**
     * Backup debug logging
     * Used for backup operation troubleshooting
     */
    public const LOG_CATEGORY_BACKUP_DEBUG = 'BackupDebug';

    /**
     * Backup operation tracking
     * Used for general backup operation logging
     */
    public const LOG_CATEGORY_BACKUP_OPERATION = 'BackupOperation';

    // ========== VARIOUS UTILITY CATEGORIES ==========

    /**
     * Form data operations
     * Used for form submission and processing tracking
     */
    public const LOG_CATEGORY_FORM_DATA = 'FormData';

    /**
     * Redirect diagnostic logging
     * Used for redirect troubleshooting
     */
    public const LOG_CATEGORY_REDIRECT_DIAG = 'RedirectDiag';

    /**
     * Permission fix operations
     * Used when correcting permission issues
     */
    public const LOG_CATEGORY_PERMISSION_FIX = 'PermissionFix';

    /**
     * Permission fix errors
     * Used when permission correction fails
     */
    public const LOG_CATEGORY_PERMISSION_FIX_ERROR = 'PermissionFixError';

    /**
     * Offline mode operations
     * Used when application operates in offline mode
     */
    public const LOG_CATEGORY_OFFLINE = 'Offline';
}
