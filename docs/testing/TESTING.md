# Elan Registry Test Suite

Comprehensive automated test cases for the Elan Registry application, organized into logical test suites optimized for development workflows and CI/CD pipelines.

## Test Infrastructure Overview

The test suite uses a **dual-bootstrap architecture** for optimal test isolation and execution speed:

### **Unit Tests** (`tests/unit/` - 407 tests)
Fast tests using **mock infrastructure** (no database):
- **Bootstrap**: `tests/bootstrap-unit.php`
- **Execution Time**: <1 second
- **Dependencies**: Mock DB, Mock functions, no real database
- **Purpose**: Core logic, validation, security, and component functionality
- **Mock Infrastructure**: Complete DB class simulation with configurable mock data

### **Integration Tests** (`tests/integration/` - 126 tests)
Tests using **real database connections** and UserSpice framework:
- **Bootstrap**: `tests/bootstrap-integration.php`
- **Execution Time**: <2 seconds
- **Dependencies**: Live database connection, UserSpice framework
- **Purpose**: Database operations, data integrity, workflow validation
- **Database Setup**: Connects via environment variables, supports encrypted .env.enc
- **Zero Skips**: All 126 tests pass without skipping

### **Regression Tests** (`tests/regression/` - in `tests/unit/`)
Issue-specific tests preventing previously fixed bugs from reoccurring:
- **Bootstrap**: `tests/bootstrap-unit.php`
- **Dependencies**: Varies by issue
- **Purpose**: Prevent regressions of specific GitHub issues
- **Template**: Copy `RegressionTestTemplate.php` and replace `{ISSUE_NUMBER}`

### 📁 Browser Tests (`tests/playwright/`) - REQUIRES SETUP
End-to-end browser automation tests (not included in core infrastructure):
- **Execution Time**: 2-5 minutes
- **Dependencies**: Playwright setup, configured web server, authentication
- **Purpose**: User workflows, UI validation, cross-browser testing
- **Status**: Available but requires additional configuration

**Test Suites:**
- **navigation.test.js** - File reorganization and backward compatibility (301 redirects, breadcrumbs, navigation)
- **security.test.js** - Security validations (CSRF tokens, session cookies, XSS prevention, input sanitization)
- **functionality.test.js** - Core features (DataTables, car edit forms, chassis validation, contact forms, AJAX endpoints)
- **ui-consistency.test.js** - Style consistency (card layouts, responsive design, button/form styling, mobile compatibility)
- **maps-charts.test.js** - JavaScript integrations (Google Maps, Google Charts, map markers, statistics visualization)
- **csp-validation.spec.js** - Content Security Policy validation
- **ajax-endpoints.test.js** - AJAX endpoint testing
- **login-functionality.test.js** - Login flow validation

**Browser Coverage:**
- Chromium (Desktop Chrome)
- Firefox (Desktop Firefox)
- WebKit (Desktop Safari)
- Mobile Chrome (Pixel 5)
- Mobile Safari (iPhone 12)

See [PLAYWRIGHT_E2E.md](PLAYWRIGHT_E2E.md) for detailed two-tier testing strategy (local development vs production).

## Test Coverage

### CarUpdateTest.php
Tests core car update functionality including:
- ✅ **Car Creation** - Adding new cars with validation
- ✅ **Car Updates** - Modifying existing car information  
- ✅ **Input Validation** - Required fields, data formatting
- ✅ **CSRF Protection** - Token validation for security
- ✅ **Date Handling** - Date parsing and formatting
- ✅ **Engine Number Formatting** - Uppercase, space removal
- ✅ **Chassis Validation** - Pre-1970 4-digit rule, race car exception
- ✅ **Image Management** - Fetch and remove car images
- ✅ **Error Handling** - Invalid actions and missing data

### FileUploadSecurityTest.php  
Tests file upload security enhancements including:
- 🔒 **Secure Filename Generation** - Cryptographic randomness
- 🔒 **MIME Type Validation** - Strict allowlisting 
- 🔒 **File Size Limits** - Maximum and minimum size enforcement
- 🔒 **Upload Error Handling** - Proper error code processing
- 🔒 **Directory Traversal Prevention** - Path validation
- 🔒 **Malicious File Protection** - Polyglot and script injection prevention
- 🔒 **Entropy Testing** - Filename randomness quality

