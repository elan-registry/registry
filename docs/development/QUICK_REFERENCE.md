# Quick Reference Guide

Quick reference for common development tasks and commands. For detailed
information, see the linked documentation.

## Essential Commands

### Testing

```bash
composer test:quick        # Unit tests only (<30s)
composer test:medium       # Unit + Integration (<2min)
composer test:full         # All PHP tests
composer test:coverage     # Coverage report

npm run playwright:test    # UI tests (requires setup)
```

### Pre-commit Quality Checks

```bash
./scripts/setup-git-hooks.sh   # Setup once (RECOMMENDED)
composer phpcs                  # Manual coding standards check
```

### Git & Deployment

```bash
git push origin main && git push origin --tags   # GitHub
git push test main                                # Staging
git push prod main && git push prod --tags        # Production
```

See [DEPLOYMENT.md](DEPLOYMENT.md) for complete release procedures.

## Common File Locations

```text
/app/                      # Main application pages
  /cars/                   # Car listing, details, edit
  /admin/                  # Admin interfaces
  /reports/                # Statistics and reports
  /contact/                # Owner contact functionality
/users/                    # UserSpice authentication
/usersc/                   # UserSpice customizations
  /classes/                # Custom PHP classes
  /includes/               # Custom functions
  /plugins/                # Custom plugins
/tests/                    # PHPUnit and Playwright tests
/docs/                     # Documentation
```

### Key Files

```text
z_us_root.php              # Root path configuration (add new dirs here)
users/init.php             # UserSpice initialization
.env.enc                   # Encrypted environment variables
VERSION                    # Current version number
```

## Key Patterns (Quick Summary)

**Database Access:**
`$db = DB::getInstance()` → `$db->query("SQL", [$params])->results()`
See [DATABASE.md](DATABASE.md)

**User/Profile Access:**
`$owner = getUserWithProfile($userId)` → `$owner->fname`, `$owner->city`
See [CLASSES.md](CLASSES.md)

**Error Handling:**
Backend: `ApiResponse::success()`, `ApiResponse::validationError()`
Frontend: `new ElanRegistryAPI()` → `api.post()` / `api.get()`
See [ERROR_HANDLING.md](ERROR_HANDLING.md)

**Security:**
`securePage($php_self)` on all protected pages, `Token::generate()` / `Token::check()` for CSRF
See [CODING_STANDARDS.md](CODING_STANDARDS.md)

**Logging:**
`logger($userId, LogCategories::LOG_CATEGORY_*, 'message')`
See [LOG_CATEGORIES.md](LOG_CATEGORIES.md)

**Server Globals (v2.13.0+):**
Use `$is_https`, `$host`, `$method`, `$current_url`, `$current_origin`,
`$php_self`, `$remote_addr` instead of `$_SERVER`. For values not covered,
use `Server::get('KEY', 'default')`.
See [PAGE_LOADING_FLOW.md](PAGE_LOADING_FLOW.md)

**New PHP Directories:**
Add path to `$path` array in `/z_us_root.php`, register pages in UserSpice admin
See [GitHub Wiki: UserSpice Integration Guide](https://github.com/jimboone/elan-registry/wiki/Integration)

## Custom Functions Available on All Pages

These functions are loaded globally and available on every page:

| Function | Returns | Purpose | Example |
|----------|---------|---------|---------|
| `getUserWithProfile($userId)` | object | Get user + profile data in one query | `$owner = getUserWithProfile(5)` |
| `isRegistryAdmin($userId)` | bool | Check if user has admin/editor perms | `if (isRegistryAdmin()) { ... }` |
| `getBaseUrl()` | string | Get app base URL (environment-aware) | `$base = getBaseUrl()` |
| `getAdminEmails()` | string | Get comma-separated admin emails | `$emails = getAdminEmails()` |
| `getFeedbackEmail()` | string | Get feedback form email address | `$email = getFeedbackEmail()` |
| `dbInt($value)` | int | Cast database value to int safely | `$id = dbInt($row->id)` |
| `dbIntOrNull($value)` | ?int | Cast to nullable int | `$id = dbIntOrNull($row->id)` |
| `currentUserId()` | int | Get logged-in user's ID (throws if not) | `$uid = currentUserId()` |
| `logger($userId, $type, $note, $metadata)` | bool | Log user action for audit trail | `logger($uid, LogCategories::LOG_CATEGORY_LOGIN, 'User logged in')` |

**Examples:**

```php
// Get owner data with profile information
$owner = getUserWithProfile($userId);
echo $owner->fname . " from " . $owner->city;

// Check admin status
if (isRegistryAdmin()) {
    echo '<a href="admin">Admin Panel</a>';
}

// Log an action
logger(currentUserId(), LogCategories::LOG_CATEGORY_CAR_CREATE, 'Created new car');
```

See [USERSPICE_QUICK_LOOKUP.md](USERSPICE_QUICK_LOOKUP.md) for additional UserSpice functions.

## Troubleshooting

| Problem | Solution |
| --- | --- |
| `securePage()` redirecting to login | Register page in UserSpice admin; add dir to `z_us_root.php` `$path` array |
| Database triggers not firing | Only `cars` table has triggers; other tables use app-level logging |
| Tests failing | Check PHP 8.1+; run `composer install` && `npm install` |
| File modified by hooks | Re-read file; check `.markdownlint.json` |

## Documentation Index

```text
CLAUDE.md                  # Start here - AI assistant guide
docs/development/
  INSTALLATION.md          # Setup and installation
  DATABASE.md              # Database schema
  CODING_STANDARDS.md      # Coding standards
  ERROR_HANDLING.md        # Error handling patterns
  LOG_CATEGORIES.md        # Logging categories (140+)
  CLASSES.md               # Application classes
  DEPLOYMENT.md            # Release and deployment
Wiki (GitHub):
  Architecture             # System architecture (github.com/jimboone/elan-registry/wiki/Architecture)
  UserSpice Integration    # UserSpice integration (github.com/jimboone/elan-registry/wiki/Integration)
docs/faq/                  # User documentation
docs/faq/admin/            # Admin documentation
docs/README.md             # Complete documentation index
```
