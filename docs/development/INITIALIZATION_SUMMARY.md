# Page Initialization Quick Summary

Fast reference for understanding how pages load in the Elan Registry.

> For complete details, see [PAGE_LOADING_FLOW.md](PAGE_LOADING_FLOW.md)

---

## Standard Page Pattern

Every page follows this structure:

```php
<?php
// 1. Initialize UserSpice and load core framework
require_once 'users/init.php';

// 2. Load template header
require_once $abs_us_root . $us_url_root . 'users/includes/template/prep.php';

// 3. Protect page from unauthorized access
if (!securePage($php_self)) {
    die();
}

// 4. Your page logic here
$car = new Car($carId);
?>

<!-- 5. HTML content -->

<?php
// 6. Load template footer (must be last)
require_once $abs_us_root . $us_url_root . 'users/includes/html_footer.php';
?>
```

---

## What `users/init.php` Loads

After `require_once 'users/init.php'`, these are available globally:

### Database

```php
$db = DB::getInstance();
$result = $db->query('SELECT * FROM cars WHERE id = ?', [$carId]);
```

### Current User

```php
$user = new User();
if ($user->isLoggedIn()) {
    echo $user->data()->username;
}
```

### Validation

```php
$validate = new Validate();
$input = new Input();
```

### Security

```php
Token::generate();        // Generate CSRF token
Token::check($_POST['csrf']); // Validate CSRF token
Hash::make('password');   // Hash password
```

### Settings & Configuration

```php
$settings->{'sitename'};  // Site configuration
```

### Server Globals (v2.13.0+)

```php
$is_https;        // true if HTTPS, false if HTTP
$method;          // GET, POST, etc.
$current_url;     // Full URL with query string
$current_origin;  // Origin (scheme://host)
$host;            // Hostname
$php_self;        // Script path (use in securePage)
$remote_addr;     // Client IP
```

### Custom Functions

```php
$owner = getUserWithProfile($userId);     // User + profile
isRegistryAdmin($userId);                 // Check permissions
currentUserId();                          // Get logged-in user's ID
logger($uid, LogCategories::LOG_CATEGORY_*, 'message');  // Audit log
```

---

## Common Tasks by Lifecycle Phase

### Before `users/init.php`

- **Not available**: Database, User object, functions, validation classes
- **Available**: Raw PHP, superglobals ($_GET, $_POST)
- **Use case**: Very rare - usually not needed

### After `users/init.php` (Before `prep.php`)

```php
require_once 'users/init.php';

// Now available: $db, $user, validation, custom functions
$data = $db->query('SELECT * FROM cars WHERE id = ?', [$carId])->first();
```

### After `prep.php` (Before securePage)

```php
require_once 'users/includes/template/prep.php';

// Template hooks are loaded, but page not yet protected
// Use for: Public pages that don't require login
```

### After `securePage()` (Protected pages)

```php
if (!securePage($php_self)) { die(); }

// Page is protected, user is authenticated
// Preferred location for page logic
```

### Before `html_footer.php` (HTML content)

```php
?>
<h1><?php echo $page_title; ?></h1>
<p>Your HTML content</p>
<?php
require_once 'users/includes/html_footer.php';
```

---

## Loading Order Reference

```text
users/init.php
├─ AutoLoader (UserSpice core classes)
├─ Database Connection
├─ Session Management
├─ User Object
├─ UserSpice Plugins
├─ Custom Loader (usersc/includes/loader.php)
├─ Custom Functions (usersc/includes/custom_functions.php)
├─ Custom Classes (usersc/classes/ - PSR-4 autoload)
├─ Server Globals (usersc/includes/server_globals.php)
└─ [All globals ready for use]

prep.php
├─ Template Header Setup
├─ Navigation System
└─ Menu Loading

[Your page logic here]

html_footer.php
├─ Template Footer
├─ Footer Hooks
└─ [Page ends]
```

---

## Key Rules