### UserDeletionCleanupTest.php
Tests GDPR-compliant user deletion and database cleanup including:
- 🔒 **Dynamic noowner Lookup** - No hardcoded user IDs
- 🔒 **Profile Cleanup** - Orphaned profile removal
- 🔒 **Car Ownership Transfer** - Preserves cars while respecting deletion rights
- 🔒 **Audit Trail Compliance** - Complete logging for GDPR requirements  
- 🔒 **Fallback Handling** - Graceful degradation when noowner missing
- 🔒 **Batch Processing** - Multiple user deletion scenarios
- 🔒 **Data Integrity** - Database consistency after cleanup operations

### Test Architecture

**Unit Tests** - Use comprehensive mocking system:
- **Mock DB Class** - Complete database interface simulation in `bootstrap-unit.php`
- **Mock Functions** - Mocked UserSpice functions, Token class, Input validation
- **Isolated Testing** - No real database connections or side effects
- **Fast Execution** - Suitable for rapid feedback during development

**Integration Tests** - Use real database:
- **Real Database** - Connects to live database via secure environment variables
- **UserSpice Framework** - Full bootstrap of application framework
- **SecureEnvPHP** - Encrypted environment file support (.env.enc)
- **Socket Configuration** - Automatic MAMP MySQL socket detection
- **Data Fixtures** - Tests use existing database data (user ID 1, car ID 1)

## Security Validations

The test suite validates protection against:

- **SQL Injection** - All database operations use prepared statements
- **File Upload Attacks** - MIME validation, size limits, secure naming
- **Directory Traversal** - Path sanitization and validation
- **CSRF Attacks** - Token verification for all state-changing operations
- **Input Validation** - Comprehensive field validation and sanitization
- **GDPR Violations** - User deletion rights balanced with data preservation
- **Data Integrity Issues** - Database consistency during cleanup operations

## Quick Start Commands

### PHP (PHPUnit) Tests
```bash
# Fast feedback loop (<1s) - Unit tests only
composer test:quick       # Runs 407 unit tests with mocks

# Pre-commit validation (<3s) - Unit + Integration
composer test:medium      # Runs 407 unit + 126 integration tests

# Complete PHP test suite
composer test:full        # All 533 tests (407 unit + 126 integration)

# Run specific test suites
composer test:unit        # 407 unit tests (mocks, no database)
composer test:integration # 126 integration tests (real database)
composer test:regression  # Issue-specific regression tests

# Generate coverage report
composer test:coverage    # Coverage from unit tests
```

### Test Results Snapshot
```
Unit Tests:        407 tests ✅
Integration Tests: 126 tests ✅
Regression Tests:  In unit suite
Total:            533 tests passing with zero failures, zero skipped
```

### Browser (Playwright) Tests - REQUIRES SETUP

**Prerequisites:**
- Local development server running at `http://localhost:9999/elan_registry`
- Playwright browsers installed

**Installation:**
```bash
npm install
npx playwright install
```

**Running Tests:**
```bash
# UI tests require additional setup
npm test  # Shows setup instructions

# Once configured, Playwright tests are available:
npm run playwright:install    # Install browsers
npm run playwright:test       # Run all UI tests

# Run specific test suites
npm run test:security         # Security-focused tests
npm run test:navigation       # Navigation and redirects
npm run test:functionality    # Core functionality
npm run test:ui               # UI consistency tests
npm run test:maps             # Maps and charts

# Debug mode
npm run test:debug            # Opens browser with debugging tools
npm run test:headed           # Run tests in headed mode (visible browser)

# View test reports
npm run test:report           # Opens HTML test report
```

**Configuration:**
- **Base URL**: `http://localhost:9999/elan_registry`
- **Timeout**: 30 seconds per test
- **Retries**: 2 on CI, 0 locally
- **Screenshots**: Captured on failure
- **Videos**: Recorded on failure

### Legacy Commands (Still Supported)
```bash
# Direct PHPUnit usage
./vendor/bin/phpunit tests/unit/CarTest.php
./vendor/bin/phpunit --testsuite=Unit
./vendor/bin/phpunit --coverage-html=coverage/
```

