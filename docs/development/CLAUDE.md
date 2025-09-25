# CLAUDE.md

This file provides essential guidance to Claude Code (claude.ai/code) when working with code in this repository.

## 📋 Required Reading for All Sessions

**CRITICAL:** Read these files at the start of every Claude Code session:

- `docs/development/CLAUDE.md` (this file) - Essential development guidance
- `docs/development/CODING_STANDARDS.md` - Code quality requirements
- `docs/development/DEVELOPMENT_WORKFLOW.md` - Detailed development processes
- `docs/development/DEPLOYMENT.md` - Production deployment procedures
- `docs/development/ENVIRONMENT.md` - Environment setup and configuration

## 🏗️ Architecture Overview

This is a PHP web application for the Lotus Elan Registry hosted at <https://elanregistry.org>. It's built on top of UserSpice (userspice.com) for user authentication and management, with custom car registry functionality.

### 🔧 FIX Script Creation Guidelines

**When creating FIX scripts, ALWAYS use the standardized template:**

1. **Use Template**: Start with `FIX/_TEMPLATE_Fix-Script.php`
2. **Sequential Naming**: Use format `##-Descriptive-Name.php` (e.g., `13-Fix-Something.php`)
3. **UI Standards**: Maintain two-step process (description → start button → progress tracking)
4. **Progress Tracking**: Use `outputMessage()` for progress updates and step indicators
5. **Logging**: Use simple `INSERT INTO fix_script_runs (script_name) VALUES (?)` format
6. **Database**: Always use proper transactions and error handling

**Template Features:**
- Professional UI with progress bars and status updates
- Standardized completion summaries with statistics
- Proper error handling and rollback capabilities
- Consistent return navigation and logging

### Core Application Structure

- `/app/` - Main application pages (car listings, details, forms, actions)
- `/users/` - UserSpice authentication system
- `/usersc/` - UserSpice customizations (templates, plugins, overrides)
- `/userimages/` - User-uploaded car images organized by car ID
- `/docs/` - Documentation organized by category (elanregistry/, development/, technical/)
- `/tests/` - PHPUnit and Playwright test files

### UserSpice Management Requirements

**CRITICAL:** When working with UserSpice-managed pages:

1. **New Directories with PHP Files**: When adding new folders containing PHP files, update the `$path` array in `/z_us_root.php` to include the new directory path. This ensures proper path resolution and security monitoring. !securePage($_SERVER['PHP_SELF']) directive, verify the directory is in the `$path` array in `/z_us_root.php`

   ```php
   // Example: Adding 'app/reports/api/' directory
   $path = ['', 'users/', 'usersc/', 'app/', 'app/reports/', 'app/reports/api/', ...];
   ```

2. **securePage() Authentication**: Pages that use `securePage($_SERVER['PHP_SELF'])` are managed by UserSpice's permission system. When creating new pages with `securePage()`:
   - The page must be manually added to UserSpice's page management system
   - Set appropriate permissions through UserSpice admin interface
   - Without proper page registration, `securePage()` will redirect to login/unauthorized pages

### Database Architecture

- MySQL database with comprehensive car registry schema
- `cars` table for vehicle records with full audit trail via `cars_hist`
- `car_user` junction table for car sharing between users
- **DEPRECATED VIEWS**: `usersview`, `users_carsview` remain due to privilege limitations but are unused (contains deprecated username references)
- Database triggers automatically maintain audit trails

### Class Architecture & Integration Patterns

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
$userQ = $db->query("SELECT u.*, p.* FROM users u LEFT JOIN profiles p ON u.id = p.user_id WHERE u.id = ?", [$userId]);
```

**Geocoding Integration:**

- **Location**: `/app/views/_geolocate.php`
- **Usage**: Include file, sets `$fields['lat']` and `$fields['lon']` based on city/state/country
- **Required Variables**: `$city`, `$state`, `$country` must be set before inclusion
- **Integration**: Used in user_settings.php and should be used in ElanRegistryOwner class

### Key Application Files

- `app/cars/index.php` - Searchable car listing with DataTables
- `app/cars/details.php` - Individual car detail pages
- `app/cars/edit.php` - Car editing forms
- `app/reports/statistics.php` - Registry analytics & statistics with Chart.js (tabbed interface)
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
npm run playwright:security      # Security-focused tests
npm run playwright:ui           # UI consistency tests
npm run playwright:navigation   # Navigation and redirects
npm run playwright:functionality # Core functionality
npm run playwright:maps         # Maps and charts
npm run playwright:csp          # CSP validation tests
```

## 🔧 Essential Development Guidelines

### Pre-commit Quality Checks (HIGHLY RECOMMENDED)

**Setup once per developer:**

```bash
./scripts/setup-git-hooks.sh
```

**What it does:**

- **Step 1**: PHP coding standards validation (security, types, documentation)
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

