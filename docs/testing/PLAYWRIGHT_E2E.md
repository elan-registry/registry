# Playwright E2E Testing Guide

Four-tier Playwright testing strategy spanning local development, staging, and production environments. Local and Dev both target the local Docker site — see below.

## Four-Tier Architecture

| Tier | Location | Environment | When to Run |
| ------ | ---------- | ------------- | ------------- |
| **Local** | `tests/playwright/` | `localhost:8001`[^1] | During development |
| **Dev** | `tests/playwright/e2e/` | `localhost:8001` | During development (logged-in E2E flows) |
| **Test** | `tests/playwright/e2e/` | `test.elanregistry.org` | Before releases |
| **Production** | `tests/playwright/e2e/` | `elanregistry.org` | Post-deployment |

[^1]: Default — override with `PLAYWRIGHT_BASE_URL`, see [ENVIRONMENT.md](../development/ENVIRONMENT.md).

**Why Dev exists alongside Local, against the same environment:**
`playwright.config.js` (Local) covers `tests/playwright/` broadly — fast,
mostly-unauthenticated browser checks plus one `admin` project.
`playwright.config.dev.js` (Dev) scopes to `tests/playwright/e2e/` only —
the same `not-logged-in`/`admin` specs that run against Test/Production
in CI — so a developer can validate that exact suite against the local
Docker site first.

Every tier below has one authenticated project: `admin` (admin account).
PHPUnit integration tests cover the non-admin refusal of the admin AJAX
endpoints (`tests/integration/AdminAjaxGuardBehaviorTest.php`), so no E2E
tier has a non-admin project.

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
npm run test:e2e:test:report
```

### Production

```bash
npm run test:e2e                   # All tests
npm run test:e2e:headed            # With browser
npm run test:e2e:not-logged-in
npm run test:e2e:admin             # Authenticated admin tests
npm run test:e2e:report
```

### Dev Environment

```bash
npm run test:e2e:dev               # All E2E against the local Docker site
npm run test:e2e:dev:headed        # With browser
npm run test:e2e:dev:ui            # UI mode
npm run test:e2e:dev:not-logged-in # Public pages only
npm run test:e2e:dev:admin         # Authenticated flows (admin account)
npm run test:e2e:dev:report        # View test report
```

## Authentication Setup

E2E tests use session persistence to avoid Turnstile challenges. Local and
Dev tiers instead use a live login (`auth.setup.js` / `auth-dev.setup.js`, see
[ENVIRONMENT.md](../development/ENVIRONMENT.md)), with credentials from
`E2E_DEV_ADMIN_USERNAME`/`E2E_DEV_ADMIN_PASSWORD` in `.env.local` — plain
HTTP, no Turnstile challenge. Some local specs in `tests/playwright/` also
log in as a member with
`E2E_DEV_NONADMIN_USERNAME`/`E2E_DEV_NONADMIN_PASSWORD`. Test and Production
use a pre-generated storageState file described below, since both run HTTPS with an active Cloudflare
Turnstile challenge that an automated browser cannot solve.

### Prerequisites

- Admin credentials for Test and Production stored in `.env.local` as
  `E2E_TEST_ADMIN_*` and `E2E_PROD_ADMIN_*` (see
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
node scripts/playwright-auth-setup.js prod admin
```

### Setup Process

1. Disable Turnstile on the target environment (Cloudflare dashboard).
2. Run the relevant command above. Browser opens to the login page.
3. Credentials from `.env.local` are auto-filled and submitted.
4. Auth state saved to `tests/playwright/.auth/user-<tier>-admin.json`.
5. Browser closes.
6. Re-enable Turnstile on the target environment.

### Auth Files

| Environment | Role | File | Config |
| ----------- | ---- | ---- | ------ |
| Test | admin | `.auth/user-test-admin.json` | `playwright.config.test.js` |
| Production | admin | `.auth/user-prod-admin.json` | `playwright.config.prod.js` |

Each config registers its `admin` project only when the admin auth file for
that tier is present.

Files are gitignored. Re-run setup if sessions expire.

## Test Projects

### not-logged-in

- Public page accessibility
- No authentication required
- Always runs

### admin

- Authenticated workflows with the admin account
- Requires a valid admin auth file for that tier
- Local/Dev: preceded by a `setup` project that performs a live login on
  every run
- Test/Production: preceded by a `check-auth-admin` project that loads the
  existing auth file
  and verifies the session is still authenticated (navigates to an
  authenticated page and checks for a logged-in marker — live re-login isn't
  possible there, both origins run a real Cloudflare Turnstile challenge).
  If the file is missing or the session has expired, `check-auth-admin`
  fails loudly with a message pointing at
  `node scripts/playwright-auth-setup.js <tier> admin`, and the
  corresponding project is skipped — its tests do not silently run
  anonymous. `not-logged-in` is unaffected and still runs to completion.

## Troubleshooting

| Issue | Solution |
| ------- | ---------- |
| Auth file doesn't exist | Run `node scripts/playwright-auth-setup.js <tier> admin` — also now caught automatically by `check-auth-admin` before the corresponding project runs on Test/Production |
| `check-auth-admin` fails with "session is stale/expired" | Re-run `node scripts/playwright-auth-setup.js <tier> admin` (session expired — now caught automatically instead of the project silently running anonymous) |
| Turnstile blocks login | Disable Turnstile entirely on the target environment before running the setup script; re-enable once done |
| Auth setup fails with "Turnstile is enabled on this environment..." | **Turnstile is enabled.** An automated browser cannot pass it — this is not a bug in the login flow, and the check fires on any Turnstile widget including a test/always-pass sitekey. Disable Turnstile entirely, re-run the auth script, then re-enable it once the auth file is saved. Do not attempt to work around Turnstile programmatically. |
| Auth setup fails with "Invalid credentials / 2FA / network" but credentials are correct | Turnstile may have rejected the submission after the pre-submit check missed it (a race — see `playwright-auth-setup.js`'s Turnstile-check comment). Confirm Turnstile is fully disabled, not just set to a test sitekey, then re-run. |
| CI/CD failures | These tests do not run in CI — CI has no complete UserSpice installation. Test/Prod runs are manual, at milestone-release time. |

## Recommended Workflow

1. **Development**: `npm run playwright:test`
2. **Pre-release**: `npm run test:e2e:test`
3. **Post-deploy**: `npm run test:e2e`

See [TESTING.md](TESTING.md) for PHPUnit test documentation.
