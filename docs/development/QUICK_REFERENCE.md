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

### Milestone Lifecycle

```bash
/start-milestone v2.17.0     # Create milestone branch, draft release notes
/start-issue 423              # Branch, plan, implement, test, security review
/simplify                     # Clean up the code (optional)
/commit                       # Commit locally
/commit-push-pr               # Push + PR targeting milestone branch
/finish-issue 423             # CI check, squash-merge, close issue
/finish-milestone v2.17.0    # PR to main, finalize release notes, update wiki
/review-pr                    # Multi-agent PR review
/release-milestone v2.17.0   # Merge, tag, GitHub release, close milestone
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
| --- | --- | --- | --- |
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

## Code Patterns & Snippets

### Database Queries

```php
// Get single record
$result = $db->query('SELECT * FROM cars WHERE id = ?', [$carId])->first();

// Get multiple records
$results = $db->query('SELECT * FROM cars WHERE user_id = ?', [$userId])->results();

// Check existence
$exists = $db->query('SELECT id FROM cars WHERE id = ?', [$carId])->count() > 0;

// Get single value
$color = $db->cell('cars.color', ['id' => $carId]);

// Insert
$db->insert('cars', ['user_id' => $uid, 'year' => 2020]);

// Update
$db->update('cars', $carId, ['color' => 'red']);

// Delete
$db->delete('cars', ['id' => $carId]);
```

### Backend Error Handling

```php
try {
    // Operation
    $car = new Car($carId);
    $car->validateYear();

} catch (CarValidationException $e) {
    // Validation error (422)
    ApiResponse::validationError(['field' => $e->getMessage()])
        ->withLogging($userId, $e->getLogCategory(), $e->getMessage())
        ->send();

} catch (CarNotFoundException $e) {
    // Not found (404)
    ApiResponse::notFound($e->getUserMessage())
        ->withLogging($userId, LogCategories::LOG_CATEGORY_CAR_ERRORS, $e->getMessage())
        ->send();

} catch (Exception $e) {
    // Server error (500)
    ApiResponse::serverError('An error occurred')
        ->withLogging($userId, LogCategories::LOG_CATEGORY_SYSTEM_ERROR, $e->getMessage())
        ->send();
}
```

### Frontend AJAX Request

```javascript
const api = new ElanRegistryAPI();

try {
    const result = await api.post('app/action/update-car.php', {
        car_id: 123,
        color: 'red'
    });

    NotificationHelper.show(result.message, 'success');

} catch (error) {
    if (error instanceof ApiValidationError) {
        NotificationHelper.showValidationErrors(error.errors);
    } else {
        NotificationHelper.show(error.message, 'error');
    }
}
```

### Permission Checking

```php
// Page-level protection
securePage($php_self);  // Redirect to login if not authenticated

// Feature-level checking
if (!hasPerm([2], $userId)) {  // Permission level 2 = admin
    ApiResponse::forbidden('Admin access required')->send();
}

// Owner verification
$car = new Car($carId);
if ($car->user_id !== $userId && !isRegistryAdmin($userId)) {
    ApiResponse::forbidden('You can only modify your own cars')->send();
}
```

### Form Submission with Validation

```php
if ($method === 'POST') {
    try {
        // Validate CSRF
        if (!Token::check(Input::get('csrf'))) {
            throw new ValidationException('Invalid CSRF token');
        }

        // Get and validate input
        $carId = dbInt(Input::get('car_id'));
        $color = Input::get('color');

        if (empty($color) || strlen($color) > 50) {
            throw new ValidationException('Color must be 1-50 characters');
        }

        // Process
        $car = new Car($carId);
        $car->update(['color' => $color]);

        ApiResponse::success('Car updated')
            ->withLogging(currentUserId(), LogCategories::LOG_CATEGORY_CAR_UPDATE, "Updated car $carId")
            ->send();

    } catch (ValidationException $e) {
        ApiResponse::validationError(['field' => $e->getMessage()])->send();
    }
}
```

### User & Profile Data

```php
// Get user with profile (single query)
$owner = getUserWithProfile($userId);
echo $owner->fname . ' ' . $owner->lname;
echo $owner->city . ', ' . $owner->state;

// Check if admin/editor
if (isRegistryAdmin($userId)) {
    // Show admin controls
}

// Get current user
$currentUser = currentUserId();  // Throws if not logged in
```

### Logging Patterns

```php
// Login/Logout
logger($userId, LogCategories::LOG_CATEGORY_LOGIN, 'User logged in');

// Car actions
logger($userId, LogCategories::LOG_CATEGORY_CAR_CREATE, 'Created car with VIN ' . $vin);
logger($userId, LogCategories::LOG_CATEGORY_CAR_UPDATE, 'Updated car color');
logger($userId, LogCategories::LOG_CATEGORY_CAR_DELETE, 'Deleted car ' . $carId);

