# CLAUDE.md

This file provides essential guidance to Claude Code (claude.ai/code) when
working with code in this repository.

## 📋 Required Reading for All Sessions

**CRITICAL:** Read these files at the start of every Claude Code session:

- `CLAUDE.md` (this file) - Essential development guidance
- `docs/development/CODING_STANDARDS.md` - Code quality requirements
- `docs/development/DEVELOPMENT_WORKFLOW.md` - Detailed development processes
- `docs/development/DEPLOYMENT.md` - Production deployment procedures
- `docs/development/ENVIRONMENT.md` - Environment setup and configuration

## 📖 Recommended Reading Path

To avoid information overload, follow this structured learning path:

**Essential Context:**

1. `CLAUDE.md` (this file) - Overview and quick reference (~15 min)
2. `docs/development/QUICK_REFERENCE.md` - Common tasks lookup (~10 min)
3. `docs/development/QUICK_START.md` - Setup and testing commands (~20 min)

**Core Understanding:**

1. `docs/development/ARCHITECTURE.md` - System architecture and patterns
   (~30 min)
2. `docs/development/DATABASE.md` - Database schema and relationships (~30
   min)
3. `docs/development/PROJECT_CONVENTIONS.md` - Project coding standards (~15
   min)
4. `docs/development/INTEGRATION.md` - UserSpice integration (~15 min)

**As Needed - Specialized Topics:**

- `docs/development/PAGE_LOADING_FLOW.md` - When debugging initialization or
  understanding file loading sequence
- `docs/development/DEPLOYMENT.md` - When preparing releases
- `docs/development/FIX_SCRIPTS.md` - When creating database maintenance
  scripts
- `docs/development/CLASSES.md` - When working with application classes
- `docs/development/BACKUP_SYSTEM.md` - When using BackupManager class
- `docs/development/DATATABLES.md` - When working with DataTables or updating
  CDN configuration
- `docs/development/CSS_AND_ASSETS.md` - When modifying stylesheets or updating
  CDN resources (includes CSS minification procedures)
- `docs/testing/TESTING.md` - When writing or running tests
- `docs/development/STRICT_TYPE_HANDLING.md` - When working with strict types

**See [docs/README.md](docs/README.md) for complete documentation index**

## 🏗️ Architecture Overview

This is a PHP web application for the Lotus Elan Registry hosted at
<https://elanregistry.org>. It's built on top of UserSpice <https://userspice.com>
for user authentication and management, with custom car registry
functionality.

### Core Application Structure

> **For complete application architecture, see
> [ARCHITECTURE.md](docs/development/ARCHITECTURE.md)**

**Quick Reference**:

- `/app/` - Main application pages (car listings, details, forms, actions)
- `/users/` - UserSpice authentication system
- `/usersc/` - UserSpice customizations (templates, plugins, overrides)
- `/usersc/classes/` - Custom application classes
- `/tests/` - PHPUnit and Playwright test files

### UserSpice Integration

> **For detailed UserSpice integration patterns, see
> [INTEGRATION.md](docs/development/INTEGRATION.md)**

**Critical Requirements**:

- **Page Security**: All protected pages must include security check:

  ```php
  if (!securePage($_SERVER['PHP_SELF'])) {
      die();
  }
  ```

- **New PHP Directories**: Update `$path` array in `/z_us_root.php` for new
  directories containing PHP files
- **Page Registration**: Pages using `securePage()` must be registered in
  UserSpice admin panel with appropriate permissions

### Database Architecture

> **For complete database schema, see [DATABASE.md](docs/development/DATABASE.md)**
> **For architecture patterns, see [ARCHITECTURE.md](docs/development/ARCHITECTURE.md)**

**Quick Reference**:

- MySQL 8.0+ with comprehensive audit trails
- `cars`/`cars_hist` - Vehicle records with full audit trail
- `car_user`/`car_user_hist` - Many-to-many user-car relationships with audit
- `car_transfer_requests` - Self-service ownership transfer workflow
- Database triggers automatically maintain audit trails (cars table only)

### DataTables Configuration

> **For complete DataTables documentation, see
> [DATATABLES.md](docs/development/DATATABLES.md)**

We use DataTables for searchable, sortable, paginated table views of cars and
factory data. As of v2.11.0, we load **only 3 extensions** for optimal
performance.