## Development Workflow

### For Local Development
```bash
# 1. Quick feedback while coding (PHP only)
composer test:quick

# 2. Before committing changes (PHP only)
composer test:medium

# 3. Before pushing to main branch (PHP only)
composer test:full

# 4. UI testing (requires setup)
# Configure web server and Playwright, then:
npm run playwright:test
```

### For Issue Resolution
```bash
# 1. Create regression test for the issue
cp tests/regression/RegressionTestTemplate.php tests/regression/Issue{NUMBER}RegressionTest.php

# 2. Update Issue{NUMBER}RegressionTest class name and replace {ISSUE_NUMBER} with actual number

# 3. Ensure test fails with current code
composer test:regression

# 4. Fix the issue in the codebase
# ... make your changes ...

# 5. Verify regression test now passes
composer test:regression

# 6. Run full test suite to check for regressions
composer test:full
```

### For Writing New Tests

**Unit Test** (uses mocks):
```bash
# Create new unit test file
touch tests/unit/FeatureName/NewFeatureTest.php

# Extend MockTestCase for access to mock infrastructure
class NewFeatureTest extends MockTestCase { ... }
```

**Integration Test** (uses real database):
```bash
# Create new integration test file
touch tests/integration/NewFeatureTest.php

# Extend IntegrationTestCase for database access
class NewFeatureTest extends IntegrationTestCase {
    protected function setUp(): void {
        parent::setUp();
        $this->requireDatabase();  # Skip if DB unavailable
    }
}
```

## Test Requirements

### For Unit Tests
- **PHP 8.2+** (required for PHPUnit 12 compatibility)
- **PHPUnit 11.5+** - Installed via Composer
- **No database required** - All tests use mock infrastructure
- **No additional setup** - Ready to run out of the box

### For Integration Tests
- **PHP 8.2+** with MySQL 8.0+
- **Database connection** - Real MySQL database
- **Environment configuration** - `.env.enc` (encrypted) or `.env.local` (plaintext)
- **SecureEnvPHP** - For parsing encrypted environment files
- **UserSpice framework** - Full initialization during bootstrap

## Test Configuration

### Unit Tests (`phpunit-unit.xml`)
- Bootstrap: `tests/bootstrap-unit.php`
- Mock infrastructure with complete DB simulation
- No external dependencies
- Fast execution

### Integration Tests (`phpunit-integration.xml`)
- Bootstrap: `tests/bootstrap-integration.php`
- Real database connection via secure environment variables
- UserSpice framework initialization
- SecureEnvPHP for encrypted environment files

### Environment Variables
Integration tests read from:
1. Encrypted `.env.enc` (via SecureEnvPHP)
2. Plaintext `.env.local` (fallback)
3. Database configuration: `DB_HOST`, `DB_USERNAME`, `DB_PASSWORD`, `DB_NAME`

### Database Configuration
```
Database: Auto-detected from environment
Host: localhost (or configured)
Port: Detected from DB_HOST (supports MAMP MySQL sockets)
```

## Expected Results

### Unit Tests

```bash
$ composer test:unit
PHPUnit 11.5.46 by Sebastian Bergmann and contributors.

...............................................................  63 / 407 ( 15%)
............................................................... 126 / 407 ( 30%)
............................................................... 189 / 407 ( 46%)
............................................................... 252 / 407 ( 61%)
............................................................... 315 / 407 ( 77%)
............................................................... 378 / 407 ( 92%)
.............................                                   407 / 407 (100%)

Time: 00:00.758, Memory: 12.00 MB

OK (407 tests, 3389 assertions)
```

### Integration Tests

```bash
$ composer test:integration
PHPUnit 11.5.46 by Sebastian Bergmann and contributors.

WWWWWWWW...........WW...........................
✅ Database has 1136 users
✅ Database has 1229 cars
✅ Retrieved user: admin (ID: 1)
✅ Retrieved car: 1966 S3|FHC-preairflow|36 (ID: 1)
...................................
✓ Forward geocoding successful: Portland → (45.5202, -122.6742)
✓ Reverse geocoding successful: (45.52, -122.68) → Portland, United States
✓ Coordinate validation working correctly
........................................................... 126 / 126 (100%)

Time: 00:00.098, Memory: 12.00 MB

OK (126 tests, 451 assertions)
```

