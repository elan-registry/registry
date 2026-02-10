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

- **cars/**: CarCoreTest.php, CarCrudTest.php
- **security/**: FileUploadSecurityTest.php, InputValidationTest.php
- **users/**: UserDeletionCleanupTest.php
- **api/**: ApiResponseTest.php, GetDataTablesFindCarByChassisTest.php

### Integration Tests (`tests/integration/`)

- Car operations, database workflows, API endpoints
- **Featured tests**: FactoryRegistryLinkIntegrationTest.php (Registry Link feature)
- Requires: MySQL connection, UserSpice framework

### Browser Tests (`tests/playwright/`)

- **e2e/**: factory-registry-link.spec.js (Registry Link UI workflow)
- Security, navigation, functionality, UI consistency
- Requires: Local dev server at `http://localhost:9999/elan_registry`

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

## Configuration

### Environment Variables (Integration Tests)

- `DB_HOST`, `DB_USERNAME`, `DB_PASSWORD`, `DB_NAME`
- Reads from `.env.enc` (encrypted) or `.env.local` (plaintext)

### PHPUnit Config Files

- `phpunit-unit.xml` - Unit test configuration
- `phpunit-integration.xml` - Integration test configuration

## Troubleshooting

### Unit Tests

- **Mock errors**: Check `bootstrap-unit.php` is loading
- **Missing deps**: Run `composer install`

### Integration Tests

- **DB connection failed**: Check `.env.local` credentials
- **MAMP socket**: Verify `/Applications/MAMP/tmp/mysql/mysql.sock`
- **Missing data**: Ensure user ID 1 and car ID 1 exist

### Debugging

```bash
# Verbose output
vendor/bin/phpunit -c phpunit-unit.xml --verbose

# Single test
vendor/bin/phpunit tests/unit/cars/CarCoreTest.php::testFind
```

## Best Practices

1. **Prefer unit tests** for logic (faster, isolated)
2. **Use integration tests** for database operations
3. **Follow naming**: `TestClass.php`, `testMethodName()`
4. **Test both paths**: success and failure cases
5. **Keep tests focused**: one assertion concept per test

See [PLAYWRIGHT_E2E.md](PLAYWRIGHT_E2E.md) for browser test details.
