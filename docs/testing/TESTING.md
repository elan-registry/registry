# Elan Registry Test Suite

Automated test infrastructure using PHPUnit for PHP tests and Playwright for browser tests.

## Test Architecture

**Dual-bootstrap architecture** for test isolation and speed:

| Suite | Location | Bootstrap | Purpose |
| --- | --- | --- | --- |
| Unit | `tests/unit/` | `bootstrap-unit.php` | Fast tests with mocks, no database |
| Integration | `tests/integration/` | `bootstrap-integration.php` | Real database, UserSpice framework |
| Browser | `tests/playwright/` | Playwright config | End-to-end UI testing |

## Quick Start

### PHPUnit Commands

```bash
# Fast feedback (<1s)
composer test:quick       # Unit tests only

# Pre-commit (<3s)
composer test:medium      # Unit + Integration

# Full suite
composer test:full        # All PHP tests

# Individual suites
composer test:unit        # Unit tests (mocks)
composer test:integration # Integration tests (database)
composer test:regression  # Regression tests

# Coverage
composer test:coverage    # Generate HTML report
```

### Playwright Commands

```bash
# Setup (one-time)
npm install
npx playwright install

# Run tests
npm run playwright:test   # All browser tests
npm run test:security     # Security tests
npm run test:navigation   # Navigation tests
npm run test:functionality # Core functionality
npm run test:ui           # UI consistency
npm run test:debug        # Debug mode
```

## Test Organization

### Unit Tests (`tests/unit/`)

- **cars/services/**: CarValidatorTest.php, CarRepositoryTest.php, CarImageProcessorTest.php
- **security/**: FileUploadSecurityTest.php, InputValidationTest.php
- **users/**: VerificationSecurityTest.php
- **api/**: ApiResponseTest.php, GetDataTablesFindCarByChassisTest.php

### Integration Tests (`tests/integration/`)

- Car operations, database workflows, API endpoints
- **Reference/**: CarModelTest.php (car_models reference data)
- **cars/services/**: CarValidatorModelTest.php (model validation with database)
- **Featured tests**: FactoryRegistryLinkIntegrationTest.php (Registry Link feature)
- Requires: MySQL connection, UserSpice framework, car_models reference data

### Browser Tests (`tests/playwright/`)

- **e2e/**: factory-registry-link.spec.js (Registry Link UI workflow)
- Security, navigation, functionality, UI consistency
- Requires: Local dev server, default `http://localhost:9999/ElanRegistry/Registry` — override with `PLAYWRIGHT_BASE_URL`, see [ENVIRONMENT.md](../development/ENVIRONMENT.md)

## Writing Tests

### Unit Test Template

```php
<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class MyFeatureTest extends TestCase
{
    public function testFeatureWorks(): void
    {
        // Mock infrastructure available
        $this->assertTrue(true);
    }
}
```

### Integration Test Template

```php
<?php declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

final class MyFeatureIntegrationTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();
    }

    public function testWithDatabase(): void
    {
        $result = $this->db->query("SELECT 1")->first();
        $this->assertNotNull($result);
    }
}
```

## Test Database Setup

Integration tests require the `car_models` reference table (plus `settings`
and the `noowner` system account) to be populated.

### Provisioning

```bash
./scripts/provision-schema.sh
```

Applies the vendored stock UserSpice structure, runs `composer migrate`
(which applies the `UpdateSettingsBaselineDefaults`, `RegisterBaselinePermissions`,
and `RegisterNoownerAccount` migrations' ElanRegistry defaults, permissions
row, and system account), then `phinx seed:run` for `CarModelsSeed`
(`database/seeds/`). `tests/bootstrap-integration.php` only *verifies* these
exist on every test run — it aborts with a clear message (pointing at
`composer migrate`/`composer seed:run`) if they don't, rather than seeding
inline.

### Reference Data Requirements

Tests that require `car_models` data:

- `tests/integration/Reference/CarModelTest.php` - Complete CarModel class testing
- `tests/integration/cars/services/CarValidatorModelTest.php` - Model validation with real database

Unit tests never validate model-combination existence — that check needs a
real `car_models` row and is proven only in the integration tier above.

## Configuration

### Environment Variables (Integration Tests)

- `DB_HOST`, `DB_USER`, `DB_PASS`, `DB_NAME`
- Reads from `.env.local` (local dev) or `.env` (CI), loaded via phpdotenv

### PHPUnit Config Files

- `phpunit-unit.xml` - Unit test configuration (mocks only, no database)
- `phpunit-integration.xml` - Integration test configuration (uses real database)

## Troubleshooting

### Unit Tests

- **Mock errors**: Check `bootstrap-unit.php` is loading
- **Missing deps**: Run `composer install`

### Integration Tests

- **DB connection failed**: Check `.env.test.local` credentials
- **MAMP socket**: Verify `/Applications/MAMP/tmp/mysql/mysql.sock`
- **Missing data**: Tests must create their own fixtures via
  `IntegrationTestCase::createTestUser()`/`createTestCar()` — the isolated test
  schema starts empty, so no ambient user/car ID is guaranteed to exist
- **Empty car_models**: Run `./scripts/provision-schema.sh` (bare `composer seed:run`
  targets `.env`'s database, not the test schema in `.env.test.local`)

### Debugging

```bash
# Debug output
vendor/bin/phpunit -c phpunit-unit.xml --debug

# Single test
vendor/bin/phpunit -c phpunit-unit.xml --filter testFindByIdReturnsObjectForExistingCar tests/unit/cars/services/CarRepositoryTest.php
```

## Best Practices

1. **Prefer unit tests** for logic (faster, isolated)
2. **Use integration tests** for database operations
3. **Follow naming**: `TestClass.php`, `testMethodName()`
4. **Test both paths**: success and failure cases
5. **Keep tests focused**: one assertion concept per test

See [PLAYWRIGHT_E2E.md](PLAYWRIGHT_E2E.md) for browser test details.
