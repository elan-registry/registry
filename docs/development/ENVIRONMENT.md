# Environment Variables Documentation

> **Maintainer:** Registry maintainer.
> **Repository review:** 2026-09-27.
> **Review triggers:** PHP runtime, hosting, database, or environment-file changes.
> **Claim scope:** Local paths and code behavior are repository facts. Test and
> production values are live settings. No live host check is recorded here.
> Record the date and evidence when you check those settings.

This document covers environment variables and environments used in the Elan
Registry application.

## Database Access

### Local Development (Docker)

Use phpMyAdmin at `http://localhost:<PMA_HOST_PORT>/` (`8081` for `Registry/`
by default). See the "Docker Dev Environment" section below. The `db`
service has no host port, so a host-side MySQL client cannot reach it.

To run a query from the command line, use the client inside the `app`
container:

```bash
docker compose exec -u www-data app mysql \
  -h db -u [DB_USER from .env] -p \
  -D [DB_NAME from .env]
# Enter DB_PASS from .env when prompted
```

### Remote Database Access (Test/Production)

Test and production databases require SSH tunnel or direct connection:

```bash
# Test environment: https://test.elanregistry.org
# Production environment: https://elanregistry.org
# Credentials are in each server's own .env, not in any local env file
# See DEPLOYMENT.md for the servers and how to reach them
```

## Overview

The Elan Registry uses **vlucas/phpdotenv** v5 to load environment variables
from plaintext `.env` files. Set file permissions to `chmod 600`.

### Loading System

- **Plaintext storage**: Store variables in the plaintext `.env` file.
- **Permissions**: Set `chmod 600` to restrict access to the web server user.
- **Library**: The app uses `vlucas/phpdotenv` v5.
- **Loading**: `users/init.php` loads variables in Phase 1.6 with `Dotenv::createImmutable()->safeLoad()`.

### Which Tools Read Each File

A local checkout can have up to three environment files. Different tools read
each file. Most tools get their settings from one file only. Two tools read a
second file for one narrow purpose each. `scripts/provision-schema.sh` reads
`.env.test.local` to set up the test database. It also reads `DB_NAME` from
`.env` to protect the application database from deletion.
Playwright also reads two keys from `.env` as fallbacks:
`tests/playwright/resolve-base-url.js` reads `APP_HOST_PORT`, and
`tests/playwright/global-setup.js` reads `LANDING_HOST_PORT` for its error
message. Each parses one key from the file and never copies it into
`process.env`. The Playwright configs load `.env.local` into `process.env`
first, so a `PLAYWRIGHT_BASE_URL` or `APP_HOST_PORT` in `.env.local` wins. Put each variable in the file that its reader uses. Do not copy
it to another file because other tools ignore the copy.

| File | Read by | Holds | Template |
| --- | --- | --- | --- |
| `.env` | The PHP app (`users/init.php`), Phinx (`phinx.php`), `scripts/log-deployment.php`, Docker Compose (variable interpolation only), Playwright's `resolve-base-url.js` (`APP_HOST_PORT` only) and `global-setup.js` (`LANDING_HOST_PORT` only), both as fallbacks | Everything the app needs at runtime: `DB_*`, Turnstile, admin emails, webhook token — plus local-only switches (`US_ENVIRONMENT`, `BREVO_API_HOST`, session names, Docker host ports) | `.env.example` |
| `.env.local` | Playwright (`playwright.config*.js`) and `scripts/playwright-auth-setup.js` — nothing else | Browser-test settings: `E2E_*` credentials, `PLAYWRIGHT_BASE_URL`, `CAR_ID_STANDARD` | the `.env.local` block of `.env.example` |
| `.env.test.local` | `tests/bootstrap-integration.php` only | The five `DB_*` keys of the disposable integration-test schema | `.env.test.local.sample` |

Why they stay separate:

- **`.env` vs production.** Production and test servers have only `.env`,
  holding their own values. The local-only switches in `.env` are optional
  and simply absent there, so the file's *keys* match production's without
  a separate "prod-like" file.
- **`.env.local` is not loaded by the app.** Keeping browser-test
  credentials (including Test/Prod admin passwords) out of `.env` keeps them
  out of the PHP process's environment. Conversely, `DB_*` or
  `US_ENVIRONMENT` placed in `.env.local` has no effect on the app.
