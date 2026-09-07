# Deployment Guide

This document provides comprehensive deployment procedures for the Lotus Elan
Registry application.

## 🚀 Production Environment

### Hosting Infrastructure

- **Hosting**: A2 Hosting with git deployment hooks
- **Remote**: `prod` remote configured for direct deployment to production server
- **Auto-deployment**: Master branch deploys automatically when pushed to prod remote
- **Version Display**: Uses VERSION file modification time for deployment timestamp

### 🚨 CRITICAL: Production Deployment Commands

**⚠️ IMPORTANT:** When someone says "push to prod", always use the `prod`
remote, NOT `origin`!

**Live Production Server:**

```bash
# 1. Push the release tag FIRST — the post-receive hook writes VERSION with
#    `git describe HEAD` during the second push, so the tag must already be
#    on the server or the footer shows the previous version
git push prod vX.Y.Z

# 2. Deploy exactly the tagged commit. Never push `main` itself: it may
#    already hold commits merged after the tag, and they must not ride along.
#    The quoted refspec sets the server's main ref to the tagged commit.
git push prod 'vX.Y.Z^{commit}:main'
```

**GitHub Repository (backup/development):**

```bash
# Push to GitHub for repository backup
git push origin main && git push origin --tags
```

### Remote Configuration Reference

```bash
origin git@github.com:elan-registry/registry.git    # GitHub repository
test   [test-server-path]                            # Test/staging server
prod   [prod-server-path]                            # LIVE PRODUCTION SERVER
```

The real `test` and `prod` paths are deliberately not published — run
`git remote -v` to see the values configured in your clone.

**🔄 Deployment Rule:**

- `origin` = GitHub (development/backup)
- `test` = Test/staging server for validation
- `prod` = **LIVE WEBSITE** (elanregistry.org)

**Testing Feature Branches:**

```bash
# Deploy feature branch to test server
git push test feature/v2.9.1

# Deploy tag to test server
git push test v2.9.1
```

## 🤖 Automated Pull Request Checks

All pull requests to the `main` branch are automatically validated through a
comprehensive set of checks before merge is allowed. These checks ensure code
quality, security, and project management compliance.

### Quick Reference: PR Check Status

| Check Name                    | Purpose               | Blocks | Runs When          |
| ----------------------------- | --------------------- | ------ | ------------------ |
| **CodeQL Analysis**           | Security scanning     | ✅ Yes | All PRs to main    |
| **GitGuardian Security**      | Secret detection      | ✅ Yes | All commits/PRs    |
| **Claude Code Review**        | Coding standards      | ❌ No* | All PRs (see §3)   |
| **Issue Management**          | Auto-label issues     | ❌ No  | Issue events       |
| **PR Management**             | Link PRs to issues    | ❌ No  | PR events          |
| **PHPUnit Unit + Regression** | Behavioral test suite | ❌ No* | All PRs            |
| **Static Analysis**           | PHPStan, phpcs, lint  | ✅ Yes | PHP/JS/MD changes  |

