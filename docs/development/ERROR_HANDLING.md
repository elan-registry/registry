# Error Handling Guide

## Overview & Philosophy

The Elan Registry implements comprehensive, centralized error handling across the
entire application stack (PHP backend and JavaScript frontend) introduced in
v2.12.0.

**Core Philosophy**:

- **Centralized Logging**: All errors logged via UserSpice `logger()` function
  using standardized LogCategories for audit trails and debugging
- **Typed Exceptions**: Domain-specific exception classes replace generic
  `Exception` for proper error classification
- **User-Friendly Messages**: Separate technical messages (for logs) from
  user-safe messages (for UI display)
- **Pattern A Response Format**: All AJAX endpoints return standardized
  `{success, message, ...data}` JSON responses
- **Integrated Frontend API**: ElanRegistryAPI client handles errors
  consistently across the entire application

**System Components Overview**:

1. **Backend**: ApiResponse class, ElanRegistryException hierarchy,
   LogCategories constants, logger() function
2. **Frontend**: ElanRegistryAPI client, NotificationHelper utility,
   error type hierarchy (ApiError, ApiValidationError, ApiCancelledError)
3. **Integration**: CSRF token handling, automatic logging before response
   send, type-specific error handling patterns

**Documentation Structure**: This guide covers essential error handling patterns
with cross-references to detailed documentation. For complete LogCategories
reference, see [LOG_CATEGORIES.md](LOG_CATEGORIES.md).

---

## Backend Error Handling

### ApiResponse Class

The ApiResponse class provides a standardized JSON response format for all AJAX
endpoints with integrated logging and proper HTTP status codes.

**Pattern A Response Format**:

All API responses follow this structure:

```json
{
  "success": true|false,
  "message": "Human-readable message",
  "optional_data": "Additional fields as needed"
}
```

**Factory Methods**:

| Method | HTTP Code | Use Case |
| --- | --- | --- |
| `success()` | 200 | Successful operation |
| `error()` | 400 | Generic error (customizable) |
| `validationError()` | 422 | Field validation failures |
| `unauthorized()` | 401 | Authentication required |
| `forbidden()` | 403 | User lacks permissions |
| `notFound()` | 404 | Resource not found |
| `serverError()` | 500 | Internal server error |

**Builder Methods**:

- `->withData(string $key, mixed $value)` - Add single data item
- `->withDataArray(array $data)` - Add multiple data items at once
- `->withLogging(int $userId, string $category, string $message)` - Queue log
  entry to execute when send() is called
- `->withStatusCode(int $code)` - Override HTTP status code
- `->send()` - Output JSON response and exit (executes pending log)

**Complete Usage Example**:

```php
try {
    // Get search query
    $query = Input::get('query');
    if (empty($query)) {
        throw new LocationServiceException('Search query is required');
    }

    // Validate query length
    if (strlen($query) < 2) {
        throw new LocationServiceException('Search query must be at least 2 characters');
    }

    // Create LocationService and search
    $locationService = new LocationService();
    $results = $locationService->searchLocation($query, $userId, 8);

    // Return results with logging
    ApiResponse::success('Search completed')
        ->withData('results', $results)
        ->withData('count', count($results))
        ->withLogging($userId, 'LocationService', 'Location search: ' . $query)
        ->send();

} catch (LocationServiceException $e) {
    ApiResponse::error($e->getMessage(), 400)
        ->withLogging($userId, 'LocationService', 'Location search failed: ' . $e->getMessage())
        ->send();

} catch (\Throwable $e) {
    ApiResponse::serverError('An error occurred while searching locations')
        ->withLogging($userId, 'SystemError', 'Location search error: ' . $e->getMessage())
        ->send();
}
```

### Exception Hierarchy

ElanRegistry uses typed exception classes to categorize errors for proper
handling and logging.

**Architecture**:

- **Namespace**: `ElanRegistry\Exceptions` - All exception classes are namespaced
- **Location**: `/usersc/classes/Exceptions/` directory
- **Base Class**: `ElanRegistryException` - Abstract base for all custom exceptions
- **Properties**: Each exception type has:
  - User-friendly message (safe for UI display)
  - Log category (from LogCategories constants)
  - HTTP status code (for API responses)
- **Autoloading**: PSR-4 autoload via composer.json

