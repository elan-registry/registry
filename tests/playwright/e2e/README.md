# E2E Production Tests

These tests validate user workflows on the **production environment** (`https://elanregistry.org`).

## Quick Start

### 1. Setup Authentication (One-time)

```bash
# Using 1Password (recommended)
./scripts/playwright-auth-1password.sh

# Or manually with environment variables
export ELAN_USERNAME="your_test_username"
export ELAN_PASSWORD="your_test_password"
node scripts/playwright-auth-setup.js
```

### 2. Run Tests

```bash
# Run all E2E tests
npm run test:e2e

# Run with browser visible
npm run test:e2e:headed

# Run only public page tests (no auth needed)
npm run test:e2e:not-logged-in

# Run only authenticated tests (auth required)
npm run test:e2e:logged-in
```

## Test Files

- **`not-logged-in.spec.js`** - Public page accessibility and link validation
- **`logged-in.spec.js`** - Authenticated user workflows
- **`helpers.js`** - Authentication utilities

## Configuration

- **Config**: `playwright.config.prod.js` (project root)
- **Base URL**: `https://elanregistry.org`
- **Auth State**: Saved to `tests/playwright/.auth/user.json` (gitignored)

## Documentation

See comprehensive guide: [`docs/testing/PLAYWRIGHT_E2E.md`](../../../docs/testing/PLAYWRIGHT_E2E.md)

## When to Run

- **CI/CD**: Scheduled runs (daily/weekly)
- **Pre-release**: Before deploying to production
- **Monitoring**: Continuous validation of production environment

## Troubleshooting

**Tests fail with login errors?**
→ Re-run auth setup: `./scripts/playwright-auth-1password.sh`

**No auth file found?**
→ Run setup script (see step 1 above)

**CAPTCHA timeout?**
→ Re-run setup and solve CAPTCHA within 5 minutes
