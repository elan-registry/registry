# Quick Reference Guide

Use this guide for common development tasks and commands. Read the linked
documents for details.

## Essential Commands

### Testing

See the "Development Setup" section in [CLAUDE.md](../../CLAUDE.md) for the
full testing and build commands.

### Pre-commit Quality Checks

```bash
composer check:php               # Coding standards + PHPStan (no `composer phpcs` script exists)
```

### Milestone Lifecycle

See the Developer Workflow section in [CLAUDE.md](../../CLAUDE.md) for the
milestone lifecycle and slash commands.

### Git & Deployment

See [DEPLOYMENT.md](DEPLOYMENT.md) for the release procedures.

## Common File Locations

```text
/app/                      # Main application pages
  /owner/cars/             # Car listing, details, edit, factory
  /owner/contact/          # Owner contact functionality
  /owner/reports/          # Statistics and reports
  /admin/                  # Admin interfaces
  /api/                    # AJAX JSON endpoints
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
.env                       # Environment variables (plaintext, chmod 600, not committed)
.env.example               # Public template for .env (committed)
VERSION                    # Current version number
```

## Key Patterns (Quick Summary)

**Database Access:**
`$db = DB::getInstance()` → `$db->query("SQL", [$params])->results()`
See [DATABASE.md](DATABASE.md)

**User/Profile Access:**
`$owner = (new Owner($userId))->data()` → `$owner->fname`, `$owner->city`
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
Never use `$_SERVER` directly. Use the checked global variables instead.
See [CLAUDE.md](../../CLAUDE.md) for the full list and [PAGE_LOADING_FLOW.md](PAGE_LOADING_FLOW.md) for details.

