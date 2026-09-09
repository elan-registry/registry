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
mostly-unauthenticated browser checks plus one `logged-in` project.
`playwright.config.dev.js` (Dev) scopes to `tests/playwright/e2e/` only —
the same `not-logged-in`/`logged-in` specs that run against Test/Production
in CI — so a developer can validate that exact suite against local MAMP
first. Dev also provisions a `logged-in-non-admin` project — a second local
test account distinct from `logged-in`'s admin account — as infrastructure
for future non-admin e2e coverage; no spec targets it yet, so it has no npm
script until one does.

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
npm run test:e2e:test:logged-in
npm run test:e2e:test:report
```

### Production

```bash
npm run test:e2e                   # All tests
npm run test:e2e:headed            # With browser
npm run test:e2e:not-logged-in
npm run test:e2e:logged-in
npm run test:e2e:report
```

### Dev Environment

```bash
npm run test:e2e:dev               # All E2E against local MAMP
npm run test:e2e:dev:headed        # With browser
npm run test:e2e:dev:ui            # UI mode
npm run test:e2e:dev:not-logged-in # Public pages only
npm run test:e2e:dev:logged-in     # Authenticated flows (admin account)
npm run test:e2e:dev:report        # View test report
```

## Authentication Setup

E2E tests use session persistence to avoid CAPTCHA challenges. Local and Dev
tiers instead use a live login (`auth.setup.js` / `auth-dev.setup.js`
respectively, see [ENVIRONMENT.md](../development/ENVIRONMENT.md)), with
credentials from `TEST_USERNAME`/`TEST_PASSWORD` in `.env.local`; Test and
Production use the 1Password / CAPTCHA flow described below.

### Prerequisites

- **1Password CLI** (`op`) installed and authenticated
- Credentials stored in 1Password:
  - Test: `op://ElanRegistry/Elanregistry - Test Admin/username`
  - Production: `op://ElanRegistry/elanregistry - test account/username`
- **Turnstile must be disabled on Test before running
  `./scripts/playwright-auth-1password-test.sh`** — an automated browser
  cannot solve a real challenge, including a Cloudflare test/always-pass
  sitekey (the script's own check does not distinguish sitekey types; see
  Troubleshooting). Re-enable it once auth setup completes. Production runs
  a real Turnstile challenge too, but its setup script is human-driven (see
  Setup Process below) — you solve the challenge yourself, so it isn't
  affected by this limitation.

### Setup Commands

```bash
# Test environment
./scripts/playwright-auth-1password-test.sh

# Production
./scripts/playwright-auth-1password.sh
```

### Setup Process

1. Browser opens to login page
2. Credentials auto-filled
3. **YOU MUST**: Solve CAPTCHA, click LOGIN, wait for redirect
4. Auth state saved (script waits up to 5 minutes)
5. Browser closes

### Auth Files

| Environment | File                   | Config                      |
| ----------- | ---------------------- | --------------------------- |
| Test        | `.auth/user-test.json` | `playwright.config.test.js` |
| Production  | `.auth/user.json`      | `playwright.config.prod.js` |

Both auth files are intended to hold a session for the **non-admin owner
account** (`elanregistry - test account` in 1Password) with at least one
registered car. Admin-session coverage on Test/Production is not yet
available.

**Known discrepancy:** Prerequisites above lists Test's 1Password credential
as `Elanregistry - Test Admin`, not the non-admin `elanregistry - test
account` this table describes — `user-test.json` may currently hold an admin
session under a tier documented as non-admin. Tracked by #2035, which also
covers building the dedicated admin tier this repo needs.

Files are gitignored. Re-run setup if sessions expire.

## Test Projects

### not-logged-in

- Public page accessibility
- No authentication required
- Always runs

### logged-in

- Authenticated workflows
- Requires a valid auth file
- Local/Dev: preceded by a `setup` project that performs a live login on
  every run
- Test/Production: preceded by a `check-auth` project that loads the
  existing auth file and verifies the session is still authenticated
  (navigates to an authenticated page and checks for a logged-in marker —
  live re-login isn't possible there, both origins run a real Cloudflare
  Turnstile challenge). If the file is missing or the session has expired,
  `check-auth` fails loudly with a message pointing at the relevant
  `scripts/playwright-auth-1password[-test].sh` script, and `logged-in` is
  skipped — its tests do not silently run anonymous. `not-logged-in` is
  unaffected and still runs to completion.

## Troubleshooting

| Issue | Solution |
| ------- | ---------- |
| Auth file doesn't exist | Run auth setup script — also now caught automatically by `check-auth` before `logged-in` runs on Test/Production |
| `check-auth` fails with "session is stale/expired" | Re-run `./scripts/playwright-auth-1password[-test].sh` (session expired — now caught automatically instead of `logged-in` silently running anonymous) |
| CAPTCHA timeout | Re-run setup, solve CAPTCHA promptly |
| Test-env auth setup fails with "Turnstile is enabled on this environment..." | **Turnstile is enabled on Test.** An automated browser cannot pass it — this is not a bug in the login flow, and the check fires on any Turnstile widget including a test/always-pass sitekey. Disable Turnstile entirely on Test, re-run the auth script, then re-enable it once the auth file is saved. Do not attempt to work around Turnstile programmatically. |
| Test-env auth setup fails with "Invalid credentials / 2FA / network" but credentials are correct | Turnstile may have rejected the submission after the pre-submit check missed it (a race — see `playwright-auth-setup-test.js`'s Turnstile-check comment). Confirm Turnstile is fully disabled on Test, not just set to a test sitekey, then re-run. |
| CI/CD failures | Check secrets, ensure auth runs before tests |

## Recommended Workflow

1. **Development**: `npm run playwright:test`
2. **Pre-release**: `npm run test:e2e:test`
3. **Post-deploy**: `npm run test:e2e`

See [TESTING.md](TESTING.md) for PHPUnit test documentation.
