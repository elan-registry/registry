# Scripts Directory

Utility scripts for the Elan Registry project.

## Build

### build.js

Minifies first-party JS and CSS using esbuild. Invoked via `npm run build` — run
after editing any source file under `app/assets/js/`, `app/assets/css/`, or
`app/admin/assets/`.

```bash
npm run build
```

## Version Management

### update-version.sh

Updates the `VERSION` file in a development environment from the current git tags.
Run after creating a new tag locally.

```bash
./scripts/update-version.sh
```

## Git Hooks Management

### setup-git-hooks.sh

Configures Git to use the `.githooks` directory for pre-commit and commit-msg
quality checks. Run once per developer after cloning the repo.

```bash
./scripts/setup-git-hooks.sh
```

**What it does:**

- Configures Git to use `.githooks` instead of `.git/hooks`
- Makes all hook files executable
- Verifies installation and tests required tools (PHP, Composer, npx)
- Checks that vendor/ and node_modules/ are present

**Pre-commit hook steps** (`.githooks/pre-commit`):

1. PHP coding standards validation (security, types, PHPDoc, and — for
   `tests/unit/regression/*.php` — issue-linking traceability, checked via
   `checkRegressionTestStructure()` inside this same step, not a separate one)
2. Markdown linting for formatting
3. Unit tests (if critical files changed) — runs concurrently with step 4
4. PHPStan static analysis (if PHP files changed) — runs concurrently with step 3
5. JavaScript linting (if JS files changed and ESLint is available)
6. Documentation consistency (`composer check:docs`, if PHP or Markdown files
   are staged) — dead links, stale indexes, ADR drift, dead symbols
7. Minify first-party JS/CSS (if source files changed and Node is available)

