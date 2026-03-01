# Scripts Directory

Utility scripts for the Elan Registry project.

## update-version.sh

Updates VERSION file in development environment from git tags.

**Usage:**

```bash
./scripts/update-version.sh
```

## Cleanup Scripts

### cleanup-outdated-docs.sh

**Purpose:** Removes outdated markdown documentation files that have been
moved or are no longer needed.

**When to use:**

- After deploying documentation restructuring changes to production
- When release notes have been moved from root to `docs/releases/`
- When cleaning up old release plan files

**What it does:**

- Removes root-level `RELEASE_NOTES_V*.md` files (moved to `docs/releases/`)
- Removes old `docs/development/*RELEASE_PLAN*.md` files (superseded by
  version-specific test plans in `docs/technical/`)
- Keeps intentional files like `CLAUDE.md` (redirect) and `README.md`
- Reports what was removed and suggests proper locations

**Files targeted for removal:**

```bash
./RELEASE_NOTES_V2.8.1.md      # → docs/releases/RELEASE_NOTES_V2.8.1.md
./RELEASE_NOTES_V2.8.6.md      # → docs/releases/RELEASE_NOTES_V2.8.6.md
./docs/development/V2.8.1_RELEASE_PLAN.md  # Outdated release plan
./docs/development/RELEASE_PLAN.md         # Outdated generic template
```

**Files preserved:**

- `./CLAUDE.md` - Intentional redirect to `docs/development/CLAUDE.md`
- `./README.md` - Main project README

**Usage:**

```bash
# Preview what would be removed (safe dry-run)
./scripts/cleanup-outdated-docs.sh --dry-run

# Actually remove files (from current project)
./scripts/cleanup-outdated-docs.sh

# Run on production server (dry-run first!)
./scripts/cleanup-outdated-docs.sh /home/unibrain/elanregistry.org --dry-run

# Run on production server (live removal)
./scripts/cleanup-outdated-docs.sh /home/unibrain/elanregistry.org

# Get help
./scripts/cleanup-outdated-docs.sh --help
```

**Options:**

- `--dry-run` - Preview changes without removing any files (RECOMMENDED FIRST)
- `--help`, `-h` - Show usage information
- `PROJECT_ROOT` - Specify custom project path (default: auto-detect)

**Safety:**

- **Always run with `--dry-run` first** to preview changes
- Safe to run multiple times (idempotent)
- Only removes specifically identified outdated files
- Reports what it found and what it removed
- Can be recovered with `git checkout HEAD -- <filename>` if needed

**Example output (dry-run):**

```text
═══════════════════════════════════════════════════════════
  Outdated Documentation Cleanup Script
═══════════════════════════════════════════════════════════

Project Root: /home/unibrain/elanregistry.org
Mode: DRY RUN (preview only, no files will be removed)

Checking for outdated files...

Found outdated file: ./RELEASE_NOTES_V2.8.1.md
  → Moved to: ./docs/releases/RELEASE_NOTES_V2.8.1.md
⚠  WOULD REMOVE: ./RELEASE_NOTES_V2.8.1.md

═══════════════════════════════════════════════════════════
  Cleanup Summary
═══════════════════════════════════════════════════════════
Would remove:    2 files
Already missing: 2 files
Kept:            0 files

✓ Dry-run complete!

To actually remove these files, run:
  ./scripts/cleanup-outdated-docs.sh /home/unibrain/elanregistry.org
```

**Production deployment workflow:**

```bash
# 1. SSH into production server
ssh user@production-server

# 2. Navigate to project directory
cd /home/unibrain/elanregistry.org

# 3. Run dry-run to preview changes
./scripts/cleanup-outdated-docs.sh --dry-run

# 4. Review output carefully

# 5. If everything looks correct, run live removal
./scripts/cleanup-outdated-docs.sh

# 6. Verify cleanup completed successfully
```

## Git Hooks Management

### setup-git-hooks.sh

**Purpose:** Configures Git to use pre-commit and commit-msg hooks for
automated code quality checks.

**Usage:**

```bash
# One-time setup (run once per developer)
./scripts/setup-git-hooks.sh
```

**What it does:**

- Configures Git to use `.githooks` directory instead of default `.git/hooks`
- Makes all hook files executable
- Verifies installation with comprehensive checks
- Tests required tools (PHP, Composer, npx)
- Checks dependencies (vendor/, node_modules/)
- Provides detailed status report

**Pre-commit Hook Features:**

- **Step 1**: PHP coding standards validation (security, types, documentation)
- **Step 2**: Markdown linting for documentation consistency
- **Step 3**: Regression test validation (issue linking)
- **Step 4**: Fast unit tests (if critical files modified)

**Commit-msg Hook Features** (if installed):

- Validates commit message format
- Ensures issue references for non-trivial commits
- Enforces conventional commit patterns

### check-hooks-status.sh

**Purpose:** Verifies git hooks are properly configured and all dependencies
are available.

**Usage:**

```bash
# Check hook health status
./scripts/check-hooks-status.sh
```

**What it checks:**

- Git hook configuration (`core.hooksPath`)
- Hook files exist and are executable
- Required tools (PHP 8.1+, Composer, npx)
- Dependencies (vendor/, node_modules/)
- Supporting scripts availability
- Provides actionable fix suggestions