**Exception Types** (26 total):

| Exception | HTTP | Category | Purpose |
| --- | --- | --- | --- |
| **CarException** (abstract) | 500 | CarErrors | Base for all car exceptions |
| CarCreationException | 500 | CarCreation | Car creation |
| CarDatabaseException | 500 | DatabaseError | Car database operations |
| CarDeletionException | 500 | CarDeletion | Car deletion |
| CarMergeException | 500 | CarMerge | Car merge operation |
| CarNotFoundException | 404 | CarErrors | Car not found |
| CarPermissionException | 403 | AccessDenied | Car permission denied |
| CarTransferException | 500 | CarTransferError | Ownership transfer |
| CarValidationException | 422 | ValidationError | Car data validation |
| OwnerCreationException | 500 | OwnerCreation | Owner creation |
| OwnerUpdateException | 500 | OwnerUpdate | Owner profile update |
| OwnerValidationException | 422 | OwnerValidation | Owner data validation |
| OwnerNotFoundException | 404 | OwnerErrors | Owner not found |
| OwnerSearchException | 500 | OwnerSearch | Owner search |
| ValidationException | 422 | ValidationError | Generic validation |
| LocationServiceException | 400 | LocationService | Location API |
| GeocodingException | 400 | Geocode | Maps geocoding |
| ImageProcessingException | 500 | ImageRemoval | Image resize/upload |
| SchemaException | 500 | SchemaOperationError | Database schema |
| BackupException | 500 | BackupManager | Backup operations |
| DocumentationException | 404 | DocumentationError | Documentation load |
| ForbiddenException | 403 | AccessDenied | Permission denied |
| UnauthorizedException | 401 | AccessDenied | Auth required |
| AdminOperationException | 500 | AdminActions | Admin operations |
| AdminContactException | 500 | AdminContact | Admin contact form |
| AdminVerificationException | 500 | AdminVerification | Admin verification |

**Usage Pattern**:

```php
use ElanRegistry\Exceptions\OwnerValidationException;
use ElanRegistry\Exceptions\ElanRegistryException;

try {
    // Operation that might fail
    $owner = new ElanRegistryOwner($ownerId);
    $owner->validateLocation();

} catch (OwnerValidationException $e) {
    // Handle validation error (422)
    ApiResponse::validationError(['location' => $e->getUserMessage()])
        ->withLogging($userId, $e->getLogCategory(), $e->getMessage())
        ->send();

} catch (ElanRegistryException $e) {
    // Handle any other domain error
    ApiResponse::error($e->getUserMessage(), $e->getHttpStatusCode())
        ->withLogging($userId, $e->getLogCategory(), $e->getMessage())
        ->send();

} catch (\Throwable $e) {
    // Unexpected error
    ApiResponse::serverError('An unexpected error occurred')
        ->withLogging($userId, 'SystemError', 'Unexpected error: ' . $e->getMessage())
        ->send();
}
```

### LogCategories

Centralized log category constants ensure consistent, discoverable logging
throughout the application.

**Purpose**: Replace hardcoded log strings with constants to prevent typos,
improve discoverability, and maintain consistency.

**Organization**: 140+ categories organized by functional domain:

- Car Management (CarActions, CarCreation, CarUpdate, CarDeletion, CarMerge,
  CarTransfer, CarVerification, CarSold, CarErrors)
- Owner/User Management (OwnerActions, OwnerCreation, OwnerUpdate,
  OwnerValidation, OwnerErrors, UserDeletion, InactiveCleanup)
- Authentication (Login, LoginFail, PasskeyAuth, PasswordReset, TOTP)
- Database Operations (DatabaseError, DatabaseMaintenance, BackupManager,
  SchemaOperationError)
- Email/Communications (EmailSuccess, EmailError, FeedbackForm)
- System & File Operations (SystemError, FileError, ValidationError,
  ImageRemoval)
- Admin & Management (AdminVerification, SettingsUpdate, UserManager, Logs)
- Location & Geocoding (Geocode, LocationService, LocationReverse)
- Access Control (AccessDenied, SecurePage, HasPerm, PageNotFound)
- And 4+ more domains

**Discovery**:

```bash
# Find all available categories
grep "const LOG_CATEGORY" usersc/classes/LogCategories.php
```

**Usage Pattern**:

