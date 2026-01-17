# Log Category Constants Reference

**Location:** `usersc/classes/LogCategories.php`

**Version:** v2.12.0+

**Last Updated:** 2026-01-17

## Overview

All logger() calls in the application MUST use standardized log category
constants from the LogCategories class. This centralized approach ensures:

- **Consistency** - All similar operations use the same category
- **Discoverability** - IDE autocomplete and grep searches find all
  categories
- **Maintainability** - Single source of truth for category values
- **Type Safety** - Constants prevent typos and catch misspellings

## Quick Start

```php
// Import LogCategories (autoloaded automatically)
logger($userId, LogCategories::LOG_CATEGORY_CAR_ACTIONS, 'Car created');
```

## Complete Category Reference

### Car Management Categories

- `LOG_CATEGORY_CAR_ACTIONS` → `CarActions` (General car operations)
- `LOG_CATEGORY_CAR_CREATION` → `CarCreation` (New car added)
- `LOG_CATEGORY_CAR_UPDATE` → `CarUpdate` (Car data modified)
- `LOG_CATEGORY_CAR_DELETION` → `CarDeletion` (Car removed)
- `LOG_CATEGORY_CAR_MERGE` → `CarMerge` (Duplicate cars merged)
- `LOG_CATEGORY_CAR_TRANSFER` → `CarTransfer` (Ownership transfer)
- `LOG_CATEGORY_CAR_TRANSFER_ERROR` → `CarTransferError` (Transfer failed)
- `LOG_CATEGORY_CAR_VERIFICATION` → `CarVerification` (Verification checks)
- `LOG_CATEGORY_CAR_SOLD` → `CarSold` (Car marked as sold)
- `LOG_CATEGORY_CAR_ERRORS` → `CarErrors` (Operation failures)

### Owner/User Management Categories

- `LOG_CATEGORY_OWNER_ACTIONS` → `OwnerActions` (Profile updates)
- `LOG_CATEGORY_USER_DELETION` → `UserDeletion` (Account deleted)
- `LOG_CATEGORY_USER` → `User` (General user operations)
- `LOG_CATEGORY_USER_CREATION` → `UserCreation` (New user account)
- `LOG_CATEGORY_INACTIVE_CLEANUP` → `InactiveCleanup` (Account processing)
- `LOG_CATEGORY_INACTIVE_DELETION` → `InactiveDeletion` (Account deleted)
- `LOG_CATEGORY_INACTIVE_USER_NOTIFICATION` → `InactiveUserNotification`
  (Notification sent)
- `LOG_CATEGORY_INACTIVE_USER_NOTIFICATION_ERROR` →
  `InactiveUserNotificationError` (Notification failed)

### Email & Communications Categories

- `LOG_CATEGORY_EMAIL_SUCCESS` → `EmailSuccess` (Email sent)
- `LOG_CATEGORY_EMAIL_ERROR` → `EmailError` (Send failed)
- `LOG_CATEGORY_EMAIL_SETTINGS` → `EmailSettings` (Configuration changed)
- `LOG_CATEGORY_FEEDBACK_FORM` → `FeedbackForm` (Submission received)
- `LOG_CATEGORY_SENDINBLUE` → `SendinblueDebug` (Third-party service)

### Authentication Categories

- `LOG_CATEGORY_LOGIN` → `Login` (Successful login)
- `LOG_CATEGORY_LOGIN_FAIL` → `LoginFail` (Failed attempt)
- `LOG_CATEGORY_LOGIN_METHOD` → `LoginMethod` (Method tracking)

### Passkey Authentication Categories

- `LOG_CATEGORY_PASSKEY_HANDLER` → `PasskeyHandler` (Core operations)
- `LOG_CATEGORY_PASSKEY_AUTH_ATTEMPT` → `PasskeyAuthAttempt` (Attempt)
- `LOG_CATEGORY_PASSKEY_AUTH_CHALLENGE` → `PasskeyAuthChallenge`
  (Challenge operations)
- `LOG_CATEGORY_PASSKEY_AUTH_CHALLENGE_GENERATED` →
  `PasskeyAuthChallengeGenerated` (Challenge created)
- `LOG_CATEGORY_PASSKEY_AUTH_CHALLENGE_INFO` →
  `PasskeyAuthChallengeInfo` (Challenge metadata)
- `LOG_CATEGORY_PASSKEY_AUTH_DEBUG` → `PasskeyAuthDebug` (Debugging)
- `LOG_CATEGORY_PASSKEY_AUTH_FAIL` → `PasskeyAuthFail` (Auth failed)
- `LOG_CATEGORY_PASSKEY_AUTH_FOUND` → `PasskeyAuthFound` (Located)
- `LOG_CATEGORY_PASSKEY_AUTH_LOOKUP` → `PasskeyAuthLookup` (Searching)
- `LOG_CATEGORY_PASSKEY_AUTH_SUCCESS` → `PasskeyAuthSuccess` (Succeeded)
- `LOG_CATEGORY_PASSKEY_AUTH_VALIDATE_CHALLENGE` →
  `PasskeyAuthValidateChallenge` (Validation)