**When to use:**

- After cloning repository on new machine
- Troubleshooting hook issues
- Verifying setup after system updates
- Before reporting hook-related problems

## Troubleshooting Git Hooks

### Hooks Not Running

**Symptom:** Commits succeed without quality checks running.

**Diagnosis:**

```bash
# Check hook configuration
git config core.hooksPath
# Should output: .githooks

# If not configured or showing default:
./scripts/setup-git-hooks.sh
```

**Common causes:**

1. **Hooks not configured**: Run `./scripts/setup-git-hooks.sh`
2. **Wrong directory**: Ensure you're in project root
3. **Hooks not executable**: Fixed automatically by setup script

### Tests Failing Unexpectedly

**Symptom:** Pre-commit hook reports test failures.

**Diagnosis:**

```bash
# Update dependencies
composer install
npm install

# Run tests manually to see detailed output
composer test:quick

# Check for broken tests
vendor/bin/phpunit --list-groups
```

**Common causes:**

1. **Outdated dependencies**: Run `composer install`
2. **Missing vendor directory**: Run `composer install`
3. **Environment issues**: Check PHP version (8.1+ required)

### Coding Standards Violations

**Symptom:** Pre-commit blocked with "PHP coding standards violations".

**Diagnosis:**

```bash
# Test standards checker directly
php scripts/check-coding-standards.php app/

# Common issues to fix:
# - Missing declare(strict_types=1) at top of PHP files
# - Missing return type declarations
# - Missing PHPDoc blocks on public methods
# - SQL queries using string concatenation instead of prepared statements
```

**Fix workflow:**

1. Read the specific error message (it tells you what's wrong)
2. Fix the issue in your code
3. Stage the fix: `git add <file>`
4. Try committing again

### Markdown Linting Issues

**Symptom:** Pre-commit blocked with "Markdown lint issues found".

**Common fixes:**

```bash
# Ensure blank lines around headers
# Wrong:
## Header
Content

# Right:
## Header

Content

# Fix list indentation (2 spaces for nested items)
# Ensure proper line endings (no trailing whitespace)
```

**Resources:**

- Markdown rules: <https://github.com/DavidAnson/markdownlint/blob/main/doc/Rules.md>
- Config file: `.markdownlint.json`

### Need to Bypass Hooks Temporarily

**Emergency bypass** (NOT recommended for regular use):

```bash
# Skip ALL pre-commit checks
git commit --no-verify -m "message"

# OR use environment variable
SKIP=1 git commit -m "message"
```

**When bypass is acceptable:**

- Emergency hotfixes (fix first, clean up later)
- Working on non-code files that don't affect quality
- Hooks are broken and need fixing

**When bypass is NOT acceptable:**

- Regular development workflow
- "Saves time" (it doesn't - you'll fix it in PR review anyway)
- Avoiding coding standards compliance

**Better approach:**

Fix the issues - the error messages tell you exactly what's wrong!

### Hook Execution Too Slow

**Symptom:** Pre-commit takes longer than 30 seconds.

**Diagnosis:**

```bash
# Check what's slow:
# - Step 1 (PHP standards): Usually <5s
# - Step 2 (Markdown): Usually <2s
# - Step 3 (Regression): Usually <1s
# - Step 4 (Unit tests): Usually <30s

# If unit tests are slow:
composer test:quick  # Should complete in <30s
```

**Common causes:**

1. **Large number of staged files**: Commit smaller changesets
2. **Slow unit tests**: Check for database operations in unit tests
3. **Network issues**: Check if tests are making external requests

### Hooks Not Detecting Staged Files

**Symptom:** Hook says "No relevant files staged" but you staged PHP/MD files.

**Diagnosis:**

```bash
# Check what's actually staged
git diff --cached --name-only

# If files are missing:
git add <file>
git status  # Verify files are in "Changes to be committed"
```

### Getting Help

**Before reporting an issue:**

1. Run status check: `./scripts/check-hooks-status.sh`
2. Check git status: `git status`
3. Verify staged files: `git diff --cached --name-only`
4. Try re-running setup: `./scripts/setup-git-hooks.sh`

**Include in bug reports:**

- Output of `./scripts/check-hooks-status.sh`
- PHP version: `php -v`
- Git version: `git --version`
- Operating system
- Exact error message
- Steps to reproduce

## 6. Git Repository Setup

### Current VERSION File

**Decision:** Keep VERSION file in git for now, then add to .gitignore.

**Rationale:**

- Maintains backward compatibility during transition
- Allows gradual rollout
- Can be removed later if desired

**Steps:**

1. Add VERSION to `.gitignore`
2. Commit .gitignore change
3. VERSION file stops being tracked but remains in working directory
4. Each environment generates its own VERSION file via hooks
5. Developers use `./scripts/update-version.sh` to generate locally

**Optional future cleanup:**

```bash
# Remove from git tracking (keeps file in working directory)
git rm --cached VERSION
git commit -m "CLEANUP: Stop tracking VERSION file (auto-generated)"
```

## Adding New Scripts

When adding new scripts:

1. Place them in the `scripts/` directory
2. Make them executable: `chmod +x scripts/your-script.sh`
3. Add documentation to this README
4. Include usage examples and safety notes
5. Consider adding a `--help` flag
6. Use proper error handling with `set -e`
