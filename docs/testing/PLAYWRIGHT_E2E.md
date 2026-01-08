# Playwright E2E Testing Guide

## Overview

The Elan Registry project uses a **three-tier Playwright testing strategy** to ensure comprehensive coverage across local development, test/staging, and production environments.

## Three-Tier Testing Architecture

### Tier 1: Local Development Tests
**Location**: `tests/playwright/` (root level test files)
**Purpose**: Feature development and debugging
**Environment**: Local development server (`http://localhost:9999/elan_registry`)
**When to run**: During development, before commits
**Configuration**: `playwright.config.js` (default)

**Test Files**:
- `security.test.js` - CSRF, XSS, session security
- `functionality.test.js` - DataTables, car forms, validation
- `navigation.test.js` - Navigation and backward compatibility
- `ui-consistency.test.js` - UI component consistency
- `login-functionality.test.js` - Login flow
- `maps-charts.test.js` - Maps and charts
- `csp-validation.spec.js` - Content Security Policy
- `ajax-endpoints.test.js` - AJAX endpoint testing
- `debug-tabs.test.js` - Debug features

### Tier 2: Test Environment E2E Tests
**Location**: `tests/playwright/e2e/`
**Purpose**: Pre-production validation and staging verification
**Environment**: Test/Staging (`https://test.elanregistry.org`)
**When to run**: Before production releases, feature validation
**Configuration**: `playwright.config.test.js`

**Test Files**:
- `not-logged-in.spec.js` - Public page accessibility and link validation
- `logged-in.spec.js` - Authenticated user workflows and menu verification
- `helpers.js` - Authentication utilities with session persistence

### Tier 3: Production E2E Tests
**Location**: `tests/playwright/e2e/`
**Purpose**: Production user workflow validation
**Environment**: Production (`https://elanregistry.org`)
**When to run**: CI/CD, post-release monitoring, scheduled health checks
**Configuration**: `playwright.config.prod.js`

**Test Files**:
- `not-logged-in.spec.js` - Public page accessibility and link validation
- `logged-in.spec.js` - Authenticated user workflows and menu verification
- `helpers.js` - Authentication utilities with session persistence

---

## Running Tests

### Local Development Tests

```bash
# Run all local tests
npm run playwright:test

# Run specific test suites
npm run playwright:security
npm run playwright:functionality
npm run playwright:navigation
npm run playwright:ui
npm run playwright:maps
npm run playwright:csp

# Run with UI
npm run playwright:headed

# Debug mode
npm run playwright:debug

# View report
npm run playwright:report
```

### Test Environment E2E Tests

```bash
# Run all test environment E2E tests
npm run test:e2e:test

# Run with browser visible
npm run test:e2e:test:headed

# Run with Playwright UI mode
npm run test:e2e:test:ui

# Run specific project
npm run test:e2e:test:not-logged-in
npm run test:e2e:test:logged-in

# View test environment E2E report
npm run test:e2e:test:report
```

### Production E2E Tests

```bash
# Run all E2E tests
npm run test:e2e

# Run with browser visible
npm run test:e2e:headed

# Run with Playwright UI mode
npm run test:e2e:ui

# Run specific project
npm run test:e2e:not-logged-in
npm run test:e2e:logged-in

# View E2E report
npm run test:e2e:report
```

---

## Recommended Testing Workflow

For safe, incremental validation of changes:

1. **Local Development**: Run local tests during development (`npm run playwright:test`)
2. **Test Environment**: Validate on test.elanregistry.org before production (`npm run test:e2e:test`)
3. **Production**: Run production tests post-deployment for verification (`npm run test:e2e`)

This three-tier approach ensures:
- Rapid feedback during development
- Safe pre-production validation
- Production health monitoring

---

## E2E Authentication Setup

E2E tests use **session persistence** to avoid CAPTCHA challenges on every test run. Separate authentication files are maintained for test and production environments.

### Prerequisites

**1Password CLI** - Install and authenticate with 1Password CLI (`op`)

**Account Credentials** - Stored in 1Password:
- **Test Environment**: `op://ElanRegistry/Elanregistry - Test Admin/username`
- **Production**: `op://ElanRegistry/elanregistry - test account/username`

### Setup Process

**For Test Environment (test.elanregistry.org):**

```bash
# Run the test environment setup script
./scripts/playwright-auth-1password-test.sh
```

**For Production (elanregistry.org):**

```bash
# Run the production setup script
./scripts/playwright-auth-1password.sh
```

#### What Happens During Setup

1. Browser window opens to login page
2. Credentials are auto-filled
3. **YOU MUST**:
   - Solve any CAPTCHA if present
   - Click the LOGIN button
   - Wait for redirect (script waits up to 5 minutes)
4. Authentication state is saved
5. Browser closes

### Session State Management

- **Auth Files**:
  - Test Environment: `tests/playwright/.auth/user-test.json`
  - Production: `tests/playwright/.auth/user.json`
- **Lifetime**: Sessions may expire; re-run setup if tests fail with login errors
- **Security**: Files are gitignored; never commit to repository
- **CI/CD**: Store as encrypted secret or regenerate in pipeline

