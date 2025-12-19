# Static Analysis & Type Safety Tools

## Overview

This document describes the static analysis and type safety tools available
for the Elan Registry codebase to catch type errors and bugs before runtime.

## Tools Available

### 1. Pre-commit Quality Checks (Automatic)

**Location:** `.githooks/pre-commit` (uses `scripts/check-coding-standards.php`)

**What it does:**

- Automatically runs on every `git commit`
- Checks PHP coding standards including new database type casting rules
- Validates markdown documentation
- Runs fast unit tests for critical files
- **New:** Detects database values passed to strict-typed functions without casts

**Database Type Casting Detection:**

The pre-commit hook now warns about potential type casting issues:

```php
// ✅ PASS - Explicit cast
$manager = new BackupManager($db, $dir, (int)$user->data()->id);

// ⚠️  WARN - Missing cast in strict mode
$manager = new BackupManager($db, $dir, $user->data()->id);
//Warning: Database ID value may need explicit (int) cast in strict mode
```

**Patterns detected:**

1. `$user->data()->id` without `(int)` cast
2. `$row->user_id` without `(int)` cast
3. `$result->first()->id` without type cast

**Setup:**

```bash
./scripts/setup-git-hooks.sh
```

**Bypass (not recommended):**

```bash
git commit --no-verify
```

### 2. Manual Coding Standards Check

**Usage:**

```bash
# Check entire directory
php scripts/check-coding-standards.php app/

# Check specific area
php scripts/check-coding-standards.php app/admin/includes/classes/

# Check staged files only (pre-commit mode)
php scripts/check-coding-standards.php . --staged
```

**Example output:**

```text
🔍 Checking coding standards in: app/admin/includes/classes/
===================================================

....

=============================================================
📊 CODING STANDARDS CHECK RESULTS
=============================================================
Files checked: 4
Errors: 0
Warnings: 2

⚠️  WARNINGS (should consider fixing):
-----------------------------------------------------------
• app/admin/includes/system/backup-operations.php:35:
  Database ID value may need explicit (int) cast in strict mode
• app/admin/includes/system/schema-operations.php:34:
  Database query result may need explicit type cast in strict mode
  (int/float/bool)
```

### 3. PHPStan Static Analysis (Optional)

PHPStan is a powerful static analysis tool that can catch type errors,
undefined methods, and other bugs without running the code.

**Installation:**

```bash
composer require --dev phpstan/phpstan
```

**Configuration:** `phpstan.neon` (already set up)

**Usage:**

```bash
# Analyze all configured paths
vendor/bin/phpstan analyse

# Analyze specific directory
vendor/bin/phpstan analyse app/admin/

# Higher strictness level (0-9)
vendor/bin/phpstan analyse --level=6 app/classes/

# Generate baseline (ignore existing errors)
vendor/bin/phpstan analyse --generate-baseline
```

**Example output:**

```text
------ ---------------------------------------------------------------------------
 Line   app/admin/includes/classes/BackupManager.php
------ ---------------------------------------------------------------------------
 50     Parameter #3 $userId of class BackupManager constructor expects
        int|null, string given.
------ ---------------------------------------------------------------------------
```

**Level Guide:**

- **Level 0-2:** Basic checks (undefined variables, unknown classes)
- **Level 3-5:** Type checking (our current configuration)
- **Level 6-7:** Strict type checking (recommended for new code)
- **Level 8-9:** Extreme strictness (nullable types, dead code)

**Continuous Integration:**

Add to CI pipeline:

```yaml
# .github/workflows/tests.yml
- name: PHPStan Static Analysis
  run: vendor/bin/phpstan analyse --error-format=github
```

## Type Casting Best Practices

### Database Values with Strict Types

**The Problem:**

```php
// In PHP 8.3.14 (dev): Returns int
// In PHP 8.2.29 (test): Returns string
$userId = $user->data()->id;

// With declare(strict_types=1):
function __construct($db, $settings, ?int $userId = null) { }

// TypeError: Argument #3 must be of type ?int, string given
```

**The Solution:**

```php
// ✅ Always cast database values explicitly
$userId = (int)$user->data()->id;
$carId = (int)$row->car_id;
$count = (int)$result->first()->total;
$isActive = (bool)$row->active;
$price = (float)$row->price;
```

**Why this happens:**

- PDO/mysqli return values as strings by default
- PHP 8.1+ changed this behavior in some configurations
- Environment differences (dev vs test vs prod)
- `declare(strict_types=1)` enforces strict type checking

**Prevention:**

1. Pre-commit hooks warn about missing casts
2. PHPStan detects type mismatches
3. Coding standards document the pattern

## Testing Strategy

### 1. Local Development

**Before committing:**

```bash
# Pre-commit hook runs automatically
git add app/admin/includes/system/backup-operations.php
git commit -m "Fix: Add type cast for user ID"

# Or run manually
php scripts/check-coding-standards.php app/admin/
```

**Optionally with PHPStan:**

```bash
vendor/bin/phpstan analyse app/admin/
```

### 2. Continuous Integration

**GitHub Actions workflow:**

```yaml
- name: PHP Coding Standards
  run: php scripts/check-coding-standards.php app/

- name: PHPStan Analysis
  run: vendor/bin/phpstan analyse --no-progress
```

### 3. Code Review

Reviewers should watch for:

- Database values without type casts in strict-typed code
- Missing `declare(strict_types=1)` in new files
- Functions without return types
- PHPStan errors in CI

## Troubleshooting

### Pre-commit hook not running

```bash
# Re-run setup
./scripts/setup-git-hooks.sh

# Verify hook is installed
cat .git/config | grep hooksPath
# Should show: hooksPath = .githooks
```

### Too many warnings

**Option 1:** Fix gradually

```bash
# Fix one directory at a time
php scripts/check-coding-standards.php app/admin/includes/classes/
```

**Option 2:** Use PHPStan baseline

```bash
vendor/bin/phpstan analyse --generate-baseline
# Creates phpstan-baseline.neon with existing errors
```

### False positives

**Pre-commit warnings are just warnings**, not errors. You can commit
with warnings:

```bash
git commit -m "Message"  # Will warn but allow commit
```

**PHPStan false positives:** Add to `ignoreErrors` in `phpstan.neon`:

```neon
parameters:
    ignoreErrors:
        - '#specific error message#'
```

## References

- **Coding Standards:** `/docs/development/CODING_STANDARDS.md`
- **Strict Type Strategy:** `/docs/technical/STRICT_TYPE_HANDLING.md`
- **PHPStan Documentation:** <https://phpstan.org/>
- **PHP Type System:**
  <https://www.php.net/manual/en/language.types.declarations.php>

## Quick Reference Card

```bash
# Setup (one time)
./scripts/setup-git-hooks.sh
composer require --dev phpstan/phpstan  # Optional

# Manual checks
php scripts/check-coding-standards.php app/
vendor/bin/phpstan analyse              # Optional

# Commit (automatic)
git commit -m "Message"
# → Pre-commit hook runs automatically
# → Warns about type casting issues
# → Blocks commits with errors

# Bypass (emergency only)
git commit --no-verify
```
