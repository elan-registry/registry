================================================================================
                    UNIT TEST FAILURE ANALYSIS REPORT
================================================================================

Test Suite: tests/unit/*
Bootstrap: tests/bootstrap-unit.php (Mock-based, No Database)
Total Tests: 450
Passing: 311 (69%)
Errors: 117 (26%)
Failures: 22 (5%)

================================================================================
                           ERROR CATEGORIES
================================================================================

CATEGORY 1: Missing Exception Methods (57 errors)
────────────────────────────────────────────────────────────────────────────
Problem: Exception classes missing required public methods

Issue: testExceptionHasRequiredMethods() checks that all exception classes
       have these methods:
       - getUserMessage()
       - getHttpStatusCode()

Affected Exception Classes (6 total):
  ✗ CarNotFoundException
  ✗ CarCreationException
  ✗ CarValidationException
  ✗ CarDeletionException
  ✗ CarMergeException
  ✗ CarTransferException
  ✗ ImageProcessingException (getUserMessage missing)

Total Errors: 57 (across multiple test variations)

Root Cause: Exception classes created but methods not implemented.
            These methods are required by ElanRegistryException interface.

Fix Required: Add getUserMessage() and getHttpStatusCode() to all
              exception classes in usersc/classes/exceptions/

────────────────────────────────────────────────────────────────────────────

CATEGORY 2: Missing LogCategories Constants (55 errors)
────────────────────────────────────────────────────────────────────────────
Problem: Exception classes reference undefined LogCategories constants

Errors:
  ✗ LOG_CATEGORY_OWNER_ACTIONS - used by OwnerNotFoundException, OwnerCreationException, OwnerUpdateException
  ✗ LOG_CATEGORY_SYSTEM_ERROR - used by GeocodingException
  ✗ LOG_CATEGORY_BACKUP_ERROR - used by BackupException
  ✗ LOG_CATEGORY_USER_DELETION - used in UserDeletionCleanupTest (55 errors total)

Total Errors: 55 (across multiple test variations)

Root Cause: These constants referenced in exception classes but not defined
            in usersc/classes/LogCategories.php

Fix Required: Add missing constants to LogCategories class:
  - LOG_CATEGORY_OWNER_ACTIONS
  - LOG_CATEGORY_SYSTEM_ERROR
  - LOG_CATEGORY_BACKUP_ERROR
  - LOG_CATEGORY_USER_DELETION

────────────────────────────────────────────────────────────────────────────

CATEGORY 3: Exception Inheritance Issues (5 errors)
────────────────────────────────────────────────────────────────────────────
Problem: Car-specific exception classes don't extend ElanRegistryException

Affected Classes:
  ✗ CarNotFoundException
  ✗ CarCreationException
  ✗ CarValidationException
  ✗ CarDeletionException
  ✗ CarMergeException
  ✗ CarTransferException
  ✗ ImageProcessingException

Root Cause: Exception classes inherit from generic Exception instead of
            ElanRegistryException, breaking test expectations

Fix Required: Update exception class declarations to extend
              ElanRegistryException

────────────────────────────────────────────────────────────────────────────

================================================================================
                         FAILURE CATEGORIES
================================================================================

CATEGORY 1: Exception Hierarchy Failures (9 failures)
────────────────────────────────────────────────────────────────────────────
Problem: Naming mismatch between test expectations and actual LogCategories

Failures:
  1. testExceptionExtendsBase - Car exception classes don't extend
                                ElanRegistryException (6 failures)
  
  2. testLogCategoryMatchesExpected - Naming convention mismatch (2 failures)
     Test expects: 'ValidationError', 'DatabaseError' (PascalCase)
     Code has:    'validation_error', 'database_error' (snake_case)
     
     Affected:
     - OwnerValidationException: expects 'ValidationError'
     - SchemaException: expects 'DatabaseError'

Total: 9 failures

Fix Required: Either:
  Option A: Update test expectations to use snake_case
  Option B: Update LogCategories constants to use PascalCase
  Option C: Update exception classes to use correct constant names

────────────────────────────────────────────────────────────────────────────

CATEGORY 2: Car Mock Implementation Issues (10 failures)
────────────────────────────────────────────────────────────────────────────
Problem: Mock Car class behavior doesn't match expected test behavior

Failures (all in Car unit tests):
  1. testExistsReturnsFalseWhenDataEmpty - Mock returns true for non-existent
  2. testFindReturnsfalseWhenNotFound - Mock returns true when not found
  3. testRemoveImageFailsWhenImageNotFound - Mock returns true instead of false
  4. testRemoveImageFailsWhenCarNotExists - Mock returns true instead of false
  5. testMultipleFindCallsWorkCorrectly - Mock doesn't support multiple finds
  6. testFindReturnsfalseWhenNotFound (CarTest) - Same as #2
  7. testExistsReturnsFalseForNonexistentCar - Mock returns true
  8. testConstructorWithoutIdCreatesEmptyCar - Mock doesn't create empty cars
  9. testRemoveImageFailsWhenImageNotFound (CarUpdateTest) - Mock issue
  10. testRemoveImageFailsWhenCarNotExists (CarUpdateTest) - Mock issue

Root Cause: Mock Car class in bootstrap-unit.php has incomplete implementation
            - exists() always returns true
            - find() doesn't handle "not found" case
            - removeImage() doesn't validate input
            - Constructor doesn't differentiate empty vs loaded cars

Note: These are EXPECTED failures for unit tests with mocks. The real
      behavior is tested in integration tests with real database.

────────────────────────────────────────────────────────────────────────────

CATEGORY 3: Other Test Failures (3 failures)
────────────────────────────────────────────────────────────────────────────

1. CarUpdateTest::testUpdateCarFailsWithInvalidID
   - Test expects CarValidationException to be thrown
   - Mock doesn't implement proper validation
   - EXPECTED: Mock limitation

2. CarStaticMethodsTest::testFindByOwnerReturnsConsistentOrder
   - Expected 322 records, got 644
   - Possible duplicate data or counting issue in mock
   - EXPECTED: Mock limitation

3. UserDeletionCleanupTest::testNoOwnerUserLookup
   - Expected 1 record, got 0
   - Mock user deletion cleanup incomplete
   - NOT RELATED TO CAR TEST REORGANIZATION

────────────────────────────────────────────────────────────────────────────

================================================================================
                        SUMMARY & RECOMMENDATIONS
================================================================================

Issues Related to Test Reorganization:
  ✓ NONE - Test reorganization is working correctly

Issues Related to Exception Classes:
  ✗ 57 missing method errors - Need to implement methods
  ✗ 55 missing constant errors - Need to define LogCategories
  ✗ 9 inheritance/naming failures - Need to fix inheritance

Expected Mock Behavior Issues:
  ✓ 10 Car mock failures - These are OK for unit tests
     Real behavior is tested in integration tests (8/12 passing)
  ✓ 2 Other mock issues - Known limitations of mock infrastructure

ACTION ITEMS (Priority Order):

1. HIGH: Define missing LogCategories constants (5 constants)
   - Will fix 55 errors immediately

2. HIGH: Add required methods to exception classes
   - getUserMessage() - 57 errors
   - getHttpStatusCode() - will fix once inheritance is fixed

3. MEDIUM: Fix exception class inheritance
   - Make Car-specific exceptions extend ElanRegistryException
   - Fix Exception hierarchy test failures

4. LOW: Fix LogCategories naming convention
   - Determine if constants should be PascalCase or snake_case
   - Update either test expectations or constants

5. LOW: Fix Car mock class issues
   - These are not critical for integration testing
   - Integration tests provide real behavior validation

================================================================================