**Quick Reference:**

- **Active Extensions**: DataTables Core (dt-1.10.23), FixedHeader (fh-3.1.8),
  Responsive (r-2.2.7)
- **Server-Side Processing**: All tables use AJAX-based server-side data
  loading
- **CDN Configuration**: Stored in `settings` table
  (`elan_datatables_js_cdn`, `elan_datatables_css_cdn`)
- **Used In**: `/app/cars/index.php` (car listing), `/app/cars/factory.php`
  (factory data)
- **Backend**: `/app/action/getDataTables.php` provides server-side data

**Important**: Before adding new DataTables extensions, verify they support
server-side processing. SearchPanes and SearchBuilder have poor UX with
server-side tables without significant backend work.

### 🔧 FIX Script Creation Guidelines

> **For complete FIX script creation guidelines, see
> [FIX_SCRIPTS.md](docs/development/FIX_SCRIPTS.md)**

FIX scripts are one-time administrative scripts for database maintenance tasks,
schema migrations, and data quality repairs.

**Quick Reference:**

- Use standardized template: `FIX/_TEMPLATE_Fix-Script.php`
- Sequential naming: `##-Descriptive-Name.php`
- Two-step UI process with progress tracking
- Proper transaction handling and error logging

### Class Architecture & Integration Patterns

> **For detailed class documentation, see [CLASSES.md](docs/development/CLASSES.md)**

**Quick Reference:**

- **Core domain classes**: Car, CarView, ElanRegistryOwner, ChassisValidator
- **Support classes**: BackupManager, Resize, EmailTemplate, MarkdownParser,
  DocumentConfig
- **Location**: All custom classes in `/usersc/classes/` and
  `/app/admin/includes/classes/`
- **Patterns**: DB singleton, custom exceptions, audit logging, strict typing

**Key Integration Functions:**

- **`getUserWithProfile($userId)`**: Primary function for combined
  user+profile data access
  - Located in `/usersc/includes/custom_functions.php`
  - Returns user object with profile fields (city, state, country, lat, lon,
    website)
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
- **Returns**: Array with lat/lon keys, or empty array on failure
- **Integration**: Used in ElanRegistryOwner class, user_settings.php, and during_user_creation.php
- **Note**: LocationGeocoder is internal-only; always use ElanRegistryOwner::geocodeAddress()

**BackupManager Integration (v2.9.2+):**

- **Location**: `/app/admin/includes/classes/BackupManager.php`
- **Purpose**: OOP-based database backup management system
- **Usage**: Create backups before schema operations, manual backups, cleanup
  old backups

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

**See Also**: `/docs/development/BACKUP_SYSTEM.md` for comprehensive
documentation

### Documentation System

**Unified Documentation Viewer**: `/docs/view.php`

- **Purpose**: Displays markdown documents with proper formatting and access
  control
- **Features**: Security validation, XSS protection, responsive design,
  breadcrumb navigation
- **Access Control**: Public documents in `/docs/faq/`, admin documents in
  `/docs/faq/admin/`

**Documentation Utilities**:

- **MarkdownParser** (`/usersc/classes/MarkdownParser.php`) - Converts
  markdown to HTML with security features
- **DocumentConfig** (`/usersc/classes/DocumentConfig.php`) - Manages
  document metadata and access control

**Key Documentation Files**:

- User guides: `/docs/faq/CAR_TRANSFER_USER_GUIDE.md`,
  `/docs/faq/CAR_TRANSFER_FAQ.md`
- Admin guides: `/docs/faq/admin/CAR_TRANSFER_ADMIN_GUIDE.md`
- Development docs: `/CLAUDE.md`,
  `/docs/development/DATABASE.md`, `/docs/development/ENVIRONMENT.md`
- Strategic docs: `/docs/PRD.md`

### Key Application Files

- `app/cars/index.php` - Searchable car listing with DataTables
- `app/cars/details.php` - Individual car detail pages
- `app/cars/edit.php` - Car editing forms
- `app/reports/statistics.php` - Registry analytics & statistics with
  Chart.js (tabbed interface)
- `app/contact/send-owner-email.php` - Owner contact functionality

## ⚙️ Development Setup

### System Requirements

- PHP 8.1+ required (8.2+ recommended for full PHPUnit 12 compatibility)
- MySQL 8.0+
- Uses `johnathanmiller/secure-env-php` for encrypted environment variable handling