- `LOG_CATEGORY_PASSKEY_AUTH_VALIDATED` → `PasskeyAuthValidated` (Valid)
- `LOG_CATEGORY_PASSKEY_AUTH_WARNING` → `PasskeyAuthWarning` (Warnings)
- `LOG_CATEGORY_PASSKEY_CONFIG_WARNING` → `PasskeyConfigWarning`
  (Config warnings)
- `LOG_CATEGORY_PASSKEY_DEBUG_IOS` → `PasskeyDebugIOS` (iOS debug)
- `LOG_CATEGORY_PASSKEY_DELETED` → `PasskeyDeleted` (Removed)
- `LOG_CATEGORY_PASSKEY_ERROR` → `PasskeyError` (Operation failures)
- `LOG_CATEGORY_PASSKEY_INIT` → `PasskeyInit` (Initialization)
- `LOG_CATEGORY_PASSKEY_JS` → `PasskeyJS` (Client-side operations)
- `LOG_CATEGORY_PASSKEY_LOGIN` → `PasskeyLogin` (Login flows)
- `LOG_CATEGORY_PASSKEY_LOGIN_FAIL` → `PasskeyLoginFail` (Login failed)
- `LOG_CATEGORY_PASSKEY_PARSER` → `PasskeyParser` (Data parsing)

### Password & TOTP Management Categories

- `LOG_CATEGORY_PASSWORD_RESET` → `PasswordReset` (Reset workflows)
- `LOG_CATEGORY_PASSWORDLESS_DEBUG` → `PasswordlessDebug` (Debugging)
- `LOG_CATEGORY_PASSWORDLESS_DEBUG_UA` → `PasswordlessDebugUA` (Device
  debug)
- `LOG_CATEGORY_TOTP_ENFORCEMENT` → `TOTPEnforcement` (Enforcement events)
- `LOG_CATEGORY_TOTP_ERROR` → `TOTPError` (Operation failures)
- `LOG_CATEGORY_TOTP_SECURITY` → `TOTPSecurity` (Security events)
- `LOG_CATEGORY_TOTP_SETUP` → `TOTPSetup` (Two-factor setup)
- `LOG_CATEGORY_TOTP_VERIFICATION` → `TOTPVerification` (Code verification)
- `LOG_CATEGORY_TOTP_WARNING` → `TOTPWarning` (Non-critical issues)
- `LOG_CATEGORY_VERIFY_TOTP` → `VerifyTOTP` (Additional tracking)

### Database Operation Categories

- `LOG_CATEGORY_DATABASE_ERROR` → `DatabaseError` (Operation failures)
- `LOG_CATEGORY_DATABASE_MAINTENANCE` → `DatabaseMaintenance` (Maintenance)
- `LOG_CATEGORY_DATABASE_MIGRATION` → `DatabaseMigration` (Migrations)
- `LOG_CATEGORY_DATABASE_OPTIMIZATION` → `DatabaseOptimization`
  (Performance optimization)
- `LOG_CATEGORY_SCHEMA_OPERATION_ERROR` → `SchemaOperationError` (Schema
  changes)
- `LOG_CATEGORY_TABLE_CONVERSION` → `TableConversion` (Table conversion)
- `LOG_CATEGORY_BACKUP_MANAGER` → `BackupManager` (Backup management)
- `LOG_CATEGORY_BACKUP_ERROR` → `BackupError` (Backup failures)

### System & File Operation Categories

- `LOG_CATEGORY_SYSTEM_ERROR` → `SystemError` (General failures)
- `LOG_CATEGORY_FILE_ERROR` → `FileError` (File operation failures)
- `LOG_CATEGORY_VALIDATION_ERROR` → `ValidationError` (Validation failures)
- `LOG_CATEGORY_IMAGE_REMOVAL` → `ImageRemoval` (Image deletion)
- `LOG_CATEGORY_THUMBNAIL_OPTIMIZATION` → `ThumbnailOptimization`
  (Thumbnail optimization)
- `LOG_CATEGORY_FIX_SCRIPT` → `FIXScript` (Script operations)
- `LOG_CATEGORY_FIX_SCRIPT_DEBUG` → `FIXScriptDebug` (Script debugging)
- `LOG_CATEGORY_FIX_SCRIPT_ERROR` → `FIXScriptError` (Script failures)

### Admin & Management Categories

- `LOG_CATEGORY_ADMIN_VERIFICATION` → `AdminVerification` (Access
  verification)