---

## Test Projects

The E2E configuration defines two Playwright projects:

### not-logged-in Project
- **Tests**: Public page accessibility
- **No authentication required**
- **Always runs**

### logged-in Project
- **Tests**: Authenticated user workflows
- **Requires**: Authentication state file
  - Test environment: `tests/playwright/.auth/user-test.json`
  - Production: `tests/playwright/.auth/user.json`
- **Conditional**: Only runs if respective auth file exists

If the auth file doesn't exist, logged-in tests are skipped automatically.

---

## Test Coverage

### Public Pages Tested (not-logged-in)
- Home page
- Browse cars
- Cars by model
- Cars by location
- Maps & charts
- Individual car pages
- About page
- Privacy policy
- External links validation
- Download file detection

### Authenticated Features Tested (logged-in)
- All public pages (as authenticated user)
- Menu presence verification (Add Car, Feedback, Account)
- Car update workflow
- User profile features

---

## CI/CD Integration

### GitHub Actions Example

```yaml
name: E2E Tests

on:
  schedule:
    - cron: '0 0 * * *'  # Daily at midnight
  workflow_dispatch:      # Manual trigger

jobs:
  e2e-tests:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v3

      - name: Setup Node
        uses: actions/setup-node@v3
        with:
          node-version: '18'

      - name: Install dependencies
        run: npm ci

      - name: Install Playwright
        run: npx playwright install --with-deps chromium

      - name: Setup Auth State
        env:
          ELAN_USERNAME: ${{ secrets.ELAN_TEST_USERNAME }}
          ELAN_PASSWORD: ${{ secrets.ELAN_TEST_PASSWORD }}
        run: node scripts/playwright-auth-setup.js

      - name: Run E2E Tests
        run: npm run test:e2e

      - name: Upload Test Results
        if: always()
        uses: actions/upload-artifact@v3
        with:
          name: playwright-e2e-report
          path: playwright-report-e2e/
```

---

## Troubleshooting

### Issue: "Auth file doesn't exist"
**Solution**: Run authentication setup for the appropriate environment:

**Test Environment:**
```bash
./scripts/playwright-auth-1password-test.sh
```

**Production:**
```bash
./scripts/playwright-auth-1password.sh
```

### Issue: "Tests fail with login redirect"
**Cause**: Session expired
**Solution**: Re-run auth setup to generate new session state

### Issue: "CAPTCHA timeout"
**Cause**: Didn't solve CAPTCHA within 5 minutes
**Solution**: Re-run setup and solve CAPTCHA promptly

### Issue: "Tests fail on CI/CD"
**Solution**:
1. Ensure GitHub Secrets are properly configured (ELAN_TEST_USERNAME,
   ELAN_TEST_PASSWORD)
2. Verify auth setup runs before tests
3. Check that Playwright browsers are installed

---

## Best Practices

### Do's ✅
- Run E2E tests on staging before production
- Re-run auth setup if sessions expire
- Use scheduled CI/CD runs for continuous monitoring
- Check E2E test results before releases

### Don'ts ❌
- Don't commit `tests/playwright/.auth/user.json`
- Don't run E2E tests against local development
- Don't block local development on E2E failures
- Don't hardcode credentials in scripts

---

## File Structure

```
elan_registry/
├── tests/
│   └── playwright/
│       ├── e2e/
│       │   ├── not-logged-in.spec.js
│       │   ├── logged-in.spec.js
│       │   └── helpers.js
│       ├── .auth/
│       │   ├── user-test.json (gitignored, test env)
│       │   └── user.json (gitignored, production)
│       ├── security.test.js
│       ├── functionality.test.js
│       └── ... (other local tests)
├── scripts/
│   ├── playwright-auth-setup-test.js (test env)
│   ├── playwright-auth-1password-test.sh (test env, gitignored)
│   ├── playwright-auth-setup.js (production)
│   └── playwright-auth-1password.sh (production, gitignored)
├── playwright.config.js (local dev)
├── playwright.config.test.js (test environment E2E)
├── playwright.config.prod.js (production E2E)
└── package.json
```

---

## Maintenance

### When to Update E2E Tests

1. **New user-facing features**: Add to `logged-in.spec.js`
2. **New public pages**: Add to `not-logged-in.spec.js`
3. **Authentication changes**: Update `helpers.js`
4. **Test environment URL changes**: Update `playwright.config.test.js`
5. **Production URL changes**: Update `playwright.config.prod.js`

### Session Maintenance

- Sessions may last days to weeks
- Re-generate if tests start failing with auth errors
- Consider scheduled regeneration in CI/CD (weekly)

---

## Related Documentation

- [Playwright Documentation](https://playwright.dev/)
- [Testing Strategy](./TESTING.md)

---

## Questions?

For questions about:
- **Local tests**: See existing test files as examples
- **E2E setup**: Check `scripts/playwright-auth-setup.js` comments
- **CI/CD**: See `.github/workflows/` examples (if configured)