### Quick Start Commands

```bash
# Install PHP dependencies
composer install

# Install Node dependencies (for testing)
npm install

# Setup enhanced pre-commit quality checks (RECOMMENDED)
./scripts/setup-git-hooks.sh

# PHP test commands (core infrastructure)
composer test:quick        # Unit tests only (<30s)
composer test:medium       # Unit + Integration (<2min)
composer test:full         # All PHP tests
composer test:coverage     # Generate coverage report

# UI testing (requires setup)
npm test                   # Shows setup requirements
npm run playwright:install # Install Playwright browsers
npm run playwright:test    # Run UI tests (after setup)
```

### Testing

```bash
# PHP test suites (working)
composer test:unit         # Fast unit tests
composer test:integration  # Database integration tests
composer test:regression   # Issue-specific regression tests

# UI test suites (requires setup)
npm run playwright:security       # Security-focused tests
npm run playwright:ui             # UI consistency tests
npm run playwright:navigation     # Navigation and redirects
npm run playwright:functionality  # Core functionality
npm run playwright:maps           # Maps and charts
npm run playwright:csp            # CSP validation tests
```

## 🔧 Essential Development Guidelines

### Pre-commit Quality Checks (HIGHLY RECOMMENDED)

**Setup once per developer:**

```bash
./scripts/setup-git-hooks.sh
```

**What it does:**

- **Step 1**: PHP coding standards validation (security, types,
  documentation)
- **Step 2**: Markdown linting for documentation files
- **Step 3**: Fast unit tests when critical files are modified
- **Blocks commits** with violations and provides fix guidance
- **No installation required** - uses existing tools and npx

**Benefits:**

- Prevents PR failures by catching issues locally
- Maintains consistent code quality across the team
- Provides immediate feedback with actionable fix suggestions

**Bypass (emergency only):** `git commit --no-verify`

### PHP 8+ Requirements

- **PHP 8+ Type Declarations**: All functions must have complete parameter and
  return type hints
- **Strict Typing**: New files must include `declare(strict_types=1)`
- **Custom Exceptions**: Use typed exception classes for proper error handling
- **Security First**: Follow secure coding practices outlined in coding
  standards
- **Documentation**: Complete PHPDoc blocks required for all public methods

### Security Requirements

- All forms must use CSRF tokens
- Use prepared statements for SQL queries
- Input validation and sanitization required for all user inputs
- Password hashing uses bcrypt
- Secure session handling implemented
- **CRITICAL**: Never commit credentials, API keys, or sensitive data to git
- Use environment variables for all sensitive configuration

### Owner Data Management Patterns

**Use these patterns when working with owner/user data operations:**

#### Geocoding System

**Automatic Location Geocoding:**

- Location updates automatically trigger Google Maps API geocoding
- Coordinates are automatically populated when city/state/country is provided
- Admin interface provides visual feedback for geocoding success/failure
- Failed geocoding preserves existing coordinates and shows clear error messages

```php
// ✅ Location updates with automatic geocoding
$owner = new ElanRegistryOwner($userId);
$owner->update([
    'id' => $userId,
    'city' => 'Portland',
    'state' => 'Oregon',
    'country' => 'United States',
    'csrf' => Token::generate()
]);
// Coordinates automatically populated via Google Maps API
```

**Geocoding Configuration:**

- API key stored in settings table as `elan_google_geo_key`
- Geocoding class: `usersc/classes/LocationGeocoder.php` (internal-only)
- Public API: `ElanRegistryOwner::geocodeAddress()` static method
- Coordinates rounded to 4 decimal places (~11 meter accuracy)
- Failed requests are logged for troubleshooting

#### Admin Interface Structure

**Consolidated Management Interface:**

- **Location**: `app/admin/manage-consolidated.php`
- **Purpose**: Unified admin interface for all registry management tasks
- **Tabs Available**:
  - **Car/Owner Relationships**: Transfer requests and ownership management
  - **Manage Cars**: Car data quality issues and duplicate detection
  - **Owner Management**: Owner profiles with search and quality reports
  - **System Maintenance**: Database cleanup and maintenance tasks
  - **Settings**: Configuration management
  - **Account Cleanup**: User account management

**Owner Management Features:**