**Pre-push hook steps** (`.githooks/pre-push`, #1439):

1. **Blocking integration-test gate** — only on the `origin` remote (GitHub);
   `prod`/`test` deploy pushes always skip it, since those deploy
   already-CI-verified `main` and shouldn't depend on local dev-machine test-DB
   state. If the push touches any file under `app/`, `usersc/classes/`, or
   `tests/integration/`, runs the full `composer test:integration` suite
   (~1-2 min, requires a working `.env.test.local` — see
   `docs/development/ENVIRONMENT.md`) and blocks the push (exits non-zero) on
   any test failure or an unreachable test database. Pushes that touch none
   of those paths skip this step entirely. Bypass with `git push --no-verify`
   (also skips step 2 below).
2. **Non-blocking `/review-pr` reminder** — on the first push of a
   feature/issue-style branch (`issue/*`, `claude/*`, `feat/*`, `fix/*`,
   `chore/*`, `refactor/*`), prints a reminder to run `/review-pr` locally
   before relying on CI's lighter-weight review. Silence with
   `SKIP_REVIEW_PR_REMINDER=1 git push`.

### check-hooks-status.sh

Verifies that git hooks are properly configured and all dependencies are available.
Useful after cloning on a new machine or troubleshooting hook issues.

```bash
./scripts/check-hooks-status.sh
```

### check-coding-standards.php

PHP coding standards checker used by the pre-commit hook. Can also be run directly
to inspect a specific directory.

```bash
php scripts/check-coding-standards.php app/
```

## Plugin Updates

### check-plugin-updates.sh

Weekly cron script that compares installed UserSpice plugin versions against the
upstream `mudmin/usplugins` repository and opens a GitHub issue if any are
outdated. Run once to set up, then forget.

**Cron setup (run once):**

```bash
crontab -e
# Add:
0 9 * * 1 /path/to/repo/scripts/check-plugin-updates.sh >> /tmp/plugin-update-check.log 2>&1
```

Requires `gh` CLI authenticated (`gh auth status`). Creates at most one open
`plugin-update` issue at a time to avoid duplicates.

## Database

### refresh-local-db.sh

Refreshes the local development database from production: fetches a dump over
SSH, upserts the registry tables, masks every email address, and syncs car
images into a persistent local cache.

```bash
# Full refresh: fetch a fresh production dump, import, sync images
./scripts/refresh-local-db.sh --fetch

# DB only (skip image rsync)
./scripts/refresh-local-db.sh --fetch --skip-images

# Images only (skip DB refresh)
./scripts/refresh-local-db.sh --images-only

# Import a dump you already have, instead of fetching
./scripts/refresh-local-db.sh ~/Downloads/my-dump.sql

# Rehearse against the scratch test schema before touching your dev DB
./scripts/refresh-local-db.sh --fetch --env-file .env.test.local
```

Default dump path: `~/Downloads/unibrain_registry.sql`.

**`--fetch`** runs `mysqldump` on the production host over the `a2hosting` SSH
alias, reading DB credentials from the prod docroot `.env` there — no production
credentials are stored locally. It dumps only the tables listed below (which
also sidesteps the broken `users_carsview` view), uses `--single-transaction`
so the live site is never blocked, and verifies the `Dump completed` trailer
before importing so a truncated download cannot silently import partial data.

**Tables imported:** `cars`, `cars_hist`, `car_models`, `car_transfer_requests`,
`elan_factory_info`, `users`, `profiles`, `user_permission_matches`, `country`,
`audit`. Deliberately excluded: `logs`/`crons_logs` (noise), `users_online`/
`users_session` (prod session state), `settings`/`email` (would overwrite local
dev config, holds SMTP credentials), and `phinxlog`/`fix_script_runs`/`updates`/
`pages`/`menus`/`permissions` (locally owned by migrations).

**Email masking** replaces every address with `dev.owner.{id}@elanregistry.local`,
preserving user id 1. The masking `UPDATE`s run inside the same transaction as
the inserts, so real addresses are never the committed state. A verification
pass then re-checks all five email columns; if any unmasked address survives,
the script exits non-zero and leaves the database untouched for inspection
(restore manually from `db-backups/`). City and IP columns are intentionally
left intact — they are coarse-grained and needed to exercise location and map
features.

**Safety:** the local database is dumped to `db-backups/` before any import.
`--env-file` and `--db` retarget the import, so a refresh can be rehearsed
against a scratch schema first.

**Images** sync in two hops: production to a persistent cache outside the repo
(`~/Developer/Web/ElanRegistry/.local-userimages`, override with
`ELAN_IMAGE_CACHE`), then cache to `./userimages/`. Only the first hop touches
the network, and it is incremental across runs, so the cache survives
`git clean`, branch switches, and repo moves.

## Playwright Authentication

These scripts create a saved Playwright authentication state so that tests can run
as an authenticated user without entering credentials on every run. Run once
(or whenever the session expires).

### playwright-auth-setup.js

Saves an authenticated session for **production** (`elanregistry.org`) to
`tests/playwright/.auth/user.json`. Launches a headed browser so you can solve
any CAPTCHA manually.

```bash
export ELAN_USERNAME="your-username"
export ELAN_PASSWORD="your-password"
node scripts/playwright-auth-setup.js
```

### playwright-auth-setup-test.js

Same as above but targets the **test environment** (`test.elanregistry.org`).
Saves state to `tests/playwright/.auth/user-test.json`.

```bash
export ELAN_USERNAME="your-test-username"
export ELAN_PASSWORD="your-test-password"
node scripts/playwright-auth-setup-test.js
```

### playwright-auth-1password.sh

Convenience wrapper: loads production credentials from 1Password and runs
`playwright-auth-setup.js`.

```bash
./scripts/playwright-auth-1password.sh
```

### playwright-auth-1password-test.sh

Convenience wrapper: loads test-environment credentials from 1Password and runs
`playwright-auth-setup-test.js`.

```bash
./scripts/playwright-auth-1password-test.sh
```

## Server Hooks

### server-hooks/post-receive

Git post-receive hook installed on the production and test servers. Handles
deployment when a branch is pushed: checks out the work tree, writes the VERSION
file, runs Composer, executes pending migrations, self-updates the hook from the
deployed tree, and removes dev-only files listed in `.deployignore`.

This file is managed in the repo and self-updates on every push — do not edit
the hook on the server directly.

## Troubleshooting Git Hooks

### Hooks Not Running

**Symptom:** Commits succeed without quality checks running.

```bash
# Check hook configuration
git config core.hooksPath
# Should output: .githooks

# If not configured:
./scripts/setup-git-hooks.sh
```

### Tests Failing Unexpectedly

**Symptom:** Pre-commit hook reports test failures.

```bash
composer install
npm install
composer test:quick
```

### Coding Standards Violations

**Symptom:** Pre-commit blocked with "PHP coding standards violations".

```bash
# Run directly to see detail
php scripts/check-coding-standards.php app/
```

Common issues: missing `declare(strict_types=1)`, missing return type
declarations, missing PHPDoc on public methods, SQL string concatenation.

### Push Blocked by the Integration-Test Gate

**Symptom:** `git push` blocked with an integration-suite failure or a
"Could not connect to the test database" error, on a push that touches
`app/`, `usersc/classes/`, or `tests/integration/`.

```bash
# Confirm .env.test.local exists and points at a reachable, provisioned schema
cat .env.test.local
./scripts/provision-schema.sh   # (re)builds the schema if missing/stale

# Reproduce the failure directly
composer test:integration
```

See `docs/development/ENVIRONMENT.md` — "Test Database Isolation" for setup.

### Need to Bypass Hooks Temporarily

```bash
# Emergency only — fix issues before merging
git commit --no-verify -m "message"
git push --no-verify              # also skips the integration-test gate
```

### Getting Help

```bash
./scripts/check-hooks-status.sh   # full status report
git diff --cached --name-only     # verify staged files
```

## Adding New Scripts

1. Place in `scripts/`
2. Make executable: `chmod +x scripts/your-script.sh`
3. Add a section to this README
4. Include usage examples and a `--help` flag where appropriate
5. Use `set -e` for bash scripts
