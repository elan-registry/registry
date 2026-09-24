# Test Suite Organization

Elan Registry uses a multi-tier test architecture for comprehensive quality assurance.

## Test Directory Structure

```text
tests/
├── unit/                      # Fast tests with mocks (< 1s)
│   ├── cars/                  # Car business logic
│   ├── security/              # Security validation
│   ├── users/                 # User management
│   └── api/                   # API response formatting
│
├── integration/               # Database tests with fixtures (< 5s)
│   ├── Reference/             # Reference data (CarModel)
│   ├── cars/services/         # Car service integration
│   ├── database/              # Database operations
│   ├── transfer/              # Car transfers
│   ├── workflow/              # Multi-step workflows
│   └── api/                   # API endpoints
│
├── regression/                # Legacy regression suite
│
├── Support/                   # Shared test doubles (e.g. FakeDatabase)
│
├── playwright/                # Browser E2E tests
│   ├── e2e/                   # End-to-end workflows
│   ├── security/              # Security testing
│   ├── navigation/            # Navigation flows
│   └── ui/                    # UI consistency
│
├── bootstrap-unit.php         # Unit test bootstrap (mocks)
└── bootstrap-integration.php  # Integration test bootstrap (database)
```

## Test Categories

### Unit Tests (`tests/unit/`)

**Purpose**: Fast, isolated testing with mocks
**Speed**: < 1 second total
**Database**: None (uses mocks)
**Run**: `composer test:quick` or `composer test:unit`

**Characteristics:**

- Mock DB class for database operations
- No UserSpice framework loaded
- Ideal for TDD and rapid feedback

**Example test suites:**

- `CarRepositoryTest.php` - Car database repository methods (real class, mocked DB boundary)
- `CarValidatorTest.php` - Input validation (model-combination existence is proven in the integration tier — see `CarValidatorModelTest.php`)
- `FileUploadSecurityTest.php` - Upload security

