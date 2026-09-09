# Playwright E2E Testing Guide

Four-tier Playwright testing strategy spanning local development, staging, and production environments (two tiers, Local and Dev, target local MAMP — see below).

## Four-Tier Architecture

| Tier | Location | Environment | When to Run |
| ------ | ---------- | ------------- | ------------- |
| **Local** | `tests/playwright/` | `localhost:9999/ElanRegistry/Registry`[^1] | During development |
| **Dev** | `tests/playwright/e2e/` | `localhost:9999/ElanRegistry/Registry` | During development (logged-in E2E flows) |
| **Test** | `tests/playwright/e2e/` | `test.elanregistry.org` | Before releases |
| **Production** | `tests/playwright/e2e/` | `elanregistry.org` | Post-deployment |

[^1]: Default — override with `PLAYWRIGHT_BASE_URL`, see [ENVIRONMENT.md](../development/ENVIRONMENT.md).

**Why Dev exists alongside Local, against the same environment:**
`playwright.config.js` (Local) covers `tests/playwright/` broadly — fast,
mostly-unauthenticated browser checks plus one `admin` project.
`playwright.config.dev.js` (Dev) scopes to `tests/playwright/e2e/` only —
the same `not-logged-in`/`admin` specs that run against Test/Production
in CI — so a developer can validate that exact suite against local MAMP
first. Dev also provisions a `logged-in-non-admin` project — a second local
test account distinct from `admin`'s admin account — as infrastructure
for future non-admin e2e coverage; no spec targets it yet, so it has no npm
script until one does.

