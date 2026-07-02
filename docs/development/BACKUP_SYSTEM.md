# Backup System Documentation

## Overview

The Elan Registry backup system provides automated database backup
functionality with standardized naming conventions, retention policies, and
rollback capabilities. As of v2.9.2, the backup system has been refactored
into an object-oriented architecture centered around the `BackupManager`
class.

## Architecture

### BackupManager Class

**Location**: `app/admin/includes/classes/BackupManager.php`

The BackupManager class encapsulates all backup operations, providing a clean
OOP interface for creating, managing, and cleaning up database backups.

**Key Features**:

- Schema backups before maintenance operations
- Manual backups on demand
- Automated retention policy enforcement
- Backup integrity verification
- Health scoring and recommendations

## Public API Methods

### createSchemaBackup()

Creates a backup before schema operations.

```php
public function createSchemaBackup(string $operation, array $tables = []): string
```

**Parameters**:

- `$operation` (string): Operation name (e.g., "Schema Maintenance")
- `$tables` (array): Optional array of table names to backup. Defaults to
  critical tables.

**Returns**: Path to the created backup file

**Example**:

```php
$backupManager = new BackupManager(
    $db,
    $abs_us_root . $us_url_root . 'app/admin/scripts/fix/backups/'
);
$backupPath = $backupManager->createSchemaBackup(
    'Update User Permissions',
    ['users', 'permissions']
);
```

**Default Tables**: settings, users, cars, car_user, profiles

---

### createManualBackup()

Creates a manual backup with enhanced metadata.

```php
public function createManualBackup(
    string $reason,
    array $tables = [],
    array $metadata = []
): string
```

**Parameters**:

- `$reason` (string): Reason for backup (becomes part of filename)
- `$tables` (array): Optional array of table names. Defaults to critical tables.
- `$metadata` (array): Optional metadata for enhanced logging

**Returns**: Path to the created backup file

**Example**:

```php
$backupManager = new BackupManager($db, $abs_us_root . $us_url_root . 'app/admin/scripts/fix/backups/');
$backupPath = $backupManager->createManualBackup(
    'Pre-Migration Backup',
    ['users', 'cars', 'profiles'],
    ['migration_version' => '2.9.2']
);
```

---

### performEnhancedCleanup()

Cleans up old backups according to retention policies.

```php
public function performEnhancedCleanup(): array
```

**Returns**: Array with cleanup statistics including:

- `automated` / `manual` / `rollback`: Scanned and deleted counts
- `health_score_before` / `health_score_after`: Health score improvement
- `health_improvement`: Points of improvement

**Example**:

```php
$backupManager = new BackupManager($db, $abs_us_root . $us_url_root . 'app/admin/scripts/fix/backups/');
$results = $backupManager->performEnhancedCleanup();
echo "Deleted {$results['automated']['deleted']} automated backups\n";
echo "Health improved by {$results['health_improvement']} points\n";
```

---

### getEnhancedBackupStatistics()

Retrieves comprehensive backup statistics.

```php
public function getEnhancedBackupStatistics(): array
```

**Returns**: Array with:

- Basic statistics (count, total_size per type)
- Retention analysis (within_policy, approaching_expiry, expired)
- Health score (0-100)
- Recommendations array

**Example**:

```php
$backupManager = new BackupManager($db, $abs_us_root . $us_url_root . 'app/admin/scripts/fix/backups/');
$stats = $backupManager->getEnhancedBackupStatistics();

echo "Health Score: {$stats['health_score']}/100\n";
foreach ($stats['recommendations'] as $rec) {
    echo "- {$rec}\n";
}
```

---

### verifyBackupIntegrity()

Verifies a backup file is valid.

```php
public function verifyBackupIntegrity(string $backupPath): array
```

**Parameters**:

- `$backupPath` (string): Full path to backup file

**Returns**: Array with:

- `valid` (bool): Whether backup is valid
- `error` (string): Error message if invalid
- `file_size` (int): Size in bytes (if valid)
- `created_at` (string): Creation timestamp (if valid)
- `age_hours` (float): Age in hours (if valid)

**Example**:

```php
$backupManager = new BackupManager($db, $abs_us_root . $us_url_root . 'app/admin/scripts/fix/backups/');
$integrity = $backupManager->verifyBackupIntegrity($backupPath);

if ($integrity['valid']) {
    echo "Backup is valid: {$integrity['file_size']} bytes\n";
} else {
    echo "Backup is invalid: {$integrity['error']}\n";
}
```