```php
// ✅ CORRECT: Use LogCategories constants
try {
    $car = new Car($carId);
    $car->delete();
} catch (CarDeletionException $e) {
    logger(
        $user->data()->id,
        $e->getLogCategory(),  // Returns LogCategories::LOG_CATEGORY_CAR_DELETION
        'Car deletion failed: ' . $e->getMessage()
    );
} catch (Exception $e) {
    logger(
        $user->data()->id,
        LogCategories::LOG_CATEGORY_SYSTEM_ERROR,
        'Unexpected error: ' . $e->getMessage()
    );
}

// ❌ INCORRECT: Never use hardcoded strings
logger($userId, 'CarDeletion', 'Car deleted');  // Don't do this!
```

---

## Frontend Error Handling

### ElanRegistryAPI Client

The ElanRegistryAPI client provides standardized AJAX communication with
automatic CSRF token injection, error handling, and request cancellation.

**Overview**:

- Automatically loaded on every page via footer.php
- Follows Pattern A response format
- Handles CSRF tokens automatically
- Supports request cancellation
- Type-specific error handling (ApiError, ApiValidationError, ApiCancelledError)

**Basic Usage - POST Request**:

```javascript
const api = new ElanRegistryAPI();

try {
    const result = await api.post('app/action/update-car.php', {
        car_id: 123,
        year: 2020
    });

    // Success - check message
    NotificationHelper.show(result.message, 'success');

    // Access additional data
    if (result.data) {
        console.log('Updated car:', result.data);
    }

} catch (error) {
    if (error instanceof ApiValidationError) {
        NotificationHelper.showValidationErrors(error.errors);
    } else if (error instanceof ApiCancelledError) {
        console.log('Request cancelled');
    } else {
        NotificationHelper.show(error.message, 'error');
    }
}
```

**Basic Usage - GET Request**:

```javascript
const api = new ElanRegistryAPI();

try {
    const result = await api.get('app/action/search-cars.php', {
        query: 'Elan',
        limit: 10
    });

    console.log('Results:', result.data);

} catch (error) {
    NotificationHelper.show(error.message, 'error');
}
```

**Request Cancellation Pattern**:

```javascript
const api = new ElanRegistryAPI();
let searchRequestId = null;

async function search(query) {
    // Cancel previous search if still pending
    if (searchRequestId) {
        api.cancel(searchRequestId);
    }

    try {
        const result = await api.request('app/action/search.php', {
            method: 'GET',
            params: { q: query },
            requestId: (searchRequestId = api.generateRequestId())
        });

        console.log('Results:', result.data);
    } catch (error) {
        if (error instanceof ApiCancelledError) {
            console.log('Search cancelled');
        } else {
            NotificationHelper.show(error.message, 'error');
        }
    }
}
```

### NotificationHelper

The NotificationHelper utility displays user feedback consistently across the
application with XSS protection.

**Methods**:

- `show(message, type)` - Display general notification
  - type: 'success', 'error', 'warning', 'info'
- `showValidationErrors(errors)` - Display field-level validation errors
  - errors: { field_name: 'Error message' }

**Usage Pattern**:

```javascript
// Success notification
NotificationHelper.show('Profile updated successfully!', 'success');

// Error notification
NotificationHelper.show('Unable to save changes. Please try again.', 'error');

// Validation errors (from ApiValidationError)
try {
    const result = await api.post('endpoint', data);
} catch (error) {
    if (error instanceof ApiValidationError) {
        NotificationHelper.showValidationErrors(error.errors);
        // Displays individual error for each field
    }
}
```

---

## Migration Guide

This section documents the evolution of error handling patterns in v2.12.0 and
how to migrate existing code.

### 1. display_errors/display_successes → usError/usSuccess

