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

## Setup & Configuration

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

   Set `cron_ip` in Admin → Settings → General to the address the first
   `CronRequest` entry in Admin → Logs shows. On a standard macOS `/etc/hosts`
   curl reaches `localhost` over IPv6, so this is `::1`; `cron.php` only
   hard-codes `127.0.0.1` as the always-allowed address, so `::1` must be set
   explicitly. If your log shows `127.0.0.1`, leave `cron_ip` at `off`.
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
