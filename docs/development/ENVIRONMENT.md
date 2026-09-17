# Environment Variables Documentation

This document covers environment variables and environments used in the Elan
Registry application.

## Database Access

### Local Development (MAMP MySQL 8.0)

Access the development database using MAMP's MySQL 8.0:

```bash
# MySQL CLI access (credentials from .env.local file)
/Applications/MAMP/Library/bin/mysql80/bin/mysql -h 127.0.0.1 -P 8889 \
  -u [DB_USER from .env] -p \
  -D [DB_NAME from .env]
# Enter password from .env.local when prompted
```

### Remote Database Access (Test/Production)

Test and production databases require SSH tunnel or direct connection:

```bash
# Test environment: https://test.elanregistry.org
# Production environment: https://elanregistry.org
# Database credentials are in .env.local file
# See DEPLOYMENT.md for SSH tunnel setup and connection details
```

## Overview

The Elan Registry uses **vlucas/phpdotenv** v5 for environment variable loading from plaintext `.env` files with `chmod 600` filesystem permissions.

### Loading System

- **Plaintext Storage**: Variables stored in `.env` (plaintext file)
- **Permissions**: `chmod 600` restricts file to web server user only
- **Library**: `vlucas/phpdotenv` v5
- **Loading**: Variables loaded in Phase 1.6 of `users/init.php` via `Dotenv::createImmutable()->safeLoad()`

## Environment Variables

### Database Configuration

**Usage**: `users/init.php` (Phase 1.6–1.7)

- `DB_HOST` - Database server hostname/IP (e.g., `localhost`)
- `DB_USER` - Database username (e.g., `elan_registry_user`)
- `DB_PASS` - Database password
- `DB_NAME` - Database name (e.g., `elanregi_spice`). For development, use the dev database.
  For integration tests, use a separate dedicated test schema (see "Test Database Isolation" below)

### Local Development Environment Flag

**Usage**: `usersc/includes/rate_limits_dev_override.php`

- `US_ENVIRONMENT` — set to `development` in `.env` (git-ignored, local-only)
  to multiply every rate-limit `_max` threshold 100x, so local browser/Playwright
  testing doesn't trip `login_attempt`'s circuit breaker. Defaults to
  `production` (no-op) when unset. **Never set this in a deployed `.env`.**

  This logic deliberately lives in a separate file, not in
  `usersc/includes/rate_limits.php` — that file is fully regenerated
  (overwritten, not merged) by the in-app Rate Limiting Dashboard on every
  save, which would silently delete any code appended there.

### Admin & Feedback Email Recipients

**Usage**: `usersc/includes/custom_functions.php` (`getAdminEmails()`/`getFeedbackEmail()`)

- `ADMIN_EMAILS` — admin notification recipient address(es), comma-separated
  if multiple
- `FEEDBACK_EMAIL` — feedback-form recipient address

Both fall back to `registrar@elanregistry.org` if unset or empty. Formerly
web-editable `settings` table columns (`elan_admin_emails`/`elan_feedback_email`);
moved to `.env` in #1067 to close a web-writable path to reroute these
addresses via a compromised admin session — see PR #1823.

One-time migration: `scripts/generate-config.php` reads the live `settings`
row and appends these two keys to `.env` (preserving all other keys), then
re-applies `chmod 600`. Deletable from the repo once test/prod are both
confirmed populated — it is not ongoing deploy infrastructure.

### Brevo Webhook Authentication

**Usage**: `app/api/webhooks/brevo.php`

