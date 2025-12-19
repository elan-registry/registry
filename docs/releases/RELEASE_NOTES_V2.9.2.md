# Release Notes - Version 2.9.2

**Release Date**: TBD
**Status**: In Development

## Overview

Version 2.9.2 focuses on fixing critical issues with the Run Maintenance
feature and improving the backup system architecture through object-oriented
refactoring.

## Critical Bug Fixes

### Issue #365: Run Maintenance Feature Restored

**Priority**: High
**Type**: Bug Fix

**Problem**: The "Run Maintenance" button on the Admin System Maintenance tab
was non-functional. Clicking the button did not perform any maintenance
operations or create backups.

**Root Cause**: The `createStandardizedBackup()` function was located in
`FIX/backup-functions.php` (a temporary maintenance directory). The backup
creation was commented out in `EnhancedSchemaManager::performMaintenance()`
(lines 312-316) due to reliability issues (referenced as issue #364).

**Resolution**: Refactored the backup system into an object-oriented
architecture:

1. **Moved backup logic into BackupManager class** - All backup functions now
   encapsulated as private methods in
   `app/admin/includes/classes/BackupManager.php`
2. **Re-enabled backup creation** - Uncommented the backup creation code in
   `EnhancedSchemaManager::performMaintenance()`
3. **Maintained backward compatibility** - Created compatibility wrapper at
   `users/helpers/backup_functions.php` for legacy FIX scripts

**Impact**: Run Maintenance feature now works correctly, creating backups
before performing schema operations.

**Files Modified**:

- `app/admin/includes/classes/BackupManager.php` - Added private backup methods
- `app/admin/includes/tab-system.php` - Removed FIX/backup-functions.php include
- `app/admin/includes/classes/EnhancedSchemaManager.php` - Re-enabled backup creation
- `FIX/15-Fix-Page-Permissions.php` - Updated include path
- `FIX/backup-functions.php` - Deprecated with redirect to compatibility wrapper

**Files Created**:

- `users/helpers/backup_functions.php` - Compatibility wrapper for legacy code
- `docs/technical/BACKUP_SYSTEM.md` - Comprehensive backup system documentation

### Issue #364: Backup System Reliability Improved

**Priority**: High
**Type**: Architecture Improvement

**Problem**: Backup system functions were unreliable because they resided in
the temporary FIX directory, requiring `function_exists()` checks before use.

**Resolution**: As a consequence of fixing Issue #365, the backup system is
now reliably available through the BackupManager class. No more function
existence checks needed - the class methods are always available when the
class is instantiated.

**Impact**: Improved reliability and code maintainability.

## Architectural Changes

### Backup System Refactoring (OOP Architecture)

**What Changed**: Complete refactoring of backup functions from procedural to
object-oriented design.

**Before (v2.9.1 and earlier)**:

```php
// Global functions in FIX directory
require_once 'FIX/backup-functions.php';
$backupPath = createStandardizedBackup('my-script', $tables, 'automated', 'development');
```

**After (v2.9.2)**:

```php
// Object-oriented approach
$backupManager = new BackupManager($db, $backupDir);
$backupPath = $backupManager->createSchemaBackup('my-script', $tables);
```

**Benefits**:

1. **Proper Encapsulation** - All backup logic contained in single class
2. **Dependency Management** - Database and directories passed via constructor
3. **Testability** - Can mock dependencies for unit tests
4. **Maintainability** - Clear separation of concerns
5. **Backward Compatibility** - Legacy code continues working via
   compatibility wrapper

### BackupManager Class

**Location**: `app/admin/includes/classes/BackupManager.php`

**Public Methods**:

- `createSchemaBackup(string $operation, array $tables = []): string`
- `createManualBackup(string $reason, array $tables = [],
  array $metadata = []): string`
- `performEnhancedCleanup(): array`
- `getEnhancedBackupStatistics(): array`
- `verifyBackupIntegrity(string $backupPath): array`

**Private Methods** (moved from standalone functions):

- `createStandardizedBackup()` - Core backup creation
- `generateBackupMetadata()` - SQL metadata header generation
- `generateTableDump()` - Table SQL dump creation
- `getRetentionDays()` - Retention policy lookup
- `cleanupOldBackups()` - Old backup removal
- `extractEnvironmentFromFilename()` - Filename parsing
- `findBackupForRollback()` - Rollback file finding
- `logBackupEvent()` - Unified backup event logging

## Deprecations

### FIX/backup-functions.php

**Status**: Deprecated in v2.9.2
**Removal**: Planned for v3.0.0

**What Happened**: This file has been replaced with a deprecation notice that
redirects to the compatibility wrapper.

**Migration Path**:

1. **Recommended**: Update code to use BackupManager class directly
2. **Backward Compatible**: Continue using global functions via `users/helpers/backup_functions.php`

**Example Migration**:

```php
// Old (deprecated but still works)
require_once 'FIX/backup-functions.php';
$backup = createStandardizedBackup('my-script', ['users'], 'automated', 'development');

// New (recommended)
$backupManager = new BackupManager($db, $backupDir);
$backup = $backupManager->createSchemaBackup('my-script', ['users']);
```

## Documentation Updates

### New Documentation

**Technical Documentation**:

- `docs/technical/BACKUP_SYSTEM.md` - Comprehensive backup system documentation
  - BackupManager class architecture and usage
  - Public API methods with examples
  - Backup file naming conventions
  - Retention policies by environment and type
  - Migration guide for FIX scripts
  - Troubleshooting guide

**Updated Documentation**:

- `docs/development/CLAUDE.md` - Added BackupManager usage notes

## Testing

### New Tests

- `tests/BackupManagerTest.php` - PHPUnit tests for BackupManager class
- `FIX/test-backup-system.php` - Manual test script for verification

### Test Coverage

- BackupManager schema backup creation
- BackupManager manual backup creation
- Invalid backup type error handling
- Backup file naming validation
- Cleanup and retention policy enforcement
- Compatibility wrapper delegation

## Database Changes

No database schema changes in this release.

## Configuration Changes

No configuration file changes required.

## Upgrade Instructions

### From v2.9.1 to v2.9.2

1. **Pull Latest Code**:

   ```bash
   git pull origin main
   ```

2. **No Database Migrations Required**: This release contains no database changes.

3. **Test Run Maintenance Feature**:
   - Navigate to Admin Panel → System Maintenance tab
   - Click "Run Maintenance" button
   - Verify backup is created and maintenance operations complete

4. **Optional - Update FIX Scripts**: If you have custom FIX scripts using
   backup functions, consider migrating to BackupManager class (see migration
   guide in BACKUP_SYSTEM.md).

## Known Issues

None at this time.

## Contributors

- Claude Sonnet 4.5 (Development)
- Jim Boone (Project Owner, Code Review)

## Related Issues

- [#365 - Bug: Run maintenance feature does not work](https://github.com/unibrain1/elanregistry/issues/365)
- [#364 - Backup system reliability](https://github.com/unibrain1/elanregistry/issues/364)

## See Also

- [Backup System Documentation](../technical/BACKUP_SYSTEM.md)
- [Development Guide](../development/CLAUDE.md)