**Status**: 100% complete (Issue #237)

**Before** (deprecated UserSpice session messages):

```php
// Display errors and successes manually with HTML
if (!empty($errors)) {
    foreach ($errors as $error) {
        echo '<div class="alert alert-danger">' . htmlspecialchars($error) . '</div>';
    }
}
```

**After** (modern session-based messaging):

```php
// Use modern UserSpice message functions
if (!empty($errors)) {
    foreach ($errors as $error) {
        usError($error);
    }
}

// Display all messages (replaces manual Bootstrap alert HTML)
sessionValMessages($errors, $successes, null);
```

**Checklist**:

- [ ] Replace `display_errors()` calls with `usError()` loop
- [ ] Replace `display_successes()` calls with `usSuccess()` loop
- [ ] Remove manual Bootstrap alert HTML
- [ ] Use `sessionValMessages()` to display queued messages
- [ ] Test error and success message display

### 2. Hardcoded Strings → LogCategories Constants

**Status**: 95% complete (Issue #459)

**Before** (hardcoded log strings - error-prone):

```php
// Typos possible, inconsistent strings
logger($userId, 'car_creation', 'Car created');
logger($userId, 'CarCreation', 'Car updated');  // Different case!
logger($userId, 'car_deletion', 'Car deleted');
```

**After** (typed constants - discoverable and consistent):

```php
logger($userId, LogCategories::LOG_CATEGORY_CAR_CREATION, 'Car created');
logger($userId, LogCategories::LOG_CATEGORY_CAR_UPDATE, 'Car updated');
logger($userId, LogCategories::LOG_CATEGORY_CAR_DELETION, 'Car deleted');
```

**Real Example** (commit 1e00581e):

```bash
# Before
logger(0, 'Password Reset', 'Password reset requested');

# After
logger(0, LogCategories::LOG_CATEGORY_PASSWORD_RESET, 'Password reset requested');
```

**Checklist**:

- [ ] Identify all `logger()` calls in modified files
- [ ] Replace hardcoded strings with LogCategories constants
- [ ] Use discovery command: `grep "const LOG_CATEGORY" usersc/classes/LogCategories.php`
- [ ] Test log entries appear in admin logs with correct category

### 3. Generic Exception → Typed Exceptions

**Status**: Admin system complete (Issue #356)

**Before** (generic Exception - loses context):

```php
try {
    // Operation
    throw new Exception('Database insert failed');
} catch (Exception $e) {
    // Can't distinguish between different error types
    error_log($e->getMessage());
    die('An error occurred');
}
```

**After** (typed exception - proper classification):

```php
use ElanRegistry\Exceptions\CarCreationException;
use ElanRegistry\Exceptions\ElanRegistryException;

try {
    // Operation
    throw new CarCreationException('Database insert failed');
} catch (CarCreationException $e) {
    // Handle car-specific error
    ApiResponse::serverError($e->getUserMessage())
        ->withLogging($userId, $e->getLogCategory(), $e->getMessage())
        ->send();
} catch (ElanRegistryException $e) {
    // Handle other domain errors
    ApiResponse::error($e->getUserMessage(), $e->getHttpStatusCode())
        ->withLogging($userId, $e->getLogCategory(), $e->getMessage())
        ->send();
}
```

**Real Example** (commit d82e5667):

```bash
# Before
throw new Exception('Admin not found');

# After
use ElanRegistry\Exceptions\OwnerNotFoundException;

throw new OwnerNotFoundException('Admin with ID ' . $adminId . ' not found');
```

**Checklist**:

- [ ] Identify operation type (car, owner, location, admin, etc.)
- [ ] Choose appropriate exception class from 23 available types
- [ ] Replace `throw new Exception()` with typed exception
- [ ] Add try/catch blocks for specific exception types
- [ ] Test error handling paths return correct HTTP codes

### 4. Direct JSON → ApiResponse

**Status**: New endpoints only (Issue #444)

**Before** (direct JSON output - inconsistent):

```php
// Different responses for different endpoints
echo json_encode(['success' => true, 'data' => $result]);
exit;

// Or completely different format
echo json_encode($result);
exit;
```

**After** (ApiResponse - consistent Pattern A):

```php
// All endpoints return same Pattern A format
ApiResponse::success('Operation successful')
    ->withData('result', $result)
    ->withLogging($userId, 'CarActions', 'Operation completed')
    ->send();
```

**Real Example** (commit f3437c06):

```bash
# Before
echo json_encode([
    'success' => true,
    'data' => $carData
]);
exit;

# After
ApiResponse::success('Car data retrieved')
    ->withData('car', $carData)
    ->withLogging($userId, 'CarActions', 'Retrieved car ' . $carId)
    ->send();
```

**Checklist**:

- [ ] New AJAX endpoints MUST use ApiResponse
- [ ] Choose appropriate factory method (success, error, validationError, etc.)
- [ ] Add logging via `->withLogging()`
- [ ] Add additional data via `->withData()`
- [ ] Call `->send()` to output response and exit
- [ ] Test all endpoints return 200 success or appropriate error code

---

## Best Practices

### Logging Best Practices

**When to Log**:

- All errors (caught exceptions)
- Important business events (car created, transferred, deleted)
- Access control decisions (denied, permission check)
- System maintenance operations (backups, schema changes)
- User authentication events (login, logout, password reset)

**User ID Handling**:

```php
// Use actual user ID when available
$userId = $user->isLoggedIn() ? (int)$user->data()->id : 0;
logger($userId, LogCategories::LOG_CATEGORY_CAR_CREATION, 'Car created');

// For anonymous actions (registration, public searches), use 0
logger(0, LogCategories::LOG_CATEGORY_LOCATION_SERVICE, 'Location search: Paris');
```

**Error Context**:

```php
// Include enough context for troubleshooting
logger($userId, LogCategories::LOG_CATEGORY_CAR_UPDATE, 'Car update failed: ' . $e->getMessage());
logger($userId, LogCategories::LOG_CATEGORY_VALIDATION_ERROR, 'Email validation: invalid format');
logger($userId, LogCategories::LOG_CATEGORY_DATABASE_ERROR, 'Query failed for table cars: ' . $e->getMessage());
```

### Exception Best Practices

**Choose Appropriate Exception Type**:

```php
use ElanRegistry\Exceptions\CarValidationException;

// ✅ CORRECT: Specific exception for the operation
if (empty($carData['year'])) {
    throw new CarValidationException('Year field is required');
}

// ❌ INCORRECT: Generic exception loses context
if (empty($carData['year'])) {
    throw new Exception('Validation failed');
}
```

**Separate User vs Technical Messages**:

```php
use ElanRegistry\Exceptions\CarCreationException;

// ✅ CORRECT: User message is safe, technical message for logs
throw new CarCreationException(
    'Database insert failed: constraint violation on vin',  // Technical
    0,
    null,
    'Unable to create car. Please verify the VIN and try again.'  // User-safe
);

// ❌ INCORRECT: Same message for both contexts
throw new CarCreationException('Database constraint violation - VIN must be unique');
```

### API Response Best Practices

**Use Appropriate HTTP Codes**:

```php
// ✅ CORRECT: Match HTTP codes to situation
if (!$resource->exists()) {
    ApiResponse::notFound('Car not found')->send();  // 404
}

if (!hasPermission($userId)) {
    ApiResponse::forbidden('You cannot modify this car')->send();  // 403
}

if (!$data['email']) {
    ApiResponse::validationError(['email' => 'Email is required'])->send();  // 422
}

// ❌ INCORRECT: Everything returns 400
ApiResponse::error('Something went wrong', 400)->send();
```

**Include Logging in Responses**:

```php
// ✅ CORRECT: Always include logging
ApiResponse::success('Car updated')
    ->withData('car', $car)
    ->withLogging($userId, LogCategories::LOG_CATEGORY_CAR_UPDATE, 'Updated car ' . $carId)
    ->send();

// ❌ INCORRECT: Missing logging
ApiResponse::success('Car updated')->send();
```

### Frontend Best Practices

**Type-Specific Error Handling**:

```javascript
// ✅ CORRECT: Handle each error type appropriately
try {
    const result = await api.post('endpoint', data);
    NotificationHelper.show(result.message, 'success');
} catch (error) {
    if (error instanceof ApiValidationError) {
        // Field-level errors - highlight form fields
        NotificationHelper.showValidationErrors(error.errors);
    } else if (error instanceof ApiCancelledError) {
        // Silently ignore cancellations
        console.log('Request cancelled');
    } else if (error.status === 401) {
        // Unauthorized - redirect to login
        window.location.href = '/users/?view=login';
    } else {
        NotificationHelper.show(error.message, 'error');
    }
}

// ❌ INCORRECT: Generic error handling
try {
    const result = await api.post('endpoint', data);
} catch (error) {
    alert('An error occurred');
}
```

**Loading State Management**:

```javascript
const $btn = $('#submitBtn');
$btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Loading...');

try {
    const result = await api.post('endpoint', data);
    NotificationHelper.show(result.message, 'success');
} finally {
    $btn.prop('disabled', false).html('Submit');
}
```

---

## Troubleshooting

### "logger() not found"

**Cause**: Function called before UserSpice init.php is included

**Solution**: Ensure UserSpice init.php is included at the top of every file:

```php
require_once '../../users/init.php';  // Include this first
logger($userId, LogCategories::LOG_CATEGORY_CAR_CREATION, 'Message');
```

### "Class 'LogCategories' not found"

**Cause**: LogCategories class not imported

**Solution**: Add use statement or use fully qualified name:

```php
// Option 1: Use statement (required for type hints)
use ElanRegistry\Classes\LogCategories;

logger($userId, LogCategories::LOG_CATEGORY_CAR_CREATION, 'Message');

// Option 2: Fully qualified name (works without use statement)
logger($userId, \LogCategories::LOG_CATEGORY_CAR_CREATION, 'Message');
```

### "CSRF validation failed"

**Cause**: CSRF token missing or mismatched

**Solution**:

Backend:

```php
// Verify token is present and valid
if (!Token::check(Input::get('csrf'))) {
    ApiResponse::forbidden('Invalid CSRF token')->send();
}
```

Frontend:

```javascript
// ElanRegistryAPI handles this automatically
const api = new ElanRegistryAPI();
// Token automatically injected from <input name="csrf"> or <input id="csrf">

// Or manually override
const api = new ElanRegistryAPI({
    csrfToken: 'custom-token-value'
});
```

### "ApiResponse not returning JSON"

**Cause**: Headers already sent (output before send())

**Solution**: Check for output before ApiResponse call:

```php
// ❌ WRONG: Output before ApiResponse
echo "Starting...";
ApiResponse::success('Done')->send();  // Headers already sent!

// ✅ CORRECT: No output before ApiResponse
ApiResponse::success('Done')->send();
```

### "Frontend errors not displaying"

**Cause**: NotificationHelper not included or error not caught

**Solution**:

1. Verify NotificationHelper is loaded (via footer.php)
2. Ensure error is caught in try/catch block
3. Check browser console for JavaScript errors:

```javascript
// ✅ CORRECT: Error will be caught and displayed
try {
    const result = await api.post('endpoint', data);
} catch (error) {
    NotificationHelper.show(error.message, 'error');
}

// ❌ WRONG: Error not caught
const result = await api.post('endpoint', data);
```

---

## Related Files & See Also

### Core Files

- `/usersc/classes/ApiResponse.php` - API response class
- `/usersc/classes/Exceptions/ElanRegistryException.php` - Base exception (namespace: `ElanRegistry\Exceptions`)
- `/usersc/classes/Exceptions/` - All exception classes (26 total)
- `/usersc/classes/LogCategories.php` - Log category constants
- `/usersc/includes/custom_functions.php` - logger() function, getUserWithProfile()
- `/usersc/js/elan-registry-api.js` - Frontend API client
- `/usersc/js/notification-helper.js` - Notification utility
- `/composer.json` - PSR-4 autoload configuration for `ElanRegistry\Exceptions`

### Cross-References

- **[CLAUDE.md](../../CLAUDE.md)** - Quick error handling reference and when
  to read this guide
- **[CODING_STANDARDS.md](CODING_STANDARDS.md)** - Error handling requirements
  and code review checklist
- **[LOG_CATEGORIES.md](LOG_CATEGORIES.md)** - Complete list of 140+ log
  category constants
- **[QUICK_REFERENCE.md](QUICK_REFERENCE.md)** - Copy-paste code snippets for
  error handling patterns

### Related Patterns

- **UserSpice Integration**: See [INTEGRATION.md](INTEGRATION.md) for session
  management and authentication patterns
- **Class Architecture**: See [CLASSES.md](CLASSES.md) for exception patterns
  in domain classes
- **Frontend API**: See [Frontend API Client](../../CLAUDE.md#frontend-api-client-pattern-a---v2120)
  section in CLAUDE.md for complete API client reference

### Changelog

- **v2.12.0**: Complete error handling system introduced
  - ApiResponse class for Pattern A responses
  - ElanRegistryException hierarchy (23 types)
  - LogCategories constants (140+ categories)
  - ElanRegistryAPI frontend client
  - NotificationHelper utility