Every tier below has two authenticated project shapes: `admin` (admin
account) and `logged-in` (non-admin account, #2035) — renamed from a single,
ambiguously-named `logged-in` tier that was actually the admin account
everywhere except Prod.

## Running Tests

### Local Development

```bash
# All local tests
npm run playwright:test

# Specific suites
npm run playwright:security
npm run playwright:functionality
npm run playwright:navigation
npm run playwright:ui
npm run playwright:maps
npm run playwright:csp

# Debug/headed modes
npm run playwright:headed
npm run playwright:debug
npm run playwright:report
```

### Test Environment

```bash
npm run test:e2e:test              # All tests
npm run test:e2e:test:headed       # With browser
npm run test:e2e:test:not-logged-in
npm run test:e2e:test:admin        # Authenticated admin tests
# No non-admin npm script yet — logged-in project is infra-only until a
# non-admin spec exists (#2035)
npm run test:e2e:test:report
```

### Production

```bash
npm run test:e2e                   # All tests
npm run test:e2e:headed            # With browser
npm run test:e2e:not-logged-in
npm run test:e2e:admin             # Authenticated admin tests
# No non-admin npm script yet — logged-in project is infra-only until a
# non-admin spec exists (#2035)
npm run test:e2e:report
```

### Dev Environment

```bash
npm run test:e2e:dev               # All E2E against local MAMP
npm run test:e2e:dev:headed        # With browser
npm run test:e2e:dev:ui            # UI mode
npm run test:e2e:dev:not-logged-in # Public pages only
npm run test:e2e:dev:admin         # Authenticated flows (admin account)
npm run test:e2e:dev:report        # View test report
```

## Authentication Setup

E2E tests use session persistence to avoid Turnstile challenges. Local and
Dev tiers instead use a live login (`auth.setup.js` / `auth-dev.setup.js` /
`auth-non-admin.setup.js`, see [ENVIRONMENT.md](../development/ENVIRONMENT.md)),
with credentials from `TEST_USERNAME`/`TEST_PASSWORD` (admin) and
`TEST_USERNAME2`/`TEST_PASSWORD2` (non-admin) in `.env.local` — plain HTTP,
no Turnstile challenge. Test and Production use a pre-generated storageState
file described below, since both run HTTPS with an active Cloudflare
Turnstile challenge that an automated browser cannot solve.

### Prerequisites

- Credentials for all four Test/Prod tier×role combinations stored in
  `.env.local` as `E2E_TEST_ADMIN_*`, `E2E_TEST_NONADMIN_*`,
  `E2E_PROD_ADMIN_*`, `E2E_PROD_NONADMIN_*` (see
  [ENVIRONMENT.md](../development/ENVIRONMENT.md) and `.env.example`).
- **Turnstile must be manually disabled on the target environment before
  running the setup script** — an automated browser cannot solve a real
  challenge, including a Cloudflare test/always-pass sitekey (the script's
  own check does not distinguish sitekey types; see Troubleshooting).
  Re-enable it once auth setup completes. This applies identically to both
  Test and Production (#2035 unified the two — Production previously had a
  fully human-driven flow instead of this fail-fast check).

### Setup Commands

```bash
node scripts/playwright-auth-setup.js test admin
node scripts/playwright-auth-setup.js test nonadmin
node scripts/playwright-auth-setup.js prod admin
node scripts/playwright-auth-setup.js prod nonadmin
```

### Setup Process

1. Disable Turnstile on the target environment (Cloudflare dashboard).
2. Run the relevant command above. Browser opens to the login page.
3. Credentials from `.env.local` are auto-filled and submitted.
4. Auth state saved to `tests/playwright/.auth/user-<tier>-<role>.json`.
5. Browser closes.
6. Re-enable Turnstile on the target environment.

### Auth Files

| Environment | Role | File | Config |
| ----------- | ---- | ---- | ------ |
| Test | admin | `.auth/user-test-admin.json` | `playwright.config.test.js` |
| Test | non-admin | `.auth/user-test-nonadmin.json` | `playwright.config.test.js` |
| Production | admin | `.auth/user-prod-admin.json` | `playwright.config.prod.js` |
| Production | non-admin | `.auth/user-prod-nonadmin.json` | `playwright.config.prod.js` |

The non-admin auth files are intended to hold a session for a non-admin
owner account with at least one registered car. Each `(tier, role)`
combination's project only registers when its own auth file is present —
missing one file does not block the other three.

Files are gitignored. Re-run setup if sessions expire.

## Test Projects

### not-logged-in

- Public page accessibility
- No authentication required
- Always runs

### admin / logged-in

- Authenticated workflows (`admin` = admin account, `logged-in` = non-admin
  account)
- Requires a valid auth file for that tier×role
- Local/Dev: preceded by a `setup`/`setup-non-admin` project that performs a
  live login on every run
- Test/Production: preceded by a `check-auth-admin` (for `admin`) or
  `check-auth` (for `logged-in`) project that loads the existing auth file
  and verifies the session is still authenticated (navigates to an
  authenticated page and checks for a logged-in marker — live re-login isn't
  possible there, both origins run a real Cloudflare Turnstile challenge).
  If the file is missing or the session has expired, the check-auth project
  fails loudly with a message pointing at
  `node scripts/playwright-auth-setup.js <tier> <role>`, and the
  corresponding project is skipped — its tests do not silently run
  anonymous. `not-logged-in` is unaffected and still runs to completion.

## Troubleshooting

| Issue | Solution |
| ------- | ---------- |
| Auth file doesn't exist | Run `node scripts/playwright-auth-setup.js <tier> <role>` — also now caught automatically by `check-auth`/`check-auth-admin` before the corresponding project runs on Test/Production |
| `check-auth`/`check-auth-admin` fails with "session is stale/expired" | Re-run `node scripts/playwright-auth-setup.js <tier> <role>` (session expired — now caught automatically instead of the project silently running anonymous) |
| Turnstile blocks login | Disable Turnstile entirely on the target environment before running the setup script; re-enable once done |
| Auth setup fails with "Turnstile is enabled on this environment..." | **Turnstile is enabled.** An automated browser cannot pass it — this is not a bug in the login flow, and the check fires on any Turnstile widget including a test/always-pass sitekey. Disable Turnstile entirely, re-run the auth script, then re-enable it once the auth file is saved. Do not attempt to work around Turnstile programmatically. |
| Auth setup fails with "Invalid credentials / 2FA / network" but credentials are correct | Turnstile may have rejected the submission after the pre-submit check missed it (a race — see `playwright-auth-setup.js`'s Turnstile-check comment). Confirm Turnstile is fully disabled, not just set to a test sitekey, then re-run. |
| CI/CD failures | These tests do not run in CI — CI has no complete UserSpice installation. Test/Prod runs are manual, at milestone-release time. |

## Recommended Workflow

1. **Development**: `npm run playwright:test`
2. **Pre-release**: `npm run test:e2e:test`
3. **Post-deploy**: `npm run test:e2e`

See [TESTING.md](TESTING.md) for PHPUnit test documentation.
