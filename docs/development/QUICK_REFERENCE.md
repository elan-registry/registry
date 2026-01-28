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

**New PHP Directories:**
Add path to `$path` array in `/z_us_root.php`, register pages in UserSpice admin
See [INTEGRATION.md](INTEGRATION.md)

## Troubleshooting

| Problem | Solution |
|---------|----------|
| `securePage()` redirecting to login | Register page in UserSpice admin; add dir to `z_us_root.php` `$path` array |
| Database triggers not firing | Only `cars` table has triggers; other tables use app-level logging |
| Tests failing | Check PHP 8.1+; run `composer install` && `npm install` |
| File modified by hooks | Re-read file; check `.markdownlint.json` |

## Documentation Index

```text
CLAUDE.md                  # Start here - AI assistant guide
docs/development/
  INSTALLATION.md          # Setup and installation
  ARCHITECTURE.md          # System architecture
  DATABASE.md              # Database schema
  CODING_STANDARDS.md      # Coding standards
  ERROR_HANDLING.md        # Error handling patterns
  INTEGRATION.md           # UserSpice integration
  LOG_CATEGORIES.md        # Logging categories (140+)
  CLASSES.md               # Application classes
  DEPLOYMENT.md            # Release and deployment
docs/faq/                  # User documentation
docs/faq/admin/            # Admin documentation
docs/README.md             # Complete documentation index
```