- **PHP 8+ Type Declarations**: All functions must have complete parameter and return type hints
- **Strict Typing**: New files must include `declare(strict_types=1)`
- **Custom Exceptions**: Use typed exception classes for proper error handling
- **Security First**: Follow secure coding practices outlined in coding standards
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
- Geocoding script: `app/views/_geolocate.php`
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

**Quality Badge System:**
- Dynamic badges show issue counts on relevant tabs
- Owner Management tab shows owner-specific quality issues
- Manage Cars tab shows car-specific quality issues
- Badges update in real-time based on database state

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
// ✅ CORRECT: Integrate with existing geocoding system
public function updateLocation(array $locationData): bool {
    // Set required variables for geocoding
    $city = $locationData['city'];
    $state = $locationData['state'];
    $country = $locationData['country'];

    // Include geocoding system
    include($abs_us_root . $us_url_root . 'app/views/_geolocate.php');

    // Update profile with geocoded coordinates
    if (!empty($fields)) {
        $updateFields = array_merge($locationData, $fields);
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
        "SELECT u.id, u.fname, u.lname, u.email, p.city, p.state, p.country
         FROM users u
         LEFT JOIN profiles p ON u.id = p.user_id
         WHERE u.fname LIKE ? OR u.lname LIKE ? OR u.email LIKE ?
            OR p.city LIKE ? OR p.state LIKE ?
         ORDER BY u.lname, u.fname",
        [$searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm]
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

    if (!empty($owner->fname)) $completedFields++;
    if (!empty($owner->lname)) $completedFields++;
    if (!empty($owner->email)) $completedFields++;
    if (!empty($owner->city)) $completedFields++;
    if (!empty($owner->state)) $completedFields++;
    if (!empty($owner->country)) $completedFields++;
    if (!empty($owner->lat) && !empty($owner->lon)) $completedFields++;

    return round(($completedFields / $totalFields) * 100, 1);
}
```

### Error Logging Standards

**All error conditions MUST use UserSpice logger integration for centralized error visibility and audit trails.**

#### Required Error Categories

- `SystemError` - File operations, environment issues, general system failures
- `ValidationError` - Input validation failures, invalid data, malformed requests
- `FileError` - Upload/processing failures, image operations, file system issues
- `DatabaseError` - Database operation failures, query errors, connection issues
- `CarErrors` - Car-related error conditions
- `CarActions` - Car-related user operations
- `DatabaseMaintenance` - All database maintenance operations

#### Error Logging Pattern

```php
// REQUIRED: Replace error_log() calls with UserSpice logger
try {
    // Operation that might fail
    $result = riskyOperation();
} catch (Exception $e) {
    logger($user->data()->id ?? 0, 'ErrorCategory', 'Descriptive error message: ' . $e->getMessage());
    throw new SpecificException('User-friendly message');
}

// For validation errors
if (empty($requiredField)) {
    logger($user->data()->id ?? 0, 'ValidationError', 'Required field missing: fieldName');
    throw new ValidationException('Field is required');
}
```

### Message Handling Standards

**All error and success messages MUST use the modern UserSpice session-based messaging system for consistent UX.**

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

This is a CRITICAL step that must NEVER be skipped when working on any code-related task.

### Release Notes Requirements

**ALWAYS update or create release notes when creating a pull request:**

- **Update existing release notes** if the target milestone already has a RELEASE_NOTES_V[VERSION].md file
- **Create new release notes** using the template at `docs/development/RELEASE_NOTES_TEMPLATE.md` if none exist
- **Follow the standardized structure**: Required Actions → User-Facing Changes → Admin-Facing Changes → Issues Resolved
- **Focus on impact and benefits**, not implementation details (those belong in GitHub issues)
- **Include clear testing instructions** in the Required Actions section for any manual steps needed post-deployment

**📋 See [RELEASE_NOTES_TEMPLATE.md](RELEASE_NOTES_TEMPLATE.md) for complete guidelines and structure**

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
    echo "Location: {$ownerData->city}, {$ownerData->state}";
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

**🚨 CRITICAL:** When deploying to production, always use the `prod` remote, NOT `origin`!

```bash
# Push code to PRODUCTION SERVER (live site)
git push prod main

# Push to GitHub for repository backup
git push origin main && git push origin --tags
```

**📋 See [DEPLOYMENT.md](DEPLOYMENT.md) for complete deployment procedures**

## 📊 Current Development Status

### ✅ Production Ready Features

- **Security**: Enterprise-grade security implementation with comprehensive CSRF protection
- **Testing**: 35/35 Playwright browser tests passing (100% success rate) plus comprehensive PHPUnit security test suite
- **PHP 8+ Compatibility**: Full compatibility with modern PHP versions, comprehensive null handling
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

- [DEVELOPMENT_WORKFLOW.md](DEVELOPMENT_WORKFLOW.md) - Detailed development processes
- [DEPLOYMENT.md](DEPLOYMENT.md) - Production deployment procedures
- [CODING_STANDARDS.md](CODING_STANDARDS.md) - Comprehensive coding standards
- [ENVIRONMENT.md](ENVIRONMENT.md) - Environment setup and configuration