## Backup File Naming Convention

Backup files follow a standardized naming pattern:

```text
{type}_{script-name}_{environment}_{timestamp}.sql
```

**Components**:

- `type`: automated | manual | rollback
- `script-name`: Kebab-case identifier
- `environment`: development | test | production
- `timestamp`: YYYYmmdd_HHiiss format

**Examples**:

```text
automated_schema-schema-maintenance_development_20251218_143022.sql
manual_pre-migration-backup_production_20251218_090000.sql
rollback_user-permissions-fix_development_20251217_163045.sql
```

## Directory Structure

```text
app/admin/scripts/fix/backups/
├── automated/      # Automated backups (schema operations)
├── manual/         # Manual on-demand backups
└── rollback/       # Backups specifically for rollback purposes
```

## Retention Policies

Retention is configured in `usersc/includes/config.php` and applies across all
environments. `BackupManager::getRetentionDays()` and the cleanup preview in
`backup-operations.php` both read from these constants — they are the single
source of truth.

| Constant | Type | Default |
| -------- | ---- | ------- |
| `BACKUP_RETENTION_AUTOMATED` | Automated | 7 days |
| `BACKUP_RETENTION_MANUAL` | Manual | 30 days |
| `BACKUP_RETENTION_ROLLBACK` | Rollback | 30 days |
| `BACKUP_WARNING_THRESHOLD_DAYS` | Warning window | 7 days |

Backups within `BACKUP_WARNING_THRESHOLD_DAYS` of their retention limit are
flagged as `approaching_expiry` in health statistics and recommendations.

Cleanup is performed by `performEnhancedCleanup()` and removes files older
than their retention period.

## Backup Metadata

Each backup file includes metadata in SQL comments:

```sql
-- BACKUP METADATA
-- Type: automated
-- Script: schema-schema-maintenance
-- Environment: development
-- Created: 2025-12-18 14:30:22
-- Tables: settings, users, cars, car_user, profiles
-- Retention: 7 days
-- Rollback-Ready: yes
-- Generator: BackupManager v2.0
```

## Migration Guide: FIX Scripts

### Old Approach (Deprecated)

```php
// FIX script using global functions
require_once 'FIX/backup-functions.php';

$backupPath = createStandardizedBackup(
    'my-fix-script',
    ['users', 'permissions'],
    'automated',
    'development'
);
```

### New Approach (Recommended)

```php
// FIX script using BackupManager class
require_once $abs_us_root . $us_url_root . 'app/admin/includes/classes/BackupManager.php';

$backupDir = $abs_us_root . $us_url_root . 'app/admin/scripts/fix/backups/';
$backupManager = new BackupManager($db, $backupDir);

$backupPath = $backupManager->createSchemaBackup('my-fix-script', ['users', 'permissions']);
```

### Compatibility Layer (For Legacy Scripts)

Legacy FIX scripts can continue using the old function signatures via the
compatibility wrapper:

```php
// Uses compatibility wrapper
require_once $abs_us_root . $us_url_root . 'usersc/includes/backup_functions.php';

// These global functions still work
$backupPath = createStandardizedBackup(
    'my-script',
    ['users'],
    'automated',
    'development'
);
$stats = getBackupStatistics();
$cleanup = cleanupOldBackups();
```

The compatibility wrapper delegates to BackupManager internally, ensuring
backward compatibility.

## Usage Examples

### Example 1: Pre-Migration Backup

```php
// Create comprehensive backup before database migration
$backupManager = new BackupManager(
    $db,
    $abs_us_root . $us_url_root . 'app/admin/scripts/fix/backups/'
);

$allTables = [
    'users', 'cars', 'car_user', 'profiles',
    'settings', 'permissions', 'car_history'
];
$backupPath = $backupManager->createManualBackup(
    'Pre-Migration-v2.9.2',
    $allTables,
    [
        'migration_version' => '2.9.2',
        'performed_by' => $user->data()->username
    ]
);

echo "Backup created: " . basename($backupPath) . "\n";
```

### Example 2: Admin Panel Integration