### ✅ DO

- **Call `users/init.php` first** - All framework features depend on it
- **Use validated server globals** - `$method`, `$host`, `$remote_addr` (not `$_SERVER`)
- **Call `securePage()` early** - Protect pages immediately after `prep.php`
- **Load footer last** - Must be included at the very end of page
- **Use LogCategories constants** - Never hardcode log strings
- **Check file paths** - Use `$abs_us_root` and `$us_url_root` for absolute paths

### ❌ DON'T

- **Don't skip `users/init.php`** - Framework won't be initialized
- **Don't access `$_SERVER` directly** - Use validated server globals
- **Don't call database before init.php** - DB connection isn't ready
- **Don't echo before `prep.php` on standard pages** - May break template
- **Don't forget footer** - Template won't complete properly
- **Don't modify init.php** - Use customization hooks (loader.php, plugins)

---

## New Page Checklist

Creating a new secure page?

```php
<?php
// ✅ 1. Initialize framework
require_once '../../users/init.php';

// ✅ 2. Load template header
require_once $abs_us_root . $us_url_root . 'users/includes/template/prep.php';

// ✅ 3. Protect the page
if (!securePage($php_self)) {
    die();
}

// ✅ 4. Page logic
$carId = dbInt(Input::get('car_id'));
$car = new Car($carId);
?>

<!-- ✅ 5. HTML/Template content -->

<?php
// ✅ 6. Always include footer
require_once $abs_us_root . $us_url_root . 'users/includes/html_footer.php';
?>
```

---

## Troubleshooting

| Problem | Cause | Solution |
| --- | --- | --- |
| "Call to undefined function logger()" | logger() called before init.php | Move to after init.php |
| "Class 'DB' not found" | DB not loaded yet | Ensure init.php is first |
| "Variable not defined: $db" | Database not initialized | Check init.php is included |
| `securePage()` redirects to login | Page not registered in UserSpice admin | Register page; add directory to z_us_root.php |
| Template not rendering | Footer not included | Add html_footer.php at end |
| Custom function not found | Custom functions not loaded | Verify loader.php exists and runs |
| Server globals undefined | Globals not initialized | Ensure server_globals.php loaded (auto via init.php) |

---

## Performance Notes

- **Database**: First connection happens in init.php (~20-30ms)
- **Total init.php time**: ~50-100ms depending on plugins
- **Per-page overhead**: negligible after caching
- **Optimization**: Use custom loader to lazy-load expensive features

---

## AJAX Endpoints

Use the same pattern for AJAX endpoints in `/app/action/`:

```php
<?php
require_once '../../users/init.php';

// Check authentication
if (!$user->isLoggedIn()) {
    ApiResponse::unauthorized('Login required')->send();
}

// AJAX endpoints typically skip template files
// Proceed directly to logic and return JSON response

try {
    // Get input
    $carId = dbInt(Input::get('car_id'));

    // Process
    $car = new Car($carId);

    // Return response
    ApiResponse::success('Success')
        ->withData('car', $car->data())
        ->withLogging($user->data()->id, LogCategories::LOG_CATEGORY_CAR_ACTIONS, 'Fetched car')
        ->send();

} catch (Exception $e) {
    ApiResponse::serverError('An error occurred')
        ->send();
}
```

---

## Related Documentation

- **[PAGE_LOADING_FLOW.md](PAGE_LOADING_FLOW.md)** - Complete detailed reference (735 lines)
- **[INTEGRATION.md](../wiki/Integration.md)** - UserSpice integration (wiki)
- **[QUICK_REFERENCE.md](QUICK_REFERENCE.md)** - Code patterns and examples
- **[USERSPICE_FUNCTIONS.md](USERSPICE_FUNCTIONS.md)** - UserSpice framework API

---

**Last Updated:** v2.15.0
**Applies to:** v2.13.0+
**Quick reference for:** Standard pages, AJAX endpoints, initialization order
