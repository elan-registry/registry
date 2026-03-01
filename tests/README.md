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
├── playwright/                # Browser E2E tests
│   ├── e2e/                   # End-to-end workflows
│   ├── security/              # Security testing
│   ├── navigation/            # Navigation flows
│   └── ui/                    # UI consistency
│
├── bootstrap-unit.php         # Unit test bootstrap (mocks)
├── bootstrap-integration.php  # Integration test bootstrap (database)
└── setup-test-database.php    # Test database fixture loader
```

## Test Categories

### Unit Tests (`tests/unit/`)

**Purpose**: Fast, isolated testing with mocks
**Speed**: < 1 second total
**Database**: None (uses mocks)
**Run**: `composer test:quick` or `composer test:unit`

**Characteristics:**

- Mock CarModel class for model validation
- Mock DB class for database operations
- No UserSpice framework loaded
- Ideal for TDD and rapid feedback

**Example test suites:**

- `CarCoreTest.php` - Car class core methods
- `CarValidatorTest.php` - Input validation (with mock CarModel)
- `FileUploadSecurityTest.php` - Upload security

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

### Regression Tests (`tests/regression/`)

**Purpose**: Legacy test suite for backward compatibility
**Speed**: Variable
**Database**: Mock
**Run**: `composer test:regression`

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

Integration tests require the `car_models` reference table to be populated.

### Automatic Fixture Loading

The `bootstrap-integration.php` automatically loads reference data from
`database/2-reference-data.sql` when the `car_models` table is empty:

```php
// Happens automatically on first integration test run
// Loads 24 car model records into car_models table
```

### Manual Fixture Setup

You can manually run the setup script:

```bash
php tests/setup-test-database.php
```

**Output:**

```text
================================================================================
ELAN REGISTRY TEST DATABASE SETUP
================================================================================

✅ Database connection verified
📊 Current car_models records: 0
📂 Loading reference data from: /path/to/database/2-reference-data.sql
🔄 Loading car_models reference data...
✅ Loaded 24 car_models records successfully

📋 Sample records:
   - Elan 1500|Roadster|26 (Elan 1500)
   - S1|Roadster|26 (Elan 1600)
   - S4|FHC|36 (Coupe S4)
   - Sprint|FHC|36 (Coupe Sprint)
   - +2|FHC|50 (Plus 2)

================================================================================
✅ Test database setup complete.
================================================================================
```

### Fixture Requirements by Test

| Test Suite | car_models Required | Auto-loads |
| --- | --- | --- |
| `tests/unit/` | No (uses mocks) | N/A |
| `tests/integration/Reference/CarModelTest.php` | Yes | ✅ |
| `tests/integration/cars/services/CarValidatorModelTest.php` | Yes | ✅ |
| Other integration tests | No | N/A |

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
        // Uses mock CarModel automatically
        $result = $this->validator->validateAndSanitizeFields([
            'model' => 'S4|FHC|36', // Valid in mock
        ], false);

        $this->assertArrayHasKey('model', $result);
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
        // car_models table auto-populated by bootstrap
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
php tests/setup-test-database.php
```

Or check bootstrap output for fixture loading errors.

### Unit Tests Access Real Database

**Problem**: Unit test is marked `@group integration` but in `tests/unit/`

**Solution**: Move to `tests/integration/` or remove database dependency and use mocks.

### Mock CarModel Doesn't Match Real Data

**Problem**: Unit test fails because mock doesn't have all valid model combinations.

**Solution**: Either:

1. Add the model to mock in `bootstrap-unit.php`
2. Move test to integration suite if real data is required

## See Also

- [TESTING.md](../docs/testing/TESTING.md) - Comprehensive testing guide
- [PLAYWRIGHT_E2E.md](../docs/testing/PLAYWRIGHT_E2E.md) - Browser testing details
- [CODING_STANDARDS.md](../docs/development/CODING_STANDARDS.md) - Code quality standards
