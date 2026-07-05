# ADR-004: Standardize API Architecture: Pattern A Responses with ElanRegistryAPI Frontend Client

## Status

**In Review** (retroactive)

## Date

Retroactive -- documented 2026-02-25

## Context

Prior to v2.12.0, the Lotus Elan Registry lacked a standardized AJAX API architecture. Individual endpoints handled responses inconsistently:

- **Raw JSON responses**: Endpoints directly echoed `json_encode()` without standardized structure
- **No consistent error format**: Error responses varied in structure; some included error details, others did not
- **Manual CSRF token handling**: Each frontend AJAX call manually extracted and included CSRF tokens, with opportunities for mistakes
- **jQuery.ajax() dependency**: Frontend code relied on jQuery with manual error callbacks and no typed error distinction
- **No request cancellation**: Long-running searches or autocomplete requests could accumulate responses from stale requests
- **Unstructured error logging**: API failures were difficult to correlate with user actions or debug in production

The application needed to standardize AJAX communication within the constraints of the UserSpice framework: a page-based PHP architecture with no dedicated
router, middleware, or API layer.

### Problem Statement

The application's ~20 AJAX endpoints exhibited inconsistent response formats and error handling patterns, making it difficult to:

- Build reliable frontend error handling
- Track API operations in audit logs
- Debug production issues without manually correlating client and server logs
- Prevent race conditions in search-as-you-type interactions

For a registry application where data accuracy and auditability are core concerns, this fragmentation posed a risk.

## Decision

Establish a **unified API architecture** consisting of three integrated components:

### 1. Backend: ApiResponse Class

Introduce `ApiResponse` class (`usersc/classes/ApiResponse.php`) as the canonical response factory for all AJAX endpoints.

**Design Principles:**

- **Immutable Builder Pattern**: `withData()`, `withLogging()` return new instances (via `clone`)
- **Private Constructor with Factory Methods**: Enforces consistent HTTP status codes:
  - `success(message): ApiResponse` → 200 OK
  - `error(message, statusCode): ApiResponse` → 400 Bad Request (default)
  - `validationError(errors[], message): ApiResponse` → 422 Unprocessable Entity
  - `unauthorized(message): ApiResponse` → 401 Unauthorized
  - `forbidden(message): ApiResponse` → 403 Forbidden
  - `notFound(message): ApiResponse` → 404 Not Found
  - `serverError(message): ApiResponse` → 500 Internal Server Error

**Pattern A Response Format:**

```json
{
  "success": true,
  "message": "Operation completed successfully",
  "optional_field": "additional data merged at top level",
  "errors": { "field_name": "error message" }
}
```

Additional data is merged flat at the top level (not nested under a "data" key), which is unconventional but avoids breaking existing integrations and keeps the
format simple.

**Integration with Exception Hierarchy:**

Each `ElanRegistryException`subclass maps to the appropriate`ApiResponse`factory method via`getDefaultHttpStatusCode()`. The canonical catch pattern:

```php
try {
    // Business logic
} catch (SpecificException $e) {
    ApiResponse::forbidden($e->getUserMessage())->send();
} catch (ElanRegistryException $e) {
    ApiResponse::error($e->getUserMessage(), $e->getHttpStatusCode())->send();
} catch (Throwable $e) {
    ApiResponse::serverError('An unexpected error occurred')->send();
}
```

**Integrated Logging:**

The `withLogging(userId, category, message)`method queues a logger call to be executed when`send()` is called, ensuring all API operations are auditable:

```php
ApiResponse::success('Car updated')
    ->withData('carId', $carId)
    ->withLogging($userId, LogCategories::LOG_CATEGORY_CAR_MANAGEMENT, "Updated car $carId")
    ->send();
```

**Immutability and Testing:**

The immutable builder pattern provides:

- `toArray()` method for unit testing response structure
- `getStatusCode()`, `isSuccess()`, `getMessage()`, `getPendingLog()` accessors for assertions

**Autoloading:**

`ApiResponse`is autoloaded via the recursive class autoloader (configured in`users/init.php`) and available on every page after initialization.

**send() Method Behavior:**

The `send()` method (`return type: never`):

1. Executes pending logger call (if any)
2. Cleans output buffers
3. Sets HTTP response code and Content-Type header
4. Outputs JSON with `JSON_THROW_ON_ERROR`
5. Calls `exit`, terminating script execution

This design prevents accidental additional output after sending the response.

### 2. Frontend: ElanRegistryAPI Client

Introduce `ElanRegistryAPI` JavaScript client (`app/assets/js/api-client.js`) with global availability via `footer.php` injection.

**CSP-Safe Loading:**

The script is loaded in `usersc/includes/footer.php` with a Content Security Policy nonce:

