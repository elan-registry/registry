# E2E Production Tests

These tests validate user workflows on the **production environment** (`https://elanregistry.org`).

## Quick Start

### 1. Setup Authentication (One-time, or when stale)

Test/Prod run HTTPS with an active Cloudflare Turnstile challenge, so login
cannot happen automatically. A human must manually disable Turnstile, run the
setup script, then re-enable it:

```bash
node scripts/playwright-auth-setup.js prod admin
node scripts/playwright-auth-setup.js prod nonadmin
```

Credentials are read from `.env.local` (`E2E_PROD_ADMIN_USERNAME`/`_PASSWORD`,
`E2E_PROD_NONADMIN_USERNAME`/`_PASSWORD`) — see `docs/development/ENVIRONMENT.md`.

### 2. Run Tests

```bash
# Run all E2E tests
npm run test:e2e

# Run with browser visible
npm run test:e2e:headed

# Run only public page tests (no auth needed)
npm run test:e2e:not-logged-in

# Run only admin-authenticated tests (auth required)
npm run test:e2e:admin

# Run only non-admin-authenticated tests (auth required)
npm run test:e2e:logged-in
```

## Test Files

- **`not-logged-in.spec.js`** - Public page accessibility and link validation
- **`admin.spec.js`** - Authenticated admin user workflows

## Configuration

- **Config**: `playwright.config.prod.js` (project root)
- **Base URL**: `https://elanregistry.org`
- **Auth State**: Saved to `tests/playwright/.auth/user-prod-admin.json` and
  `user-prod-nonadmin.json` (gitignored)

## Documentation

See comprehensive guide: [`docs/testing/PLAYWRIGHT_E2E.md`](../../../docs/testing/PLAYWRIGHT_E2E.md)

## When to Run

- **Pre-release**: Before deploying to production, at milestone-release time
- **Monitoring**: Continuous validation of production environment

## Troubleshooting

**Tests fail with login errors, or auth file is stale/missing?**
→ Re-run auth setup (see step 1 above) — remember to manually disable
  Turnstile first, then re-enable it once done.

**Turnstile blocks the setup script?**
→ The script fails fast with an actionable message rather than hanging —
  disable Turnstile in Cloudflare before retrying.
