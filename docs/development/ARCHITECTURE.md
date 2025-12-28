# Architecture Guide

This document provides comprehensive architecture information for the Lotus Elan Registry application.

## Architecture Overview

This is a PHP web application for the Lotus Elan Registry hosted at <https://elanregistry.org>. It's built on top of UserSpice (userspice.com) for user authentication and management, with custom car registry functionality.

## Core Application Structure

- `/app/` - Main application pages (car listings, details, forms, actions)
- `/users/` - UserSpice authentication system
- `/usersc/` - UserSpice customizations (templates, plugins, overrides)
- `/userimages/` - User-uploaded car images organized by car ID
- `/docs/` - Documentation organized by category (faq/, faq/admin/, development/, technical/)
- `/usersc/classes/` - Custom application classes and utilities
- `/tests/` - PHPUnit and Playwright test files

## UserSpice Management Requirements

**CRITICAL:** When working with UserSpice-managed pages:

1. **New Directories with PHP Files**: When adding new folders containing PHP files, update the `$path` array in `/z_us_root.php` to include the new directory path. This ensures proper path resolution and security monitoring. !securePage($_SERVER['PHP_SELF']) directive, verify the directory is in the `$path` array in `/z_us_root.php`

   ```php
   // Example: Adding 'app/reports/api/' directory
   $path = ['', 'users/', 'usersc/', 'app/', 'app/reports/',
            'app/reports/api/', ...];
   ```

2. **securePage() Authentication**: Pages that use `securePage($_SERVER['PHP_SELF'])` are managed by UserSpice's permission system. When creating new pages with `securePage()`:
   - The page must be manually added to UserSpice's page management system
   - Set appropriate permissions through UserSpice admin interface
   - Without proper page registration, `securePage()` will redirect to login/unauthorized pages

## Database Architecture

- MySQL database with comprehensive car registry schema
- `cars` table for vehicle records with full audit trail via `cars_hist`
- `car_user` junction table for car sharing between users
- **DEPRECATED VIEWS**: `usersview`, `users_carsview` remain due to privilege limitations but are unused (contains deprecated username references)
- Database triggers automatically maintain audit trails

## Class Architecture & Integration Patterns

**Domain Classes follow established patterns from the Car class:**

- **Location**: All custom classes in `/usersc/classes/`
- **Naming**: PascalCase with descriptive business domain names (e.g., `ElanRegistryOwner`, `Car`, `CarView`)
- **Database Integration**: Use `DB::getInstance()` singleton pattern
- **Exception Handling**: Custom exceptions in `/usersc/exceptions/` with descriptive names
- **Audit Logging**: All operations use `logger($userId, 'Category', 'Message')` pattern

**Key Integration Functions:**

- **`getUserWithProfile($userId)`**: Primary function for combined user+profile data access
  - Located in `/usersc/includes/custom_functions.php`
  - Returns user object with profile fields (city, state, country, lat, lon, website)
  - Handles missing profile data with safe defaults
  - Use this for all owner data access rather than separate queries

**Data Access Patterns:**

```php
// ✅ PREFERRED: Use existing custom function
$ownerData = getUserWithProfile($userId);

// ✅ ACCEPTABLE: Direct query when custom function insufficient
$userQ = $db->query(
    "SELECT u.*, p.* FROM users u LEFT JOIN profiles p
     ON u.id = p.user_id WHERE u.id = ?",
    [$userId]
);
```

**Geocoding Integration:**

- **Location**: `/app/views/_geolocate.php`
- **Usage**: Include file, sets `$fields['lat']` and `$fields['lon']` based on city/state/country
- **Required Variables**: `$city`, `$state`, `$country` must be set before inclusion
- **Integration**: Used in user_settings.php and should be used in ElanRegistryOwner class

**BackupManager Integration (v2.9.2+):**

- **Location**: `/app/admin/includes/classes/BackupManager.php`
- **Purpose**: OOP-based database backup management system
- **Usage**: Create backups before schema operations, manual backups, cleanup old backups

**Key Methods:**

```php
// Create backup before schema operations
$backupManager = new BackupManager($db, $backupDir, $userId);
$backupPath = $backupManager->createSchemaBackup(
    'Operation Name',
    ['users', 'cars']
);

// Create manual backup
$backupPath = $backupManager->createManualBackup(
    'Reason',
    ['users'],
    ['key' => 'value']
);

// Get statistics and perform cleanup
$stats = $backupManager->getEnhancedBackupStatistics();
$cleanup = $backupManager->performEnhancedCleanup();
```

**Backward Compatibility:**

- Legacy FIX scripts can still use global functions via `/usersc/includes/backup_functions.php`
- Compatibility wrapper delegates to BackupManager internally
- Deprecated: `/FIX/backup-functions.php` (redirects to compatibility wrapper)

**For New Code:**

- ✅ PREFERRED: Use BackupManager class directly (OOP approach)
- ⚠️ LEGACY: Use compatibility wrapper functions (for old FIX scripts only)

**See Also**: `/docs/technical/BACKUP_SYSTEM.md` for comprehensive documentation

## Documentation System

**Unified Documentation Viewer**: `/docs/view.php`

- **Purpose**: Displays markdown documents with proper formatting and access control
- **Features**: Security validation, XSS protection, responsive design, breadcrumb navigation
- **Access Control**: Public documents in `/docs/faq/`, admin documents in `/docs/faq/admin/`

**Documentation Utilities**:

- **MarkdownParser** (`/usersc/classes/MarkdownParser.php`) - Converts markdown to HTML with security features
- **DocumentConfig** (`/usersc/classes/DocumentConfig.php`) - Manages document metadata and access control

**Key Documentation Files**:

- User guides: `/docs/faq/CAR_TRANSFER_USER_GUIDE.md`, `/docs/faq/CAR_TRANSFER_FAQ.md`
- Admin guides: `/docs/faq/admin/CAR_TRANSFER_ADMIN_GUIDE.md`, `/docs/faq/admin/DATABASE.md`
- Development docs: See root `/CLAUDE.md` for complete index

## Key Application Files

- `app/cars/index.php` - Searchable car listing with DataTables
- `app/cars/details.php` - Individual car detail pages
- `app/cars/edit.php` - Car editing forms
- `app/reports/statistics.php` - Registry analytics & statistics with Chart.js (tabbed interface)
- `app/contact/send-owner-email.php` - Owner contact functionality

**Key Features:**

- Responsive Bootstrap-themed charts
- Lazy loading for performance
- Environment-based caching system
- API endpoints for dynamic data loading
- Comprehensive analytics dashboard