- Advanced search with UNION-based prioritization (exact matches first)
- Real-time geocoding feedback with visual indicators
- Profile quality scoring and completion tracking
- Bulk location synchronization to owned cars

#### Owner Profile Access

```php
// ✅ PREFERRED: Use existing custom function for complete owner data
$owner = getUserWithProfile($userId);
if ($owner) {
    echo "Owner: {$owner->fname} {$owner->lname}";
    echo "Location: {$owner->city}, {$owner->state}, {$owner->country}";
    echo "Coordinates: {$owner->lat}, {$owner->lon}";
}

// ✅ ACCEPTABLE: When you need additional owner context
class ElanRegistryOwner {
    public static function getOwnerProfile(int $userId): ?object {
        return getUserWithProfile($userId);
    }

    public function getCarsOwned(): array {
        return $this->_db->query("SELECT * FROM cars WHERE user_id = ?", [$this->_data->id])->results();
    }
}
```

#### Location Updates with Geocoding

```php
// ✅ CORRECT: Use ElanRegistryOwner geocoding API
public function updateLocation(array $locationData): bool {
    // Use static geocoding method
    $geoResult = ElanRegistryOwner::geocodeAddress(
        $locationData['city'],
        $locationData['state'],
        $locationData['country']
    );

    // Update profile with geocoded coordinates
    if (!empty($geoResult)) {
        $updateFields = array_merge($locationData, $geoResult);
        return $this->_db->update('profiles', $this->_profileId, $updateFields);
    }

    return false;
}
```

#### Owner Search and Management Interface

```php
// ✅ CORRECT: Admin search functionality
public function searchOwners(string $searchTerm): array {
    $searchTerm = '%' . $searchTerm . '%';

    return $this->_db->query(
        "SELECT u.id, u.fname, u.lname, u.email,
                p.city, p.state, p.country
         FROM users u
         LEFT JOIN profiles p ON u.id = p.user_id
         WHERE u.fname LIKE ? OR u.lname LIKE ? OR u.email LIKE ?
            OR p.city LIKE ? OR p.state LIKE ?
         ORDER BY u.lname, u.fname",
        [$searchTerm, $searchTerm, $searchTerm,
         $searchTerm, $searchTerm]
    )->results();
}
```

#### Data Quality Integration

```php
// ✅ CORRECT: Profile completeness scoring
public function getProfileQualityScore(): float {
    $owner = $this->data();
    $totalFields = 7;
    $completedFields = 0;

    if (!empty($owner->fname))
        $completedFields++;
    if (!empty($owner->lname))
        $completedFields++;
    if (!empty($owner->email))
        $completedFields++;
    if (!empty($owner->city))
        $completedFields++;
    if (!empty($owner->state))
        $completedFields++;
    if (!empty($owner->country))
        $completedFields++;
    if (!empty($owner->lat) && !empty($owner->lon))
        $completedFields++;

    return round(($completedFields / $totalFields) * 100, 1);
}
```

### Error Logging Standards

**All error conditions MUST use UserSpice logger integration for centralized
error visibility and audit trails.**

#### Log Category Constants (v2.12.0+)

All logger() calls MUST use standardized constants from the LogCategories class
instead of hardcoded strings. This ensures consistency, prevents typos, and
makes logging categories discoverable.

**Centralized Constants Location:** `usersc/classes/LogCategories.php`

**140+ categories organized by functional domain:**
- Car Management (CarActions, CarCreation, CarUpdate, CarDeletion, CarMerge, CarTransfer, etc.)
- User/Owner Management (OwnerActions, UserDeletion, UserCreation, InactiveCleanup, etc.)
- Authentication (Login, LoginFail, PasskeyAuth*, PasswordReset, TOTP*, etc.)
- Database Operations (DatabaseError, DatabaseMaintenance, BackupManager, SchemaOperationError, etc.)
- Email/Communications (EmailSuccess, EmailError, FeedbackForm, etc.)
- System & File Operations (SystemError, FileError, ValidationError, ImageRemoval, etc.)
- Admin & Management (AdminVerification, SettingsUpdate, UserManager, Logs, etc.)
- Location & Geocoding (Geocode, LocationService, LocationReverse, etc.)
- Access Control (AccessDenied, SecurePage, HasPerm, PageNotFound, etc.)
- OAuth & External Auth (OAuthClient, OAuthServer, OAuthClientLogin, etc.)
- And 4+ more functional domains

