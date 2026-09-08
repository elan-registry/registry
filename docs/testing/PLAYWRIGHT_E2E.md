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

Both auth files hold a session for the **non-admin owner account**
(`elanregistry - test account` in 1Password) with at least one registered
car. Admin-session coverage on Test/Production (e.g. for `app/admin/*`
endpoints) is not yet available — see #2035.

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
| CI/CD failures | Check secrets, ensure auth runs before tests |

## Recommended Workflow

1. **Development**: `npm run playwright:test`
2. **Pre-release**: `npm run test:e2e:test`
3. **Post-deploy**: `npm run test:e2e`

See [TESTING.md](TESTING.md) for PHPUnit test documentation.