**Writing a Cron Job:**
The transport calls `users/cron/cron.php` every 10 minutes. Each call runs
every active job. Jobs must be idempotent and control their own schedule.
See [DEPLOYMENT.md — Cron Transport](DEPLOYMENT.md#cron-transport-userspice-cron-manager)

**New PHP Directories:**
Add path to `$path` array in `/z_us_root.php`, register pages in UserSpice admin
See [GitHub Wiki: UserSpice Integration Guide](https://github.com/elan-registry/registry/wiki/Customization-and-Integration-Patterns)

## Custom Functions Available on All Pages

UserSpice loads these functions for every page:

| Function | Returns | Purpose | Example |
| --- | --- | --- | --- |
| `isRegistryAdmin($userId)` | bool | Check if user has admin/editor perms | `if (isRegistryAdmin()) { ... }` |
| `requireAdminAjax($context)` | void | Guard an admin AJAX endpoint (exits on failure) | `requireAdminAjax('transfer approval')` |
| `getBaseUrl()` | string | Get the app base URL for the current environment | `$base = getBaseUrl()` |
| `getAdminEmails()` | string | Get comma-separated admin emails | `$emails = getAdminEmails()` |
| `getFeedbackEmail()` | string | Get feedback form email address | `$email = getFeedbackEmail()` |
| `dbInt($value)` | int | Cast database value to int safely | `$id = dbInt($row->id)` |
| `currentUserId()` | int | Get the logged-in user's ID or throw an exception | `$uid = currentUserId()` |
| `logger($userId, $type, $note, $metadata)` | bool | Log user action for audit trail | `logger($uid, LogCategories::LOG_CATEGORY_LOGIN, 'User logged in')` |

**Examples:**

```php
// Get owner data with profile information
use ElanRegistry\Owner;
$owner = (new Owner($userId))->data();
echo $owner->fname . " from " . $owner->city;

// Check admin status
if (isRegistryAdmin()) {
    echo '<a href="admin">Admin Panel</a>';
}

// Log an action
logger(currentUserId(), LogCategories::LOG_CATEGORY_CAR_CREATION, 'Created new car');
```

Read the [UserSpice framework guidance](../../usersc/plugins/ai_prompts/prompts/00_start_here.md.php) before you build a custom solution.

## Model Management

The `car_models` table stores model definitions. Do not hard-code models in
JavaScript. Add a row to `database/seeds/data/car_models.csv`, then run the
Phinx seed:

```bash
vendor/bin/phinx seed:run -s CarModelsSeed
```

The seed is idempotent. See [database/seeds/README.md](../../database/seeds/README.md)
for the seed data format and rules.

**Check Availability**:

```php
// Check if model is available in a specific year
use ElanRegistry\Reference\CarModel;

$carModel = new CarModel();
$models = $carModel->getAvailableInYear(1970);

foreach ($models as $model) {
    echo $model->human_readable_short . " (" . $model->model_value . ")\n";
}

// Check if the model combination exists
if ($carModel->exists('S4', 'FHC', '36')) {
    echo 'Valid model combination';
}
```

**Dynamic Dropdown Updates**:

- The car form in `app/owner/cars/edit.php` loads model data dynamically (no
  hard-coded JavaScript model list)
- API endpoint: `app/api/cars/models.php`
- JavaScript module: `app/assets/js/model-loader.js`
- The browser caches models after the first load.

**Notes**:

- Model definitions replace hardcoded `cardefinition.js` (now removed)
- Form submission still uses format: `series|variant|type`
- The backend checks model combinations with `CarModel::exists()`.
- No data migration of existing cars required

## Security Scanning (Semgrep)

GitHub App Managed Scan runs Semgrep on every pull request. A pull request
fails the `semgrep-cloud-platform/scan` check if it adds findings. The dashboard
at `semgrep.dev/orgs/jim_unibrain_org` shows open findings for all repositories.

### Get open findings for this repository

```bash
SEMGREP_APP_TOKEN=$(op read "op://HomeLab/SEMGREP_APP_TOKEN/credential")
curl -s "https://semgrep.dev/api/v1/deployments/jim_unibrain_org/findings?dedup=true&ref=main&repos=elan-registry%2Fregistry" \
  --header "Authorization: Bearer $SEMGREP_APP_TOKEN" | jq '.findings[] | {
    id, severity, rule: .rule_name,
    file: .location.file_path, line: .location.line,
    message: .rule_message, cwe: .rule.cwe_names, url: .line_of_code_url
  }'
```

This command requires the 1Password CLI (`op`). The token at
`op://HomeLab/SEMGREP_APP_TOKEN/credential` must have **Web API** scope.

### Periodic triage (keep the dashboard clean)

Run this process after a milestone or when findings accumulate:

1. Pull the findings with the `curl` command above.
2. Review each rule against the code. Check for integer casts, `htmlspecialchars()`, and allowlist checks.
3. Mark known false positives through the API with this command:

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

1. Create GitHub issues for real findings. Assign each issue to its milestone.

### Paths Semgrep Does Not Scan

See `.semgrepignore` in the repository root. Semgrep skips these paths:

- `users/` — UserSpice framework core (not our code)
- `app/admin/scripts/fix/` — one-time admin migration scripts
- `docs/stories/` — archived third-party HTML
- `vendor/`, `node_modules/` — dependencies
- `tests/`, `database/4-sample-data.sql` — test fixtures

### Common false positive patterns in this codebase

| Semgrep rule | Why it fires | Why it's safe |
| --- | --- | --- |
| `taint-unsafe-echo-tag` | Traces input from `$_REQUEST` | The code casts output to an integer or passes it to `htmlspecialchars()`. |
| `tainted-sql-string` | Traces input through exception handlers | `Owner` uses prepared statements for database calls. |
| `tainted-filename` | Treats `basename()` as insufficient | The code checks the basename, extension, and directory. |
| `tainted-path-traversal` | Flags an `include` call with a derived path | The code checks `$activeTab` against the `$validTabs` allowlist first. |

## Troubleshooting

| Problem | Solution |
| --- | --- |
| `securePage()` redirects to login | Register the page in UserSpice admin. Add its directory to the `$path` array in `z_us_root.php`. |
| CSRF validation fails | Add `<input name="csrf" value="<?php echo Token::generate(); ?>">` to the form. |
| API returns a 500 error | Read the PHP error log. Check that the code uses the correct exception type. |
| Database query returns no results | Check the table name, column names, and `WHERE` clause. |
| Tests fail | Check that PHP 8.2 or later runs. Run `composer install` and `npm install`. |
| `NotificationHelper` does not show | Check that `footer.php` loads. Read the browser console for JavaScript errors. |

## Documentation Index

For the complete documentation index, see [docs/README.md](../../docs/README.md).