- `LOG_CATEGORY_ADMIN_ANNOUNCEMENTS` → `AdminAnnouncements` (Announcements)
- `LOG_CATEGORY_ADMIN_TEMPLATES` → `AdminTemplates` (Template management)
- `LOG_CATEGORY_PAGES_MANAGER` → `PagesManager` (Page management)
- `LOG_CATEGORY_MENU_MANAGER` → `MenuManager` (Menu management)
- `LOG_CATEGORY_PERMISSIONS_MANAGER` → `PermissionsManager` (Permission
  management)
- `LOG_CATEGORY_SETTINGS_UPDATE` → `SettingsUpdate` (Settings modifications)
- `LOG_CATEGORY_SYSTEM_MAINTENANCE` → `SystemMaintenance` (Maintenance
  tasks)
- `LOG_CATEGORY_SYSTEM_UPDATES` → `SystemUpdates` (System updates)
- `LOG_CATEGORY_LOGS` → `Logs` (Logging system operations)
- `LOG_CATEGORY_CRON_REQUEST` → `CronRequest` (Scheduled tasks)
- `LOG_CATEGORY_MIGRATIONS` → `Migrations` (System migrations)
- `LOG_CATEGORY_USER_MANAGER` → `UserManager` (User management)
- `LOG_CATEGORY_SPAM_CLEANUP` → `SpamCleanup` (Spam removal)
- `LOG_CATEGORY_SPAM_CLEANUP_ERROR` → `SpamCleanupError` (Cleanup failures)

### OAuth & External Auth Categories

- `LOG_CATEGORY_OAUTH_CLIENT` → `OAuthClient` (Client management)
- `LOG_CATEGORY_OAUTH_CLIENT_ERROR` → `OAuthClientError` (Client failures)
- `LOG_CATEGORY_OAUTH_CLIENT_LOGIN` → `OAuthClientLogin` (OAuth logins)
- `LOG_CATEGORY_OAUTH_CLIENT_REGISTRATION` → `OAuthClientRegistration`
  (App registration)
- `LOG_CATEGORY_OAUTH_CLIENT_TAGS` → `OAuthClientTags` (Categorization)
- `LOG_CATEGORY_OAUTH_CLIENT_UPDATE` → `OAuthClientUpdate` (Client
  modifications)
- `LOG_CATEGORY_OAUTH_CLIENT_WARNING` → `OAuthClientWarning` (Non-critical
  issues)
- `LOG_CATEGORY_OAUTH_CUSTOM_DATA` → `OAuthCustomData` (Data processing)
- `LOG_CATEGORY_OAUTH_LOGIN_SCRIPT` → `OAuthLoginScript` (Login scripting)
- `LOG_CATEGORY_OAUTH_SERVER` → `OAuthServer` (Server operations)
- `LOG_CATEGORY_OAUTH_SERVER_ERROR` → `OAuthServerError` (Server failures)
- `LOG_CATEGORY_OAUTH_SERVER_SECURITY` → `OAuthServerSecurity` (Security
  events)

### Location & Geocoding Categories

- `LOG_CATEGORY_GEOCODE` → `Geocode` (Address to coordinates)
- `LOG_CATEGORY_LOCATION_SERVICE` → `LocationService` (Location operations)
- `LOG_CATEGORY_LOCATION_REVERSE` → `LocationReverse` (Coordinates to
  address)

### Access Control & Diagnostics Categories

- `LOG_CATEGORY_ACCESS_DENIED` → `AccessDenied` (Access denied)
- `LOG_CATEGORY_CHECK_ACCESS` → `CheckAccess` (Access verification)
- `LOG_CATEGORY_HAS_PERM` → `HasPerm` (Permission checks)
- `LOG_CATEGORY_SECURE_PAGE` → `SecurePage` (Protected page access)
- `LOG_CATEGORY_IP_LOGGING` → `IPLogging` (IP tracking)
- `LOG_CATEGORY_BLACKLIST_CLEAR` → `BlacklistClear` (Blacklist clearing)
- `LOG_CATEGORY_PAGE_NOT_FOUND` → `PageNotFound` (404 tracking)
- `LOG_CATEGORY_DIAGNOSTICS` → `Diagnostics` (System diagnostics)

### Application-Specific Categories

- `LOG_CATEGORY_ELAN_REGISTRY` → `ElanRegistry` (Registry operations)
- `LOG_CATEGORY_CLOAKING` → `Cloaking` (Cloaking detection)
- `LOG_CATEGORY_CROSS_DEVICE_VALIDATION` → `CrossDeviceValidation`
  (Cross-device validation)
- `LOG_CATEGORY_RATE_LIMIT` → `RateLimit` (Rate limit enforcement)

### Backup Operations Categories