// Access control
logger($userId, LogCategories::LOG_CATEGORY_ACCESS_DENIED, 'Attempted unauthorized access');

// Search/lookup
logger($userId, LogCategories::LOG_CATEGORY_LOCATION_SERVICE, 'Location search: Paris');

// Errors
logger($userId, LogCategories::LOG_CATEGORY_VALIDATION_ERROR, 'Email validation failed');
```

### Model Management (Phase 2)

Models are now managed in the database (car_models table). To add/modify car model definitions:

**Add New Car Model Definition**:

```sql
-- Insert new model definition into car_models table
INSERT INTO car_models
(year_available_from, year_available_to, display_name, human_readable_short,
 series, variant, type_code, model_value)
VALUES
(1970, 1973, 'New Model ( Type 36 Description )', 'New Model',
 'Series', 'Variant', '36', 'Series|Variant|36');
```

**Test Availability**:

```php
// Check if model is available in a specific year
use ElanRegistry\Reference\CarModel;

$carModel = new CarModel();
$models = $carModel->getAvailableInYear(1970);

foreach ($models as $model) {
    echo $model->human_readable_short . " (" . $model->model_value . ")\n";
}

// Validate if model combination exists
if ($carModel->exists('S4', 'FHC', '36')) {
    echo 'Valid model combination';
}
```

**Dynamic Dropdown Updates**:

- Model dropdowns in `edit.php` load dynamically from database (no JS changes needed)
- API endpoint: `app/cars/actions/get-models.php`
- JavaScript module: `app/assets/js/model-loader.js`
- Models are cached client-side after first load

**Notes**:

- Model definitions replace hardcoded `cardefinition.js` (now removed)
- Form submission still uses format: `series|variant|type`
- Backend validates model combination exists via CarModel::exists()
- No data migration of existing cars required

## Security Scanning (Semgrep)

Semgrep runs automatically on every PR via GitHub App Managed Scan
(`semgrep-cloud-platform/scan` check). PRs that introduce new findings will
fail the check. The dashboard at semgrep.dev/orgs/jim_unibrain_org shows all
open findings for all repos.

### Fetch open findings for this repo

```bash
bash scripts/semgrep-dump.sh
```

Requires 1Password CLI (`op`). Token stored at
`op://HomeLab/SEMGREP_APP_TOKEN/credential` — must have **Web API** scope.

### Periodic triage (keep the dashboard clean)

Run after a milestone or when findings accumulate:

1. Pull findings: `bash scripts/semgrep-dump.sh`
2. Review each rule against the actual code — check for int casts, `htmlspecialchars()`, whitelist validation, etc.
3. Bulk-mark confirmed false positives via the API:

```bash
SEMGREP_APP_TOKEN=$(op read "op://HomeLab/SEMGREP_APP_TOKEN/credential")
curl -s -X POST "https://semgrep.dev/api/v1/deployments/jim_unibrain_org/triage" \
  --header "Authorization: Bearer $SEMGREP_APP_TOKEN" \
  --header "Content-Type: application/json" \
  -d '{
    "issue_ids": ["id1","id2"],
    "issue_type": "sast",
    "new_triage_state": "ignored",
    "new_triage_reason": "false_positive",
    "note": "Reason it is safe"
  }'
```

1. Create GitHub issues for confirmed real findings; assign to appropriate milestone.

### What is excluded from scanning

See `.semgrepignore` in the repo root. Key exclusions:

- `users/` — UserSpice framework core (not our code)
- `FIX/` — one-time admin migration scripts
- `docs/stories/` — archived third-party HTML
- `vendor/`, `node_modules/` — dependencies
- `tests/`, `database/4-sample-data.sql` — test fixtures

### Common false positive patterns in this codebase

| Semgrep rule | Why it fires | Why it's safe |
| --- | --- | --- |
| `taint-unsafe-echo-tag` | Follows `$_REQUEST` source | Output is int-cast or wrapped in `htmlspecialchars()` |
| `tainted-sql-string` | Follows input through exception handlers | Actual DB calls use prepared statements via `ElanRegistryOwner` |
| `tainted-filename` | Flags `basename()` as insufficient | `basename()` + extension check + directory validation is sufficient |
| `tainted-path-traversal` | Flags `include` with derived path | `$activeTab` validated against `$validTabs` whitelist before use |

## Troubleshooting

| Problem | Solution |
| --- | --- |
| `securePage()` redirecting to login | Register page in UserSpice admin; add dir to `z_us_root.php` `$path` array |
| CSRF validation failed | Ensure `<input name="csrf" value="<?php echo Token::generate(); ?>">` in form |
| API returns 500 error | Check PHP error log; verify exception types are correct |
| Database query returns no results | Verify table name, column names, and WHERE clause |
| Tests failing | Check PHP 8.1+; run `composer install` && `npm install` |
| NotificationHelper not showing | Verify footer.php is included; check browser console for JS errors |

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