**Discovery:** `grep "const LOG_CATEGORY" usersc/classes/LogCategories.php`

#### Error Logging Pattern (with LogCategories)

```php
// ✅ CORRECT: Use LogCategories constants
try {
    // Operation that might fail
    $result = riskyOperation();
} catch (Exception $e) {
    logger(
        $user->data()->id ?? 0,
        LogCategories::LOG_CATEGORY_SYSTEM_ERROR,
        'Descriptive error message: ' . $e->getMessage()
    );
    throw new SpecificException('User-friendly message');
}

// ✅ CORRECT: Validation error logging
if (empty($requiredField)) {
    logger(
        $user->data()->id ?? 0,
        LogCategories::LOG_CATEGORY_VALIDATION_ERROR,
        'Required field missing: fieldName'
    );
    throw new ValidationException('Field is required');
}

// ❌ INCORRECT: Never use hardcoded strings
logger($user->data()->id, 'SystemError', 'message');  // Don't do this!
```

#### Using ElanRegistryException with LogCategories

Domain-specific exceptions automatically use the correct LogCategories constant:

```php
// Exception automatically logs with correct category
try {
    throw new CarCreationException('Database insert failed');
} catch (ElanRegistryException $e) {
    // $e->getLogCategory() returns LogCategories::LOG_CATEGORY_CAR_CREATION
    logger($user->data()->id, $e->getLogCategory(), $e->getMessage());
    usError($e->getUserMessage());  // Safe for UI display
}
```

### Message Handling Standards

**All error and success messages MUST use the modern UserSpice session-based
messaging system for consistent UX.**

```php
// Set error messages (instead of deprecated display_errors())
if (!empty($errors)) {
    foreach ($errors as $error) {
        usError($error);
    }
}

// Set success messages (instead of deprecated display_successes())
if (!empty($successes)) {
    foreach ($successes as $success) {
        usSuccess($success);
    }
}

// Display all messages (replaces manual Bootstrap alert HTML)
sessionValMessages($errors, $successes, null);
```

### Code Quality Requirements

**ALWAYS run the following commands before completing any task:**

- Run `mcp__ide__getDiagnostics` to check all files for diagnostics
- Fix any linting or type errors before considering the task complete
- Run appropriate test suites for modified functionality

This is a CRITICAL step that must NEVER be skipped when working on any
code-related task.

### Release Notes Requirements

**ALWAYS update or create release notes when creating a pull request:**

- **Update existing release notes** if the target milestone already has a
  RELEASE_NOTES_V[VERSION].md file
- **Create new release notes** using the template at
  `docs/development/RELEASE_NOTES_TEMPLATE.md` if none exist
- **Follow the standardized structure**: Required Actions → User-Facing
  Changes → Admin-Facing Changes → Issues Resolved
- **Focus on impact and benefits**, not implementation details (those belong
  in GitHub issues)
- **Include clear testing instructions** in the Required Actions section for
  any manual steps needed post-deployment

**📋 See [RELEASE_NOTES_TEMPLATE.md](docs/development/RELEASE_NOTES_TEMPLATE.md) for complete guidelines and structure**

### Version Release & Deployment

**For complete release and deployment procedures, see [DEPLOYMENT.md](docs/development/DEPLOYMENT.md).**

**Quick Reference:**

- **MANDATORY for major/minor releases**: Release notes, GitHub release,
  annotated git tags
- **Optional for patch releases**: Release notes for significant patches or
  security fixes
- **Remote configuration**: `origin` (GitHub), `test` (staging), `prod` (live
  production)
- **Deployment commands**: See DEPLOYMENT.md for comprehensive workflows

### ElanRegistry Terminology Standards

**CRITICAL:** Consistent terminology is essential for code clarity and user experience.

#### User vs Owner Terminology

- **Users**: Authentication and session management context (UserSpice framework)
  - Use in UserSpice integration code
  - Database table references (`users` table)
  - Session management and permissions
  - Authentication workflows

- **Owners**: Car registry business domain context (ElanRegistry terminology)
  - Use in UI elements and user-facing content
  - Business logic and domain operations
  - Car ownership and registry functionality
  - Admin interfaces referring to registry participants

#### Code Implementation Guidelines