```php
// In admin panel code
$backupManager = new BackupManager(
    $db,
    $abs_us_root . $us_url_root . 'app/admin/scripts/fix/backups/',
    $user->data()->id
);

// Get statistics for display
$stats = $backupManager->getEnhancedBackupStatistics();

echo "<div class='backup-health'>";
echo "  <h3>Backup System Health: {$stats['health_score']}/100</h3>";
echo "  <p>Automated backups: {$stats['automated']['count']} files ";
echo "({$stats['automated']['total_size']} bytes)</p>";

if (!empty($stats['recommendations'])) {
    echo "  <ul>";
    foreach ($stats['recommendations'] as $rec) {
        echo "    <li>{$rec}</li>";
    }
    echo "  </ul>";
}
echo "</div>";
```

### Example 3: Scheduled Cleanup

```php
// Run cleanup via cron or admin action
$backupManager = new BackupManager(
    $db,
    $abs_us_root . $us_url_root . 'app/admin/scripts/fix/backups/'
);

$beforeStats = $backupManager->getEnhancedBackupStatistics();
echo "Health before cleanup: {$beforeStats['health_score']}/100\n";

$cleanupResults = $backupManager->performEnhancedCleanup();

echo "Cleanup Results:\n";
echo "- Automated: {$cleanupResults['automated']['deleted']} of ";
echo "{$cleanupResults['automated']['scanned']} deleted\n";
echo "- Manual: {$cleanupResults['manual']['deleted']} of ";
echo "{$cleanupResults['manual']['scanned']} deleted\n";
echo "- Rollback: {$cleanupResults['rollback']['deleted']} of ";
echo "{$cleanupResults['rollback']['scanned']} deleted\n";
echo "Health improvement: +{$cleanupResults['health_improvement']} points\n";
```

## Health Scoring

The backup system calculates a health score (0-100) based on:

- **Expired backups**: -10 points per type with expired files
- **Approaching expiry**: -5 points per type with files expiring soon
- **Excessive storage**: -15 points if total backup size exceeds 1GB

**Health Score Ranges**:

- **90-100**: Excellent - backups current, minimal storage
- **70-89**: Good - some maintenance recommended
- **50-69**: Fair - cleanup needed soon
- **Below 50**: Poor - immediate cleanup required

## Troubleshooting

### Backup Creation Fails

**Problem**: `Failed to create backup directory`

**Solution**: Check directory permissions. Ensure the web server can write to `app/admin/scripts/fix/backups/`:

```bash
chmod 755 app/admin/scripts/fix/backups/
chmod 755 app/admin/scripts/fix/backups/automated/
chmod 755 app/admin/scripts/fix/backups/manual/
chmod 755 app/admin/scripts/fix/backups/rollback/
```

### Backup File Empty

**Problem**: Backup file created but has 0 bytes

**Solution**:

1. Check database connection is active
2. Verify tables exist: `SHOW TABLES LIKE 'users'`
3. Check PHP memory limit for large tables

### Function Not Found Error

**Problem**: `Call to undefined function createStandardizedBackup()`

**Solution**: Include the compatibility wrapper:

```php
require_once $abs_us_root . $us_url_root . 'usersc/includes/backup_functions.php';
```

Or use BackupManager directly:

```php
require_once $abs_us_root . $us_url_root . 'app/admin/includes/classes/BackupManager.php';
$backupManager = new BackupManager($db, $backupDir);
```

## Related Files

- **Core Class**: `app/admin/includes/classes/BackupManager.php`
- **Compatibility Wrapper**: `usersc/includes/backup_functions.php`
- **Deprecated**: `FIX/backup-functions.php` (redirects to compatibility wrapper)
- **Admin Integration**: `app/admin/includes/tab-system.php`

## Changelog

### v2.9.2 (2025-12-18)

- **Refactored**: Moved backup functions into BackupManager class (OOP architecture)
- **Added**: Compatibility wrapper for backward compatibility
- **Fixed**: Issue #364 - Backup system reliability improved
- **Deprecated**: `FIX/backup-functions.php` (now wrapper to compatibility layer)

### v2.9.1 and earlier

- Original standalone function implementation
- FIX directory-based backup system

## See Also

- [Database Schema Documentation](DATABASE_SCHEMA_COMPARISON.md)
- [Testing Documentation](TESTING.md)
- [Release Notes v2.9.2](../releases/RELEASE_NOTES_V2.9.2.md)