\* Not yet a GitHub-required status check — failures are caught by `/finish-issue`'s CI-status gate
before merge, not by GitHub blocking the merge button itself (see issue #1437).

### Security & Code Quality Checks

#### 1. **CodeQL Analysis**

- **What it does**: Static analysis for security vulnerabilities and code
  quality issues in JavaScript
- **When it runs**: On every pull request to main branch
- **Scope**: Analyzes JavaScript files for common vulnerabilities (XSS,
  injection attacks, etc.)
- **Pass criteria**: No critical security vulnerabilities detected
- **Failure impact**: Blocks merge until vulnerabilities are resolved

#### 2. **GitGuardian Security Checks**

- **What it does**: Scans for secrets, API keys, passwords, and credentials in code
- **When it runs**: On every commit and pull request
- **Scope**: All files in the repository for hardcoded secrets
- **Pass criteria**: No exposed credentials or API keys found
- **Failure impact**: Blocks merge and sends security alerts
- **Configuration**: External service, no local configuration files

#### 3. **Claude Code Review**

- **What it does**: Automated code review against Elan Registry coding standards
- **When it runs**: On every push to a PR (no path filter). Two modes, gated by
  branch shape in `.github/workflows/claude-code-review.yml`:
  - `pr-to-milestone-review` (light, Sonnet) — any branch → `milestone/*`, and
    any non-milestone branch → `main` (hotfix / ad-hoc PRs); skipped for
    `[skip-review]` / `[WIP]` titles
  - `milestone-review` (deep) — `milestone/*` → `main`, on open /
    ready-for-review / `deep-review` label, non-draft only
  - `workflow_dispatch` with `pr_number` re-runs the light review on demand
- **Scope**: Enforces coding standards from `docs/development/CODING_STANDARDS.md`
- **Key checks**:
  - **PHP 8+ Type Safety**: Complete type declarations, `declare(strict_types=1)`
  - **Security**: CSRF tokens, parameterized queries, input validation
  - **Architecture**: Custom exceptions, proper error handling
  - **Documentation**: PHPDoc blocks for public methods
  - **Performance**: N+1 queries, caching opportunities
- **Pass criteria**: No blocking issues (❌), warnings (⚠️) acceptable
- **Review format**: Specific feedback with code examples and standard references

### Project Management Automation

#### 4. **Issue Management Automation**

- **What it does**: Automatically manages GitHub issues with labels,
  milestones, and status tracking
- **When it runs**: On issue creation, updates, and closure
- **Key functions**:
  - **Auto-labeling**: New issues get `status: needs-planning`
  - **Priority assignment**: Based on keywords (critical, bug, enhancement, etc.)
  - **Status transitions**: Removes conflicting status labels
  - **Milestone tracking**: Updates progress when issues close
- **Labels applied**: `priority: critical/high/medium/low`, `status: needs-planning/in-progress/needs-review`

#### 5. **PR Management Automation**

- **What it does**: Links PRs to issues and manages development workflow
- **When it runs**: On PR creation, updates, and merge
- **Key functions**:
  - **Issue linking**: Detects "fixes #123", "closes #456" patterns
  - **Status updates**: Updates linked issues based on PR state
  - **Auto-closure**: Closes linked issues when PR merges
  - **Draft handling**: Marks issues as "in-progress" for draft PRs
- **Status flow**: `status: in-progress` → `status: needs-review` → issue closed

### Automated Test Execution

#### 6. **PHPUnit Unit + Regression**

- **What it does**: Runs the PHPUnit `Unit` and `Regression` testsuites (`composer test:quick:ci`
  and `composer test:regression:ci`) — mocked, no database or network required
- **When it runs**: On every PR (open/synchronize) and on push to `main`
  (`.github/workflows/tests.yml`)
- **Scope**: `tests/unit/`, excluding any test tagged `#[Group('known-broken')]` (see
  `tests/README.md`'s "CI vs. Local Test Runs" section) — the local `composer test:quick` command
  runs the same suite without that exclusion, so developers always see the full picture locally.
  The `Unit` step also excludes `#[Group('regression')]` (`tests/unit/regression/`), which the
  separate "Run regression tests" step below covers instead — see `tests/README.md`'s "The
  `regression` Group Exclusion" section for why the two steps stay disjoint
- **Pass criteria**: All included tests pass
- **Failure impact**: Not (yet) a GitHub-required status check — merge isn't blocked at the
  platform level. Enforcement instead relies on `/finish-issue`'s CI-status gate, which polls
  actual check status and requires explicit confirmation before merging. See
  [#1437](https://github.com/elan-registry/registry/issues/1437) for the rationale.
- **Configuration**: `.github/workflows/tests.yml`; PHP 8.3 via `shivammathur/setup-php`

### Milestone Release PRs

When merging a milestone branch (e.g., `milestone/v2.14.0`) into `main`, the
release PR body **must** include GitHub closing keywords for all issues resolved
in that milestone. Individual PRs merged into the milestone branch target the
milestone branch — not `main` — so their closing keywords won't trigger
auto-closure. Only the final release PR merged into `main` will auto-close
issues.

**Example release PR body:**

```markdown
## Issues Resolved

Closes #533 - Dropzone validation error display
Closes #534 - Mock DB string return types
Closes #535 - Car validation exception test coverage
```

Use `Closes`, `Fixes`, or `Resolves` followed by `#NNN`. See the
[Release Notes Template](RELEASE_NOTES_TEMPLATE.md) for the full format.

### Special Workflow Behaviors

#### Version Check Behavior

- **Feature branches**: Version check **skipped** (allows development work)
- **Main branch**: Full version validation runs (ensures production quality)
- **Why skipped on PR**: Prevents blocking development, validation happens on merge

#### Check Dependencies

- **Required for merge**: CodeQL, GitGuardian, Claude Review (if applicable)
- **Informational only**: Project management automation (doesn't block merge)
- **Manual override**: Repository administrators can override if needed

### Troubleshooting Common Check Failures

#### CodeQL Failures

- **Cause**: Security vulnerabilities in JavaScript code
- **Resolution**: Fix identified vulnerabilities, rerun analysis
- **Common issues**: XSS vulnerabilities, unsafe DOM manipulation

#### GitGuardian Failures

- **Cause**: Hardcoded secrets, API keys, or credentials detected
- **Resolution**: Remove secrets, use environment variables instead
- **Prevention**: Use `.env` (plaintext, chmod 600) or environment variables for sensitive data

#### Claude Review Failures

- **Cause**: Coding standard violations (missing types, CSRF, documentation)
- **Resolution**: Address specific issues mentioned in review comments
- **Reference**: Follow examples and standards in review feedback

## 🛠️ Local Development Tools

### Pre-Commit and Pre-Push Quality Checks

Pre-commit hooks validate PHP coding standards, markdown formatting, and run
fast unit tests before each commit. Pre-push additionally runs the full
integration suite and blocks the push on failure, on pushes touching `app/`,
`usersc/classes/`, or `tests/integration/` (#1439).

```bash
./scripts/setup-git-hooks.sh    # One-time setup
git commit --no-verify           # Bypass pre-commit (emergency only)
git push --no-verify             # Bypass pre-push, including the integration gate
```

**See `scripts/README.md`** ("Git Hooks Management" / "Troubleshooting Git Hooks") for full hook step-by-step details and troubleshooting.

## 📋 Complete Production Deployment Process

### Step-by-Step Deployment

> For a release, `/release-milestone` prints a filled-in copy of
> [RELEASE_INSTRUCTIONS_TEMPLATE.md](RELEASE_INSTRUCTIONS_TEMPLATE.md) — the per-release
> sequence (test first, validation gate, prod, verification). The steps below are the
> underlying mechanics it is built from.

1. **Create git tag**: `git tag vX.Y.Z`
2. **Commit changes** (if any) before creating tag
3. **Push to remotes** - deployment hooks automatically update VERSION file.
   On deployment remotes, push the tag **before** the deploy push (the hook
   writes VERSION during the second push and needs the tag present), and
   deploy the **tagged commit**, never the current `main` — `main` may hold
   commits merged after the tag that are not part of the release:
   - GitHub: `git push origin main && git push origin vX.Y.Z`
   - Test: `git push test vX.Y.Z && git push test 'vX.Y.Z^{commit}:main'`
   - Production: `git push prod vX.Y.Z && git push prod 'vX.Y.Z^{commit}:main'`
4. **Run database migrations** (see below)
5. **Verify deployment** by checking version display matches git tag on
   production site
6. **Complete post-deployment verification** (see checklist below)

### Database Migrations

After every deployment, run pending migrations:

```bash
# 1. Verify no orphaned rows that would block the FK migration
#    (must return 0 before applying for the first time)
# SELECT COUNT(*) FROM car_transfer_requests WHERE existing_car_id NOT IN (SELECT id FROM cars);

composer install --no-dev --optimize-autoloader   # ensure vendor/ is up to date
composer migrate:status                            # preview what will run
composer migrate:dry-run                           # confirm SQL before applying
composer migrate                                   # apply pending migrations
```

**If a migration fails:** The runner stops at the failed migration and exits non-zero. Fix the migration file
and redeploy — Phinx retries only the failed migration (already-applied ones are skipped).

**Check migration status at any time:**

```bash
composer migrate:status   # list pending and applied migrations
```

**Automated deployment:** any push to the `prod` remote's `main` ref (normally `git push prod 'vX.Y.Z^{commit}:main'`) runs `composer install` and `composer migrate`
automatically via the post-receive hook. The manual steps above serve as a fallback if the hook needs to
be bootstrapped on a fresh server.

**Migrations vs. seeds — the hook only runs migrations.** `composer seed:run`
(`vendor/bin/phinx seed:run` — see `PageRegistrationSeed`, `CarModelsSeed`,
`ElanFactoryInfoSeed` under `database/seeds/`) and `scripts/provision-schema.sh`
are **never** invoked by the post-receive hook. They are provisioning-only,
run by hand when standing up a new environment. If you add a new seed and
expect it to populate test/prod data, a push alone will not do it — run
`composer seed:run` manually against that environment after deploying.

**Trigger-rebuilding migrations:** A migration that drops and recreates triggers (as `20260902104755` —
the first such migration executed live — does for the `cars` table) needs privileges beyond a normal
schema change. Before pushing:

- **Back up the affected tables through the host's phpMyAdmin** (Export → Custom → select the tables
  the migration touches, e.g. `cars` and `cars_hist` → SQL, structure and data). This export — not
  `composer migrate:rollback` — is the rollback path when a migration's `down()` drops columns and
  would lose data written since deploy.

Then confirm on the target database:

- The deploy DB user has the `TRIGGER` privilege (`SHOW GRANTS FOR CURRENT_USER()`).
- If `SHOW VARIABLES LIKE 'log_bin'` is `ON`, `log_bin_trust_function_creators` must also be `ON` (or the
  user needs `SUPER`).

After deploying, confirm `SHOW TRIGGERS LIKE 'cars'` returns 3 rows and
`SELECT version FROM phinxlog WHERE version = 20260902104755` returns one row. (`composer migrate:status`
is not available on the server after a successful deploy — the post-receive hook removes `composer.json`
and `database/` per `.deployignore` once migrations have applied; the push output is the other record.)

### One-Time: Stamping the ElanRegistry Baseline Migration

`database/migrations/20260709000000_add_elanregistry_baseline.php` reproduces the full
ElanRegistry-vs-stock-UserSpice schema diff as a single migration — the schema-of-record for any
newly provisioned environment (`scripts/provision-schema.sh`). Dev and prod already have this exact
schema natively; their databases predate Phinx and were never migrated through it. Running the
baseline migration for real against either would try to `CREATE TABLE car_models` (and 12 other
already-existing tables) and fail immediately.

**Take a full database backup through the host's phpMyAdmin before you begin** (Export → Custom →
all tables → SQL, structure and data). `git push` triggers the post-receive hook, which runs
`composer migrate` immediately — once you push there is no window to intervene, so the backup and
the stamp below must both be done first. A phpMyAdmin export is the rollback path if a migration
behaves unexpectedly against real data; the application's own backup feature runs against the same
database being changed and is not a substitute.

**Before the next `composer migrate` runs on dev or prod** (once, the first time this migration is
ever deployed there — not a repeatable step), manually mark it as already-applied instead of running
it:

```sql
INSERT INTO phinxlog (version, migration_name, start_time, end_time, breakpoint)
VALUES (20260709000000, 'AddElanregistryBaseline', NOW(), NOW(), 0);
```

Verify first that this hasn't already been stamped (`SELECT * FROM phinxlog WHERE version =
20260709000000`) — the `PRIMARY KEY` on `version` makes a duplicate `INSERT` fail loudly rather than
silently, but check anyway before running it against a production database. After the stamp,
`composer migrate` skips `20260709000000` and applies only the migrations genuinely pending on that
environment, exactly like any other deploy.

### Git & Version Control

#### Branch Management Strategy

- `main` branch always contains production-ready code
- All development work happens on feature/phase branches
- Direct commits to main are discouraged

#### Branch Naming Convention

- Milestone branches: `milestone/v{X.Y.Z}` (created by `/start-milestone`)
- Issue branches: `issue/{number}-brief-description` (created by `/start-issue`)
- Bug fix branches: `bug/{number}-brief-description` (created by `/start-issue`)
- Feature branches: `feature/{number}-brief-description` (created by `/start-issue`)
- Hotfix branches: `hotfix/issue-{number}-brief-description`

#### Version Management & Git Tag-Based Versioning

**Automated VERSION File Generation:**

- VERSION file is **auto-generated during deployment** (not manually edited)
- Git post-receive hooks run `git describe --tags > VERSION` on push
- VERSION file added to `.gitignore` (not tracked in git)
- Each environment generates its own VERSION file from its git repository
- Format: `vX.Y.Z` or `vX.Y.Z-N-gHASH` (semantic versioning via git describe)

**Deployment Hooks:**

Test and production servers have a single shared post-receive hook
(`scripts/server-hooks/post-receive`) that automatically:

1. Checkout latest code
2. Run `git describe --tags` and write VERSION file
3. Run `composer install --no-dev --optimize-autoloader`
4. Run `php vendor/bin/phinx migrate` (halts deployment on failure)
5. Run `npm ci --omit=dev && npm run build` to (re)generate vendored frontend
   assets in `usersc/js/`/`usersc/css/` (halts deployment on failure). These
   directories are gitignored build output, not committed — see
   [ADR-018](adr/ADR-018-build-at-deploy-for-frontend-vendoring.md)
6. Self-update the installed hook from the newly deployed working tree
7. Remove dev-only paths listed in `.deployignore` (e.g. `tests/`, `scripts/`,
   `utilities/`, `wiki/`) via `rm -rf`, then remove `.deployignore` itself

**Host requirements:** steps 3-5 require Composer, PHP, and Node/npm to be
resolvable non-interactively on the deploy host's login shell (no `nvm use`
or similar interactive step). Verified against `test.elanregistry.org` during
ADR-018's research: Node v18.20.8 / npm 10.8.2, no shell workaround needed.

**Important:** Never list a persistent, server-writable directory in
`.deployignore` (e.g. `backups/`) — step 7's `rm -rf` would delete it wholesale
on every subsequent push. See #1479, where this happened to the server's
backup directory.

**Important — deploying a change to `post-receive` itself takes two pushes,
not one:** Step 6's self-update copies the new hook file to disk *during* the
currently-running invocation, but that invocation is already executing the
*old* code — it can't reload itself mid-run. So the first push after any
change to `scripts/server-hooks/post-receive` still executes the **old**
hook logic end-to-end (any new steps added in that change do not run yet);
it only installs the new file for the *next* invocation.

A second push is required to actually exercise the new logic — and it must
be a **genuine** push. `git push` short-circuits client-side when the remote
already has the commit ("Everything up-to-date") and never contacts the
server's hook at all, so simply repeating the same push does nothing. Force
an empty commit instead:

```bash
git push test 'vX.Y.Z^{commit}:main'                  # 1st push: old hook runs, self-updates the file
git checkout -q -b tmp/hook-rerun vX.Y.Z              # throwaway commit on a temp branch, never merged
git commit --allow-empty -m "chore: trigger post-receive hook rerun"
git push test tmp/hook-rerun:main                     # 2nd push: new hook logic runs for the first time
git push --force test 'vX.Y.Z^{commit}:main'          # put the server's main back on the tagged commit
git checkout -q main && git branch -D tmp/hook-rerun
```

Repeat independently for `prod` when you deploy there — each environment's
installed hook is updated (and needs re-triggering) on its own schedule,
based only on when that specific remote last received a push.

**Development:**

Run `./scripts/update-version.sh` to generate VERSION file locally after creating tags.

**Version Display:**

- `ApplicationVersion::get()` reads VERSION file (unchanged)
- Deployment timestamp shows VERSION file modification time
- Example output: `v2.9.1-rc1 (2025-12-14 10:30:00)`

## ✅ Post-Deployment Configuration Requirements

**CRITICAL:** After deploying code changes to production, always verify and update:

### UserSpice Page Permissions

- **Problem:** New pages and admin scripts need proper access permissions registered
- **Solution:** Run `app/admin/scripts/maintenance/21-Fix-Page-Permissions.php`
  on test, verify, then run on prod — this scans all pages and corrects their
  permission entries in UserSpice's `pages` table
- **Required whenever:** A new page, admin script, or route is added or renamed

### Hooker Hook Registration

- **Problem:** A new hooker plugin hook
  (`usersc/plugins/hooker/hooks/sync_owner_email_on_verify.php`, fixing #1958 —
  a confirmed email change via the verify-by-link flow wasn't syncing to
  `cars.email`) needs to be registered in the `us_plugin_hooks` table before it
  takes effect; a hook file alone does nothing until registered per environment
- **Solution:** Run `app/admin/scripts/fix/26-Register-Verify-Email-Sync-Hook.php`
  on test, verify, then run on prod — the same test-then-prod order as the Page
  Permissions script above, though note the two live in different directories:
  this one is a one-time script under `app/admin/scripts/fix/`, while
  `21-Fix-Page-Permissions.php` is a repeatable one under
  `app/admin/scripts/maintenance/`. Registration is idempotent (safe to re-run) but per-environment —
  running it on test does not register it on prod
- **Required whenever:** This specific one-time deploy fix for #1958 — a
  one-time fix script, not a repeatable maintenance task (contrast with the
  Cron Transport contract, which is per-environment but repeatable)
- **Manual verification** (no automated E2E exists — no Playwright pattern in
  this repo retrieves a Mailtrap-captured confirmation link): log in as a
  test owner with at least one car, change the account email (stages
  `email_new`; `cars.email` should still show the OLD address at this
  point), retrieve the confirmation link from the local Mailtrap inbox
  (see [EMAIL_SYSTEM.md](EMAIL_SYSTEM.md)), click it, confirm the success
  page renders, then check `cars.email` reflects the NEW address for every
  car the owner has and that no `LOG_CATEGORY_DATABASE_ERROR` row mentioning
  `sync_owner_email_on_verify` was logged

### Cron Transport (UserSpice Cron Manager)

UserSpice's Cron Manager (Admin → Settings → Cron Manager) only maintains the
job list in the `crons` table. Nothing runs until something requests
`users/cron/cron.php`; that request is the *transport*, and it is provisioned
per environment outside the codebase. Installed on test and prod on 2026-09-03
(#1872) — neither host had a cron trigger before that date.

> **Contract for cron job authors — read before writing a job.**
>
> - The transport fires **every 10 minutes** on dev, test, and prod
>   (`*/10 * * * *`). Every *active* row in `crons` runs on every hit, in
>   `sort` order — a job executes about 144 times a day whether or not it has
>   work to do.
> - A job must be **idempotent and gate its own cadence**: keep a "last ran"
>   timestamp (in `settings` or the job's own table) or write a "due" query
>   that returns nothing when there is nothing to do. Never assume daily,
>   never assume hourly.
> - `crons_logs` gets one row per job per hit regardless of whether the job did
>   anything, so it cannot tell you how often real work happened. Log real work
>   under the job's own `LogCategories` constant.
> - The interval is a cPanel setting, not code, and can change. Treat 10 minutes
>   as the *maximum latency* before a due job is picked up, not as a schedule a
>   job may rely on. This section is the single place the number is recorded.
> - Runtime budget: a job must finish comfortably inside the interval or it will
>   overlap its own next run.

**What `users/cron/cron.php` does on every hit** (upstream UserSpice, read-only):

1. Logs `Cron request from <ip>.` under the `CronRequest` log category — before
   any access check, so every hit is visible in Admin → Logs.
2. Applies the `cron_ip` allowlist (table below). A denied request logs
   `Cron request DENIED from <ip>.` and stops.
3. Runs every active job and inserts one `crons_logs` row per job
   (`user_id` is `1` for unauthenticated hits).

**`cron_ip` semantics** (Admin → Settings → General). The IP is taken from
`REMOTE_ADDR` only — `X-Forwarded-For` is ignored — so behind Cloudflare it is
the real client address and cannot be spoofed with a header.

| `cron_ip` value | Who may trigger `cron.php` |
| --- | --- |
| empty | anyone — never leave it like this |
| `off` (UserSpice sentinel) | `127.0.0.1` only |
| a literal IP | that IP and `127.0.0.1` |

**Per-environment configuration:**

| Environment | Trigger | Interval | `cron_ip` | Evidence |
| --- | --- | --- | --- | --- |
| dev (MAMP, macOS) | launchd job, see [ENVIRONMENT.md](ENVIRONMENT.md#development-setup) | 10 min | `::1` on this machine (`/etc/hosts` lists both loopbacks and curl prefers IPv6; only `127.0.0.1` is hard-coded, so use whichever address your `CronRequest` log shows) | `~/Library/Logs/ElanRegistry/local-cron.log`, Admin → Logs |
| test.elanregistry.org | cPanel Cron Job, `curl` to the public URL | 10 min | the server's public outbound IP, as shown in the first `CronRequest` entry | Admin → Logs (`CronRequest`), Cron Manager job log |
| elanregistry.org | cPanel Cron Job, `curl` to the public URL | 10 min | same policy, checked independently | same |

The literal server IP is deliberately not published here. Because the cPanel
`curl` targets the public hostname, the request leaves the box, passes through
Cloudflare, and arrives with the server's public IP as `REMOTE_ADDR` — **not**
`127.0.0.1`. `cron_ip=off` would therefore reject it; a literal IP is required.

**Recommended crontab line** (one per host, cPanel → Cron Jobs):

```text
*/10 * * * * /usr/bin/curl -fsS -o /dev/null https://elanregistry.org/users/cron/cron.php
```

`-o /dev/null` discards the body so cPanel does not mail it every run; `-fsS`
stays silent on success but still reports HTTP errors to cron mail. The lines
installed on 2026-09-03 used `-s -k /dev/null` (no `-o`, unnecessary `-k`);
correcting them is pending and does not affect whether jobs run.

**Verifying the transport** (what #1872 checked; repeat after any hosting change):

- [ ] A new `crons_logs` row appears for an active job without anyone touching
      the URL (Cron Manager shows the last-run time per job).
- [ ] A request from an unexpected IP (e.g. your home connection) produces a
      `Cron request DENIED` entry in Admin → Logs — proves the allowlist is
      enforced, not just configured.
- [ ] The interval in cPanel still matches the contract above; if it changed,
      update this section in the same change.

### Deployment Verification Checklist

After each deployment, verify:

- [ ] Maps display correctly: world map on Statistics page, single-marker map on car Details pages (no API key required — uses self-hosted MapLibre GL JS + VersaTiles)
- [ ] All redirected pages work and maintain proper permissions
- [ ] **Purge Cloudflare cache for any static file the release moved, renamed, or
      deleted** (CSS/JS/PDF/image paths). Static assets are served with
      `cache-control: max-age=31536000`, so the edge keeps returning the old
      200 for up to a year after the origin starts returning 301/404 — the
      v2.29.6 deploy left `/docs/assets/document-content.css` cached this way.
      Cloudflare dashboard → Caching → Purge by URL, one entry per old path.
      Run `npm run test:e2e` afterwards; the redirect specs hit those URLs.
- [ ] New pages have appropriate UserSpice permission levels
- [ ] Contact forms send to correct email addresses
- [ ] VERSION file exists on server (created by deployment hook)
- [ ] Deployment hooks executed successfully (check server logs)
- [ ] Test critical user workflows (car registration, editing, contact forms)
- [ ] Database connectivity and functionality
- [ ] Email delivery system functioning
- [ ] Cron transport still firing: a `CronRequest` entry in Admin → Logs within the
      last 10 minutes (see "Cron Transport" above)
- [ ] Image upload and display working
- [ ] Search and filtering functionality
- [ ] Mobile responsiveness maintained

### Database Access

See [ENVIRONMENT.md](ENVIRONMENT.md) for database credentials, MySQL binary path, and connection commands.

## 🛠️ Environment Variables

See [ENVIRONMENT.md](ENVIRONMENT.md) for complete environment configuration (database
credentials, API keys, CAPTCHA keys, phpdotenv plaintext `.env` with `chmod 600`).

See [ENVIRONMENT.md](ENVIRONMENT.md) for `.env` setup steps.

### UserSpice Plugins

**Active Plugins:**

- `Auto Assign Usernames` - Hides username field and auto-assigns usernames
  on registration
- `getSettings Function` - Provides global settings access via getSettings()
  function
- `hooker` - Custom hooks system for code injection points
- `Brevo Sendinblue` - API-based email delivery replacing phpmailer
  (300 emails/day free)

## 🚨 Troubleshooting

### Common Deployment Issues

1. **Version mismatch**: Ensure VERSION file content matches git tag exactly
2. **Permission errors**: Check UserSpice admin panel for new page permissions
3. **Map not rendering**: Check browser console for CSP violations; verify
   `usersc/js/maplibre-gl.min.js`, `usersc/css/maplibre-gl.css`, and
   `usersc/js/versatiles-colorful.json` are deployed
4. **Email not working**: Check Brevo/Sendinblue API configuration
5. **Database connection**: Verify production database credentials

### Rollback Procedure

If deployment fails:

1. **Immediate rollback**: `git push prod previous-working-tag`
2. **Verify rollback**: Check version display and core functionality
3. **Investigate issue**: Review error logs and deployment differences
4. **Fix and redeploy**: Address issues and follow deployment process again

### Emergency Contacts

- **Hosting Support**: A2 Hosting technical support
- **Domain Management**: Check domain registrar for DNS issues
- **Database Issues**: Contact hosting provider database support

---

**📖 Related Documentation:**

- [CLAUDE.md](../../CLAUDE.md) - Essential development guidance
- [Development Workflow](https://github.com/elan-registry/registry/wiki/Development-Workflow) - Development processes (wiki)
- [ENVIRONMENT.md](ENVIRONMENT.md) - Environment setup and configuration