- `LOG_CATEGORY_BACKUP_DEBUG` → `BackupDebug` (Backup debugging)
- `LOG_CATEGORY_BACKUP_OPERATION` → `BackupOperation` (Backup tracking)

### Utility Categories

- `LOG_CATEGORY_FORM_DATA` → `FormData` (Form submission tracking)
- `LOG_CATEGORY_REDIRECT_DIAG` → `RedirectDiag` (Redirect debugging)
- `LOG_CATEGORY_PERMISSION_FIX` → `PermissionFix` (Permission correction)
- `LOG_CATEGORY_PERMISSION_FIX_ERROR` → `PermissionFixError` (Correction
  failures)
- `LOG_CATEGORY_OFFLINE` → `Offline` (Offline mode operations)

## Usage Examples

### Example 1: Car Creation with Exception

```php
try {
    $car = new Car();
    $car->create([
        'model' => 'Elan S2',
        'year' => 1962,
        'chassis' => 'CH-0001'
    ]);
    logger(
        $user->data()->id,
        LogCategories::LOG_CATEGORY_CAR_CREATION,
        'Car created successfully'
    );
} catch (CarCreationException $e) {
    logger($user->data()->id, $e->getLogCategory(), $e->getMessage());
    usError($e->getUserMessage());
}
```

### Example 2: Validation Error

```php
if (empty($email)) {
    logger(
        $user->data()->id ?? 0,
        LogCategories::LOG_CATEGORY_VALIDATION_ERROR,
        'Email field missing in user registration'
    );
    usError('Email is required');
    return false;
}
```

### Example 3: Database Maintenance Script

```php
// In FIX/_TEMPLATE_Fix-Script.php
define(
    'LOG_CATEGORY_PLACEHOLDER',
    LogCategories::LOG_CATEGORY_DATABASE_MAINTENANCE
);

// Use throughout the script
logger(
    $user->data()->id,
    LOG_CATEGORY_PLACEHOLDER,
    'Script started processing 500 records'
);
```

### Example 4: Admin Operation

```php
try {
    $settings = updateSystemSettings([
        'max_upload_size' => 10485760
    ]);
    logger(
        $admin->data()->id,
        LogCategories::LOG_CATEGORY_SETTINGS_UPDATE,
        'Max upload size changed to 10MB'
    );
} catch (Exception $e) {
    logger(
        $admin->data()->id,
        LogCategories::LOG_CATEGORY_SYSTEM_ERROR,
        'Settings update failed: ' . $e->getMessage()
    );
}
```

## Discovery Commands

### List all categories

```bash
grep "const LOG_CATEGORY" usersc/classes/LogCategories.php
```

### Find all uses of a category

```bash
grep -r "LOG_CATEGORY_CAR_ACTIONS" --include="*.php"
```

### Find hardcoded log strings

This should return zero results after migration is complete:

```bash
grep -r "logger([^,]*,\s*['\"][^'\"]*['\"]" --include="*.php" | grep -v "LogCategories::"
```

## Adding New Categories

When you need a new logging category:

1. Add to LogCategories class
2. Use UPPER_SNAKE_CASE for constant name (e.g.,
   `LOG_CATEGORY_NEW_FEATURE`)
3. Use PascalCase for value (e.g., `'NewFeature'`)
4. Add PHPDoc comment explaining the category's purpose
5. Update this reference

Example:

```php
/**
 * New feature operations
 * Used when the new feature processes something
 */
public const LOG_CATEGORY_NEW_FEATURE = 'NewFeature';
```

## Backward Compatibility

During the migration to LogCategories constants, the logger() function
continues to accept both:

- Old hardcoded strings (e.g., `'CarActions'`)
- New constants (e.g., `LogCategories::LOG_CATEGORY_CAR_ACTIONS`)

However, **all new code must use constants**. Hardcoded strings are being
phased out.

## Integration with Exception Classes

All ElanRegistryException-based exceptions automatically provide the correct
log category:

```php
public class CarCreationException extends ElanRegistryException {
    protected function getDefaultLogCategory(): string {
        return LogCategories::LOG_CATEGORY_CAR_CREATION;
    }
}
```

Use in code:

```php
try {
    throw new CarCreationException('DB insert failed');
} catch (ElanRegistryException $e) {
    // Automatically logs with correct category
    logger($user->data()->id, $e->getLogCategory(), $e->getMessage());
}
```

## Related Documentation

- [CLAUDE.md - Error Logging Standards](../CLAUDE.md#error-logging-standards)
- [CODING_STANDARDS.md - Logging Requirements](./CODING_STANDARDS.md#logging)
- [CLASSES.md - ElanRegistryException](./CLASSES.md#elanregistryexception)

## Version History

- **v2.12.0** (2026-01-17) - Initial LogCategories implementation with 140+
  constants