### Full Test Suite

```bash
$ composer test:full

Unit Tests:        407 tests ✅
Integration Tests: 126 tests ✅
Total:            533 tests passing, zero failures, zero skipped
```

### What This Validates


✅ **Unit Tests** (407) - Core logic, security, validation, file uploads
✅ **Integration Tests** (126) - Database operations, workflows, APIs
✅ **Zero Failures** - All functionality working correctly
✅ **Zero Skipped** - Complete test coverage without gaps
✅ **Security Validations** - CSRF, XSS, SQL injection, file upload, input validation

## Troubleshooting

### Troubleshooting Unit Tests

1. **Mock DB errors**
   - Verify `tests/bootstrap-unit.php` is properly loading
   - Check that mock infrastructure is initialized
   - Run with: `composer test:unit`

2. **Missing dependencies**
   - Install: `composer install`
   - Verify PHPUnit: `vendor/bin/phpunit --version`

3. **Failed security tests**
   - Check validation logic hasn't changed
   - Review security headers in code
   - Ensure input sanitization is applied

### Troubleshooting Integration Tests

1. **Database connection failed**
   - Check environment variables in `.env.local` or `.env.enc`
   - Verify MySQL is running and accessible
   - For MAMP: Check socket path at `/Applications/MAMP/tmp/mysql/mysql.sock`

2. **SecureEnvPHP parsing errors**
   - Verify `.env.enc` is properly encrypted
   - Check `.env.local` exists as fallback
   - Ensure DB credentials are correct

3. **UserSpice framework errors**
   - Verify `users/init.php` loads correctly
   - Check `users/classes/` directory exists
   - Ensure all required plugins are present

4. **Test data not found**
   - Ensure database has user ID 1 and car ID 1
   - Tests assume existing data in database
   - Check database was properly initialized

### Debugging Failed Tests

1. **Run with verbose output**:
   ```bash
   vendor/bin/phpunit -c phpunit-unit.xml --verbose     # Unit tests
   vendor/bin/phpunit -c phpunit-integration.xml --verbose # Integration tests
   ```

2. **Run individual tests**:
   ```bash
   vendor/bin/phpunit tests/unit/cars/CarTest.php::CarTest::testMethodName
   vendor/bin/phpunit tests/integration/StatisticsApiTest.php::StatisticsApiTest::testMethodName
   ```

3. **Check logs**:
   - Unit test errors: Check mock data setup in `bootstrap-unit.php`
   - Integration test errors: Check database queries and schema
   - PHP errors: Check PHP error logs for warnings/notices

## Adding New Tests

### Unit Test (with mocks)

```php
<?php declare(strict_types=1);

require_once __DIR__ . '/../bootstrap-unit.php';

class MyFeatureTest extends PHPUnit\Framework\TestCase
{
    protected function setUp(): void
    {
        // Mock infrastructure ready to use
    }

    public function testFeatureWorks(): void
    {
        // Test logic here
        $this->assertTrue(true);
    }
}
```

### Integration Test (with database)

```php
<?php declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

class MyFeatureIntegrationTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();  // Skip test if DB unavailable
    }

    public function testFeatureWithDatabase(): void
    {
        // Real database available via $this->db
        $result = $this->db->query("SELECT * FROM cars LIMIT 1")->first();
        $this->assertNotNull($result);
    }
}
```

### Best Practices

1. **Prefer unit tests** for logic testing (faster, no dependencies)
2. **Use integration tests** for database operations and workflows
3. **Follow naming conventions**: `TestClassName.php` and `testMethodName()`
4. **Include setUp/tearDown** for proper test isolation
5. **Add security-focused tests** for any new security-relevant functionality
6. **Test both success and failure** paths

## Security Note

These tests validate critical security measures. If any security tests fail:

1. **Do NOT deploy to production** until issues are resolved
2. **Review the specific security validation** that failed
3. **Fix the underlying security issue** before proceeding
4. **Re-run all tests** to ensure no regressions

The test suite serves as both validation and documentation of the security measures implemented in the car update system.