```html
<script nonce="<?=htmlspecialchars($usespice_nonce ?? '')?>">
  src="<?=$us_url_root?>app/assets/js/api-client.js"
</script>
```

This ensures the script loads even under strict CSP policies without requiring the script source to be whitelisted.

**Exports:**

- `ElanRegistryAPI` -- Main HTTP client
- `NotificationHelper` -- Unified toast notification wrapper
- `ApiError` -- General API errors
- `ApiValidationError` -- 422 validation errors with field-keyed errors
- `ApiCancelledError` -- Request cancellation via AbortController

**CSRF Token Management:**

The client automatically detects and injects CSRF tokens from three DOM locations (in priority order):

1. `input[name="csrf"]` -- HTML form input
2. `#csrf` -- HTML element with ID
3. `data-csrf-token`attribute on`<html>` element

Tokens are injected via:

- **Header**: `X-CSRF-Token: <token>`
- **Body**: CSRF field in FormData (for POST/PUT/DELETE)

This dual injection provides defense-in-depth and compatibility with server-side CSRF validation.

```php
// Server-side validation
if ($method === 'POST' && !Token::check(Input::get('csrf'))) {
    ApiResponse::forbidden('CSRF validation failed')->send();
}
```

**Request Cancellation:**

All requests use `AbortController` with a configurable timeout (default 30 seconds):

```javascript
ElanRegistryAPI.post('/app/search', { query: searchTerm }, { timeout: 15000 })
    .then(response => {
        // Handle success
    })
    .catch(error => {
        if (error instanceof ApiCancelledError) {
            console.log('Request cancelled or timed out');
        }
    });
```

This prevents race conditions in search-as-you-type and prevents stale responses from being processed.

**Convenience Methods:**

- `get(url, options)` -- GET request
- `post(url, data, options)` -- POST request
- `put(url, data, options)` -- PUT request
- `delete(url, options)` -- DELETE request

**FormData Serialization:**

Handles:

- `File`and`Blob` objects
- Arrays as `key[]` parameters
- Scalars as string values

```javascript
// Arrays are serialized as key[]
const data = { items: ['a', 'b', 'c'] };
// Produces: FormData with items[]=a, items[]=b, items[]=c
```

**Typed Error Handling:**

The client distinguishes three error types:

- **`ApiValidationError` (422)**: Validation failures with field-keyed errors

  ```javascript
  .catch(error => {
      if (error instanceof ApiValidationError) {
          NotificationHelper.showValidationErrors(error.errors);
      }
  });
  ```

- **`ApiError` (non-422 failures)**: General API or network errors

  ```javascript
  .catch(error => {
      if (error instanceof ApiError) {
          console.error(`HTTP ${error.status}: ${error.message}`);
      }
  });
  ```

- **`ApiCancelledError`**: Request was cancelled or timed out

  ```javascript
  .catch(error => {
      if (error instanceof ApiCancelledError) {
          // Silently ignore cancelled requests
      }
  });
  ```

### 3. NotificationHelper (Co-located in api-client.js)

Provides unified toast notification integration with UserSpice toast functions.

**Methods:**

- `show(message, type, duration)` -- Display a notification
  - Types: 'success', 'error', 'warning', 'info'
  - Delegates to UserSpice functions: `usSuccess()`, `usError()`, `usInfo()`

- `showValidationErrors(errors)` -- Display field-level validation errors
  - Flattens field errors and appends `is-invalid` CSS class to corresponding form inputs
  - Enables Bootstrap form validation styling

**XSS Protection:**

The `escapeHtml()` helper function escapes user-controlled error messages before display:

```javascript
NotificationHelper.escapeHtml(userMessage); // Escapes <, >, &, ", '
```

## Consequences

### Positive

- **Consistent response format eliminates per-endpoint parsing.** All endpoints return Pattern A: `{success, message, ...data}`. Frontend code uses a single
  response handler instead of custom per-endpoint logic.

- **Typed frontend errors enable specific handling.** `ApiValidationError`, `ApiError`, and `ApiCancelledError` allow frontend code to respond appropriately to
  different failure modes (validation vs. auth vs. network).

- **Automatic CSRF injection eliminates manual token management.** The client automatically detects and injects tokens; developers write:

  ```javascript
  ElanRegistryAPI.post('/endpoint', data)
  ```

  instead of manually extracting and appending CSRF tokens.

- **Integrated logging ensures all API operations are auditable.** The `withLogging()` method queues audit entries that are executed atomically with the
  response, ensuring no audit gap between the API operation and its log entry.

- **Request cancellation prevents race conditions.** Search-as-you-type and other multi-request patterns are protected by `AbortController` timeout and
  cancellation, preventing stale responses from being processed.