```php
// ✅ CORRECT: UserSpice context
$user = new User();
if ($user->isLoggedIn()) {
    // UserSpice authentication logic
}

// ✅ CORRECT: ElanRegistry context
$owner = new ElanRegistryOwner($userId);
$ownerProfile = $owner->getOwnerProfile();
echo "Owner: " . $owner->data()->fname . " " . $owner->data()->lname;

// ✅ CORRECT: Database operations use UserSpice table names
$userQuery = $db->query("SELECT * FROM users WHERE id = ?", [$userId]);
$profileQuery = $db->query(
    "SELECT * FROM profiles WHERE user_id = ?",
    [$userId]
);

// ✅ CORRECT: UI elements use owner terminology
echo "<h3>Owner Information</h3>";
echo "<button>Contact Owner</button>";
echo "<span>Owner Location: {$owner->city}, {$owner->state}</span>";
```

#### Integration Patterns

**Use existing `getUserWithProfile()` function for combined data access:**

```php
// ✅ CORRECT: Leverage existing custom function
$ownerData = getUserWithProfile($userId);
if ($ownerData) {
    echo "Owner: {$ownerData->fname} {$ownerData->lname}";
    echo "Location: {$ownerData->city}, ";
    echo "{$ownerData->state}";
}
```

**Follow established Car class patterns for new domain classes:**

```php
// ✅ CORRECT: ElanRegistryOwner class follows Car class patterns
class ElanRegistryOwner {
    private $_db;
    private $_data;

    public function __construct(?int $id = null) {
        $this->_db = DB::getInstance();
        if ($id) {
            $this->find($id);
        }
    }

    public function create(array $fields): bool {
        // Validation, sanitization, audit logging
        // Follow Car class exception handling patterns
    }
}
```

## 🚀 Quick Deployment Reference

**🚨 CRITICAL:** When deploying, use the correct remote for each environment!

```bash
# Push to GitHub for repository backup
git push origin main && git push origin --tags

# Deploy to test server for validation
git push test feature/v2.9.1
git push test v2.9.1

# Push code to PRODUCTION SERVER (live site)
git push prod main
git push prod --tags
```

**📋 See [DEPLOYMENT.md](docs/development/DEPLOYMENT.md) for complete release and deployment
procedures**

## 📊 Current Development Status

### ✅ Production Ready Features

- **Security**: Enterprise-grade security implementation with comprehensive
  CSRF protection
- **Testing**: 35/35 Playwright browser tests passing (100% success rate) plus
  comprehensive PHPUnit security test suite
- **PHP 8+ Compatibility**: Full compatibility with modern PHP versions,
  comprehensive null handling
- **Documentation**: Complete setup, development, and deployment documentation

### 📋 Active Development Areas

Current GitHub Issues are organized into development phases:

- **Phase 1 Critical Issues** - Bug fixes and stability improvements
- **Phase 2-5** - Core enhancements, UX improvements, and optional features

See GitHub Issues for detailed development roadmap and current work items.

## 📊 Recent Major Changes

### Chart.js Migration (Issue #285) - v2.8.1

**Completed Migration from Google Charts to Chart.js:**

- **Statistics Page Enhanced**: Converted to tabbed interface with lazy loading
  - Overview, Geographic, Production, Colors, Data Quality tabs
  - 11+ interactive charts with Bootstrap theming
  - Performance optimized with caching (1 day prod, 5 minutes dev)
- **Analytics Page Consolidated**: All analytics features moved to statistics page
- **Security Improved**: Removed Google Charts CSP dependencies
- **Self-Hosted Solution**: Chart.js CDN configurable via Admin Panel

**Key Features:**

- Responsive Bootstrap-themed charts
- Lazy loading for performance
- Environment-based caching system
- API endpoints for dynamic data loading
- Comprehensive analytics dashboard

---

**📖 For detailed information, see the complete documentation files:**

- [DEVELOPMENT_WORKFLOW.md](docs/development/DEVELOPMENT_WORKFLOW.md) -
  Development processes and workflows
- [DEPLOYMENT.md](docs/development/DEPLOYMENT.md) - Production deployment
  procedures
- [CODING_STANDARDS.md](docs/development/CODING_STANDARDS.md) - Code quality
  requirements
- [ENVIRONMENT.md](docs/development/ENVIRONMENT.md) - Environment setup and configuration