- **`.env.test.local` stands alone by design.** The integration suite writes
  to and reprovisions its database. The bootstrap stops if this file is
  missing. It does not fall back to `.env` or `.env.local`. Set all five keys,
  even when they match `.env`. Under Docker, only `DB_NAME` differs. If a key
  is missing, `users/init.php` reads it from `.env`. The bootstrap detects
  this only when the result matches the development database name.

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

Both use `registrar@elanregistry.org` when they are unset or empty. These
values used to come from the web-editable `settings` columns
`elan_admin_emails` and `elan_feedback_email`. PR #1823 moved them to `.env`
in #1067. This change closed a path that could let a compromised admin
session reroute the addresses.

One-time migration: `scripts/generate-config.php` reads the live `settings`
row and appends these two keys to `.env`. It preserves all other keys and then
sets `chmod 600` again. Delete this script from the repository after you check
that both test and production contain these values. The script is not part of
ongoing deploy work.

### Brevo Webhook Authentication

**Usage**: `app/api/webhooks/brevo.php`

- `BREVO_WEBHOOK_TOKEN` — bearer token Brevo must present
  (`Authorization: Bearer <token>`) on every call to the webhook receiver
  (#1887). The receiver compares the token with `hash_equals()`. An empty or
  missing value rejects every request. The receiver fails closed.

**Generating a good token:** use at least 32 bytes (256 bits) of
cryptographically secure randomness, hex- or base64-encoded — do not hand-type
a password or reuse a value from elsewhere. On any machine with OpenSSL:

```bash
openssl rand -hex 32
```

Set the same value in the app's `.env` (`BREVO_WEBHOOK_TOKEN=...`,
`chmod 600 .env`) and in the Brevo webhook configuration for that environment's
URL (#1888). To rotate the token, generate a new value and update both sides
together. If you update only one side, the receiver rejects every webhook call
until both values match. You do not need this token in local development.
Brevo cannot call a development machine because it has no public URL. See
the note under
[Test Database Isolation](#test-database-isolation) and
`docs/development/EMAIL_SYSTEM.md`'s "Brevo Webhooks — Verified Behaviour"
section for why webhook testing happens on test.elanregistry.org instead).

### Cloudflare Turnstile CAPTCHA

**Usage**: `usersc/includes/turnstile.php`

- `TURNSTILE_SITE_KEY` — Turnstile widget site key. The page shows this key in HTML.
- `TURNSTILE_SECRET_KEY` — Turnstile secret key. The server uses it to check tokens.

Omit either key to disable Turnstile (off mode — forms work without CAPTCHA).
Get production keys from Cloudflare Dashboard → Turnstile → your site.
See [test key combinations](#testing-turnstile-in-development) below.

#### Testing Turnstile in Development

Turnstile requires HTTPS. Cloudflare serves the widget iframe over `https://`.
Browsers block this frame on plain `http://localhost` and report
**TurnstileError 110200**.

#### Option A — Disable Turnstile (simplest)

Delete or omit either key from `.env`. The widget is hidden and forms work
without CAPTCHA validation. Use this when Turnstile behaviour is not under test.

#### Option B — Cloudflare Tunnel (test the full widget)

`cloudflared` creates a temporary public HTTPS URL that proxies to your local
Docker site. Cloudflare Tunnel ends TLS upstream and forwards HTTP internally.
It sets the `X-Forwarded-Proto: https` header, so `$is_https` is `true` and
Turnstile enables. With `X-Forwarded-Proto` set, `$current_origin` and
`getBaseUrl()` never add a port, because `SERVER_PORT` then describes the
proxy-to-Apache hop. Any other local proxy that sends the header gets the same
result.

1. **Install `cloudflared`**:

   ```bash
   brew install cloudflare/cloudflare/cloudflared
   ```

2. **Start the tunnel** (while the Docker stack is running):

   ```bash
   cloudflared tunnel --url http://localhost:$APP_HOST_PORT
   ```

   The command prints a temporary `https://*.trycloudflare.com` URL — open
   that in your browser instead of `http://localhost:$APP_HOST_PORT`.

3. **Choose test keys** based on what you are testing:

   | Scenario           | `TURNSTILE_SITE_KEY`       | `TURNSTILE_SECRET_KEY`                | Widget result                  | Server result    |
   | ------------------ | -------------------------- | ------------------------------------- | ------------------------------ | ---------------- |
   | Always pass        | `1x00000000000000000000AA` | `1x0000000000000000000000000000000AA` | Green check ✓                  | `success: true`  |
   | Widget block       | `2x00000000000000000000AB` | `2x0000000000000000000000000000000AB` | Blocked / "Troubleshoot"       | `success: false` |
   | Server-side reject | `1x00000000000000000000AA` | `2x0000000000000000000000000000000AB` | Green check ✓                  | `success: false` |

   - **Always pass** — Use this for normal development. The widget passes the
     check automatically, and the form submits.
   - **Widget block** — The widget shows a failed state before the form submits.
     It also shows a "Troubleshoot" link. Cloudflare provides this link for this test key.
   - **Server-side reject** — The widget shows a green check. The server-side
     `verifyTurnstile()` function returns `false`. Use this case to check PHP
     validation. The server rejects the form and shows a CAPTCHA error. This
     tests server validation separately from the widget.

> **Note:** The tunnel URL changes every run. Browser DevTools → Network tab
> will show requests to `challenges.cloudflare.com` succeeding under HTTPS.

### Local Playwright Base URL

**Usage**: `playwright.config.js` and `playwright.config.dev.js` use this setting.
The prod and test configs use their deployed environment URLs.

Both configs load `.env.local` into the process environment before
`tests/playwright/base-url.js` runs. `base-url.js` calls
`resolve-base-url.js`, which resolves the local `baseURL` in this order:

1. `PLAYWRIGHT_BASE_URL`, if set — from `.env.local` or the process
   environment.
2. `APP_HOST_PORT`, if set — from `.env.local` or the process environment.
   Because `.env.local` loads first, a value there wins over `.env`.
3. `APP_HOST_PORT` read directly from the `.env` file (the Docker checkout's
   own port — see "Docker Dev Environment" below). This step reads the file
   itself, not the process environment, and it reads only this one key.
4. `8001`, the compose default.

An empty value at any step counts as not set. `APP_HOST_PORT` must be an
integer from 1 to 65535 — any other value makes `resolve-base-url.js` throw a
`RangeError`. `PLAYWRIGHT_BASE_URL` must be an absolute URL with an `http:` or
`https:` scheme, or `resolve-base-url.js` throws a `TypeError`.

Steps 2 to 4 always produce `http://localhost:<port>/`, with a trailing
slash. A missing slash makes `page.goto('')` collapse the path.
`PLAYWRIGHT_BASE_URL` does not have to include a trailing slash —
`resolve-base-url.js` adds one if it is missing.

- `PLAYWRIGHT_BASE_URL` — set this only to point at a URL that does not
  come from `APP_HOST_PORT`, such as a Cloudflare tunnel. Do not add this
  setting to the prod or test configs — that would let a destructive test
  run reach the wrong live site.
- Do not set `APP_HOST_PORT` in `.env.local`. If you must, keep it equal to
  the value in `.env`, so Playwright targets the same port as the running
  Docker stack.

### Playwright Test Credentials (Local/Dev)

**Usage**: `playwright.config.js` uses these values for its `admin` project
through `auth.setup.js`. `playwright.config.dev.js` uses them for its `admin`
and `logged-in-non-admin` projects through `auth-dev.setup.js` and
`auth-non-admin.setup.js`.

- `E2E_DEV_ADMIN_USERNAME` / `E2E_DEV_ADMIN_PASSWORD` — credentials for an
  admin test account. Each setup project logs in through `usersc/login.php`
  and saves a storage state file. The local config uses
  `tests/playwright/.auth/user.json`. The dev config uses
  `tests/playwright/.auth/user-dev.json`. Separate files prevent a dev run
  from overwriting the local storage state. The `admin` project requires these
  credentials. If they are unset, the setup test skips and deletes the storage
  state file. The `admin` tests then run without authentication.
- `E2E_DEV_NONADMIN_USERNAME` / `E2E_DEV_NONADMIN_PASSWORD` — credentials for a
  non-admin test account. `auth-non-admin.setup.js` saves its storage state to
  `tests/playwright/.auth/user-dev-non-admin.json`. The dev config uses this
  file for the `logged-in-non-admin` project. The project has no test specs yet.
- All four live in `.env.local` (gitignored) and must never be committed. See
  `.env.example` for the placeholder entries.
- These accounts are for local and dev environments. They use plain HTTP on
  the Docker site and do not use Turnstile. Issue #2059 renamed the variables to match
  the `E2E_<TIER>_<ROLE>_*` pattern used for test and production. The old
  names were `TEST_USERNAME`, `TEST_PASSWORD`, `TEST_USERNAME2`, and
  `TEST_PASSWORD2`.

### Playwright Test Credentials (Test/Prod)

**Usage**: Run `scripts/playwright-auth-setup.js` manually to create the
pre-authenticated storage state files. Issue #2035 consolidated this script.
Before each test or production run, `auth-staleness.setup.js` and
`auth-staleness-admin.setup.js` check these files. The test and production
configs use them for the `admin` project. The `logged-in` project also uses a
storage state file, but no non-admin spec targets it yet.

- `E2E_TEST_ADMIN_USERNAME` / `E2E_TEST_ADMIN_PASSWORD` — admin account on
  `test.elanregistry.org`.
- `E2E_TEST_NONADMIN_USERNAME` / `E2E_TEST_NONADMIN_PASSWORD` — non-admin
  account on `test.elanregistry.org`.
- `E2E_PROD_ADMIN_USERNAME` / `E2E_PROD_ADMIN_PASSWORD` — admin account on
  `elanregistry.org`.
- `E2E_PROD_NONADMIN_USERNAME` / `E2E_PROD_NONADMIN_PASSWORD` — non-admin
  account on `elanregistry.org`.
- Both environments use HTTPS and an active Cloudflare Turnstile challenge.
  This challenge blocks automated login. A human must disable Turnstile before
  running `node scripts/playwright-auth-setup.js <test|prod> <admin|nonadmin>`
  once for each tier and role. The script creates
  `tests/playwright/.auth/user-<tier>-<role>.json`. Re-enable Turnstile after
  the script completes. See `docs/testing/PLAYWRIGHT_E2E.md` for the full
  setup process.
- All eight live in `.env.local` (gitignored) and must never be committed. See
  `.env.example` for the placeholder entries.

### Multi-Clone Session Isolation

**Usage**: `users/init.php` (`$GLOBALS['config']['session']` /
`['remember']`)

- `SESSION_NAME` / `TOKEN_NAME` / `REMEMBER_COOKIE_NAME` — These variables
  override UserSpice's `$_SESSION` keys (`user`, `token`) and its hardcoded
  remember-me cookie name. See `users/init.php`. Use them only when you run
  more than one local clone on the same host and port, such as `Registry/`
  and `Registry2/`. This setup supports work on two milestones at the same
  time. See the top-level `Web/ElanRegistry/CLAUDE.md` file.

  Each clone shares the same PHP session cookie (`PHPSESSID`) on the same
  origin. Its `path` value is `/`. `SESSION_NAME` and `TOKEN_NAME` select the
  keys that each clone uses inside the shared session. Without unique names,
  clones can overwrite each other's login state and CSRF token. This can cause
  login failures with no log entry (#1935). `REMEMBER_COOKIE_NAME` sets a
  separate browser cookie for each clone.
- Leave these variables unset in production, test, and single-clone local
  installs. The app uses the original hardcoded values in those environments.
  Set them only in the `.env` file for a clone that needs separate session
  state. Do not set them in `.env.local`. Other clones can use the defaults.
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

### Docker Dev Environment

The repository provides a Docker Compose stack for each checkout. The stack
is the only supported local environment for the Registry (#2180). It needs
Docker Compose v2.0.0 or later, and `jq` on the host. Claude Code's
`PreToolUse` hooks (`.claude/hooks/guard-private-paths.sh`,
`guard-deploy-push.sh`, `guard-upstream-paths.sh`) parse every Bash and Edit
tool call with `jq` and deny the call if it is missing, so a session without
`jq` cannot run any of those tools. Install it with `brew install jq` (macOS)
or your package manager before starting a Claude Code session in this repo.
The stack runs a PHP 8.4 app container,
MySQL 8.0, phpMyAdmin, a mock Brevo API, and a landing page. It bind-mounts
the checkout as the web root. Issue 2116 introduced the stack for
`Registry2/`. Both `Registry2/` and `Registry/` run the full toolchain with
this stack: `composer install`, `composer test:full`, `npm run build`, and
Playwright.

**One compose file, per-checkout ports in `.env`.** Every checkout on the
branch shares `docker-compose.yml`. Do not edit it for one checkout. Each
checkout sets host ports in its own gitignored `.env`. The compose file's
own defaults (`docker-compose.yml`'s `${APP_HOST_PORT:-8001}` and so on)
match `Registry/`, so that checkout needs no port entries.

| Checkout | `APP_HOST_PORT` | `PMA_HOST_PORT` | `MOCK_BREVO_HOST_PORT` | `LANDING_HOST_PORT` |
| --- | --- | --- | --- | --- |
| `Registry/` (compose defaults) | 8001 | 8081 | 8091 | 8101 |
| `Registry2/` | 8002 | 8082 | 8092 | 8102 |
| next checkout | 8003 | 8083 | 8093 | 8103 |

Also set `CHECKOUT_NAME` (the landing page's label). The network and
volume need no per-checkout change: Compose prefixes both with the project
name (the checkout's directory), so `Registry/` gets `registry_elan`
and `registry_db_data`, separate from Registry2's.

**Upgrading a stack created before the rename.** The network and volume
keys used to be `registry2`/`registry2_db_data`, so an existing stack's
data sits in `<project>_registry2_db_data` (e.g.
`registry2_registry2_db_data`). The next `up` would start an empty
`<project>_db_data` instead. Copy the data across once, with the stack
down:

```bash
docker compose down
docker compose up --no-start            # creates <project>_db_data and <project>_elan
docker run --rm -v registry2_registry2_db_data:/from:ro \
  -v registry2_db_data:/to alpine sh -c 'cp -a /from/. /to/'
docker compose up -d
# once verified: docker volume rm registry2_registry2_db_data
#                docker network rm registry2_registry2
```

Substitute your project name for `registry2`.

**Landing page:** Open `http://localhost:<LANDING_HOST_PORT>/`. For `Registry/`,
use `localhost:8101`. The page links to that checkout's site, phpMyAdmin, and
the mock Brevo inbox. The container renders the page at startup from
`docker/landing/index.html.template` and the same port variables. The links
therefore match the checkout.

Worktrees under `Registry-worktrees/` get the same treatment: give the
worktree's `.env` the next row of ports.

```bash
docker compose up -d
docker compose exec -u www-data app composer install
docker compose exec -u www-data app composer test:full
```

To run local cron as well, start the stack with `--profile cron`. See
[Development Setup](#development-setup), step 6.

The stack has five services, plus the opt-in `cron` service (see step 6 of
[Development Setup](#development-setup)). `app` runs PHP 8.4. `db` runs
MySQL 8.0 and has no host port. `phpmyadmin` provides database inspection.
`mock-brevo` runs the local mock of Brevo's transactional email API
(`ghcr.io/unibrain1/mock-brevo:1.2.0`) and provides a web inbox. `landing`
serves the landing page. Use the port table above. The `app` container reaches
`mock-brevo` at `http://mock-brevo:8080/v3` on the `elan` network.

The image is a maintained fork
([`unibrain1/mock-brevo`](https://github.com/unibrain1/mock-brevo)), not the
upstream project. The fork has an English UI and adds the event report and the
block list. Its simulated webhooks also send a bearer token and the message
tags, as Brevo does. `docker-compose.yml` gives the mock the app's
`BREVO_WEBHOOK_TOKEN`, so the webhook endpoint accepts them. Registry's
reconciliation cron, suppression-sync cron, and webhook endpoint need those
features to work against the mock. `docker-compose.yml` pins an exact tag,
never `latest`, so every checkout runs the same mock. To pick up a new
release: change the tag in `docker-compose.yml`, then run `docker compose pull
mock-brevo && docker compose up -d mock-brevo`.

See the "Local Development" section in `docs/development/EMAIL_SYSTEM.md` for
the `BREVO_API_HOST` variable, the `US_ENVIRONMENT=development` check, and
the Brevo plugin override. This document covers the Docker setup. The email
guide covers application configuration.

**Always pass `-u www-data` to `exec`** — it has no compose-file default
and otherwise runs as root, which would root-own anything written into the
bind mount. See the `docker-compose.yml` header comment for the full
rationale, the port table, and the one per-checkout setting outside `.env`
(`APP_UID`/`APP_GID`, if a different host user works on the checkout).

**MAMP is retired (#2180 supersedes #2120).** Docker is the only supported
local environment for the Registry. #2120 had planned for MAMP and Docker to
coexist; #2180 changed that decision. A developer on an older checkout that
still has a MAMP `.env` must switch `DB_HOST` to `db` and `DB_PORT` to
`3306`, the values Docker Compose reads.

Run `scripts/provision-schema.sh` and other scripts that read `.env` inside
the container. Use `docker compose exec -u www-data app
scripts/provision-schema.sh ...`. Do not run these scripts from the host
shell.

**The Docker database user needs `SYSTEM_VARIABLES_ADMIN`.** The standard
`GRANT ALL` on `elanregi_*` schemas does not grant this permission. Some
integration tests run `SET GLOBAL`, which needs this permission. See the
comment in `docker/mysql-init/01-grant-all-elanregi-schemas.sql` for the
test, mechanism, and failure mode. Add the same permission to the grant file
for each new checkout.

### PHP Version

- **Local development, CI, test, and production**: These environments use
  PHP 8.4.x. Production uses PHP 8.4.25. Issue #1968 tracked an earlier hold
  on PHP 8.2 for test and production. That hold ended when production moved to
  PHP 8.4.25. This statement does not say whether anyone should close #1968.
- **`phpstan.neon` value `phpVersion: 80229`**: This setting pins static
  analysis to the compatibility floor. The floor is lower than the deployed
  version. This choice keeps first-party code from using syntax that PHP 8.3
  or 8.4 introduced, such as property hooks and asymmetric visibility. It does
  not mean that any environment uses PHP 8.2. `composer.json` also sets the
  compatibility floor with `>=8.2.29`. Neither value describes the deployed
  version. Changing either value requires a separate decision.
- **CLI PHP (Homebrew)**: The shell finds `php` and `composer` through PATH.
  These commands use Homebrew's linked PHP. Keep it on the same version as
  the Docker app container with `brew install php@8.4`, then
  `brew link php@8.4 --force --overwrite` and `hash -r`. The shell runs unit
  tests (`composer test:quick`, `test:unit`) and static analysis
  (`composer check:php`, PHPStan) with Homebrew's linked PHP. Integration
  tests need the database, so run them in the container instead — see step 4
  below.

### Development Setup

1. **Get Database Credentials**:

   Put local development database credentials in `.env` (step 2). The app
   reads only this file. Playwright reads `.env.local`. Integration tests read
   `.env.test.local`. See [Which Tools Read Each File](#which-tools-read-each-file).

   See "Database Access" section above for connecting to databases.

2. **Create `.env` from `.env.example`**:

   ```bash
   # Copy the public template
   cp .env.example .env

   # Edit with your local credentials
   # Example contents:
   # DB_HOST=db
   # DB_PORT=3306
   # DB_USER=elanregi_spice
   # DB_PASS=password
   # DB_NAME=elanregi_spice
   ```

   `DB_HOST=db` and `DB_PORT=3306` point the app at the Docker stack's `db`
   service. Keep `DB_USER=elanregi_spice`: Compose passes it to MySQL as
   `MYSQL_USER`, which cannot be `root`, and
   `docker/mysql-init/01-grant-all-elanregi-schemas.sql` grants only that
   user. See "Docker Dev Environment" above for the full `.env` port
   settings.

3. **Set Secure Permissions**:

   ```bash
   # Restrict to web server user only
   chmod 600 .env
   ```

4. **Start the Stack and Set Up the Integration Test Database**:

   Integration tests run against a dedicated test schema to avoid damaging the dev database.

   ```bash
   # Start the Docker stack (see "Docker Dev Environment" above)
   docker compose up -d

   # Copy the test database template
   cp .env.test.local.sample .env.test.local

   # Edit with your test database password (other fields are pre-filled)
   nano .env.test.local

   # Set secure permissions
   chmod 600 .env.test.local

   # Provision the test schema (stock UserSpice base + migrations + seeds)
   # Run inside the container — do not run this script from the host shell
   docker compose exec -u www-data app scripts/provision-schema.sh

   # Run integration tests inside the container
   docker compose exec -u www-data app composer test:integration
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
   `users/cron/cron.php` on a schedule. The `cron` service in
   `docker-compose.yml` does this. It is behind the Compose profile `cron`,
   because `cron.php` runs real scheduled jobs, such as the verification
   batch send. A plain `docker compose up -d` does not start it.

   ```bash
   docker compose --profile cron up -d     # start the stack with cron
   docker compose logs -f cron             # one line per request
   docker compose --profile cron down      # stop everything, cron included
   ```

   - The `cron` service shares the `app` container's network namespace
     (`network_mode: "service:app"`). It requests
     `http://127.0.0.1/users/cron/cron.php` when Apache answers, then 600
     seconds after each request ends. This is close to test and production
     (every 10 minutes). The request has no retry, because each retry would
     run every cron job again.
   - Because the request shares `app`'s network namespace, `cron.php` sees
     the request's `REMOTE_ADDR` as `127.0.0.1`.
   - The request sends `Host: localhost:$APP_HOST_PORT` (default 8001), the
     same Host header as the browser. Apache then reports that port, so
     links in emails that a job sends, for example the verification batch,
     point at `http://localhost:$APP_HOST_PORT`, not `http://127.0.0.1`.
   - Until Apache answers, the service prints
     `<ISO-8601 timestamp> waiting for app at http://127.0.0.1/` about once
     a minute.
   - Each request prints one line:
     `<ISO-8601 timestamp> status=<code> exit=<curl exit code> size=<bytes> <curl error message>`.
     `status=000` with a nonzero exit means curl got no HTTP response at
     all. The error message says why (for example connection refused or a
     timeout).
   - After 3 requests in a row with a nonzero curl exit, the service prints
     a stop message and exits, so `docker compose ps` no longer shows it as
     running.
   - Use `--profile cron` with `down` too. A plain `docker compose down`
     does not stop `cron`.
   - If `app` is recreated or restarted alone (for example
     `docker compose restart app`, or `docker compose up -d` without the
     profile after an image change), `cron` loses its network, logs
     `status=000 exit=7`, and stops after 3 requests. Run
     `docker compose --profile cron up -d` again.

   **`status=200` does not prove that a job ran.** `cron.php` returns HTTP
   200 also when the `cron_ip` allowlist denies the request. An accepted
   request updates `er_verification_settings.last_cron_request_at`
   (Admin → Verification). A denied request adds a `CronRequest` row to the
   `logs` table: `Cron request DENIED from <ip>.`

   **Set `cron_ip` to `off` for local development.** `off` is UserSpice's
   sentinel for "allow `127.0.0.1` only" — see
   [DEPLOYMENT.md's `cron_ip` semantics table](DEPLOYMENT.md#cron-transport-userspice-cron-manager).
   Because the `cron` service's request arrives as `127.0.0.1`, `off` admits
   it. `off` also denies every other caller, including a Cloudflare tunnel
   set up for Turnstile testing (see "Cloudflare Turnstile CAPTCHA" above) —
   a request through the published port instead arrives as the Docker
   bridge IP (for example `172.20.0.1`) and is denied.

   A local database from before Docker can still hold an old value. An
   empty string admits every caller, the tunnel included. `::1` acts like
   `off`, because the gate always admits `127.0.0.1`, but set `off` so the
   value states the intent.
   `scripts/refresh-local-db.sh` does not copy the `settings` table, so the
   old value stays until you clear it: in Admin → Settings, or with
   `docker compose exec db mysql -u [DB_USER] -p -D [DB_NAME] -e
   "UPDATE settings SET cron_ip='off'"`.

   See [DEPLOYMENT.md — Cron Transport](DEPLOYMENT.md#cron-transport-userspice-cron-manager)
   for the interval rules, allowlist table, and cron job requirements.

### Test Database Isolation

Integration tests change real database records. They insert, update, delete,
and merge records to check application logic. The test suite requires a
dedicated test schema to protect the development database:

- **Separate schema**: Tests use `elanregi_spice_test` or an equivalent schema.
  They never use the development database `elanregi_spice`.
- **Required configuration**: `tests/bootstrap-integration.php` stops with an
  error if `.env.test.local` is missing or cannot load.
- **Safety checks**: `tests/bootstrap-integration.php` checks `DB_NAME` before
  connecting. It checks the connected database again after the connection.
  The second check catches a missing `DB_*` key in `.env.test.local` when the
  app fills it from the root `.env`. Either check exits with `exit(1)` if the
  database name is `elanregi_spice`. If you rename the development database,
  update both checks.
- `scripts/provision-schema.sh` protects the `DROP DATABASE` command. It stops
  if the schema name does not contain `test` (case-insensitive). It also stops
  if the target name matches `DB_NAME` in the app's `.env` file. Use
  `--force` to override either check. The script also creates new development
  and CI databases.
- Both `tests/bootstrap-integration.php` and `scripts/provision-schema.sh`
  stop when `DB_HOST` is `db` and `/.dockerenv` is absent, that is, on the
  host. On the host, `db` can resolve to another machine through the DNS
  search domain. `--force` does not override this check.

**Files involved:**

- `.env.test.local` — Test database credentials (gitignored, created once per developer)
- `.env.test.local.sample` — Template with safe defaults (tracked in repo)
- `scripts/provision-schema.sh` — You can rerun this script when the schema
  changes, such as after a new migration. Each run drops and recreates only
  the target schema. It then loads `database/vendor/userspice-6.1.4-base.sql`,
  runs `composer migrate`, and loads the Phinx seeds. The script needs a
  `mysql` client on `$PATH` or a `MYSQL_BIN` value that points to one. Under
  Docker, run the script inside the `app` container (see "Docker Dev
  Environment" above), which has the client on `$PATH`.

After setup, you can run tests against the test schema as often as needed.
These runs do not affect the development database.

**Blocking pre-push gate (#1439):** `.githooks/pre-push` checks changes to
code that affects integration tests. The push fails if any check fails,
including a connection to the test database. Set up `.env.test.local` before
you change these files. Otherwise, the connectivity check in
`tests/bootstrap-integration.php` fails during the push. With Docker, set
`DB_HOST=db`. The gate runs the suite inside the `app` container, so start the
stack before you push. See the "Git Hooks Management" section in
`scripts/README.md` for the gate schedule and bypass instructions.

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

The application loads environment variables during bootstrap. PHP code accesses
them through the `$_ENV` superglobal:

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

### .env.local File (Playwright Only)

The `.env.local` file holds browser-test settings, read by Playwright's
configs and `scripts/playwright-auth-setup.js` — **not** by the app:

- **Location**: Root directory (not committed to git)
- **Permissions**: `chmod 600` (it holds Test/Prod account passwords)
- **Contents**: `E2E_*` credentials, `PLAYWRIGHT_BASE_URL`, `CAR_ID_STANDARD`
- **Distribution**: Created locally, from the Playwright entries in `.env.example`
- **Not here**: `DB_*`, `TURNSTILE_*`, `US_ENVIRONMENT` and other app
  settings. The app does not read this file. Copies here have no effect and
  can become stale. Put these values in `.env`. See
  [Which Tools Read Each File](#which-tools-read-each-file).

### .env.test.local File (Integration Tests Only)

This file holds the five `DB_*` keys for the disposable integration-test
schema. Only `tests/bootstrap-integration.php` reads it. Create it from
`.env.test.local.sample`. See "Test Database Isolation" above.

**Important**: Do not commit `.env`, `.env.local`, `.env.test.local`, or other
environment files. Git lists all of them in `.gitignore`.

## Security Requirements

### File Security

- **Never commit** `.env`, `.env.local`, or other environment files to version control
- **Restrict file permissions** to web server user only: `chmod 600 .env`
- **Backup security** — ensure backups are encrypted by hosting provider
- **CI scanning** — GitGuardian detects accidental plaintext secret commits

### API Key Security

As of v2.22.0, the application uses no external map API keys. It shows maps
with self-hosted **MapLibre GL JS** and **VersaTiles** tile servers. The
map does not need a Google Maps key. The app uses **Nominatim** (OpenStreetMap)
for location geocoding. Nominatim does not need an API key.

### Database Security

- **Least Privilege**: Database user should have only necessary permissions
- **Network Security**: Restrict database access to application server
- **Connection Security**: Use SSL/TLS when possible

## PHP Error Logging

PHP logs errors, warnings, and fatal errors to separate files on test and
production. Both servers use the mod_php SAPI.

- **Test**: `/home/unibrain/php_error/test.elanregistry.org-php-error.log`
- **Production**: `/home/unibrain/php_error/elanregistry.org-php-error.log`

The root `.htaccess` file selects the log destination during each Apache
request. An `HTTP_HOST`-conditional `RewriteRule` sets the environment
variable that `php_value error_log %{ENV:PHP_ERROR_LOG}` uses. The deploy
process does not change this value. Git tracks one `.htaccess` file and
deploys it to every environment. Search `.htaccess` for `PHP_ERROR_LOG` to
find this rule.

The `<IfModule mod_php.c>` block does nothing if the server changes from
mod_php to another SAPI, such as PHP-FPM. Apache skips unknown `IfModule`
blocks without an error. If the error logs stop after a server or PHP change,
check that the server still uses mod_php.

Local Docker development uses PHP's default error log location inside the
`app` container.

## Troubleshooting

**Environment Loading Issues**:

- Check that `.env` exists and the web server can read it.
- Run `ls -la .env`. The permissions should show `-rw-------` (600).
- Check that the file is not readable by other users or groups.
- Set the owner with `chown www-data:www-data .env`.

**Database Connection Issues**:

- Check that `.env` has the correct credentials.
- Use the MySQL CLI to check the database connection.
- Check that the application host can reach the database server.
- Check the database user's permissions. It may need `SELECT`, `INSERT`, `UPDATE`, and `DELETE`.

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
- [VersaTiles Documentation](https://versatiles.org/) — documentation for the tile server that provides map tiles
- [Nominatim API Documentation](https://nominatim.org/release-docs/latest/api/Search/) — used for location geocoding (lat/lon lookup on car save)