- `BREVO_WEBHOOK_TOKEN` — bearer token Brevo must present
  (`Authorization: Bearer <token>`) on every call to the webhook receiver
  (#1887). Compared with `hash_equals()`; an empty or missing value rejects
  **every** request rather than accepting everything (fail-closed).

**Generating a good token:** use at least 32 bytes (256 bits) of
cryptographically secure randomness, hex- or base64-encoded — do not hand-type
a password or reuse a value from elsewhere. On any machine with OpenSSL:

```bash
openssl rand -hex 32
```

Set the same value on both sides: this app's `.env` (`BREVO_WEBHOOK_TOKEN=...`,
`chmod 600 .env`) and the Brevo-side webhook configuration for that
environment's URL (webhook registration is #1888). Rotate by generating a new
value and updating both sides together — updating only one side rejects every
webhook call until they match again. Not needed in local dev — no public URL
reaches a dev machine, so Brevo can never call it (see the note under
[Test Database Isolation](#test-database-isolation) and
`docs/development/EMAIL_SYSTEM.md`'s "Brevo Webhooks — Verified Behaviour"
section for why webhook testing happens on test.elanregistry.org instead).

### Cloudflare Turnstile CAPTCHA

**Usage**: `usersc/includes/turnstile.php`

- `TURNSTILE_SITE_KEY` — Turnstile widget site key (public; rendered in HTML)
- `TURNSTILE_SECRET_KEY` — Turnstile secret key (private; server-side token verification)

Omit either key to disable Turnstile (off mode — forms work without CAPTCHA).
Production keys: Cloudflare Dashboard → Turnstile → your site.
See [test key combinations](#testing-turnstile-in-development) below.

#### Testing Turnstile in Development

Turnstile requires HTTPS — the widget iframe is served over `https://` and
browsers block cross-protocol frame loading, causing **TurnstileError 110200**
on plain `http://localhost`.

#### Option A — Disable Turnstile (simplest)

Remove or omit either key from `.env`. The widget is hidden and forms work
without CAPTCHA validation. Use this when Turnstile behaviour is not under test.

#### Option B — Cloudflare Tunnel (test the full widget)

`cloudflared` creates a temporary public HTTPS URL that proxies to your local
MAMP server. Cloudflare Tunnel terminates TLS upstream and forwards HTTP
internally, setting the `X-Forwarded-Proto: https` header so `$is_https` is
`true` and Turnstile enables.

1. **Install `cloudflared`**:

   ```bash
   brew install cloudflare/cloudflare/cloudflared
   ```

2. **Start the tunnel** (while MAMP is running):

   ```bash
   cloudflared tunnel --url http://localhost:9999
   ```

   The command prints a temporary `https://*.trycloudflare.com` URL — open
   that in your browser instead of `http://localhost:9999`.

3. **Choose test keys** based on what you are testing:

   | Scenario           | `TURNSTILE_SITE_KEY`       | `TURNSTILE_SECRET_KEY`                | Widget result                  | Server result    |
   | ------------------ | -------------------------- | ------------------------------------- | ------------------------------ | ---------------- |
   | Always pass        | `1x00000000000000000000AA` | `1x0000000000000000000000000000000AA` | Green check ✓                  | `success: true`  |
   | Widget block       | `2x00000000000000000000AB` | `2x0000000000000000000000000000000AB` | Shows blocked / "Troubleshoot" | `success: false` |
   | Server-side reject | `1x00000000000000000000AA` | `2x0000000000000000000000000000000AB` | Green check ✓                  | `success: false` |

   - **Always pass** — use for normal development; widget auto-verifies, form submits.
   - **Widget block** — the widget itself shows a failed state before the form is submitted.
     A "Troubleshoot" link appears — this is expected Cloudflare behaviour for this test key.
   - **Server-side reject** — the widget shows a green check (client-side pass), but
     `verifyTurnstile()` returns `false` on the server. Use this to test the PHP
     validation path — the form submission is blocked with the CAPTCHA error message —
     independently of the widget UI.

> **Note:** The tunnel URL changes every run. Browser DevTools → Network tab
> will show requests to `challenges.cloudflare.com` succeeding under HTTPS.

### Local Playwright Base URL

**Usage**: `playwright.config.js` and `playwright.config.dev.js` (local configs only —
not `playwright.config.prod.js` or `playwright.config.test.js`, which stay hardcoded to
their real deployed environments)

- `PLAYWRIGHT_BASE_URL` — overrides the default local Playwright `baseURL`
  (`http://localhost:9999/ElanRegistry/Registry/`) for developers whose MAMP
  document root serves the site from a different path. Must include a
  trailing slash, same as the default value — `page.goto('')` collapses the
  path without one. Unset behaves identically to today.

  Excluded from the prod/test configs intentionally, to avoid accidentally
  pointing a destructive test run at the wrong live site.

### Playwright Test Credentials (Local/Dev)

**Usage**: `playwright.config.js`'s `admin` project (via `auth.setup.js`),
`playwright.config.dev.js`'s `admin`/`logged-in-non-admin` projects (via
`auth-dev.setup.js` / `auth-non-admin.setup.js`)

- `E2E_DEV_ADMIN_USERNAME` / `E2E_DEV_ADMIN_PASSWORD` — credentials for an admin test
  account, used to populate a storageState file via a live login through
  `usersc/login.php` each time the corresponding `setup` project runs —
  `tests/playwright/.auth/user.json` for `playwright.config.js`,
  `tests/playwright/.auth/user-dev.json` for `playwright.config.dev.js` (kept separate so
  a dev run can't overwrite the Local storageState). Required for the
  `admin` project; if unset, the setup test skips and the storageState file is
  removed, so `admin` tests run unauthenticated instead of failing on a missing
  file.
- `E2E_DEV_NONADMIN_USERNAME` / `E2E_DEV_NONADMIN_PASSWORD` — credentials for a
  non-admin test account, used the same way by `auth-non-admin.setup.js` to populate
  `tests/playwright/.auth/user-dev-non-admin.json`, feeding
  `playwright.config.dev.js`'s `logged-in-non-admin` project (infrastructure only —
  no spec targets it yet).
- All four are gitignored via `.env.local` and must never be committed. See
  `.env.example` for the placeholder entries.
- These are Local/Dev-only accounts (plain-HTTP MAMP, no Turnstile) — same
  `E2E_<TIER>_<ROLE>_*` naming scheme as the Test/Prod credentials below (#2059
  renamed these from `TEST_USERNAME`/`TEST_PASSWORD`/`TEST_USERNAME2`/`TEST_PASSWORD2`
  for consistency).

### Playwright Test Credentials (Test/Prod)

**Usage**: `scripts/playwright-auth-setup.js` (consolidated setup script,
issue #2035), invoked manually to populate the pre-authenticated storageState
files that `tests/playwright/e2e/auth-staleness.setup.js` /
`auth-staleness-admin.setup.js` check for staleness before each Test/Prod run
(`playwright.config.test.js` / `playwright.config.prod.js`'s `admin` project;
`logged-in` also wires up but is infrastructure only — no non-admin spec
targets it yet).

- `E2E_TEST_ADMIN_USERNAME` / `E2E_TEST_ADMIN_PASSWORD` — admin account on
  `test.elanregistry.org`.
- `E2E_TEST_NONADMIN_USERNAME` / `E2E_TEST_NONADMIN_PASSWORD` — non-admin
  account on `test.elanregistry.org`.
- `E2E_PROD_ADMIN_USERNAME` / `E2E_PROD_ADMIN_PASSWORD` — admin account on
  `elanregistry.org`.
- `E2E_PROD_NONADMIN_USERNAME` / `E2E_PROD_NONADMIN_PASSWORD` — non-admin
  account on `elanregistry.org`.
- Both environments run HTTPS with an active Cloudflare Turnstile challenge,
  which blocks automated login entirely — these credentials are never used
  for a live per-run login. Instead a human manually disables Turnstile, runs
  `node scripts/playwright-auth-setup.js <test|prod> <admin|nonadmin>` once
  per tier/role to produce `tests/playwright/.auth/user-<tier>-<role>.json`,
  then re-enables Turnstile. See `docs/testing/PLAYWRIGHT_E2E.md` for the
  full setup process.
- All eight are gitignored via `.env.local` and must never be committed. See
  `.env.example` for the placeholder entries.

### Multi-Clone Session Isolation

**Usage**: `users/init.php` (`$GLOBALS['config']['session']` /
`['remember']`)

- `SESSION_NAME` / `TOKEN_NAME` / `REMEMBER_COOKIE_NAME` — override
  UserSpice's `$_SESSION` key names (`user`, `token`) and its existing
  hardcoded remember-me cookie name (see `users/init.php`). Only needed
  when running more than one local
  clone of this repo from the same MAMP host/port (e.g. `Registry/` and
  `Registry2/`, a supported workflow for working two milestones in parallel
  — see the top-level `Web/ElanRegistry/CLAUDE.md`). Every clone shares the
  same PHP session cookie (`PHPSESSID`, scoped `path=/` on the same origin)
  regardless of these vars — `SESSION_NAME`/`TOKEN_NAME` only change which
  key each clone uses *inside* that shared session, so without distinct
  names, one clone's login state and CSRF token silently collide with
  another's — surfacing as inexplicable login failures with no error in any
  log (see #1935). `REMEMBER_COOKIE_NAME` is the one exception: it names an
  actual separate browser cookie, so setting it does give each clone its own
  remember-me cookie rather than just a distinct key within a shared one.
- Unset in production, test, and a single-clone local install — the app
  falls back to the original hardcoded values, so this is a no-op there. Set
  only in the `.env` (not `.env.local`) of whichever clone should get
  distinct session state; the other clone(s) can keep the defaults.
- `SESSION_NAME` also feeds `users/helpers/us_helpers.php`'s vericode-secret
  *fallback* (used only if `usersc/vericode_secret.php` cannot be written —
  see that file's `hash('sha256', mysql/password . session/session_name)`).
  Two clones sharing one database but set to different `SESSION_NAME` values
  will derive different fallback secrets and invalidate each other's
  verification codes on that path. Keep `usersc/vericode_secret.php`
  writable in any multi-clone setup sharing a database to avoid depending on
  this fallback at all.
- See `.env.example` for the placeholder entries.

## Setup & Configuration

### PHP Version

- **Local dev, CI, test, and production**: All now target PHP 8.4.x.
  Production is confirmed running PHP 8.4.25. (Issue #1968 tracked the
  earlier test/prod hold on 8.2; that hold is resolved now that prod is
  confirmed on 8.4.25 — this doc makes no claim about whether #1968 itself
  should be closed.)
- **`phpstan.neon`'s `phpVersion: 80229`** pins static analysis to a
  compatibility floor lower than the actual deployed version — it is a
  deliberate choice to keep first-party code free of 8.3/8.4-only syntax
  (property hooks, asymmetric visibility, etc.), not a claim that any
  environment still runs 8.2. Similarly, `composer.json`'s `>=8.2.29`
  constraint is a compatibility floor, not a statement about what's
  deployed. Neither value needs to change for this doc to be accurate, and
  changing either is a separate decision outside the scope of this note.
- **MAMP Apache PHP version**: MAMP's Apache does not use the
  `/Applications/MAMP/bin/php/php` symlink — it serves PHP via
  `/Applications/MAMP/fcgi-bin/php.fcgi`, a wrapper script MAMP.app
  regenerates on every Apache restart based on the PHP version selected in
  MAMP's Preferences → PHP panel. To switch versions, use MAMP.app's
  Preferences GUI (the wrapper file itself says "Do not modify, it will be
  overwritten"). Verify the actual serving version with a `phpinfo()` page
  load after restarting MAMP's servers, not by checking any symlink.
- **CLI PHP (Homebrew)**: The `php`/`composer` commands on the shell PATH
  resolve to Homebrew's linked PHP, separate from MAMP's Apache-served PHP.
  Keep it on the same target version as MAMP (`brew install php@8.4 && brew
  link php@8.4 --force --overwrite`, then `hash -r`) — `composer
  test:integration` and other CLI-invoked test/tooling commands run under
  whichever version is linked, not MAMP's.

### Docker Dev Environment (optional, experimental)

An alternative to MAMP, available per checkout: a self-contained Docker
Compose stack (`docker-compose.yml` at the repo root of each checkout) —
PHP 8.4 app container, MySQL 8.0, phpMyAdmin — bind-mounting the checkout
as the webroot. Live in `Registry2/` (port 8002/8082) as of issue #2116,
verified against the full toolchain (`composer install`/`test:full`,
`npm run build`, Playwright).

The pattern has also been proven working against `Registry/` (port
8001/8081, same toolchain, same verification) during #2120's
investigation, but that checkout's Docker files were not committed —
`Registry/` is a separate git clone on its own branch with independent,
unrelated in-progress work, and bundling Docker tooling into that
checkout's history belongs to a change made there directly, not to this
repo's PR. If `Registry/`'s Docker environment is wanted, port the
pattern from `Registry2/docker-compose.yml` (ports 8001/8081, network
`registry`, volume `registry_db_data`, DB user `elanregi_spice` — same as
Registry2's, Registry/'s DB just isn't suffixed `2`) as a change on that
checkout's own branch.

Worktrees under `Registry-worktrees/` are explicitly **not** covered —
that directory had zero active worktrees at the time of #2120 and was
removed rather than given unused Docker scaffolding; if worktree usage
resumes, a new compose file following the same pattern (next port in the
sequence, e.g. 8003/8083) is a small addition, not a prerequisite.

```bash
docker compose up -d
docker compose exec -u www-data app composer install
docker compose exec -u www-data app composer test:full
```

The stack includes four services: `app` (PHP 8.4, the main application), `db` (MySQL 8.0), `phpmyadmin` (database inspection, exposed on host port 8082), and `mock-brevo` (a local mock of Brevo's transactional email API, `ghcr.io/c0boleis/mock-brevo:1.0.0`, exposed on host port 8090 for manual inspection — deliberately outside the 8001/8081, 8002/8082, 8003/8083... per-checkout port sequence documented above, since it's an additional service within one checkout's stack, not a new checkout). The `mock-brevo` service is reachable from the `app` container at `http://mock-brevo:8080/v3` over the `registry2` network. For how the application routes email requests to it (the `BREVO_API_HOST` environment variable, the `US_ENVIRONMENT=development` guard, and the Brevo plugin's override activation), see `docs/development/EMAIL_SYSTEM.md`'s "Local Development" section — Docker infrastructure is documented here, app-level wiring lives there.

**Always pass `-u www-data` to `exec`** — it has no compose-file default
and otherwise runs as root, which would root-own anything written into the
bind mount. See each checkout's `docker-compose.yml` header comment for
the full rationale and the port convention (Registry=8001/8081,
Registry2=8002/8082, next checkout=8003/8083, ...) plus the four things
that must change together when copying the pattern to a new checkout
(ports, network name, volume name, `APP_UID`/`APP_GID`).

**MAMP: coexists indefinitely, no deprecation planned.** This is a
deliberate decision (#2120), not a transitional state — applies to any
checkout with a Docker stack, not just Registry2's. Coexistence is
port-only, not data: MAMP and Docker both read the same `.env`, and
`.env`'s `DB_HOST` decides which stack can actually reach a database at
any given moment. `DB_HOST=db` (the Docker stack's setting) is
unreachable from MAMP's PHP process, so MAMP-served pages will fail on any
DB access while `.env` is pointed at Docker. Switch `.env`'s
`DB_HOST`/`DB_PORT` back to MAMP's values (`127.0.0.1`/`8889`) to use MAMP
again; a backup of the original MAMP-pointed `.env` is typically kept
alongside it as `.env.mamp.bak` (gitignored, not committed).

This also means `scripts/provision-schema.sh` and any other script reading
`.env` must run **inside** the container when `.env` is Docker-pointed:
`docker compose exec -u www-data app scripts/provision-schema.sh ...`, not
directly from the host shell.

**Docker DB user needs `SYSTEM_VARIABLES_ADMIN`**, beyond the standard
`GRANT ALL` on `elanregi_*` schemas — some integration tests run
`SET GLOBAL`, which the Docker image's non-root user can't do by default
(MAMP's app DB user apparently can). See
`docker/mysql-init/01-grant-all-elanregi-schemas.sql`'s comment for the
specific test, mechanism, and failure mode; any future checkout's grant
file should include the same `SYSTEM_VARIABLES_ADMIN` line.

**Optional Traefik routing**: `docker-compose.traefik.yml` has placeholder
Docker-label routing (join the external `traefik_proxy` network, `Host()`
rule per checkout) — not applied automatically, since this repo doesn't
own or deploy the HomeLab Traefik instance's config. Merge it manually for
a dev-domain route: `docker compose -f docker-compose.yml -f
docker-compose.traefik.yml up -d`. See that file's header for the
reference pattern this HomeLab already uses elsewhere
(`HomeLab/services/user_services/elan-registry-monitoring-viewer/docker-compose.yml`).
The Docker-label mechanism is the actual live routing path — Traefik's
static config has no route for any ElanRegistry dev domain today.

### Development Setup

1. **Get Database Credentials**:

   Database credentials are stored in `.env.local` file (not committed to git).
   This file should be provided separately and contains local development database credentials.

   See "Database Access" section above for connecting to databases.

2. **Create `.env` from `.env.example`**:

   ```bash
   # Copy the public template
   cp .env.example .env

   # Edit with your local credentials
   # Example contents:
   # DB_HOST=127.0.0.1
   # DB_USER=root
   # DB_PASS=password
   # DB_NAME=elanregi_spice
   ```

3. **Set Secure Permissions**:

   ```bash
   # Restrict to web server user only
   chmod 600 .env
   ```

4. **Set Up Integration Test Database**:

   Integration tests run against a dedicated test schema to avoid damaging the dev database.

   ```bash
   # Copy the test database template
   cp .env.test.local.sample .env.test.local

   # Edit with your test database password (other fields are pre-filled)
   nano .env.test.local

   # Set secure permissions
   chmod 600 .env.test.local

   # Provision the test schema (stock UserSpice base + migrations + seeds)
   ./scripts/provision-schema.sh

   # Run integration tests
   composer test:integration
   ```

5. **Set Up Claude Code Local Overrides (optional)**:

   Personal/machine-specific paths that Claude Code needs (e.g. the local
   GitHub Wiki clone path — see `CLAUDE.md`'s GitHub Wiki section) go in
   `.claude.local.md`, gitignored and not shared with the team.

   ```bash
   cp .claude.local.md.example .claude.local.md
   # Edit with your own local paths
   ```

6. **Local Cron Trigger (optional)**:

   UserSpice's Cron Manager does nothing until something requests
   `users/cron/cron.php` on a schedule. On macOS the development machine uses a
   user launchd agent rather than `crontab`:

   - Label `org.elanregistry.local-cron`, plist in `~/Library/LaunchAgents/`
     (machine-local, not committed)
   - `StartInterval` 600 — every 10 minutes, matching test and prod
   - Runs `curl -s -o /dev/null -w '%{http_code}'` against
     `http://localhost:9999/ElanRegistry/Registry/users/cron/cron.php` and
     appends `<timestamp> status=<code>` to
     `~/Library/Logs/ElanRegistry/local-cron.log`

   ```bash
   launchctl load ~/Library/LaunchAgents/org.elanregistry.local-cron.plist
   tail -3 ~/Library/Logs/ElanRegistry/local-cron.log   # expect status=200 lines
   ```

   `cron.php` only logs when `cron_ip` is already set to something and a
   request's IP doesn't match it (and isn't `127.0.0.1`, which is always
   allowed) — with `cron_ip` empty (the default), the allowlist check never
   runs at all, so nothing to read is logged either way (#1974 also removed
   the unconditional per-hit log, so a *matching* request was never
   discoverable via Admin → Logs regardless). The launchd log
   (`~/Library/Logs/ElanRegistry/local-cron.log`) only records a
   `status=200`/`4xx` HTTP code, not the request's source IP, so it can't
   answer this. To find the address this machine's curl actually connects
   from, deliberately set `cron_ip` to a wrong value first, run the curl
   command above once by hand, and read the resulting
   `Cron request DENIED from <ip>.` line from Admin → Logs (or
   `SELECT ip FROM logs ORDER BY id DESC LIMIT 1`) — that line shows the
   real address regardless of what `cron_ip` was set to. On a standard
   macOS `/etc/hosts` curl reaches `localhost` over IPv6, so this is
   normally `::1`; `cron.php` only hard-codes `127.0.0.1` as the
   always-allowed address, so `::1` must be set explicitly. Once `cron_ip`
   is set correctly, `er_verification_settings.last_cron_request_at`
   (Admin → Verification tab) confirms accepted hits are landing.
   Interval semantics, the allowlist table, and the contract every cron job
   must honour are in
   [DEPLOYMENT.md — Cron Transport](DEPLOYMENT.md#cron-transport-userspice-cron-manager).

### Test Database Isolation

Integration tests are **destructive** — they insert, update, delete, and merge real database
records to verify application logic end-to-end. To prevent accidental damage to the development
database, the test suite requires a dedicated test schema:

- **Separate Schema**: Tests run against `elanregi_spice_test` (or equivalent), never the dev database `elanregi_spice`.
- **Mandatory Configuration**: `tests/bootstrap-integration.php` **fails immediately with an error message** if `.env.test.local` is missing or fails to load.
- **Safety Guards**: Two layers of defense-in-depth in `tests/bootstrap-integration.php` — the loaded
  `DB_NAME` value is checked before connecting, and the *actual* connected database is checked again
  afterward (catching the case where `.env.test.local` omits a `DB_*` key and it gets silently
  backfilled from the root `.env`). Either guard tripping aborts with `exit(1)`. Both guards check
  against the literal name `elanregi_spice` — if the dev database is ever renamed, update these
  checks accordingly.
- Separately, `scripts/provision-schema.sh` guards the one truly destructive operation in this
  workflow — the `DROP DATABASE` on the target schema. It refuses to run against a schema name
  that does not contain `test` (case-folded), or against the database this checkout's application
  is configured to use (`DB_NAME` in `.env.local`/`.env`). Both guards require an explicit
  `--force` to override, since the same script also provisions fresh dev and CI databases.

**Files involved:**

- `.env.test.local` — Test database credentials (gitignored, created once per developer)
- `.env.test.local.sample` — Template with safe defaults (tracked in repo)
- `scripts/provision-schema.sh` — Provisioning script; safe to rerun any time the schema changes
  (e.g. after a new migration) — it drops and recreates only the target schema each run, then
  rebuilds it from `database/vendor/userspice-6.1.4-base.sql`, `composer migrate`, and the Phinx
  seeds. Requires a `mysql` client on `$PATH`, or `MYSQL_BIN` pointing at one (MAMP's client is
  not on `$PATH` by default)

After the initial setup, tests can be re-run safely and repeatedly against the test schema without risking the development database.

**Blocking pre-push gate (#1439):** `.githooks/pre-push` blocks pushes that touch
integration-suite-relevant code on any failure, including an unreachable test
database — set up `.env.test.local` per this section *before* you first touch
those paths, or the push will fail at `tests/bootstrap-integration.php`'s
connectivity check. See `scripts/README.md`'s "Git Hooks Management" section
for exactly which paths trigger it and the bypass flag.

### Production Deployment

```bash
# Create .env from current credentials
# (obtain credentials securely, via 1Password, secure email, etc.)
cat > .env << 'EOF'
DB_HOST=your_production_host
DB_USER=your_production_user
DB_PASS=your_production_password
DB_NAME=your_production_database
EOF

# Set secure file permissions (web server user only)
chmod 600 .env
chown www-data:www-data .env

# After verifying site boots correctly, remove old encrypted files
# (if migrating from SecureEnvPHP)
shred -vfz -n 3 .env.enc .env.key
```

## Code Usage

Environment variables are loaded during application bootstrap and accessed via
PHP's `$_ENV` superglobal:

```php
// Loading (in users/init.php, Phase 1.6)
$dotenv = \Dotenv\Dotenv::createImmutable($abs_us_root . $us_url_root);
$dotenv->safeLoad();
$dotenv->required(['DB_HOST', 'DB_USER', 'DB_PASS', 'DB_NAME']);

// Usage throughout application (phpdotenv populates $_ENV, not putenv)
$host = $_ENV['DB_HOST'];
```

## Credential Management

### .env File (Production/Staging)

The `.env` file contains database credentials for the running environment:

- **Location**: Root directory (not committed to git)
- **Permissions**: `chmod 600` (web server user only)
- **Format**: Plain text key-value pairs
- **Distribution**: Created on server via secure channel (SFTP, SSH, deployment automation)
- **Creation**: Copy from `.env.example` and fill in credentials

**Security**: File permissions (`chmod 600`) combined with `.gitignore` and GitGuardian CI scanning
provide industry-standard protection. See ADR-014 for security analysis.

### .env.local File (Local Development)

The `.env.local` file contains local development database credentials:

- **Location**: Root directory (not committed to git)
- **Permissions**: `chmod 600`
- **Format**: Plain text key-value pairs using `DB_*` variable names
- **Distribution**: Created locally, following the format in `.env.example`
- **Usage**: Local development against the dev database (`elanregi_spice`). Also used
  as the source when cloning structure into the test schema — see
  "Test Database Isolation" above; integration tests themselves use `.env.test.local`,
  not `.env.local`.

**Important**: Never commit `.env`, `.env.local`, or other environment files to version control. All are listed in `.gitignore`.

## Security Requirements

### File Security

- **Never commit** `.env`, `.env.local`, or other environment files to version control
- **Restrict file permissions** to web server user only: `chmod 600 .env`
- **Backup security** — ensure backups are encrypted by hosting provider
- **CI scanning** — GitGuardian detects accidental plaintext secret commits

### API Key Security

As of v2.22.0 the application uses no external map API keys. Map display uses
self-hosted **MapLibre GL JS** with **VersaTiles** tile servers — no Google
Maps key required. Location geocoding uses **Nominatim** (OpenStreetMap) which
also requires no API key.

### Database Security

- **Least Privilege**: Database user should have only necessary permissions
- **Network Security**: Restrict database access to application server
- **Connection Security**: Use SSL/TLS when possible

## PHP Error Logging

PHP errors, warnings, and fatals are logged to per-environment files on
test and production. mod_php is the confirmed PHP SAPI on both servers.

- **Test**: `/home/unibrain/php_error/test.elanregistry.org-php-error.log`
- **Production**: `/home/unibrain/php_error/elanregistry.org-php-error.log`

The destination is resolved at Apache request-time in the root `.htaccess`
via an `HTTP_HOST`-conditional `RewriteRule` that sets an environment
variable consumed by `php_value error_log %{ENV:PHP_ERROR_LOG}` — not by
deploy-time templating, since `.htaccess` is committed once and deployed
identically everywhere. See `.htaccess` (search `PHP_ERROR_LOG`) for the
block.

The block is wrapped in `<IfModule mod_php.c>`, so it silently becomes a
no-op if the server ever moves off mod_php (e.g. to PHP-FPM) — Apache skips
unrecognized `IfModule` bodies without error. If error logs stop appearing
after a server/PHP change, verify mod_php is still the active SAPI.

Local MAMP development is unaffected and continues to use PHP's default
error log location.

## Troubleshooting

**Environment Loading Issues**:

- Verify `.env` file exists and is readable by web server
- Check file permissions: `ls -la .env` should show `-rw-------` (600)
- Ensure `.env` file is not world-readable or group-readable
- Verify ownership: `chown www-data:www-data .env`

**Database Connection Issues**:

- Verify credentials in `.env` are correct
- Test database connection: use MySQL CLI to verify connectivity
- Check database server accessibility from application host
- Verify database user permissions (SELECT, INSERT, UPDATE, DELETE as needed)

**Debug Environment Loading**:

```php
// Check if variables loaded
if (empty($_ENV['DB_HOST'])) {
    error_log('Environment variables not loaded');
}
```

## References

- [vlucas/phpdotenv Documentation](https://github.com/vlucas/phpdotenv)
- [ADR-014: Replace secure-env-php with phpdotenv](adr/ADR-014-replace-secure-env-php-with-phpdotenv.md)
- [MapLibre GL JS Documentation](https://maplibre.org/maplibre-gl-js/docs/)
- [VersaTiles Documentation](https://versatiles.org/) — tile server used for map display
- [Nominatim API Documentation](https://nominatim.org/release-docs/latest/api/Search/) — used for location geocoding (lat/lon lookup on car save)