- **Builder pattern keeps ApiResponse immutable and testable.** The immutable design with `toArray()` accessors enables unit testing without mocking
  the entire response sending infrastructure. Tests can assert on response structure before `send()` is called.

- **Single injection point ensures client availability.** Loading the client in `footer.php`via nonce-based CSP injection guarantees that`ElanRegistryAPI`,
  `NotificationHelper`, and error classes are available on every page without individual script tag management.

- **Exception integration maps typed errors to HTTP codes.** Each `ElanRegistryException`subclass defines its own HTTP status code; the canonical catch pattern
  automatically selects the appropriate`ApiResponse` factory method.

### Negative

- **Flat data structure violates REST conventions.** Pattern A merges additional data at the top level alongside `success`and`message`, rather than nesting it
  under a "data" key. This is unconventional but was retained to avoid breaking existing integrations during incremental migration. New integrations should be
  mindful of key collision risks (e.g., "data" field name could shadow the API's custom data).

- **send() calls exit, making it impossible to run code after response.** By design, `send()` terminates script execution to prevent accidental additional
  output. This means:
  - No middleware-pattern request/response filtering
  - No chaining multiple response handlers
  - No Server-Sent Events or streaming responses

  This is intentional for safety but limits advanced patterns.

- **No support for Server-Sent Events or streaming responses.** The `send()` method exits immediately; streaming or long-polling patterns require alternative
  endpoints using raw PHP output.

- **CSRF token staleness on long-lived pages.** If a user keeps a page open for hours, the session CSRF token may be regenerated by UserSpice. The client will
  continue using the stale token until the page is refreshed. This is mitigated by session timeout; typical timeouts are 1-2 hours.

- **jQuery.ajax() migration complete as of v2.25.3** (issues #528, #968). The incremental migration tracked in
  Issue #481 is finished — no remaining `$.ajax()` calls exist in `app/` or `usersc/`.

### Risks

| Risk | Likelihood | Impact | Mitigation |
| --- | --- | --- | --- |
| Flat data key collision with "success"/"message" | Low | Medium | Code review for "success" or "message" in custom data keys; consider namespace prefix for future-added custom fields |
| Incomplete migration leaving inconsistent API behavior | Medium | Medium | @deprecated tags mark jQuery.ajax() calls; Issue #481 tracks migration; add ApiResponse requirement to code review checklist |
| CSRF token staleness on long-lived pages | Low | Low | Session timeout (typically 1-2 hours) covers normal usage; consider client-side token refresh via AJAX polling for ultra-long sessions |
| Missing try/catch causes ApiResponse.send() failure to silently exit | Low | Medium | All endpoint code must be wrapped in try/catch; pre-commit hooks could enforce this via AST analysis |
| CORS issues with modern browser API restrictions | Low | Low | All endpoints are same-origin (no external APIs); SameSite=Strict session cookies provide additional defense |
| FormData file upload edge cases in older IE/Safari versions | Low | Low | Application targets modern browsers (ES6+); no IE11 support required |

## Alternatives Considered

### Full REST Framework (Slim, Lumen)

Adopt a lightweight PHP REST framework with built-in routing, middleware, and request/response abstraction.

**Rejected because:**

- Adds router/middleware overhead incompatible with UserSpice's page-based architecture. Would require running two frameworks in parallel (UserSpice for auth,
  Slim for routing), complicating initialization and session management.

- The application has ~20 JSON endpoints; a dedicated REST framework is overengineered for this scale. The incremental cost of ad-hoc ApiResponse is lower than
  framework adoption and maintenance.

- UserSpice is the existing framework; introducing a second framework fragments the architecture and creates deployment complexity.

### JSON:API or HAL Specification

Adopt a standardized hypermedia API specification (JSON:API, HAL) for response envelopes.

**Rejected because:**

- Both specifications are over-engineered for an internal, non-public API with a single frontend client. JSON:API's inclusion of links, relationships, and meta
  fields adds complexity that provides no benefit to the registry.

- HAL's constraint of nesting data under `_embedded`and`_links` would require rewriting all frontend integration logic.

- The application has no public API; conforming to a standard for standardization's sake provides no practical benefit.

- Incremental migration to a formal spec would require rewriting all 20+ endpoints simultaneously; Pattern A allows incremental migration.

### Axios or Other Third-Party HTTP Client

Adopt Axios (or similar) as the frontend HTTP client instead of building a custom ElanRegistryAPI wrapper.

**Rejected because:**

- Adds an npm dependency for features (automatic CSRF injection, request cancellation, typed error hierarchy) that are straightforward to implement with native
  Fetch API. The custom client is ~300 lines of code; Axios is 40+ KB minified.

- Custom implementation allows tight integration with UserSpice CSRF token generation (Token class) and retrieval without adapter complexity.

- Application-specific error types (`ApiValidationError`, `ApiError`) and notification delegation to UserSpice toast functions are simpler in custom code than
  in a generic HTTP client.

- Reduces frontend dependencies; the application currently has no npm packages in production (build tools only).

### Keep jQuery.ajax() with Standardized Callbacks

Standardize jQuery.ajax() responses and error handling without introducing a new client.

**Rejected because:**

- jQuery.ajax() provides no mechanism for automatic CSRF injection; tokens must be manually extracted and added to each request.

- No typed error distinction; all errors are handled via the same `error` callback regardless of whether they're validation, authentication, network, or server
  errors.

- No built-in request cancellation (AbortController is native Fetch feature, not available in jQuery.ajax() without `jqXHR.abort()` manual management).

- Perpetuates jQuery dependency for AJAX when modern Fetch API is universally supported. jQuery.ajax() is fundamentally a wrapper over XMLHttpRequest; Fetch is
  the modern standard.

- Does not address the root problems: inconsistent error handling, race conditions, and audit trail gaps.

## Migration Status

This decision was made in v2.12.0 and is documented retroactively.

### Adoption Summary

- **18 endpoints** (fully migrated to Pattern A)
  - Genuine RESTful endpoints using ApiResponse factory methods
  - Integrated error handling with ElanRegistryException
  - Automatic logging via withLogging()

- **3 endpoints** (fully migrated in v2.25.3, issue #1036)
  - `app/api/cars/list.php`, `app/api/cars/factory-list.php`: DataTables success path uses `json_encode(..., JSON_THROW_ON_ERROR)`
    (DataTables expects raw shape, not `{success, message}` wrapper); error paths use ApiResponse
  - `app/api/cars/chassis-lookup.php`: Fully ApiResponse-driven

- ~~**5 jQuery.ajax() calls** (deprecated, Issue #481)~~
  - Fully migrated in v2.25.3 (issues #528, #968)
  - `tab-settings.php`, `tab-owner_mgmt.php` converted to `ElanRegistryAPI`; `tab-cleanup.php` removed
  - No remaining `$.ajax()` calls in the application

- **8 raw fetch() calls** (partially legacy)
  - admin/includes/tab-system.php uses 8 native Fetch API calls without ElanRegistryAPI wrapper
  - Pre-dates ElanRegistryAPI integration
  - Functional but bypass centralized error handling
  - Low priority for migration

- **2 HTML-fragment endpoints** (ineligible for JSON migration)
  - Return HTML snippets, not JSON (e.g., form snippets, dropdown lists)
  - Will not be migrated to Pattern A

### Migration Path Forward

- **New endpoints** are required to use Pattern A (ApiResponse + ElanRegistryAPI on frontend)
- **Existing jQuery.ajax() calls** should be migrated incrementally as related features are updated (Issue #481 tracks this)
- **Raw fetch() calls** in tab-system.php can remain during v2.13 but should be wrapped in ElanRegistryAPI pattern in future refactoring
- ~~**getDataTables.php** partial migration~~ — completed in v2.25.3 (issue #1036); split into three dedicated endpoints in `app/api/cars/`

## References

- **ApiResponse Class**: [usersc/classes/ApiResponse.php](../../usersc/classes/ApiResponse.php)
- **ElanRegistryAPI Client**: [app/assets/js/api-client.js](../../app/assets/js/api-client.js)
- **ElanRegistryException Base Class**: [usersc/classes/Exceptions/ElanRegistryException.php](../../usersc/classes/Exceptions/ElanRegistryException.php)
- **LogCategories Constants**: [usersc/classes/LogCategories.php](../../usersc/classes/LogCategories.php)
- **Client Injection**: [usersc/includes/footer.php](../../usersc/includes/footer.php)
- **Error Handling Guide**: [docs/development/ERROR_HANDLING.md](../development/ERROR_HANDLING.md)
- **Coding Standards**: [docs/development/CODING_STANDARDS.md](../development/CODING_STANDARDS.md)
- **Class Documentation**: [docs/development/CLASSES.md](../development/CLASSES.md)
- **CSRF Pattern**: ADR-001 covers UserSpice Token class and CSRF protection
- **Migration Issue**: [GitHub Issue #481](https://github.com/unibrain1/elanregistry/issues/481) (jQuery.ajax() removal — completed v2.25.3)
- **Nygard ADR Format**:
  [https://cognitect.com/blog/2011/11/15/documenting-architecture-decisions](https://cognitect.com/blog/2011/11/15/documenting-architecture-decisions)
