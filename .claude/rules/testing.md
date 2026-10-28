---
paths:
  - "tests/**"
  - "phpunit*.xml"
  - "app/**/*.php"
  - "usersc/classes/**/*.php"
---

# Tests

Test tiers: `docs/development/TESTING_STRATEGY.md`. Playwright: `docs/testing/PLAYWRIGHT_E2E.md`.
Test locations: `tests/unit/`, `tests/integration/`, `tests/playwright/`.

## Commands

| Command | Runs |
| --- | --- |
| `composer test:quick` | PHPUnit unit tests. Use this for fast feedback. |
| `composer test:medium` | Unit and integration tests. |
| `composer test:full` | All PHP tests. |
| `composer test:coverage` | Unit tests with a coverage report. |
| `npm run playwright:security` | Security E2E tests. |
| `npm run playwright:maps` | Maps and charts. |
| `npm run playwright:csp` | CSP validation. |
| `npm run playwright:mobile` | Mobile responsive. |

- Before you finish, run `composer test:quick` and the Playwright suite for the
  changed page.
- Run database commands in the app container. See `CLAUDE.md`.

## When you write tests

- Add `declare(strict_types=1)` to test files.
- Name PHPUnit tests in camelCase: `test` + method + condition + result, for
  example `testGetCarsOwnedReturnsEmptyArrayWhenNoDataLoaded()`.
- Use data providers for input variants.
- Verify audit logging where the code writes audit records.
- Use a live test database to check SQL, triggers, and `*_hist` audit rows.
- Do not write a tautological test. It repeats the code under test.

```php
// Wrong: the test repeats the code under test
$this->assertSame(array_sum($prices), $cart->total());
```

- AJAX endpoint tests assert the response format `{success, message, ...}`.
- Form tests exercise the CSRF token path.
- DB change tests cover the audit-trail trigger behavior.
- Reproduce a bug with a PHPUnit test, a `curl` request, or a Playwright script.

## When you review test coverage

- Check tests for new public methods on `Car`, `Owner`, and `ApiResponse`.
  Missing tests here are critical gaps.
- Cover business branches: ownership transfers, payment, auth, and permissions.
- UI pages need a Playwright golden-path test and at least one edge case. See
  `playwright-tests.md`.
- Do not suggest tests for UserSpice framework behavior.
