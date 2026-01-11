<!-- markdownlint-disable MD013 -->

# Architecture Guide

This document provides comprehensive architecture information for the Lotus
Elan Registry application.

## Architecture Overview

This is a PHP web application for the Lotus Elan Registry hosted at <https://elanregistry.org>. It's built on top of UserSpice (userspice.com) for user authentication and management, with custom car registry functionality.

## Core Application Structure

- `/app/` - Main application pages (car listings, details, forms, actions)
- `/users/` - UserSpice authentication system
- `/usersc/` - UserSpice customizations (templates, plugins, overrides)
- `/userimages/` - User-uploaded car images organized by car ID
- `/docs/` - Documentation organized by category (faq/, faq/admin/, development/, testing/)
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

> **For complete database schema documentation, see
> [DATABASE.md](DATABASE.md)**

### Core Database Components

**User Management:**

- `users` - UserSpice user accounts with authentication
- `profiles` - Extended user information (location, bio, website)
- One-to-one relationship between users and profiles

**Car Registry:**

- `cars` - Vehicle records with denormalized owner data for performance
- `cars_hist` - Complete audit trail for all car changes (INSERT/UPDATE/DELETE)
- `car_user` - Many-to-many junction table linking users to cars
- `car_user_hist` - Audit trail for relationship changes
- `car_transfer_requests` - Self-service ownership transfer workflow

**Reference Data:**

- `elan_factory_info` - Lotus Elan factory specifications and production data
- `country` - Country reference data for location fields

**System Tables:**

- `audit` - UserSpice audit logging for user actions
- `fix_script_runs` - Database maintenance script execution tracking

### Database Features

**Triggers** (automatic audit logging):

> **For complete trigger documentation and implementation details, see
> [DATABASE.md](DATABASE.md#database-triggers)**

- `cars` table has 3 audit triggers (INSERT/UPDATE/DELETE)
- Triggers can be bypassed with `@disable_triggers` variable
- `car_user` changes logged via application code, not triggers

**Data Synchronization:**

- Owner data in `cars` table (email, fname, lname, city, state, country, lat,
  lon, website) is automatically synchronized from user profiles
- Location changes in user profiles trigger updates to all owned cars
- Geocoding integration automatically populates coordinates from address data

**Special Accounts:**

> **For complete account details and GDPR cleanup processes, see
> [DATABASE.md](DATABASE.md#special-system-accounts)**

- `noowner` - Fallback owner for orphaned cars (GDPR compliance)
- `admin` - Primary administrative account

## Ownership Transfer System

The application implements a self-service ownership transfer workflow that
allows current owners to approve or deny transfer requests from new owners.

### Transfer Workflow

1. **Request Initiation**: New owner submits transfer request with car details
   - System generates unique security token
   - Request stored in `car_transfer_requests` table with status "pending"
   - Token expires after configured time period

2. **Current Owner Notification**: Current owner receives notification
   - Can approve or deny the transfer request
   - Response tracked with timestamp

3. **Admin Review** (optional): Administrators can review pending requests
   - View all transfer requests in admin consolidated interface
   - Add administrative notes
   - Manually approve, deny, or mark as completed

4. **Transfer Completion**: On approval
   - Car ownership transferred to new owner via `car_user` table
   - Car data updated with submitted information
   - Request status updated to "completed"
   - All changes logged to audit trails

### Transfer Request Data

The `car_transfer_requests` table stores:

- **Metadata**: Request ID, status, dates, security token
- **User References**: Requesting user, current owner (via car ID)
- **Submitted Data**: Complete snapshot of car data fields (15 fields)
- **Administrative**: Notes, denial reasons, timestamps

### Security Features

- Unique security tokens prevent unauthorized access
- Token expiration prevents stale requests
- Admin oversight and manual intervention capability
- Complete audit trail of all transfer actions

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

- **Class**: `LocationGeocoder` (`/usersc/classes/LocationGeocoder.php`)
- **Usage**: Call `ElanRegistryOwner::geocodeAddress()` static method
- **Returns**: Array with `lat`/`lon` keys, or empty array on failure
- **Integration**: Used in `ElanRegistryOwner` class, `user_settings.php`, and
  `during_user_creation.php`
- **Note**: LocationGeocoder is internal-only; always use
  `ElanRegistryOwner::geocodeAddress()`

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

**See Also**: `/docs/development/BACKUP_SYSTEM.md` for comprehensive documentation

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
- Admin guides: `/docs/faq/admin/CAR_TRANSFER_ADMIN_GUIDE.md`
- Development docs: See `/CLAUDE.md` for complete index

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
