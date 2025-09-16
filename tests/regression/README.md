# Regression Test Framework

This directory contains regression tests that ensure previously fixed issues do not reoccur.

## Creating a New Regression Test

1. **Copy the template**: Copy `RegressionTestTemplate.php` to `Issue{NUMBER}RegressionTest.php`
2. **Update the class name**: Change `RegressionTestTemplate` to `Issue{NUMBER}RegressionTest`
3. **Replace placeholders**: Update `{ISSUE_NUMBER}` and `{BRIEF_DESCRIPTION_OF_ISSUE}`
4. **Implement the test**: Replace the `markTestIncomplete()` with actual test logic
5. **Verify the test**: Ensure it fails without the fix and passes with the fix

## Example File Structure

```
tests/regression/
├── README.md                      # This file
├── RegressionTestTemplate.php     # Template for new regression tests
├── Issue317RegressionTest.php     # Regression test for issue #317
└── Issue284RegressionTest.php     # Regression test for issue #284
```

## Running Regression Tests

```bash
# Run all regression tests
composer test:regression

# Run specific regression test
vendor/bin/phpunit tests/regression/Issue317RegressionTest.php

# Run with test tagging system (NEW)
vendor/bin/phpunit --group=regression              # All regression tests
vendor/bin/phpunit --group=regression,fast         # Fast regression tests only
vendor/bin/phpunit --group=regression,database     # Database-dependent regression tests
vendor/bin/phpunit --group=regression,slow         # Slow/comprehensive regression tests
```

## Validation Requirements

**All regression tests must include required annotations for automated validation:**

- ✅ **@issue {NUMBER}** - GitHub issue number (e.g., `@issue 317`)
- ✅ **@link https://github.com/...** - Link to the GitHub issue
- ✅ **@description** - Brief description of what was fixed
- ✅ **@category** - Issue category (bug, enhancement, security, etc.)

**Pre-commit hooks will validate compliance and block commits with missing annotations.**

## Test Tagging System

**Add appropriate @group tags for test filtering:**

- `@group regression` - Required for all regression tests
- `@group fast` - Quick tests (< 1 second)
- `@group slow` - Tests with loops, complex calculations
- `@group database` - Tests requiring database connections

## Best Practices

- **One test per issue**: Create one test file per GitHub issue
- **Clear naming**: Use descriptive test method names
- **Documentation**: Include the GitHub issue number and description
- **Minimal setup**: Keep tests focused on the specific regression
- **Independent**: Tests should not depend on each other
- **Fast execution**: Aim for tests that run quickly in the test suite
- **Required annotations**: Use the enhanced template with all required metadata

## Enhanced Template Usage

The `RegressionTestTemplate.php` now includes:

- ✅ Required annotation placeholders
- ✅ Proper PHPDoc structure with @group tags
- ✅ Example test methods with correct naming patterns
- ✅ Integration with the test tagging system

**After copying the template, ensure all placeholders are replaced:**
- `{ISSUE_NUMBER}` → Actual issue number
- `{BRIEF_DESCRIPTION_OF_ISSUE}` → Description of the fix
- `{GITHUB_ISSUE_URL}` → Full GitHub issue URL
- `{CATEGORY}` → Issue category (bug, enhancement, security, etc.)

## Automated Quality Checks

**Pre-commit hooks will automatically validate:**
- ✅ Filename follows `Issue{Number}RegressionTest.php` pattern
- ✅ Class name matches filename convention
- ✅ All required annotations are present (@issue, @link, @description, @category)
- ✅ Proper PHPDoc blocks on test methods
- ✅ Test tagging compliance

## Test Categories

- **High Priority**: Critical functionality that must not break
- **Security**: Security-related fixes that need continuous validation
- **Data Integrity**: Database and data consistency issues
- **User Experience**: UI/UX issues that affect user workflows

## Integration with CI/CD

Regression tests are integrated into the overall testing strategy:

```bash
# Quick feedback during development
composer test:fast                    # Includes fast regression tests

# Full regression testing
composer test:regression             # All regression tests

# Pre-deployment validation
composer test:full                   # All tests including regression
```