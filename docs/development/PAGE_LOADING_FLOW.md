# Page Loading Flow Reference

**Last Updated:** 2026-04-28
**Version:** 2.19.0

## Purpose

This document provides a comprehensive reference for understanding how files are
loaded and executed in a standard Elan Registry page. Use this as a guide when:

- Debugging initialization issues
- Understanding the order of execution
- Adding new functionality that depends on specific components
- Tracing where classes and functions are defined
- Optimizing page load performance
- Understanding UserSpice integration points

## Overview

Every standard page in the Elan Registry follows a consistent loading pattern
established by the UserSpice framework. The typical page structure is:

```php
<?php
require_once 'users/init.php';
require_once $abs_us_root . $us_url_root . 'users/includes/template/prep.php';

// Security check
if (!securePage($php_self)) {
    die();
}

// Page-specific logic here
?>

<!-- HTML content -->

<?php
require_once $abs_us_root . $us_url_root . 'users/includes/html_footer.php';
?>
```

This simple structure triggers the loading of 40-60+ PHP files in a specific order.

## Complete Loading Sequence

### Phase 1: Core Initialization (`users/init.php`)

**Purpose:** Establish database connection, load core framework, initialize session

```text
users/init.php
│
├─ 1.1. users/classes/class.autoloader.php (Line 5)
│   └─ Registers SPL autoloader for UserSpice core classes:
│       ├─ Loaded with absolute path (__DIR__ . '/classes/class.autoloader.php')
│       ├─ Recursively searches from users/classes/ directory
│       ├─ Handles classes: Input, Server, DB, User, Token, Validate, etc.
│       └─ Registered first in autoloader queue
│
├─ 1.2. Session Configuration (Lines 7-18)
│   ├─ Sets session name from config
│   ├─ Configures session cookie parameters
│   └─ Starts PHP session
│
├─ 1.3. Path Resolution (Lines 20-36)
│   └─ Determines $abs_us_root and $us_url_root by searching for z_us_root.php
│
├─ 1.4. usersc/classes/class.autoloader.php (Lines 38-43)
│   └─ Unified hybrid autoloader for all custom application classes:
│       ├─ Loaded directly in init.php after path variables are set
│       ├─ PSR-4 for namespaced classes (ElanRegistry\* namespace)
│       ├─ Recursive iterator for non-namespaced classes (Car, ElanRegistryOwner, etc.)
│       ├─ Loads 10+ core classes on demand
│       ├─ Loads 13+ custom exception classes on demand
│       └─ Appended to SPL autoloader queue (runs after UserSpice autoloader)
│
├─ 1.5. users/helpers/helpers.php (Line 45)
│   │
│   ├─ 1.5.1. usersc/includes/custom_functions.php
│   │   └─ Custom helper functions:
│   │       ├─ getUserWithProfile() - Combined user/profile data
│   │       ├─ isRegistryAdmin() - Check registry admin/editor permissions
│   │       ├─ getBaseUrl() - Get environment-aware base URL
│   │       ├─ getAdminEmails() - Get admin email addresses
│   │       ├─ getFeedbackEmail() - Get feedback form email
│   │       └─ Additional registry-specific utilities
│   │
│   ├─ 1.5.2. usersc/plugins/plugins.ini.php
│   │   └─ Parse plugin configuration to determine enabled plugins
│   │
│   ├─ 1.5.3. Plugin Override Files (for each enabled plugin)
│   │   └─ usersc/plugins/[plugin_name]/override.php
│   │
│   ├─ 1.5.4. UserSpice Helper Files
│   │   ├─ users/helpers/us_helpers.php
│   │   │   └─ Core UserSpice utility functions
│   │   │
│   │   ├─ users/helpers/encryption.php
│   │   │   └─ spiceEncrypt(), spiceDecrypt() functions
│   │   │
│   │   ├─ users/helpers/rate_limit_helpers.php
│   │   │   └─ Rate limiting for login attempts, API calls
│   │   │
│   │   ├─ users/helpers/class.treeManager.php
│   │   │   └─ Hierarchical menu management
│   │   │
│   │   ├─ users/helpers/menus.php
│   │   │   └─ Navigation menu generation
│   │   │
│   │   ├─ users/helpers/permissions.php
│   │   │   └─ Permission checking functions
│   │   │
│   │   ├─ users/helpers/users.php
│   │   │   └─ User management utilities
│   │   │
│   │   └─ users/helpers/dbmenu.php
│   │       └─ Database-driven menu system
│   │
│   ├─ 1.5.5. Deprecated Functions
│   │   └─ usersc/includes/deprecated/*.php
│   │       └─ All files in deprecated directory (glob pattern)
│   │
│   ├─ 1.5.6. Composer Autoloaders
│   │   ├─ usersc/vendor/autoload.php (if exists)
│   │   │   └─ Custom application Composer packages
│   │   │
│   │   └─ users/vendor/autoload.php (if exists)
│   │       └─ UserSpice framework Composer packages (includes phpdotenv)
│   │
│   ├─ 1.5.7. PHPMailer
│   │   └─ users/classes/phpmailer/PHPMailerAutoload.php
│   │       └─ Email functionality (PHPMailer\PHPMailer\PHPMailer)
│   │
│   ├─ 1.5.8. Version Information
│   │   └─ users/includes/user_spice_ver.php
│   │       └─ UserSpice version constants
│   │
│   └─ 1.5.9. Plugin Function Files (for each enabled plugin)
│       └─ usersc/plugins/[plugin_name]/functions.php
│           └─ Plugin-specific helper functions
│
├─ 1.6. Load Environment Variables (back in users/init.php, Lines 47-51)
│   └─ phpdotenv class (autoloaded via step 1.5.6 → vendor/autoload.php)
│       ├─ Load .env via Dotenv::createImmutable()->safeLoad()
│       └─ Environment variables populated into $_ENV:
│           └─ DB_HOST, DB_USER, DB_PASS, DB_NAME
│
├─ 1.7. Database Configuration
│   ├─ Read database credentials from $_ENV (set by phpdotenv in step 1.6)
│   ├─ Create $config array with connection parameters
│   └─ Initialize DB singleton instance ($db)
│
├─ 1.8. Session & Auto-Login Management
│   ├─ Check for remember-me cookie
│   ├─ Validate session token
│   └─ Auto-login if valid cookie exists
│
├─ 1.9. User Object Initialization
│   ├─ Create global $user object (User class instance)
│   ├─ Load current user data if logged in
│   └─ Set user permissions and roles
│
├─ 1.10. Timezone Configuration
│   └─ Set default timezone (from settings or UTC)
│
└─ 1.11. users/includes/loader.php
    │
    ├─ 1.11.1. Settings Database Query
    │   └─ Load all settings from database into $settings object
    │
    ├─ 1.11.2. usersc/includes/security_headers.php
    │   └─ Set HTTP security headers:
    │       ├─ Content-Security-Policy (CSP)
    │       ├─ Strict-Transport-Security (HSTS)
    │       ├─ X-Frame-Options
    │       ├─ X-Content-Type-Options
    │       ├─ Referrer-Policy
    │       └─ Permissions-Policy
    │
    ├─ 1.8.3. IP Ban Check
    │   └─ Query database for banned IPs and block if matched
    │
    ├─ 1.8.4. Language File Loading
    │   └─ users/lang/{language}.php
    │       └─ Load language strings based on user/system preference
    │
    ├─ 1.8.5. Conditional Security Enforcement
    │   ├─ users/includes/totp_enforcement.php (if TOTP enabled)
    │   │   └─ Two-factor authentication enforcement
    │   │
    │   └─ users/includes/oauth_enforcement.php (if OAuth enabled)
    │       └─ OAuth authentication enforcement
    │
    ├─ 1.8.6. Debug Mode Initialization
    │   └─ Enable debug logging if configured
    │
    ├─ 1.8.7. Site Status Checks
    │   ├─ Offline/maintenance mode check
    │   └─ Under construction mode check
    │
    ├─ 1.8.8. SSL Enforcement
    │   └─ Redirect to HTTPS if SSL required
    │
    ├─ 1.8.9. Password Reset Enforcement
    │   └─ Check if user must reset password
    │
    ├─ 1.11.10. Page Title Lookup
    │   └─ Query database for page title metadata
    │
    ├─ 1.11.11. Custom Loader (if exists)
    │   └─ usersc/includes/loader.php
    │       └─ Custom initialization code
    │
    └─ 1.11.12. Server Globals Initialization
        └─ usersc/includes/server_globals.php
            └─ Provides validated global variables:
                ├─ $scheme - HTTP scheme ('http' or 'https')
                ├─ $is_https - Boolean for HTTPS detection
                ├─ $host - Domain name (validated via Server::get)
                ├─ $method - HTTP request method (GET, POST, etc.)
                ├─ $request_uri - Request URI (sanitized)
                ├─ $current_url - Full URL (scheme://host/path?query)
                ├─ $current_origin - Origin only (scheme://host)
                ├─ $referer - HTTP referer (sanitized)
                ├─ $user_agent - User agent string (max 512 chars)
                ├─ $php_self - Current script path
                └─ $remote_addr - Client IP address
```

