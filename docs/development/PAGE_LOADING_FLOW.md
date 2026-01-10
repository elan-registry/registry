# Page Loading Flow Reference

**Last Updated:** 2026-01-09
**Version:** 2.10.2

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
if (!securePage($_SERVER['PHP_SELF'])) {
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
├─ 1.1. users/classes/class.autoloader.php
│   └─ Registers SPL autoloader for classes in:
│       - users/classes/**/*.php (UserSpice core classes only)
│       - Recursively searches from users/classes/ directory
│
├─ 1.2. Session Configuration
│   ├─ Sets session name from config
│   ├─ Configures session cookie parameters
│   └─ Starts PHP session
│
├─ 1.3. users/helpers/helpers.php
│   │
│   ├─ 1.3.1. usersc/includes/custom_functions.php
│   │   ├─ vendor/autoload.php (Composer dependencies)
│   │   │   └─ johnathanmiller/secure-env-php
│   │   │
│   │   ├─ .env.enc & .env.key (Encrypted environment variables)
│   │   │   ├─ Database credentials
│   │   │   ├─ API keys (Google Maps, etc.)
│   │   │   └─ Application secrets
│   │   │
│   │   ├─ usersc/classes/class.autoloader.php
│   │   │   └─ Unified hybrid autoloader for all custom classes:
│   │   │       ├─ PSR-4 for namespaced classes (fast path)
│   │   │       ├─ Recursive iterator for non-namespaced classes
│   │   │       ├─ Loads 10+ core classes on demand
│   │   │       ├─ Loads 13 custom exception classes on demand
│   │   │       └─ Prepended to SPL autoloader queue
│   │   │
│   │   └─ Custom helper functions:
│   │       ├─ getUserWithProfile() - Combined user/profile data
│   │       ├─ getOwnerName() - Format owner display names
│   │       ├─ formatPhoneNumber() - US phone formatting
│   │       └─ Additional registry-specific utilities
│   │
│   ├─ 1.3.2. usersc/plugins/plugins.ini.php
│   │   └─ Parse plugin configuration to determine enabled plugins
│   │
│   ├─ 1.3.3. Plugin Override Files (for each enabled plugin)
│   │   └─ usersc/plugins/[plugin_name]/override.php
│   │
│   ├─ 1.3.4. UserSpice Helper Files
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
│   ├─ 1.3.5. Deprecated Functions
│   │   └─ usersc/includes/deprecated/*.php
│   │       └─ All files in deprecated directory (glob pattern)
│   │
│   ├─ 1.3.6. Composer Autoloaders
│   │   ├─ usersc/vendor/autoload.php (if exists)
│   │   │   └─ Custom application Composer packages
│   │   │
│   │   └─ users/vendor/autoload.php (if exists)
│   │       └─ UserSpice framework Composer packages
│   │
│   ├─ 1.3.7. PHPMailer
│   │   └─ users/classes/phpmailer/PHPMailerAutoload.php
│   │       └─ Email functionality (PHPMailer\PHPMailer\PHPMailer)
│   │
│   ├─ 1.3.8. Version Information
│   │   └─ users/includes/user_spice_ver.php
│   │       └─ UserSpice version constants
│   │
│   └─ 1.3.9. Plugin Function Files (for each enabled plugin)
│       └─ usersc/plugins/[plugin_name]/functions.php
│           └─ Plugin-specific helper functions
│
├─ 1.4. Database Configuration
│   ├─ Load encrypted database credentials from environment
│   ├─ Create $config array with connection parameters
│   └─ Initialize DB singleton instance ($db)
│
├─ 1.5. Session & Auto-Login Management
│   ├─ Check for remember-me cookie
│   ├─ Validate session token
│   └─ Auto-login if valid cookie exists
│
├─ 1.6. User Object Initialization
│   ├─ Create global $user object (User class instance)
│   ├─ Load current user data if logged in
│   └─ Set user permissions and roles
│
├─ 1.7. Timezone Configuration
│   └─ Set default timezone (from settings or UTC)
│
└─ 1.8. users/includes/loader.php
    │
    ├─ 1.8.1. Settings Database Query
    │   └─ Load all settings from database into $settings object
    │
    ├─ 1.8.2. usersc/includes/security_headers.php
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
    ├─ 1.8.10. Page Title Lookup
    │   └─ Query database for page title metadata
    │
    └─ 1.8.11. Custom Loader (if exists)
        └─ usersc/includes/loader.php
            └─ Custom initialization code
```

**Key Classes Available After Phase 1:**

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
- `MarkdownParser` - Markdown to HTML conversion (namespace:
  ElanRegistry\Documentation)
- `DocumentConfig` - Document metadata management (namespace:
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
  `ElanRegistry\Documentation\MarkdownParser`)
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
│   └─ HTML document structure:
│       ├─ <!DOCTYPE html> declaration
│       ├─ <head> section:
│       │   ├─ Meta tags (charset, viewport, description)
│       │   ├─ Title tag (from page metadata)
│       │   ├─ Favicon links
│       │   ├─ CSS includes:
│       │   │   ├─ Bootstrap 5.x (CDN or local)
│       │   │   ├─ Font Awesome icons
│       │   │   ├─ DataTables CSS
│       │   │   ├─ Custom theme CSS
│       │   │   └─ Page-specific CSS
│       │   └─ JavaScript includes (header):
│       │       ├─ jQuery
│       │       ├─ Bootstrap bundle
│       │       └─ CSP nonce for inline scripts
│       └─ <body> opening tag
│
├─ 2.3. usersc/templates/{template}/navigation.php
│   └─ Site navigation:
│       ├─ Navigation bar structure
│       ├─ Logo and branding
│       ├─ Main menu items (database-driven)
│       ├─ User menu (login/logout, account)
│       └─ Mobile responsive menu toggle
│
├─ 2.4. usersc/templates/{template}/container_open.php
│   └─ Main content container:
│       └─ <div class="container"> or page wrapper divs
│
└─ 2.5. System Messages
    └─ usersc/includes/system_messages_header.php (or users/ fallback)
        └─ Display error and success messages:
            ├─ Session-based messages (usError, usSuccess)
            ├─ Bootstrap alert styling
            └─ Auto-dismiss after configured time
```

**Template-Specific Files:**

Depending on `$settings->template` value (typically 'ElanRegistry'), loads from:

- `usersc/templates/ElanRegistry/header.php`
- `usersc/templates/ElanRegistry/navigation.php`
- `usersc/templates/ElanRegistry/container_open.php`

### Phase 3: Page Content Execution

**Purpose:** Execute page-specific logic

```text
index.php (or other page-specific file)
│
├─ 3.1. Security Check
│   └─ securePage($_SERVER['PHP_SELF'])
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

**Purpose:** Close HTML structure, load footer scripts, execute plugin hooks

```text
users/includes/html_footer.php
│
├─ 4.1. usersc/templates/{template}/footer.php
│   └─ Footer content:
│       ├─ Footer HTML (copyright, links, etc.)
│       ├─ Closing main container divs
│       ├─ JavaScript includes (footer):
│       │   ├─ DataTables JS
│       │   ├─ Chart.js
│       │   ├─ Google Maps API
│       │   ├─ Page-specific JavaScript
│       │   └─ Custom application scripts
│       └─ </body> and </html> closing tags
│
├─ 4.2. Plugin Footer Hooks (for each enabled plugin)
│   └─ usersc/plugins/{plugin_name}/footer.php
│       └─ Plugin-specific footer content and scripts
│
├─ 4.3. Custom Footer (if exists)
│   └─ usersc/includes/footer.php
│       └─ Custom footer code, analytics, tracking
│
└─ 4.4. UserSpice Footer Scripts
    └─ Inline JavaScript for:
        ├─ Error message auto-dismiss timer
        ├─ Bootstrap popover initialization
        └─ Bootstrap tooltip initialization
```

## Critical Global Variables

After initialization, these global variables are available throughout
the application:

| Variable       | Type       | Purpose                       | After     |
|----------------|------------|-------------------------------|-----------|
| `$db`          | `DB`       | Database singleton instance   | Phase 1.4 |
| `$user`        | `User`     | Current user object           | Phase 1.6 |
| `$settings`    | `stdClass` | Site settings from database   | Phase 1.8 |
| `$abs_us_root` | `string`   | Absolute filesystem root path | Phase 1   |
| `$us_url_root` | `string`   | Relative URL root path        | Phase 1   |
| `$lang`        | `array`    | Language strings              | Phase 1.8 |
| `$usplugins`   | `array`    | Enabled plugins config        | Phase 1.3 |
| `$config`      | `array`    | Database and system config    | Phase 1.4 |

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
- **[PROJECT_CONVENTIONS.md](PROJECT_CONVENTIONS.md)** - Coding standards
- **[QUICK_REFERENCE.md](QUICK_REFERENCE.md)** - Common tasks lookup

## Revision History

| Version | Date       | Changes                                    |
|---------|------------|--------------------------------------------|
| 1.0.0   | 2026-01-09 | Initial documentation of page loading flow |

---

**Note:** This document reflects the loading sequence for UserSpice 5.x and Elan
Registry v2.10.2. File paths and loading order may vary in different versions.
