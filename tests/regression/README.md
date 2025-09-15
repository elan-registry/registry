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
```

## Best Practices

- **One test per issue**: Create one test file per GitHub issue
- **Clear naming**: Use descriptive test method names
- **Documentation**: Include the GitHub issue number and description
- **Minimal setup**: Keep tests focused on the specific regression
- **Independent**: Tests should not depend on each other
- **Fast execution**: Aim for tests that run quickly in the test suite

## Test Categories

- **High Priority**: Critical functionality that must not break
- **Security**: Security-related fixes that need continuous validation
- **Data Integrity**: Database and data consistency issues
- **User Experience**: UI/UX issues that affect user workflows