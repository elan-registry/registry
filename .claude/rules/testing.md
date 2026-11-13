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
- Use a data provider when three or more tests have the same shape.
- Write a test only for a real behavior. Follow the rules in
  `docs/development/TESTING_STRATEGY.md`, "Writing a Test That Earns Its
  Place":
  - No new test that reads app source text, unless no behavior test is
    possible. Its docblock says why.
  - No test of the test's own regex or fixture.
  - An edge case needs a real trigger.
  - No test of PHP, MySQL, or UserSpice behavior, and no test that checks
    only a type or `instanceof`.
  - Tests of dev-only tooling cover the happy path and the failures that
    matter. Deploy and release safety scripts keep their depth.
  - Security tests (CSRF, XSS escaping, auth, SQL injection, upload
    validation, path traversal) keep their depth, unless another test covers
    the same case.
  - Delete a migration sandbox test (a class that extends
    `MigrationSandboxTestCase`, directly or through a subclass) one release
    after the migration deploys to production. Also delete each base class or
    fixture that only those tests use. Keep a class that tests code still in
    use after the migration.
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

- Ask for a test of each real behavior that has none: a business branch (for
  example an ownership transfer), an auth or permission decision, a data
  write, or a past bug that can occur again. Do not ask for a test of each
  public method or each field.
- A UI page needs a Playwright golden-path test. Ask for an edge case only
  when it has a real trigger. See `playwright-tests.md`.
- Do not suggest tests for UserSpice framework behavior.
- Do not suggest a test that breaks the rules above. Suggest a merge or a data
  provider when tests copy one another.