**Mock/fake audit log:** as of 2026-08-10 (#1556), all 66 files in `tests/unit/`
have been individually confirmed to exercise real production code, not a
bootstrap mock/fake standing in for the subject under test.
Issues #1440, #1441, #1444, #1445, #1446, #1554, and #1566 fixed 14 files
found by name-based grepping; #1556 read the remaining 52 and found 8 more reimplementation/
tautology cases, now tracked as #1597–#1604 (targeted at v2.29.2). One
file (`RobotsTxtPolicyTest.php`) has a different honesty gap: it verifies
the repo's `robots.txt` rather than what's actually served, and its own
evaluator is a documented, deliberate simplification (first-matching-group
only, no tie-break) that isn't fully RFC 9309-compliant even against the
repo file alone. `tests/integration/RobotsTxtAsServedTest.php` (#1542)
covers the as-served case with a stricter evaluator instead, living in the
integration tier because it requires live network access. Before assuming a
new `tests/unit/` file is clean by default, check whether it predates this
audit.

### Integration Tests (`tests/integration/`)

**Purpose**: Real database operations and workflows
**Speed**: < 5 seconds total
**Database**: Required (auto-loads fixtures)
**Run**: `composer test:integration` or `composer test:medium`

**Characteristics:**

- Real CarModel class with car_models table
- Real DB class with MySQL connection
- UserSpice framework loaded
- Auto-loads reference data on first run

**Example test suites:**

- `CarModelTest.php` - CarModel reference data queries
- `CarValidatorModelTest.php` - Model validation with real database
- `FactoryRegistryLinkIntegrationTest.php` - Registry Link feature

`RobotsTxtAsServedTest.php` in this directory is the one exception to the
"database only" characterisation: it additionally performs a live outbound
HTTPS fetch of `https://test.elanregistry.org/robots.txt` (picked up by
`composer test:integration` and `composer test:full`, but not by
`composer test:medium`, which runs only `tests/integration/database`), and it
skips cleanly rather than failing when the network or that host is unreachable.

### Regression Tests (`tests/unit/regression/`)

**Purpose**: Pin specific bug fixes so they cannot silently regress
**Speed**: Fast (same as `tests/unit/`)
**Database**: Mock
**Run**: `composer test:regression`

Regression tests are ordinary unit tests — they load via the same
`tests/bootstrap-unit.php` mocks as the rest of `tests/unit/`, and live under
`tests/unit/regression/` rather than a separate top-level directory. What
distinguishes them is the `#[Group('regression')]` attribute, which
`composer test:regression` filters on (`--testsuite=Unit --group regression`
against `phpunit-unit.xml`).

To add a new one, copy an existing file in `tests/unit/regression/` as a
model — there is no template file anymore. Include these PHPDoc annotations
and the group attribute:

- `@issue {NUMBER}` — the GitHub issue number the test pins
- `@link https://github.com/elan-registry/registry/issues/{NUMBER}`
- `@category` — a short category (e.g. `security`, `regression`, `infrastructure`)
- `#[Group('regression')]` on the class, immediately above the class
  declaration

Name the file `Issue{NUMBER}RegressionTest.php` when it pins a single issue,
or a descriptive `{Name}RegressionTest.php` (e.g.
`EncodeAtOutputRegressionTest.php`) when it covers a cross-cutting concern
that doesn't map to one issue number. A regression test that needs a real
database belongs in `tests/integration/` instead.

### Browser Tests (`tests/playwright/`)

**Purpose**: End-to-end UI testing
**Speed**: Seconds to minutes
**Database**: Live test environment
**Run**: `npm run playwright:test`

**Specialized suites:**

- `:security` - CSRF, XSS, authentication
- `:navigation` - Menu, breadcrumbs, routing
- `:functionality` - Core features
- `:ui` - Visual consistency

## Database Fixtures

Integration tests require the `car_models` reference table (plus `settings`
and the `noowner` system account) to be populated. This is provisioning's job,
not the test bootstrap's — see [Test Data Isolation](#test-data-isolation) below.

### Provisioning a Test Schema

```bash
./scripts/provision-schema.sh
```

This applies the vendored stock UserSpice structure, runs `composer migrate`
(which applies the `UpdateSettingsBaselineDefaults`, `RegisterBaselinePermissions`,
and `RegisterNoownerAccount` migrations' ElanRegistry settings defaults,
permissions row, and system account), then `phinx seed:run` for
`CarModelsSeed` (see `database/seeds/`). `tests/bootstrap-integration.php`
verifies these exist on every test run and aborts with a clear message —
pointing at `composer migrate`/`composer seed:run` — if they don't, rather
than silently trying to fix it inline.

### Fixture Requirements by Test

| Test Suite | car_models Required | Auto-loads |
| --- | --- | --- |
| `tests/unit/` | No (uses mocks) | N/A |
| `tests/integration/reference/CarModelTest.php` | Yes | ✅ |
| `tests/integration/cars/services/CarValidatorModelTest.php` | Yes | ✅ |
| Other integration tests | No | N/A |

## Test Data Isolation

Integration tests run against a dedicated, isolated test schema (see #1436).
A **freshly provisioned** schema (`scripts/provision-schema.sh`) starts with no
ambient cars, users, or other rows. Two different lifecycles apply
after that:

- **Per-test fixtures** — anything a test creates (via `createTestUser()`,
  `createTestCar()`, or a direct insert like a `profiles`/`car_transfer_requests`
  row) is torn down after *that test* in `tearDown()`. Every test starts and
  ends with none of its own data left behind.
- **Schema-level reference/config data** — `car_models` is seeded once by
  `composer seed:run` (`database/seeds/CarModelsSeed.php`), then **persists
  across every subsequent run** (it is never torn down). The `noowner`
  system account and `permissions` id=3 row are created once by the
  `RegisterNoownerAccount` and `RegisterBaselinePermissions` migrations. The
  `settings` row (id=1) is created by UserSpice's own install wizard rather
  than a seed. This mirrors a real install, which configures these once, not
  per test. `tests/bootstrap-integration.php` only *verifies* they exist — it
  does not create them itself. Re-running `scripts/provision-schema.sh`
  resets everything, migrations and seeds included.

The `settings` row's ElanRegistry default values are applied by the
`UpdateSettingsBaselineDefaults` migration
(`database/migrations/20260817033111_update_settings_baseline_defaults.php`),
layered over UserSpice's own column defaults. Those values come from the real
ElanRegistry production configuration (`site_name`, `permission_restriction`,
`session_manager`, `req_cap`/`req_num`, `email_login`, etc.), plus a few
standard UserSpice defaults (`min_pw`/`max_pw`/`min_un`/`max_un`) not tied to
any ElanRegistry-specific value. Don't assume a setting *not* covered by that
migration matches production — set it explicitly in your test if it does.

Every test must create the fixtures it depends on and must never assume
pre-existing data exists. Tests that relied on ambient data in the old shared
dev database (1590+ cars, 1763+ users) will fail or pass for the wrong reason
against an empty schema.

**Don't** hardcode assumed IDs, grab "whatever exists", or assert on
registry-wide counts:

```php
// Assumes car/user 1 exists, or that a row is "just lying around"
$owner = new Owner($userId = 1);
$row = $this->db->query('SELECT * FROM cars LIMIT 1')->first();
$count = $this->db->query('SELECT COUNT(*) AS c FROM cars')->first();
$this->assertGreaterThan(0, $count->c);
```

**Do** create the fixtures the test needs, then assert against them:

```php
$userId = $this->createTestUser();
$carId = $this->createTestCar($userId, ['chassis' => 'EL12345S']);

$car = $this->db->query('SELECT * FROM cars WHERE id = ?', [$carId])->first();
$this->assertSame($userId, (int) $car->user_id);
```

Use `IntegrationTestCase::createTestUser()` and `createTestCar()`
(`tests/integration/IntegrationTestCase.php`) to create fixture rows — both
generate unique, non-colliding data and are automatically cleaned up in
`tearDown()`.

## Quick Reference

```bash
# Development workflow
composer test:quick              # Fast unit tests only
composer test:medium             # Unit + Integration
composer test:full               # All PHP tests

# Individual suites
composer test:unit               # Unit tests with mocks
composer test:integration        # Integration tests with database
composer test:regression         # Regression tests

# Coverage analysis
composer test:coverage           # HTML coverage report

# Browser tests
npm run playwright:test          # All browser tests
npm run playwright:test:security # Security suite
npm run playwright:test:ui       # UI consistency
```

## CI vs. Local Test Runs, and the `known-broken` Group

`.github/workflows/tests.yml` runs `composer test:quick:ci` and `composer test:regression:ci`
(not plain `test:quick`/`test:regression`) for the CI-blocking check on every PR. Each `:ci`
variant is identical to its base command except it adds one or more `--exclude-group` flags —
both suites support the exclusion identically, so a tagged test is skipped in CI regardless of
which suite it lives in.

**PHPUnit CLI gotcha:** `--exclude-group` does **not** accept a comma-separated list of groups
in a single flag (e.g. `--exclude-group foo,bar` silently excludes nothing, since PHPUnit treats
the whole comma-joined string as one literal group name that matches no test). To exclude
multiple groups, repeat the flag: `--exclude-group foo --exclude-group bar`. Verified empirically
while adding the `requires-upstream-install` group (#1471) — the comma form ran the excluded
test anyway with no error or warning, so this is easy to get wrong silently.

**Second CLI gotcha — CLI exclusions *replace* XML ones:** `phpunit-integration.xml` declares
`<groups><exclude>` for `live-network` (tests making real outbound HTTP calls — `LocationServiceTest`'s
live methods, `RobotsTxtAsServedTest`, `VendorBootstrapMapsScriptTest`) and `known-broken`. In
PHPUnit 12 any `--exclude-group` passed on the command line **replaces** that XML list entirely
rather than adding to it (`vendor/phpunit/phpunit/src/TextUI/Configuration/Merger.php:797-805`),
so a `--exclude-group known-broken` on an integration run would silently re-enable `live-network`.
That is why the integration composer scripts pass no `--exclude-group` at all and rely on the XML
— only the `phpunit-unit.xml` scripts (`test:quick:ci`, `test:regression:ci`) use the flag, and
`phpunit-unit.xml` has no `<groups>` block for them to clobber. To run an excluded group
deliberately, opt it back in with `--group`, which is unaffected:

```bash
vendor/bin/phpunit -c phpunit-integration.xml --group live-network
```

**Why this exists:** landing a new CI gate (`#1437`) should never be blocked indefinitely by
an unrelated, already-tracked, pre-existing bug — but a bypass that's silent or permanent is
worse than no gate at all. The `known-broken` group is the explicit, visible, temporary
escape hatch:

- Tag an affected test method with `#[Group('known-broken')]` **plus** an inline comment
  citing the tracking issue, e.g. `// #1470 — fails on Linux CI, root cause under investigation`.
  **This comment format is load-bearing, not just documentation** — `/finish-milestone`'s
  Step 3.5 greps for the tag and parses the issue number out of that free-text `// #NNN — ...`
  comment to check whether it's still open. A tag added without a `#NNN` reference in that
  exact form will silently defeat that check.
- `composer test:quick`/`composer test:regression` (the default local/dev commands, and each
  `:ci` variant's superset) still run and report these failures — nobody loses visibility locally.
- Only the `:ci` variants (the CI-blocking commands) skip them, and only until the tracking
  issue is resolved and the tag is removed.
- `/finish-milestone` checks for any remaining `known-broken`-tagged tests and asks for
  explicit confirmation before finishing a milestone with known-excluded tests still present
  — this prevents the escape hatch from silently becoming permanent.

Do not use this group for a test you simply don't feel like fixing right now — it exists
specifically for "this genuinely needs investigation, is tracked, and shouldn't block an
unrelated PR" scenarios, discovered in practice when `tests.yml` first ran on a clean Linux
CI checkout and surfaced pre-existing macOS-vs-Linux behavior differences invisible to local
development.

## The `requires-upstream-install` Group — a Different Concept From `known-broken`

A second `--exclude-group` tag exists in `test:quick:ci`: `requires-upstream-install`. Unlike
`known-broken`, this is **not** a temporary bypass for a tracked, resolvable bug — it's a
permanent, environment-conditional classification for a test that structurally cannot assert
anything without a real local UserSpice install (e.g. `SecurityHeadersTest::testUpstreamScriptHashesMatchActualFiles()`,
which verifies CSP hashes against gitignored, environment-local upstream files that never
exist in a fresh checkout — see CLAUDE.md's Template Customization Rules for why those files
aren't tracked).

- Not tied to any GitHub issue, not expected to ever be "resolved" or removed.
- Such a test must `markTestSkipped(...)` with a clear reason when its required local files are
  absent, rather than silently completing with zero assertions (PHPUnit reports a zero-assertion
  test as "risky," not "skipped" — risky tests don't fail the build by default and are easy to
  miss in CI output; an explicit skip is visible and self-explanatory).
- `composer test:quick`/`composer test:regression` (no `:ci` suffix) still run these tests, so a
  developer with a full local UserSpice install gets real verification when running the full suite.
- `/finish-milestone`'s known-broken check does **not** apply to this group — there's nothing to
  resolve or report on, it's a standing, intentional environment split.

## The `regression` Group Exclusion in `test:quick:ci` — Keeping the Two CI Steps Disjoint

A third `--exclude-group` tag exists in `test:quick:ci`, but for a different reason than either
group above: `regression`. Since `tests/unit/regression/` is a subdirectory of `tests/unit/`,
`test:quick:ci`'s `Unit` testsuite would otherwise run every regression test a second time —
`.github/workflows/tests.yml`'s dedicated "Run regression tests" step (`composer
test:regression:ci`) already covers them via `--group regression`. Excluding the group from
`test:quick:ci` keeps the two CI steps disjoint, the same way `tests/regression/` and `tests/unit/`
were disjoint directories before #1559 collapsed them together. `composer test:quick` (no `:ci`
suffix, the local/dev command) does **not** exclude this group — locally, one full run of
`tests/unit/` is expected to include everything under it, and running the regression subset
redundantly costs a developer nothing the way a second CI job would.

## Writing New Tests

### Unit Test Example

```php
<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use ElanRegistry\Car\CarValidator;

final class MyValidatorTest extends TestCase
{
    private CarValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new CarValidator();
    }

    public function testValidatesInput(): void
    {
        // Model-combination validation needs a real car_models row — that
        // check is only meaningful in the integration tier (see
        // CarValidatorModelTest.php). Unit tests exercise everything else
        // validateAndSanitizeFields() does.
        $result = $this->validator->validateAndSanitizeFields([
            'chassis' => 'ABC123',
        ], false);

        $this->assertArrayHasKey('chassis', $result);
    }
}
```

### Integration Test Example

```php
<?php declare(strict_types=1);

namespace Tests\Integration\Reference;

use PHPUnit\Framework\TestCase;
use ElanRegistry\Reference\CarModel;

/**
 * @group integration
 * @group reference-data
 */
class MyCarModelTest extends TestCase
{
    private CarModel $carModel;

    protected function setUp(): void
    {
        // Real CarModel with database
        // car_models table populated by CarModelsSeed during provisioning
        $this->carModel = new CarModel();
    }

    public function testQueriesDatabase(): void
    {
        $models = $this->carModel->getAll();
        $this->assertGreaterThanOrEqual(20, count($models));
    }
}
```

## Troubleshooting

### Integration Tests Fail with "car_models table is empty"

**Solution:**

```bash
./scripts/provision-schema.sh
```

`composer seed:run` alone targets whatever `.env` (not `.env.test.local`) points
at — the app/dev database, not the test schema — so it's not a safe substitute
here.

### Unit Tests Access Real Database

**Problem**: Unit test is marked `@group integration` but in `tests/unit/`

**Solution**: Move to `tests/integration/` or remove database dependency and use mocks.

## See Also

- [TESTING.md](../docs/testing/TESTING.md) - Comprehensive testing guide
- [PLAYWRIGHT_E2E.md](../docs/testing/PLAYWRIGHT_E2E.md) - Browser testing details
- [CODING_STANDARDS.md](../docs/development/CODING_STANDARDS.md) - Code quality standards