**Key Classes Available After Phase 1:**

**Server Environment Globals** (available after Phase 1.11.12):

- `$scheme` - HTTP scheme detection ('http' or 'https')
- `$is_https` - Boolean for quick HTTPS checks
- `$host` - Validated hostname (from HTTP_HOST)
- `$method` - HTTP request method (GET, POST, etc.)
- `$request_uri` - Sanitized request URI (path + query)
- `$current_url` - Full constructed URL (scheme://host/path?query)
- `$current_origin` - Scheme + host for CORS and redirects
- `$php_self` - Current script path (for securePage)
- `$remote_addr` - Client IP address (for logging)
- `$referer` - HTTP referer (sanitized, user-controlled)
- `$user_agent` - User agent string (sanitized, max 512 chars)

**UserSpice Core Classes** (auto-loaded via SPL autoloader from `users/classes/`):

- `DB` - Database singleton with query builder
- `User` - User authentication and management
- `Config` - Configuration management
- `Input` - Input sanitization and validation
- `Token` - CSRF token management
- `Cookie` - Cookie handling
- `Session` - Session management
- `Redirect` - URL redirection utilities
- `Validate` - Data validation
- `Hash` - Password hashing (bcrypt)

**Custom Application Classes** (auto-loaded in step 1.3.1 via
`usersc/classes/class.autoloader.php`):

**Core Classes:**

- `Car` - Car data model with CRUD operations (will become `ElanRegistry\Car`)
- `ElanRegistryOwner` - Owner data model (will become `ElanRegistry\Owner`)
- `CarView` - Car display utilities
- `Resize` - Image resizing and optimization
- `BackupManager` - Database backup management
- `ChassisValidator` - VIN/chassis validation
- `EmailTemplate` - Email template rendering
- `DocumentPortalTemplate` - Documentation index/card rendering (namespace:
  ElanRegistry\Documentation)
- `CarErrorMessages` - Centralized error message definitions

**Custom Exceptions** (`usersc/classes/exceptions/`):

- **Car exceptions:** `CarCreationException`, `CarNotFoundException`,
  `CarValidationException`, `CarTransferException`, `CarMergeException`,
  `CarDeletionException`
- **Owner exceptions:** `OwnerCreationException`, `OwnerNotFoundException`,
  `OwnerValidationException`, `OwnerUpdateException`
- **System exceptions:** `ImageProcessingException`, `BackupException`,
  `SchemaException`

**Autoloader Details:**

- **Location:** `usersc/classes/class.autoloader.php`
- **Type:** Hybrid namespace-aware autoloader (PSR-4 + recursive scan)
- **PSR-4 Support:** Handles namespaced classes (e.g., `ElanRegistry\Car`,
  `ElanRegistry\Documentation\DocumentPortalTemplate`)
- **Backward Compatibility:** Recursive iterator for non-namespaced classes
- **Performance:** Cached iterator (< 1ms), direct path for namespaced
  classes (< 0.1ms)
- **Prepended to SPL queue:** Custom classes loaded before UserSpice fallback

**Loading Mechanism:**

All classes in `usersc/classes/**/*.php` are loaded automatically on first
use. No explicit includes required in application code. The autoloader tries
PSR-4 first (for namespaced classes), then falls back to recursive filename
matching (for non-namespaced classes).

### Phase 2: Template Preparation (`users/includes/template/prep.php`)

**Purpose:** Load HTML structure, navigation, and display system messages

```text
users/includes/template/prep.php
│
├─ 2.1. Template Validation
│   ├─ Verify template exists in database settings
│   └─ Fallback to 'basic' template if invalid
│
├─ 2.2. usersc/templates/{template}/header.php
│   └─ HTML document structure (Customizer template):
│       ├─ <!DOCTYPE html> declaration
│       ├─ <head> section:
│       │   ├─ Meta tags (charset, viewport, description)
│       │   ├─ Title tag (from page metadata)
│       │   ├─ Favicon links
│       │   ├─ CSS includes:
│       │   │   ├─ Bootstrap 5.3.3 (cdnjs.cloudflare.com, SRI-hashed)
│       │   │   ├─ Font Awesome icons (self-hosted)
│       │   │   ├─ DataTables CSS (self-hosted)
│       │   │   ├─ Customizer child theme CSS (see CSS loading below)
│       │   │   └─ usersc/templates/{template}.css override (if exists)
│       │   └─ JavaScript includes (header):
│       │       ├─ jQuery (users/js/jquery.php → code.jquery.com CDN)
│       │       └─ Bootstrap bundle 5.3.3 (cdnjs.cloudflare.com, SRI-hashed)
│       └─ <body> opening tag
│
├─ 2.3. usersc/templates/{template}/navigation.php
│   └─ Site navigation (Customizer template):
│       ├─ Inline CSS for nav-item spacing
│       ├─ File-based navigation ($settings->navigation_type == 0):
│       │   ├─ Checks for file_nav_custom.php first (survives Spice Shaker upgrades)
│       │   ├─ Falls back to file_nav.php if file_nav_custom.php absent
│       │   └─ Error if neither exists
│       └─ Database-driven navigation ($settings->navigation_type != 0):
│           └─ Menu::display() — renders menu from DB (menu_override or id 1)
│
└─ 2.4. usersc/templates/{template}/container_open.php
    └─ Main content container:
        └─ <div class="container"> or page wrapper divs
```

**Template-Specific Files:**

The active template is `customizer` with the `elanregistry` child theme. Files loaded:

- `usersc/templates/customizer/header.php`
- `usersc/templates/customizer/navigation.php`
- `usersc/templates/customizer/container_open.php`

**Customizer CSS Loading Mechanism:**

The Customizer template uses a versioned/timestamped CSS system managed by `revision.php`:

1. `usersc/templates/customizer/assets/css/revision.php` is loaded by `header.php`. It defines:
   - `$css_revision` — the current base theme filename (e.g., `custom-bootstrap-20260427100615.css`)
   - `$child_themes` — an associative array mapping child theme names to their timestamped CSS files
     (e.g., `['elanregistry' => 'elanregistry-20260427100627.css', 'dashboard' => '...']`)

2. If `$child_theme` is set and matches a key in `$child_themes[]`, the corresponding
   file from `assets/child_themes/` is loaded. This is the normal path for app pages.

3. If no child theme is set or matched, the base theme from `assets/css/$css_revision` loads.

4. If `usersc/templates/{template}.css` exists at the template root, it is loaded last as a
   final override (rarely used).

**Setting `$child_theme`:**

`$child_theme` must be set **before** `prep.php` is included. Two patterns are used:

- **App pages** — use `usersc/includes/elanregistry_prep.php` instead of requiring `prep.php`
  directly.

  ```php
  require_once $abs_us_root . $us_url_root . 'usersc/includes/elanregistry_prep.php';
  // This file sets $child_theme = 'elanregistry' and then includes prep.php
  ```

- **Admin dashboard** — `usersc/includes/dashboard_overrides.php` sets
  `$child_theme = 'dashboard'` (and `$template_override = 'customizer'`).

**`file_nav_custom.php` vs `file_nav.php`:**

For file-based navigation (`$settings->navigation_type == 0`), the Customizer template
checks for `file_nav_custom.php` before `file_nav.php`. Use `file_nav_custom.php` for
site-specific navigation — Spice Shaker template upgrades overwrite `file_nav.php` but
leave `file_nav_custom.php` intact. The active custom navigation is at
`usersc/templates/customizer/file_nav_custom.php`.

### Phase 3: Page Content Execution

**Purpose:** Execute page-specific logic

```text
index.php (or other page-specific file)
│
├─ 3.1. Security Check
│   └─ securePage($php_self)
│       ├─ Check if page requires authentication
│       ├─ Verify user has required permissions
│       └─ die() if unauthorized
│
├─ 3.2. Page Logic
│   ├─ Process form submissions
│   ├─ Execute database queries
│   ├─ Instantiate model classes (Car, ElanRegistryOwner, etc.)
│   │   └─ All classes auto-loaded on first use (no requires needed)
│   ├─ Business logic execution
│   └─ Prepare data for display
│
└─ 3.3. HTML Output
    └─ Render page-specific content within template structure
```

**Common Patterns in Page Content:**

```php
// Database queries using DB singleton
$db = DB::getInstance();
$results = $db->query("SELECT * FROM cars WHERE id = ?", [$carId])->results();

// Using model classes
$car = new Car($carId);
$data = $car->data();

// CSRF protection for forms
$token = Token::generate();

// Input sanitization
$safeInput = Input::get('field_name');

// User data access
if ($user->isLoggedIn()) {
    $userId = $user->data()->id;
}

// Permission checks
if (!hasPerm([2], $userId)) {
    die('Access denied');
}
```

### Phase 4: Footer & Cleanup (`users/includes/html_footer.php`)

**Purpose:** Close HTML structure, load toast system, footer scripts, plugin hooks

```text
users/includes/html_footer.php
│
├─ 4.1. usersc/templates/{template}/footer.php
│   │   (e.g., usersc/templates/ElanRegistry/footer.php)
│   │
│   ├─ 4.1.1. usersc/templates/{template}/container_close.php
│   │   └─ Close main content container divs
│   │
│   ├─ 4.1.2. users/includes/page_footer.php
│   │   │   *** Toast notification system loaded here ***
│   │   │
│   │   ├─ 4.1.2a. usersc/includes/pre_footer.php (if exists)
│   │   │   └─ Pre-footer hook (v2.14.0+):
│   │   │       └─ Includes toast container HTML + CSS:
│   │   │           └─ usersc/includes/system_messages_header.php
│   │   │               (or users/ fallback)
│   │   │               ├─ #us-toast-container div (position-fixed)
│   │   │               ├─ Toast positioning (default: top-right)
│   │   │               ├─ z-index: 1090 (Bootstrap 5 toast layer)
│   │   │               └─ Toast CSS (color bars, close button, layout)
│   │   │
│   │   └─ 4.1.2b. usersc/includes/system_messages_footer.php
│   │       (or users/ fallback)
│   │       └─ Toast notification JavaScript:
│   │           ├─ userSpiceMessage() - Core toast creation function
│   │           ├─ Window globals: usSuccess(), usError(), usInfo(),
│   │           │   usPrimary(), usDark()
│   │           ├─ HTML sanitization (allowlisted tags only)
│   │           ├─ Bootstrap Toast API integration (6s auto-hide)
│   │           └─ Emit PHP session messages as toasts on page load
│   │
│   ├─ 4.1.3. Footer HTML (copyright, version, links)
│   │
│   └─ 4.1.4. JavaScript includes (footer):
│       ├─ DataTables JS
│       ├─ Chart.js
│       ├─ Google Maps API
│       ├─ Page-specific JavaScript
│       └─ Custom application scripts
│
├─ 4.2. Plugin Footer Hooks (for each enabled plugin)
│   └─ usersc/plugins/{plugin_name}/footer.php
│       └─ Plugin-specific footer content and scripts
│
├─ 4.3. Custom Footer (if exists)
│   └─ usersc/includes/footer.php
│       ├─ ElanRegistryAPI client (app/assets/js/api-client.js)
│       │   ├─ Global window.ElanRegistryAPI instance with auto CSRF injection
│       │   └─ NotificationHelper (delegates to usSuccess/usError/usInfo)
│       └─ Custom footer code, analytics, tracking
│
└─ 4.4. UserSpice Footer Scripts
    └─ Inline JavaScript for:
        ├─ Error message auto-dismiss timer
        ├─ Bootstrap popover initialization
        └─ Bootstrap tooltip initialization
```

**Toast System Architecture (v2.14.0+):**

The toast notification system has two components loaded in sequence during
Phase 4.1.2:

1. **Container (system_messages_header.php)** — outputs the `#us-toast-container`
   div with positioning CSS. Loaded via `pre_footer.php` hook. Without this,
   toast JS falls back to `document.body` and toasts render unstyled/unpositioned.

2. **JavaScript (system_messages_footer.php)** — defines `usSuccess()`,
   `usError()`, etc. Looks for `#us-toast-container`; uses `document.body` as
   fallback if container is missing.

Both files support UserSpice's custom override pattern: if the file exists in
`usersc/includes/`, it is used instead of the `users/includes/` version.

**Important:** `NotificationHelper.show()` in `api-client.js` delegates to the
UserSpice toast functions (`usSuccess`, `usError`, `usInfo`). There is a single
unified toast system — do not create separate notification containers.

## Critical Global Variables

After initialization, these global variables are available throughout
the application:

| Variable          | Type       | Purpose                               | After          |
|-------------------|------------|---------------------------------------|----------------|
| `$db`             | `DB`       | Database singleton instance           | Phase 1.4      |
| `$user`           | `User`     | Current user object                   | Phase 1.6      |
| `$settings`       | `stdClass` | Site settings from database           | Phase 1.8      |
| `$abs_us_root`    | `string`   | Absolute filesystem root path         | Phase 1        |
| `$us_url_root`    | `string`   | Relative URL root path                | Phase 1        |
| `$lang`           | `array`    | Language strings                      | Phase 1.8      |
| `$usplugins`      | `array`    | Enabled plugins config                | Phase 1.3      |
| `$config`         | `array`    | Database and system config            | Phase 1.4      |
| `$scheme`         | `string`   | HTTP scheme ('http' or 'https')       | Phase 1.11.12  |
| `$is_https`       | `bool`     | Whether request is HTTPS              | Phase 1.11.12  |
| `$host`           | `string`   | Validated hostname (no port)          | Phase 1.11.12  |
| `$method`         | `string`   | HTTP request method (GET, POST, etc.) | Phase 1.11.12  |
| `$request_uri`    | `string`   | Sanitized request URI (path + query)  | Phase 1.11.12  |
| `$current_url`    | `string`   | Full URL (scheme://host/path?query)   | Phase 1.11.12  |
| `$current_origin` | `string`   | Origin (scheme://host) for CORS       | Phase 1.11.12  |
| `$php_self`       | `string`   | Current script path (for securePage)  | Phase 1.11.12  |
| `$remote_addr`    | `string`   | Client IP address                     | Phase 1.11.12  |
| `$referer`        | `string`   | HTTP referer (user-controlled)        | Phase 1.11.12  |
| `$user_agent`     | `string`   | User agent string (max 512 chars)     | Phase 1.11.12  |

### Server Globals Usage Examples

Use the validated server globals instead of direct `$_SERVER` access:

```php
// HTTPS check
if ($is_https) {
    // secure context
}

// Method check for form handling
if ($method === 'POST') {
    // process form
}

// Build redirect URL
$redirect = $current_origin . '/app/cars/details.php?id=' . $carId;

// Log with IP
logger($userId, LogCategories::LOG_CATEGORY_LOGIN, "Login from $remote_addr");

// Security check (already done by framework, but available)
securePage($php_self);
```

**Do NOT access `$_SERVER` directly.** Use the globals above or `Server::get()`
for any values not covered by the globals.

## Common Integration Points

### Adding Custom Initialization Code

#### Option 1: Custom Loader

Recommended for site-wide initialization:

```php
// File: usersc/includes/loader.php
// Executes during Phase 1.8.11, after all core initialization

// Example: Set custom timezone
date_default_timezone_set('America/Los_Angeles');

// Example: Initialize custom logging
require_once $abs_us_root . $us_url_root . 'usersc/includes/logger.php';
```

#### Option 2: Custom Functions

Recommended for reusable functions:

```php
// File: usersc/includes/custom_functions.php
// Executes during Phase 1.3.1, early in initialization

function myCustomHelper($param) {
    // Your code here
}
```

### Adding Custom Security Headers

```php
// File: usersc/includes/security_headers.php
// Executes during Phase 1.8.2

// Add custom CSP directives
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-{$usespice_nonce}' cdn.example.com;");
```

### Adding Custom Footer Scripts

```php
// File: usersc/includes/footer.php
// Executes during Phase 4.3
?>
<script nonce="<?=htmlspecialchars($usespice_nonce ?? '')?>">
// Your custom JavaScript
console.log('Custom footer script loaded');
</script>
```

### Adding Plugin Functionality

Plugins can hook into multiple points:

#### 1. Plugin Override

File: `usersc/plugins/{plugin}/override.php`

- Executes: Phase 1.3.3
- Purpose: Override core functions

#### 2. Plugin Functions

File: `usersc/plugins/{plugin}/functions.php`

- Executes: Phase 1.3.9
- Purpose: Add new helper functions

#### 3. Plugin Footer

File: `usersc/plugins/{plugin}/footer.php`

- Executes: Phase 4.2
- Purpose: Add footer scripts/content

## Performance Considerations

### File Count

A typical page load includes:

- **Fixed files**: ~30-40 core files
- **Plugin files**: 2-3 files per enabled plugin
- **Deprecated files**: Variable (0-10+)
- **Template files**: 5-7 files
- **Total**: 40-60+ PHP files per page load

### Optimization Strategies

#### 1. Disable Unused Plugins

- Edit `usersc/plugins/plugins.ini.php`
- Set unused plugins to `0`

#### 2. Remove Deprecated Files

- Clean up `usersc/includes/deprecated/` directory
- Only keep files if truly needed for backward compatibility

#### 3. Use OPcache

- Enable PHP OPcache for production
- Reduces parsing overhead significantly

#### 4. Optimize Autoloader

- SPL autoloader only loads classes when used
- Avoid unnecessary class instantiation

#### 5. Database Query Optimization

- Settings are loaded once per page (Phase 1.8.1)
- Use database caching for frequently accessed data

## Troubleshooting

### Common Issues and Solutions

#### Issue: "Class not found" error

- **Cause**: Class file not in autoloader path
- **Solution**: Ensure class is in `users/classes/` or explicitly include from `usersc/classes/`
- **Check**: SPL autoloader registration in `users/classes/class.autoloader.php`
- **Note**: Remember that `usersc/classes/` requires explicit includes

#### Issue: "Function not defined" error

- **Cause**: Helper file not loaded or loaded too late
- **Solution**: Add function to `usersc/includes/custom_functions.php`
- **Alternative**: Add to appropriate `users/helpers/*.php` file

#### Issue: Global variable not available

- **Cause**: Accessing variable before initialization phase
- **Solution**: Check variable availability in phase diagram above
- **Debug**: Add `var_dump($GLOBALS)` to see available variables

#### Issue: Headers already sent

- **Cause**: Output before header() calls in Phase 1.8.2
- **Solution**: Remove whitespace/output before `<?php` tags
- **Check**: `usersc/includes/custom_functions.php` for early output

#### Issue: Session not persisting

- **Cause**: Session configuration issues in Phase 1.2
- **Solution**: Check session cookie settings in `users/init.php`
- **Debug**: Verify `session.cookie_path` and `session.cookie_domain`

### Debug Mode

Enable debug mode to see detailed execution information:

1. Set in database: `UPDATE settings SET debug = 2;`
2. Or for admin only: `UPDATE settings SET debug = 1;`

Debug mode shows:

- File paths in dump() calls
- Line numbers for debugging
- Detailed error messages

## Related Documentation

- **[ARCHITECTURE.md](ARCHITECTURE.md)** - Overall system architecture
- **[INTEGRATION.md](INTEGRATION.md)** - UserSpice integration patterns
- **[CLASSES.md](CLASSES.md)** - Custom application classes
- **[CODING_STANDARDS.md](CODING_STANDARDS.md)** - Coding standards
- **[QUICK_REFERENCE.md](QUICK_REFERENCE.md)** - Common tasks lookup

## Revision History

| Version | Date | Changes |
| --- | --- | --- |
| 1.0.0 | 2026-01-09 | Initial documentation of page loading flow |
| 1.1.0 | 2026-01-28 | Add server globals to critical variables table |
| 1.2.0 | 2026-01-31 | Fix Phase 2.5/4 toast system documentation; document pre_footer.php hook, system_messages_header/footer loading sequence, and unified toast architecture (Issue #536) |
| 1.3.0 | 2026-04-28 | Update Phase 2 for Customizer template (Bootstrap 5, child themes, file_nav_custom.php); document CSS loading mechanism, revision.php, and $child_theme setup (v2.19.0) |

---

**Note:** This document reflects the loading sequence for UserSpice 6 and Elan
Registry v2.19.0. File paths and loading order may vary in different versions.
