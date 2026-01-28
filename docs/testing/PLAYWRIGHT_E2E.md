# Playwright E2E Testing Guide

Three-tier Playwright testing strategy for local development, staging, and production environments.

## Three-Tier Architecture

| Tier | Location | Environment | When to Run |
|------|----------|-------------|-------------|
| **Local** | `tests/playwright/` | `localhost:9999/elan_registry` | During development |
| **Test** | `tests/playwright/e2e/` | `test.elanregistry.org` | Before releases |
| **Production** | `tests/playwright/e2e/` | `elanregistry.org` | Post-deployment |

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

## Authentication Setup

E2E tests use session persistence to avoid CAPTCHA challenges.

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

| Environment | File | Config |
|-------------|------|--------|
| Test | `.auth/user-test.json` | `playwright.config.test.js` |
| Production | `.auth/user.json` | `playwright.config.prod.js` |

Files are gitignored. Re-run setup if sessions expire.

## Test Projects

### not-logged-in
- Public page accessibility
- No authentication required
- Always runs

### logged-in
- Authenticated workflows
- Requires auth file
- Skipped if auth file missing

## Troubleshooting

| Issue | Solution |
|-------|----------|
| Auth file doesn't exist | Run auth setup script |
| Tests fail with login redirect | Session expired - re-run auth setup |
| CAPTCHA timeout | Re-run setup, solve CAPTCHA promptly |
| CI/CD failures | Check secrets, ensure auth runs before tests |

## Recommended Workflow

1. **Development**: `npm run playwright:test`
2. **Pre-release**: `npm run test:e2e:test`
3. **Post-deploy**: `npm run test:e2e`

See [TESTING.md](TESTING.md) for PHPUnit test documentation.
