# Deployment Guide

> **Maintainer:** Registry maintainer or release manager.
> **Repository review:** 2026-09-27.
> **Review triggers:** Deploy hook, remote, CI, cron, or host changes.
> **Claim scope:** Tracked workflow and script behavior are repository facts.
> Remote URLs, host configuration, and deploy-hook state are live settings. No
> live host check is recorded here. Record the date and evidence when you check
> those settings.

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
- **Configuration**: `.github/workflows/tests.yml`; PHP 8.4 via `shivammathur/setup-php`

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
integration suite and blocks the push on failure, when the push changes code
the suite covers (#1439; trigger rules in `scripts/README.md`).

```bash
./scripts/setup-git-hooks.sh           # One-time setup
git commit --no-verify                 # Bypass pre-commit (emergency only)
git push --no-verify                   # Bypass all push hooks
SKIP_INTEGRATION_GATE=1 git push       # Bypass integration gate only
```

**See `scripts/README.md`** ("Git Hooks Management" / "Troubleshooting Git Hooks") for full hook step-by-step details and troubleshooting.

## 📋 Complete Production Deployment Process

### Step-by-Step Deployment

> For a release, `/finish-milestone` renders a filled-in copy of
> [RELEASE_INSTRUCTIONS_TEMPLATE.md](RELEASE_INSTRUCTIONS_TEMPLATE.md) — the per-release
> sequence (test first, validation gate, prod, verification) — which `/review-milestone`
> and `/release-milestone` both reuse. The steps below are the underlying mechanics it is
> built from.

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

### Patch Release from main

A patch release ships a hotfix outside the open milestone. Use it only when
production is broken, data is at risk, or there is a security exposure
([ISSUE_WORKFLOW.md](ISSUE_WORKFLOW.md#interrupts-and-the-hotfix-track)).
`/start-issue <N> --hotfix` and `/finish-issue` merge the fix into `main`
through a PR. Then do these steps by hand. Never push to `test` or `prod`
until the user asks for that deploy.

1. **Find the newest release tag and what ships.** Every commit on `main`
   after that tag ships in the patch, not only the hotfix:

   ```bash
   git fetch origin --tags
   git describe --tags --abbrev=0 origin/main
   git log --oneline <newest-tag>..origin/main
   ```

2. **Choose the version.** Add a fourth number to the newest tag, for example
   `v2.30.4` → `v2.30.4.1` (as `v2.30.1.1` did). The next `vX.Y.Z` belongs to
   the open milestone. Confirm the version with the user.
   `/release-milestone` Step 3 accepts four-part tags
   (`scripts/check-version-newer.sh`).
3. **Tag `main` and push the tag to `origin`.** Use an annotated tag. Here
   `HEAD` is the commit to release, so tag `HEAD`. `git describe HEAD` must
   print the new tag with no suffix:

   ```bash
   git checkout main
   git pull --ff-only origin main
   git tag -a vX.Y.Z.N -m "Release vX.Y.Z.N: hotfix for #NNN"
   git describe HEAD
   git push origin vX.Y.Z.N
   ```

4. **Create the GitHub release as a draft:**

   ```bash
   gh release create vX.Y.Z.N --draft --verify-tag \
     --title "Elan Registry vX.Y.Z.N — Production Hotfix" \
     --generate-notes --notes-start-tag <newest-tag> \
     --repo elan-registry/registry
   ```

5. **Deploy the tag when the user asks.** Test first, then production. Push
   the tag before the branch, and deploy the tagged commit, never `main`
   (see [Production Deployment Commands](#-critical-production-deployment-commands)):

   ```bash
   git push test vX.Y.Z.N && git push test 'vX.Y.Z.N^{commit}:main'
   git push prod vX.Y.Z.N && git push prod 'vX.Y.Z.N^{commit}:main'
   gh release edit vX.Y.Z.N --draft=false --repo elan-registry/registry
   ```

   Then do the [Deployment Verification Checklist](#deployment-verification-checklist).
6. **Merge `main` into the open milestone branch,** so the milestone keeps the
   fix and its next release does not undo it:

   ```bash
   git checkout milestone/vX.Y.Z
   git pull origin milestone/vX.Y.Z
   git merge origin/main
   git push origin milestone/vX.Y.Z
   ```

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

On a dry run, `20261012000000_move_deliverability_flags_to_profiles` prints
"Dry run: this migration has no dry-run output. It reads data that earlier steps
create." and changes nothing. It reads
data that earlier steps create, so Phinx cannot preview its SQL.

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

### Migration That Moves Email Flags to `profiles` (#2101)

Migration `20261012000000_move_deliverability_flags_to_profiles` makes the
owner's `profiles` row the system of record for email deliverability. It
drops `email_bounced`, `email_bounced_address` and `email_suppressed` from
`cars` and `cars_hist`, rebuilds the three `cars` triggers, and adds columns
and a unique key to `profiles`. Migration `20261012000001` adds two columns to
`deleted_accounts_archive`. See [DATABASE.md](DATABASE.md) for the columns.

Follow these rules for the deploy:

1. **Deploy the migration and the code together.** Use one push. The new code
   no longer reads the dropped columns. The old code reads them and fails once
   the migration has run. The post-receive hook checks out the code, runs
   `composer install`, then runs the migration. Until the migration ends,
   owner and admin pages that read an email flag return a 500 error. Push at
   a quiet time. This gap is accepted.
2. **Back up before the push.** Export `cars`, `cars_hist`, `profiles` and
   `deleted_accounts_archive` through the host's phpMyAdmin (structure and
   data). A code rollback needs this backup (see "Rollback Procedure" and
   "Roll Back This Migration" below).
3. **Turn the webhook and the cron jobs off.** Turn off the verification
   feature switch in the Verification tab (see
   [EMAIL_SYSTEM.md](EMAIL_SYSTEM.md#asymmetric-enabledisable-gate)). The
   switch stops the Brevo webhook and the send batch. Since #2358, it does not
   stop the reconciliation job or the suppression-sync job. Pause both with
   `UPDATE er_cron_job_runs SET enabled = 0 WHERE job_name IN
   ('brevo_reconciliation', 'brevo_suppression_sync');`. A webhook event or a
   cron job that writes a flag during the run can race the copy. Turn the
   switch on and set `enabled` back to 1 after the checks below pass.
4. **Check for duplicate profiles.** The migration stops, and changes nothing,
   when two `profiles` rows share a `user_id`. Run this before the push. It
   must return no rows.

   ```sql
   SELECT user_id, COUNT(*) FROM profiles GROUP BY user_id HAVING COUNT(*) > 1;
   ```

5. **Check the privileges** in "Trigger-rebuilding migrations" above. The
   migration drops and recreates the `cars` triggers.
6. **Do not clear a flag between a failed run and its re-run.** The migration
   runs again cleanly after a failure at any step. Phase 2 writes a flag only
   when the profile flag is 0 or is set for another address. An admin who clears
   a flag in between makes the re-run copy the old car flag back.
7. **Rehearse on a copy of production.** `composer migrate:dry-run` prints a
   notice for this migration and shows no SQL. Before the push, run the
   migration once on production data:
   1. Get a fresh production dump, for example the file that
      `scripts/refresh-local-db.sh --fetch` writes to
      `~/Downloads/unibrain_registry.sql`.
   2. Create an empty local schema whose name matches `elanregi_*`, for
      example `elanregi_rehearsal`, and import the dump into it.
   3. Run the migration on that schema:
      `docker compose exec -u www-data -e DB_NAME=elanregi_rehearsal app composer migrate`.
   4. Check that the run ends with no error and that the output lists no
      case that you do not expect. Then drop the schema.

The migration output names user IDs and car IDs only, never an address. It is
in the push output. It lists each case: `profiles` rows inserted, bounces
copied, old-address bounces dropped, opt-outs restored, blocks copied, blocks
with no known cause, and old-address suppressions dropped.

After the deploy, check on the target database:

- `SELECT version FROM phinxlog WHERE version IN (20261012000000, 20261012000001)`
  returns two rows.
- `SHOW COLUMNS FROM cars LIKE 'email%'` returns only `email`.
- `SHOW TRIGGERS LIKE 'cars'` returns 3 rows.
- `SHOW INDEX FROM profiles WHERE Key_name = 'uniq_profiles_user_id'` returns one row.

#### Roll Back This Migration

Restore the backup from rule 2, then push the previous code. Do not use
`composer migrate:rollback` on the server: after a deploy, the post-receive
hook removes `composer.json` and `database/` (`.deployignore`), so the
command and the migration files are not there.

1. Turn off the verification feature switch and pause the two Brevo cron
   jobs, as in rule 3.
2. In the host's phpMyAdmin, drop the tables `cars`, `cars_hist`, `profiles`
   and `deleted_accounts_archive`. Import the rule-2 export.
3. Delete the migration records, so that the next deploy runs the migration
   again:
   `DELETE FROM phinxlog WHERE version IN (20261012000000, 20261012000001);`
4. Push the previous release, as in "Rollback Procedure", step 1.
5. Check the restored state. `SHOW COLUMNS FROM cars LIKE 'email%'` returns
   `email`, `email_bounced`, `email_bounced_address` and `email_suppressed`.
   `SHOW TRIGGERS LIKE 'cars'` returns 3 rows.
6. Turn the verification feature switch on again. Set `enabled` back to 1
   for the two paused cron jobs:
   `UPDATE er_cron_job_runs SET enabled = 1 WHERE job_name IN
   ('brevo_reconciliation', 'brevo_suppression_sync');`.

A flag or profile change made after the deploy is lost, because the backup
is older. A restore keeps every value that the migration changed. The
`down()` methods do not: they lose the `cars_hist` flag values, the flags of
a `noowner` car, the block cause, the dates, `email_address_since`,
`email_address_seen`, and the opt-out kept for an archived account.

### Migration That Keys Email Events by User (#2358)

Migration `20261013000000_key_er_email_events_by_user` changes
`er_email_events`. It adds `user_id`, makes `car_id` optional, and replaces
the unique key `(car_id, brevo_message_id, event)` with
`(user_id, brevo_message_id, event)`. It sets `user_id` for each row, deletes
the rows that match no user, and collapses duplicate rows. See
[DATABASE.md](DATABASE.md#er_email_events---brevo-delivery-status-event-log-1887-2358)
for the schema and the matching rules.

Follow these rules for the deploy:

1. **Back up before the push.** Export `er_email_events` through the host's
   phpMyAdmin (structure and data). The migration deletes and merges rows. The
   `down()` method restores the schema only. This backup is the only full
   rollback.
2. **Deploy the migration and the code together.** Use one push. The new code
   needs `er_email_events.user_id`. The old code writes no `user_id` and fails
   once the migration has run.
3. **Turn the webhook and the cron jobs off.** Turn off the verification
   feature switch. It stops the webhook and the send batch. Pause the
   reconciliation and suppression-sync jobs, because the switch does not stop
   them:
   `UPDATE er_cron_job_runs SET enabled = 0 WHERE job_name IN
   ('brevo_reconciliation', 'brevo_suppression_sync');`.
   An event that arrives during the run can write a row that the migration
   then reads or deletes. After the checks below pass, turn the switch on and
   set `enabled` back to 1.
4. **Run the report script before the push.**
   `app/admin/scripts/maintenance/29-Report-Email-Event-Rekey.php` is read-only.
   It shows the event IDs that the migration matches by car owner, matches by
   address, drops and collapses. Production does not have the script until the
   deploy. So run it on a copy of production data, as in rule 7 of the
   [#2101 migration](#migration-that-moves-email-flags-to-profiles-2101): load a
   fresh dump into a local `elanregi_*` schema, point the local app at it, and
   open the script as an admin. Check the counts and IDs. The migration output
   names the same IDs. After the migration has run, the script shows "already
   migrated" and no counts.
5. **Rehearse the migration** on the same copy:
   `docker compose exec -u www-data -e DB_NAME=elanregi_rehearsal app composer migrate`.
   `composer migrate:dry-run` prints a notice for this migration and shows no
   SQL. Compare the run output with the report.

The migration output names event IDs only, never an address. It is in the
push output.

The migration stops before it changes the keys in two cases. Each stop
leaves the committed data phase in place.

- **A row still has `user_id` NULL.** An event arrived during the run, so
  rule 3 was not followed. The error names the count. Stop the event source
  and run the migration again. The data phase runs again and gives the late
  row a user, or drops it.
- **Two rows share `user_id`, `brevo_message_id` and `event`.** The new unique
  key cannot be built. The error names the count and the lowest event ID of
  up to five such groups. Delete or merge those rows by hand, then run the
  migration again. It skips the steps that are done.

After the deploy, check on the target database:

- `SELECT version FROM phinxlog WHERE version = 20261013000000` returns one row.
- `SHOW COLUMNS FROM er_email_events` shows `user_id` as NOT NULL and
  `car_id` as NULL.
- `SHOW INDEX FROM er_email_events` lists
  `uniq_er_email_events_user_message_event`,
  `idx_er_email_events_user_occurred` and
  `idx_er_email_events_brevo_message_id`. It does not list a unique key on
  `car_id`.

Then run `app/admin/scripts/maintenance/21-Fix-Page-Permissions.php` on test
and verify. After that, run it on production. The script sets the permissions
of pages that already have a row in the UserSpice `pages` table. It does not
create rows. UserSpice creates the row for `29-Report-Email-Event-Rekey.php`
when an admin first opens that page. So open the script once as an admin
before you run `21-Fix-Page-Permissions.php`. See
[UserSpice Page Permissions](#userspice-page-permissions).

#### Roll Back This Migration (#2358)

A rollback loses data. Restore the backup from rule 1.

1. Turn off the verification feature switch and pause the two Brevo cron
   jobs, as in rule 3.
2. In the host's phpMyAdmin, drop the table `er_email_events`. Import the
   rule-1 export.
3. Delete the migration record, so that the next deploy runs the migration
   again: `DELETE FROM phinxlog WHERE version = 20261013000000;`.
4. Push the previous release, as in "Rollback Procedure", step 1.
5. Turn the switch on and set `enabled` back to 1.

Events recorded after the deploy are lost, because the backup is older.
`composer migrate:rollback` runs `down()`. It deletes the rows with
`car_id` NULL and restores the schema only. It does not restore the rows that
`up()` deleted or collapsed. Use it only on a database where that loss is
acceptable. After a deploy, the server has no `composer.json` and no
`database/` directory (`.deployignore`), so it cannot run there.

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
- Hotfix branches: the same `issue/`, `bug/` or `feature/` names, created by
  `/start-issue {number} --hotfix` from `origin/main`. The PR base `main`
  marks a hotfix. The commands get the issue number from these three
  prefixes, so a hotfix has no prefix of its own.

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
   [ADR-018](adr/ADR-018-build-at-deploy-for-frontend-vendoring.md).
   The hook sets `GOMAXPROCS=2` for this step. See "Process limit on the
   shared host" below
6. Self-update the installed hook from the newly deployed working tree
7. Remove dev-only paths listed in `.deployignore` (e.g. `tests/`, `scripts/`,
   `utilities/`, `wiki/`) via `rm -rf`, then remove `.deployignore` itself

**Host requirements:** steps 3-5 require Composer, PHP, and Node/npm to be
resolvable non-interactively on the deploy host's login shell (no `nvm use`
or similar interactive step). Verified against `test.elanregistry.org` during
ADR-018's research: Node v18.20.8 / npm 10.8.2, no shell workaround needed.

**Process limit on the shared host:** the host runs CloudLinux. LVE limits
the account to 75 processes, and it counts each thread. The live sites,
the ssh session and the build all count against the same limit. The host
has 64 CPUs, so by default esbuild's Go runtime keeps about 35 threads alive
until `npm run build` exits. Before the hook set `GOMAXPROCS=2`, the build's
first HTTPS fetch could not start a thread, and Node aborted (`Failed to load
CA certificates off thread: resource temporarily unavailable`, or only
`Aborted`). cPanel → Resource Usage shows these as NPROC faults. With
`GOMAXPROCS=2`, esbuild keeps about 6 threads. Keep the variable when you
change the npm steps.

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
- **Solution:** Open a new page once as an admin, so UserSpice creates its
  `pages` row. Then run `app/admin/scripts/maintenance/21-Fix-Page-Permissions.php`
  on test, verify, then run on prod. The script scans the pages that have a
  `pages` row and corrects their permission entries
- **Required whenever:** A new page, admin script, or route is added or renamed

### Brevo Sendinblue Plugin

Git tracks no file under `usersc/plugins/sendinblue/`. The registry uses the
upstream plugin unmodified. It needs version 1.8.0 or later. Do these steps once
in each environment (test, then production). Do them before you turn on the
verification switch. The switch refuses to turn on until they are done.

1. Install the plugin, or update it to version 1.8.0 or later, with the plugin
   manager (Admin → Spice Shaker).
2. Make sure the plugin is active on the Plugins page. `usersc/plugins/plugins.ini.php`
   must have `sendinblue = 1`. UserSpice loads the override only for an
   active plugin.
3. Open the plugin page (Admin → Plugins → Brevo Sendinblue). Run its pending
   migrations. Migration `00006` creates the table `plg_sendinblue_messages`
   if it does not exist. Migration `00008` (version 1.8.0) adds the column
   `plg_sendinblue_messages.tags` and the columns `plg_sendinblue.tag_mode`
   and `plg_sendinblue.tag_fixed`.
4. Activate the override. A plugin update replaces `override.RENAME.php` but
   does not change `override.php`. Readiness does not check the content of
   `override.php`, and an override from before 1.8.0 drops the verification
   tag. After each plugin update, open "Show file contents" under "Override
   Update Available" on the plugin page. Then click "Replace my override.php
   with the bundled version".
5. Set "Tag untagged mail" to "Calling file name". This is database state.
   Later deploys keep it.
6. Send a test email (Test Email on the plugin page). Check in Brevo that the
   message has a tag.

The admin Verification tab shows the reason when Brevo is not ready. See
[EMAIL_SYSTEM.md](EMAIL_SYSTEM.md#mail-tags) for the tag table.

### Cron Transport (UserSpice Cron Manager)

UserSpice's Cron Manager (Admin → Settings → Cron Manager) only maintains the
job list in the `crons` table. Nothing runs until something requests
`users/cron/cron.php`; that request is the *transport*, and it is provisioned
per environment outside the codebase. Installed on test and prod on 2026-09-03
(#1872) — neither host had a cron trigger before that date.

> **Contract for cron job authors — read before writing a job.**
>
> - **Job files must live in `users/cron/`.** `cron.php` resolves each active
>   row's `file` column via `sanitizePath($file, $cronBaseDir)`, where
>   `$cronBaseDir` is hard-coded to `users/cron/` — this is a path-traversal
>   guard, not a style preference, and a file placed anywhere else cannot be
>   resolved and will not run. `users/cron/sample.php` is the stock UserSpice
>   template to copy from; register the new file's name in the `crons` table
>   (via a migration/seed, not by hand in the admin UI) so it ships with the
>   code that depends on it.
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
> - The interval is a cPanel or Compose setting, not code, and can change. Treat
>   10 minutes as the *maximum latency* before a due job is picked up, not as a
>   schedule a job may rely on. This section is the operational record of what
>   the transport is actually configured to; `CRON_TRANSPORT_INTERVAL_MINUTES`
>   in `usersc/includes/config.php` is the in-code mirror cron jobs read (see
>   #2001) — if the schedule changes, update this section, that constant, its
>   `tests/bootstrap-unit.php` mirror, and
>   `VerificationSettings::CRON_TRANSPORT_INTERVAL_MINUTES_FALLBACK` together.
> - Runtime budget: a job must finish comfortably inside the interval or it will
>   overlap its own next run.

#### Timeout & Crash Isolation

The transport's in-process dispatcher (#1889, #2034) poses two hazards every
new job author must mitigate: (1) an unhandled exception in one job can kill
every job scheduled after it in the same cron hit, and (2) a hung or slow job
can delay or prevent subsequent jobs from running at all. New cron jobs must
extend `AbstractCronJob` (in `usersc/classes/Cron/AbstractCronJob.php`), which
provides a template-method architecture that enforces four controls:

**Crash isolation**: The template method wraps `execute()` in a
`try/catch(\Throwable)` that logs the failure under
`LogCategories::LOG_CATEGORY_CRON_JOB_FAILURE` and never rethrows — ensuring
one job's bug cannot cascade to the next job in `cron.php`'s dispatcher loop.
Any subclass's own `try/catch` blocks inside `execute()` are purely for
recovery logic; a top-level catch is redundant and should be removed.

**A `set_time_limit()` backstop**: After the `CronJobGuard` claim succeeds and
immediately before calling `execute()`, the template method calls
`set_time_limit(CRON_TRANSPORT_INTERVAL_MINUTES * 60)` — bounding how long a
hung job can block the dispatcher loop. The constant is synchronized from
`usersc/includes/config.php` (see the "Runtime budget" bullet above). The
ordering matters: `cron.php` dispatches every job in a single PHP process, so
calling this before the claim would reset the shared execution clock once per
enabled job on every hit — including the ~143 of 144 daily hits where the claim
fails immediately — making the effective budget N-jobs × the interval rather
than bounding it. Only a claimed run arms the backstop. `runNow()` (the manual
admin path) deliberately does not call it at all: that runs inside a page
request which already has the web SAPI's own `max_execution_time`.
**Note:** This backstop is real only under the HTTP SAPI (how the transport
actually runs in this codebase); under CLI (`php -r` or CLI testing), PHP's
default `max_execution_time` is already `0` (no limit), so the call has no
effect there, no harm — tests and CLI invocations are not time-constrained
anyway.

**A job-owned `enabled` flag** (`er_cron_job_runs.enabled`, distinct from
UserSpice's own `crons.active`): The template method reads this before
proceeding and resolves it to one of three non-running states, logged
distinctly because they need opposite operator responses. A row with
`enabled = 0` is a deliberate pause and logs under
`LogCategories::LOG_CATEGORY_CRON_JOB_SKIPPED`; a **missing** row (never
seeded, or deleted) and an **unreadable** row (DB error) are genuine faults
and log under `LogCategories::LOG_CATEGORY_CRON_JOB_FAILURE`. All three
return without running. The skip line is rate-limited to roughly once per
guard interval rather than once per cron hit — a job left paused would
otherwise emit ~144 identical rows a day, the same noise issue #1974 removed
from the transport. That throttle is keyed on its own column,
`er_cron_job_runs.last_skip_logged_at`, which the template method stamps each
time the skip line actually fires. It deliberately does **not** key on
`last_run_at`: that column is written only by `CronJobGuard::claim()`, and a
disabled job returns before claiming, so its value is frozen for as long as
the job stays paused — any "older than one interval" test against it latches
permanently true and degrades back to logging on every hit. A new job needs no
extra work for this; it is entirely inside `AbstractCronJob`. This lets an
operator pause a single job (via direct DB update or a future admin UI control
— #2038) without deleting the entire `crons` row from UserSpice, and with
visibility that the job is disabled (not just silently not running). The
`er_cron_job_runs` table (introduced in #2034) also seeds
`BrevoEventReconciliationJob` as the reference implementation — see that job
and its shim `users/cron/brevo_event_reconciliation.php` for the required
patterns.

**The site-wide verification switch** (`VerificationSettings::isEnabled()`,
`er_verification_settings.enabled`): a job opts in. `AbstractCronJob` has the
method `refusesWhileSwitchOff(): bool`, which returns `false` by default. A job
that sends email overrides it to return `!$settings->isEnabled()`. Only
`SendVerificationBatchJob` does this. `run()` checks the method before the
job-owned `enabled` flag and before the claim, so a refused run does not
change `er_cron_job_runs.last_run_at`. `runNow()` checks it first. The
reconciliation and suppression-sync jobs do not override it. They run with the
switch off, because the prune, the backfill and the suppression import must
not wait for sends. A job's separate `runNowWithSummary()` method (if used)
bypasses `run()` and `runNow()`. It runs its work with no switch check. See
`docs/development/CLASSES.md`'s `AbstractCronJob` entry for the full "Used By"
list.

**Implementing a new cron job:**

1. Extend `AbstractCronJob` and implement `jobName(): string` (must match an
   entry in `CronJobGuard::ALLOWED_JOB_NAMES` in `usersc/classes/Cron/CronJobGuard.php`),
   `guardIntervalHours(): int` (>= 1), and `execute(): void` (the actual work).
   Do not override `run()` or `runNow()` — both are `final`.
   **Note**: Your job runs with the site-wide verification switch off. If the
   job sends email, override `refusesWhileSwitchOff()` to return
   `!$settings->isEnabled()`, so that the switch stops it. A job that reads
   from Brevo or prunes data must not override it.
2. Add the job name to `CronJobGuard::ALLOWED_JOB_NAMES` (manually maintained
   allowlist).
3. Create a migration seeding a row in `er_cron_job_runs` for your job, with
   both `job_name` and `enabled` set appropriately.
4. Create a migration or seed seeding a corresponding row in the `crons` table,
   pointing to your shim file in `users/cron/`.
5. Write a shim file (e.g., `users/cron/your_job_name.php`) that instantiates
   your job class with its dependencies and calls `->run()`.

**For manual "run now" admin maintenance scripts** (bypassing the guard and
enabled check, when an operator explicitly triggers a run), instantiate the
job and call `->runNow()` instead of `->run()` — still crash-isolated, but no
guard claim or enabled check. `app/admin/scripts/maintenance/28-Reconcile-Brevo-Suppressions.php`
(#1923) and `app/admin/scripts/maintenance/27-Reconcile-Brevo-Events.php`
(#2061) are both references for a job whose manual entry point *returns a
value* instead: each job class adds a sibling `runNowWithSummary()` method
(not an override of the `final` `runNow()`) that logs and rethrows on failure
rather than swallowing it, so the admin script can render a real summary — or
a real error — instead of `runNow()`'s void, never-throws contract. A job with
no such need can still call the plain `runNow()`.

**What `users/cron/cron.php` does on every hit.** Unlike the rest of `/users/`
(upstream UserSpice, not modified per CLAUDE.md's Template Customization
Rules), `users/cron/` carries project-specific changes documented in this
section — `cron.php` itself includes project logging/hook calls, and job
files live alongside it:

1. Applies the `cron_ip` allowlist (table below). A denied request logs
   `Cron request DENIED from <ip>.` under the `CronRequest` log category and
   stops. A non-denied hit is not logged here at all (#1974 removed the
   unconditional per-hit log line — 144 rows/day/environment with no
   diagnostic value); instead it records
   `er_verification_settings.last_cron_request_at` via
   `VerificationSettings::recordCronRequest()`, which powers the admin
   dashboard's cron-health indicator. The recorded timestamp proves cron was
   *accepted*, where the old log only proved something reached the URL.
2. Runs every active job and inserts one `crons_logs` row per job
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
| dev (Docker) | the `cron` Compose service, started with `docker compose --profile cron up -d`, see [ENVIRONMENT.md](ENVIRONMENT.md#development-setup) | 10 min | `off`. The service shares the `app` container's network namespace, so `cron.php` sees the request's `REMOTE_ADDR` as `127.0.0.1`, which `off` admits. It sends `Host: localhost:$APP_HOST_PORT`, so emailed links use the browser's address. `off` denies every other caller, including a Cloudflare tunnel | `docker compose logs cron`, `er_verification_settings.last_cron_request_at` (DB Explainer or Admin → Verification tab) |
| test.elanregistry.org | cPanel Cron Job, `curl` to the public URL | 10 min | the server's public outbound IP, as shown in a `Cron request DENIED from <ip>.` entry if misconfigured | `er_verification_settings.last_cron_request_at`, Cron Manager job log |
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
- [ ] **Purge Cloudflare cache for any static file the release moved, renamed,
      deleted, or whose *content* changed at the same path** (CSS/JS/PDF/image
      paths). Static assets are served with `cache-control: max-age=31536000`,
      so the edge keeps returning a stale 200 for up to a year — for a
      moved/renamed/deleted file that means the old 301/404 never surfaces
      (the v2.29.6 deploy left `/docs/assets/document-content.css` cached this
      way); for `usersc/js/*`/`usersc/css/*` — rebuilt in place under the same
      filenames on every deploy via ADR-018 — it means different edge PoPs can
      keep serving pre-deploy JS/CSS indefinitely after a release that touched
      any vendored frontend asset, with **no path change to detect**: some
      users get the new build, others silently keep the old one depending on
      which PoP they hit, producing symptoms that look user- or role-specific
      (the v2.30.1 test deploy: MapLibre failed to load for owner/anon
      sessions but worked for admin, purely because of which cached PoP each
      session landed on — `curl -I` showing `cf-cache-status: HIT` with a
      pre-deploy `age` on `usersc/js/*` was the tell). Purge
      `usersc/js/*`/`usersc/css/*` on every deploy that ran `npm run build`,
      not only when a path is added/removed. Cloudflare dashboard → Caching →
      Purge by URL, one entry per old path (or a prefix purge for
      `usersc/js/*`/`usersc/css/*`). Run `npm run test:e2e` afterwards; the
      redirect specs hit those URLs.
- [ ] New pages have appropriate UserSpice permission levels
- [ ] Contact forms send to correct email addresses
- [ ] VERSION file exists on server (created by deployment hook)
- [ ] Deployment hooks executed successfully (check server logs)
- [ ] Test critical user workflows (car registration, editing, contact forms)
- [ ] Database connectivity and functionality
- [ ] Email delivery system functioning
- [ ] Cron transport still firing: `er_verification_settings.last_cron_request_at`
      within the last 10 minutes (see "Cron Transport" above)
- [ ] `usersc/vericode_secret.php` exists and is non-empty on this server. It
      is gitignored/untracked, so a fresh checkout has none — `getVericodeSecret()`
      (`users/helpers/us_helpers.php`) generates one on first use, but if the
      file is later lost or replaced (redeploy to a fresh directory, restored
      from a backup taken before the file existed, etc.) every previously
      hashed `users.vericode`/`cars.vericode` value silently stops matching,
      with no error — verification links just fail. Confirm the same file
      persists across deploys (it must not be wiped by the deploy process) and
      is byte-identical between test and prod only if verification codes are
      meant to be portable between them (they normally are not — each
      environment should have its own secret).
- [ ] `.user.ini` exists in the docroot and sets `error_log` to this host's
      log (`grep error_log <docroot>/.user.ini`). Git does not track it. If it
      is missing, PHP stops writing to that log, and nothing reports it.
      Paths: [ENVIRONMENT.md](ENVIRONMENT.md#php-error-logging).
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
- `Brevo Sendinblue` (version 1.8.0 or later) - API-based email delivery
  replacing phpmailer (300 emails/day free). Git does not track it. Install
  and configure it as in
  [Brevo Sendinblue Plugin](#brevo-sendinblue-plugin).

## 🚨 Troubleshooting

### Common Deployment Issues

1. **Version mismatch**: Ensure VERSION file content matches git tag exactly
2. **Permission errors**: Check UserSpice admin panel for new page permissions
3. **Map not rendering**: Check browser console for CSP violations; verify
   `usersc/js/maplibre-gl.min.js`, `usersc/css/maplibre-gl.css`, and
   `usersc/js/versatiles-colorful.json` are deployed
4. **Email not working**: Check the Brevo/Sendinblue API configuration and the plugin version (1.8.0 or later)
5. **Database connection**: Verify production database credentials

### Rollback Procedure

If deployment fails:

1. **Immediate rollback**: `git push prod 'vPREVIOUS^{commit}:main' --force`
   (for example `'v2.30.1^{commit}:main'`). The post-receive hook skips tag
   refs, so a push of a tag alone does not redeploy. The hook writes `VERSION`
   from the newest tag that reaches the deployed commit.
2. **Verify rollback**: Check version display and core functionality
3. **Investigate issue**: Review error logs and deployment differences
4. **Fix and redeploy**: Address issues and follow deployment process again

**After the #2101 migration:** a code rollback with `git push` alone breaks the
site, because the older code reads the dropped `cars` columns. Follow "Roll
Back This Migration" in "Migration That Moves Email Flags to `profiles`
(#2101)": restore the backup first, then push.

If the hook stops after the checkout (for example `npm run build failed —
deployment halted`), the new code is already live, but the steps after the
failure did not run: the hook self-update, the deployment log and the
`.deployignore` cleanup. On the shared host, `npm run build` can fail once
from the process limit (see "Process limit on the shared host") and pass on
the next run. To finish
the deploy without a new commit, run the hook again on the server for the same
commit:

```bash
ssh <host> 'cd ~/git/elanregistry.git && echo "<old-sha> $(git rev-parse main) refs/heads/main" \
  | GIT_DIR=$HOME/git/elanregistry.git hooks/post-receive'
```

The hook is idempotent. Use `~/git/test.elanregistry.git` for test.

### Emergency Contacts

- **Hosting Support**: A2 Hosting technical support
- **Domain Management**: Check domain registrar for DNS issues
- **Database Issues**: Contact hosting provider database support

---

**📖 Related Documentation:**

- [CLAUDE.md](../../CLAUDE.md) - Essential development guidance
- [Development Workflow](https://github.com/elan-registry/registry/wiki/Development-Workflow) - Development processes (wiki)
- [ENVIRONMENT.md](ENVIRONMENT.md) - Environment setup and configuration
