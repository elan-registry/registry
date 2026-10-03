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
   state. Runs the full `composer test:integration` suite (~20s, requires
   a working `.env.test.local` — see `docs/development/ENVIRONMENT.md`) and
   blocks the push (exits non-zero) on any test failure or an unreachable
   test database. When it runs:
   - **Gated files** are `*.php` files under `app/`, `usersc/classes/`, or
     `tests/integration/`. A push with no gated-file changes skips the gate.
     Renames count as a delete plus an add (`--no-renames`), so moving a
     gated file out of those paths still runs it.
   - **What a push is diffed against:**
     - an existing branch whose remote tip is known locally: that remote tip;
     - a new branch, or one whose remote tip isn't known locally: the
       merge-base with its closest parent. Local and `origin` `milestone/*`
       branches and `origin/main` are ranked together, and the one with the
       fewest commits between merge-base and the pushed commit wins. The
       pushed branch itself, and any milestone branch sitting at the pushed
       commit, are never chosen;
     - `main`: its remote tip, or the merge-base with `origin/main` when the
       remote tip is unknown.
   - **Fail-safe:** if no base resolves or a git command fails, the suite
     runs with a warning.
   - **Trade-offs:** merging `main` or the parent into a branch runs the
     suite. A remote tip pushed with `--no-verify` or
     `SKIP_INTEGRATION_GATE=1` isn't re-checked by a later push.
   - **At most once per push.** The suite tests the working tree (`HEAD`),
     so a multi-branch push reuses one result, and pushing a branch that
     isn't `HEAD` prints a warning.
   - **Where it runs (#2171, #2245).** In this checkout's Docker `app`
     container by default, since Docker is the only supported dev
     environment (MAMP retired, #2180) and `.env.test.local` has
     `DB_HOST=db`, which resolves only on the Compose network:
     `docker compose exec -T -u www-data app composer test:integration`. The
     host is a fallback, used only when `.env.test.local` does not point at
     the Docker `db` service. If the stack isn't running, the push is
     blocked with `docker compose up -d` as the fix; it never falls back to
     the host or skips. `INTEGRATION_GATE_RUNNER=host|docker` overrides the
     detection. The detection and run logic (`_integration_runner`,
     `_run_integration_suite`) live in `scripts/lib/integration-runner.sh`,
     shared with `scripts/run-verification-suite.sh` below.
   - **Cache.** `$(git rev-parse --git-path integration-passed)` holds a
     single key — tree, test database name and runner (host or Docker) — for
     the most recent pass, so it only skips a re-push of an identical tree to
     the same environment. It is written only when
     the pushed commit is `HEAD` and `git status --porcelain` is empty (no
     modified or untracked, non-ignored files). Force a
     rerun with `rm "$(git rev-parse --git-path integration-passed)"`.

   Bypass: `SKIP_INTEGRATION_GATE=1 git push` skips only this gate;
   `git push --no-verify` skips every push hook.
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

## Cleanup ledger

The cleanup ledger is the one open GitHub issue with the `cleanup-ledger`
label. It holds small cleanup finds, grouped under a level-3 (`###`) heading for each
file. See `docs/development/ISSUE_WORKFLOW.md` for the workflow. These scripts
read and tick ledger items. Both need the `gh` CLI, signed in (`gh auth status`).

Both scripts source `scripts/lib/ledger.sh`. That library finds the ledger
issue, fetches its body and comments, and parses the items.

**Comment authors.** The scripts read only the comments whose
`author_association` is `OWNER`, `MEMBER` or `COLLABORATOR`. Other comments
are ignored. The ledger issue is public, so an outside comment could otherwise
add items that the scripts list and tick.

**Truncated `gh` output.** Each record that `gh` returns ends with a space
and an `END` marker. When a record has no marker, or has text after it, the
script stops with exit code 2 and changes nothing. macOS `base64 -d` exits 0 on short
input, so this check, not the decoder, stops a truncated body from being
written back.

**Empty input.** When stdin has no paths (or no requests after `none` and
blank lines are removed), the script makes no `gh` call, exits 0, and writes
one note to stderr, for example
`ledger-items-for-files.sh: no input paths, no query made`.

**Control characters.** Both scripts remove control characters (other than
TAB) from the text that they print. The text that they match and write back
keeps every byte.

**Heading match rule.** The scripts take each backticked token in a level-3
(`###`) heading. A token that ends in `/` matches every path under that directory. Any
other token matches only the exact path. Text after the tokens, such as
`(est. −960)`, is ignored. A section ends at the next level-2 (`##`) or
level-3 heading. An item is a line at column 0 that starts with `- [ ]` (open)
or `- [x]` (ticked), then a space.

**Exit codes (both scripts).**

| Code | Meaning |
| --- | --- |
| 0 | The query ran. Empty output means no match. |
| 1 | Usage error. |
| 2 | A `gh` call failed, `gh` output was truncated, or there is not exactly one open `cleanup-ledger` issue. |

### ledger-items-for-files.sh

Lists the open ledger items for a set of files. Input on stdin is one
repo-relative path per line. Blank lines are ignored. The script takes no
arguments. Output on stdout is one `path: item text` line for each open item
under a matching heading. An item prints once.

```bash
# Open items for every file this branch changes
git diff --name-only "$(git merge-base HEAD origin/main)"..HEAD | scripts/ledger-items-for-files.sh

# Open items for one file
echo "usersc/join.php" | scripts/ledger-items-for-files.sh
```

The `/review-pr` and `/commit-push-pr` commands run this script. `/finish-issue`
runs it to report the items that remain.

### ledger-tick-items.sh

Ticks ledger items. Input on stdin is `path: item text` lines, the same form
that `ledger-items-for-files.sh` prints. A leading `-` bullet marker is allowed, so a bullet
list from a PR body works as-is. `none` lines and blank lines are ignored.

For each line, the script ticks the first open item with exactly that text
under a heading that matches the path. It changes `[ ]` to `[x]` on that line
only. It writes each changed ledger source (body or comment) back with one
`PATCH`. A failed `PATCH` exits with code 2.

- No match: a warning on stderr, and nothing is ticked.
- Duplicate text under one heading: the first open item is ticked, with a
  warning on stderr.
- stdout: one `ticked: path: item text` line for each ticked item.
- Failed `PATCH`: one `not ticked: path: item text` line on stderr for each
  item of that source. The other sources are still sent.

The tick reads the whole body and comments, then writes each changed one back
in full. An edit that someone makes to the same body or comment between the
read and the `PATCH` is lost. This is an accepted risk: one maintainer edits
the ledger, and the window is a few seconds.

```bash
# Tick one item
echo "usersc/join.php: remove the unused \$legacy variable" | scripts/ledger-tick-items.sh

# Tick the items listed in a PR body (/finish-issue does this after the merge)
body_file="$(mktemp)"
gh pr view 2275 --repo elan-registry/registry --json body --jq .body > "$body_file"
set -o pipefail
awk '{ sub(/\r$/, "") }
     /^## / { s = ($0 == "## Ledger items"); next }
     s && /^- /' "$body_file" | scripts/ledger-tick-items.sh
```

The hermetic tests are `tests/hooks/test-ledger-items-for-files.sh` and
`tests/hooks/test-ledger-tick-items.sh`. They use a stub `gh`.

## Database

### refresh-local-db.sh

Refreshes the local development database from production: fetches a dump over
SSH, upserts the registry tables, masks every email address, and syncs car
images into a persistent local cache.

**Requirements:** this checkout's Docker stack must be running
(`docker compose up -d --wait`). The target env file must set `DB_HOST=db`. The
script runs `mysql` and `mysqldump` inside the `db` container, using that
container's own credentials, so no local MySQL client is needed. The env file
supplies only `DB_NAME` and `DB_HOST`. `--db NAME` must match `elanregi_*`, the
container user's grant scope, and `DB_HOST` is still checked.
`--images-only` needs no Docker.

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

A rehearsal fills the integration-test schema (the `DB_NAME` in
`.env.test.local`, for example `elanregi_dev_test`) with registry data. Each
run also adds `cars_hist` rows through the `cars` triggers. The integration
suite then fails (it runs out of memory in `BackupCriticalTablesTest`).
Afterwards, restore the backup that the rehearsal's first run made
(`db-backups/<that DB_NAME>_<timestamp>.sql.gz`), with the restore command
below. It is the only backup taken before any import. A later run backs up
the schema that an earlier run already filled.

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
the script exits non-zero. The import has already run, and it is not rolled
back, so the rows stay for inspection.
Restore manually from `db-backups/`:

```bash
gunzip < <checkout>/db-backups/<file>.sql.gz | docker compose --project-directory <checkout> exec -T db sh -c 'MYSQL_PWD="$MYSQL_PASSWORD" mysql -u"$MYSQL_USER" <DB_NAME>'
```

City and IP columns are intentionally left intact — they are coarse-grained and
needed to exercise location and map features.

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

Consolidated setup script (#2035) covering all four Test/Prod tier×role
combinations. Saves an authenticated session to
`tests/playwright/.auth/user-<tier>-<role>.json`. Launches a headed browser;
fails fast if Cloudflare Turnstile is enabled on the target environment
rather than hanging or attempting to bypass it — disable Turnstile manually
first, then re-enable it once the auth file is saved.

Credentials are read from `.env.local` (`E2E_<TIER>_<ROLE>_USERNAME`/
`_PASSWORD` — see `docs/development/ENVIRONMENT.md`), not from environment
variables or 1Password.

```bash
node scripts/playwright-auth-setup.js <test|prod> <admin|nonadmin>

# Examples:
node scripts/playwright-auth-setup.js test admin
node scripts/playwright-auth-setup.js prod nonadmin
```

## Brevo Webhook Setup (#1888)

Scripts for configuring and verifying the Brevo webhook that feeds the
bounce-detection endpoint (`app/api/webhooks/brevo.php`, #1887). Both are
test-environment-only by design. See `docs/releases/RELEASE_NOTES_v2.30.2.md`'s
"Required Actions After Deployment" for the full post-deployment runbook
these scripts support, and `docs/development/EMAIL_SYSTEM.md` § "Brevo
Webhooks — Verified Behaviour (#1871)" for the underlying payload/event facts
(these scripts don't re-derive those).

### spike-1888/brevo-webhook-capture.php

Temporary capture endpoint deployed by hand to test.elanregistry.org to
verify a Brevo webhook registration is configured correctly — URL reachable,
the real `BREVO_WEBHOOK_TOKEN` `Authorization: Bearer` header arrives intact,
and the subscribed events fire — before the real endpoint's code is deployed
there. Logs redacted request details to a JSONL file outside the web root.
Not shipped by the deploy hook (`scripts/` is removed on deploy); reaches a
server only via manual `scp` and is deleted from the server once
verification is complete.

Edit `CAPTURE_FILE`'s `<cpanel-account>` placeholder before copying. Requires
`BREVO_WEBHOOK_TOKEN` to already be set in that server's `.env` (see
`docs/development/ENVIRONMENT.md` — Brevo Webhook Authentication).

**Deploy path matters:** `REPO_ROOT` is hardcoded as `__DIR__ . '/../..'`, so
the file must land exactly two directories below the directory holding
`vendor/autoload.php` and `.env` (the site root) — e.g.
`~/test.elanregistry.org/scripts/spike-1888/capture.php`, preserving this
repo's own `scripts/spike-1888/` subpath under the site root. One directory
level too shallow and `is_file($autoloader)` fails silently into the same
404 the auth-failure path returns, with no way to tell the two apart from
the outside.

### spike-1888/brevo-register-webhook.php

CLI script that registers, lists, and deletes Brevo transactional webhooks
via Brevo's REST API — the checked-in, scriptable alternative to manual
dashboard clicks that #1888's acceptance criteria require.

```bash
# Register a webhook (test URLs only — no bypass for production)
php scripts/spike-1888/brevo-register-webhook.php \
  --create --url='https://test.elanregistry.org/scripts/spike-1888/capture.php' \
  --token=<same value as that server's BREVO_WEBHOOK_TOKEN>

# List existing transactional webhooks
php scripts/spike-1888/brevo-register-webhook.php --list-webhooks

# Delete a webhook by id
php scripts/spike-1888/brevo-register-webhook.php --delete --id=123
```

Reuses `scripts/spike-1871/brevo-send-test.php`'s proven Brevo API helpers
(config loading from `plg_sendinblue`, the curl wrapper, redaction) rather
than duplicating logic ad hoc — see that file for the shared security
rationale. `scripts/spike-1871/` itself is retained separately and still
used for sending Mailtrap bounce fixtures during verification.

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
docker compose exec -u www-data app scripts/provision-schema.sh   # (re)builds the schema if missing/stale

# Reproduce the failure directly, in the app container
docker compose exec -u www-data app composer test:integration
```

See `docs/development/ENVIRONMENT.md` — "Test Database Isolation" for setup.

### Need to Bypass Hooks Temporarily

```bash
# Emergency only — fix issues before merging
git commit --no-verify -m "message"
git push --no-verify              # skips every push hook, including the integration gate
SKIP_INTEGRATION_GATE=1 git push  # skips only the integration gate; keeps the /review-pr reminder
SKIP_REVIEW_PR_REMINDER=1 git push  # silences only the /review-pr reminder
rm "$(git rev-parse --git-path integration-passed)"  # not a bypass: forces the gate to rerun
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
