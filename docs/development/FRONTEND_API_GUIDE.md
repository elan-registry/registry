# Frontend API Integration Guide

Complete guide to using the ElanRegistryAPI client and NotificationHelper for frontend development.

## Overview

The Elan Registry provides a modern frontend API client (`ElanRegistryAPI`) for AJAX communication with automatic CSRF protection, error handling, and request management.

**Key Features**:

- ✅ Automatic CSRF token injection
- ✅ Fetch API with async/await
- ✅ Request cancellation support
- ✅ Type-specific error handling
- ✅ XSS-safe notification display
- ✅ Field-level validation errors

---

## ElanRegistryAPI Client

The API client is automatically loaded on every page via `footer.php` and available globally.

### Basic Usage

#### POST Request

```javascript
const api = new ElanRegistryAPI();

try {
    const result = await api.post('app/action/update-car.php', {
        car_id: 123,
        year: 2020,
        color: 'red'
    });

    // Success - access result data
    console.log(result.message);
    console.log(result.data);

} catch (error) {
    NotificationHelper.show(error.message, 'error');
}
```

#### GET Request

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

### Response Format (Pattern A)

All endpoints return this structure:

```javascript
{
    success: true,           // Boolean
    message: "Success message",   // String (always present)
    data: { /* additional fields */ }  // Optional
}
```

### Error Handling

The API client throws typed errors that can be caught specifically:

```javascript
const api = new ElanRegistryAPI();

try {
    const result = await api.post('endpoint', data);
    NotificationHelper.show(result.message, 'success');

} catch (error) {
    // Type-specific error handling
    if (error instanceof ApiValidationError) {
        // 422 - Field validation errors
        NotificationHelper.showValidationErrors(error.errors);

    } else if (error instanceof ApiCancelledError) {
        // Request was cancelled
        console.log('Request cancelled');

    } else if (error instanceof ApiError) {
        // General API or network error
        if (error.status === 401) {
            // Unauthorized - redirect to login
            window.location.href = '/users/?view=login';
        } else if (error.status === 403) {
            // Forbidden
            NotificationHelper.show('Permission denied', 'error');
        } else if (error.status === 404) {
            // Not found
            NotificationHelper.show('Resource not found', 'error');
        } else {
            // Other error
            NotificationHelper.show(error.message, 'error');
        }
    }
}
```

### Request Cancellation

Cancel previous requests to prevent race conditions:

```javascript
const api = new ElanRegistryAPI();
let searchRequestId = null;

async function searchCars(query) {
    // Cancel previous search if still pending
    if (searchRequestId) {
        api.cancel(searchRequestId);
    }

    try {
        const result = await api.request('app/action/search-cars.php', {
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

// Use in event handler
$('#searchInput').on('input', function() {
    searchCars($(this).val());
});
```

### CSRF Token Handling

CSRF tokens are **automatically injected**. Just include a CSRF field in forms:

```html
<form id="myForm">
    <input type="hidden" name="csrf" value="<?php echo Token::generate(); ?>">
    <input type="text" name="color" placeholder="Color">
    <button type="submit">Update</button>
</form>

<script>
const api = new ElanRegistryAPI();

document.getElementById('myForm').addEventListener('submit', async (e) => {
    e.preventDefault();

    const formData = new FormData(e.target);
    const data = Object.fromEntries(formData);

    try {
        const result = await api.post('app/action/update-car.php', data);
        NotificationHelper.show(result.message, 'success');
    } catch (error) {
        NotificationHelper.show(error.message, 'error');
    }
});
</script>
```

**How it works**:

- API client looks for `<input name="csrf">` or `<input id="csrf">`
- Automatically extracts token value
- Injects into request headers

**Manual token override** (if needed):

```javascript
const api = new ElanRegistryAPI({
    csrfToken: 'custom-token-value'
});
```

### Advanced: Custom Requests

For requests that don't fit POST/GET patterns:

```javascript
const api = new ElanRegistryAPI();

try {
    const result = await api.request('app/action/custom.php', {
        method: 'POST',
        headers: { 'X-Custom-Header': 'value' },
        body: JSON.stringify({ custom: 'data' }),
        timeout: 30000,  // 30 seconds
        requestId: api.generateRequestId()
    });

    console.log(result);

} catch (error) {
    console.error(error);
}
```

---

## NotificationHelper

Display user feedback consistently with XSS protection.

### Basic Notifications

```javascript
// Success
NotificationHelper.show('Operation completed successfully!', 'success');

// Error
NotificationHelper.show('Unable to save changes. Please try again.', 'error');

// Warning
NotificationHelper.show('This action cannot be undone.', 'warning');

// Info
NotificationHelper.show('Your changes will be auto-saved.', 'info');
```

### Validation Error Display

Display field-level validation errors from `ApiValidationError`:

```javascript
try {
    const result = await api.post('endpoint', data);
} catch (error) {
    if (error instanceof ApiValidationError) {
        // Display validation errors
        NotificationHelper.showValidationErrors(error.errors);

        // Example: error.errors = {
        //   email: 'Invalid email format',
        //   password: 'Password too short',
        //   phone: 'Invalid phone number'
        // }
    }
}
```

### Persistent Notifications

Disable auto-hide for important messages:

```javascript
// Notification stays until user dismisses
NotificationHelper.show('Critical alert message', 'warning', 0);

// Or use default duration (3-5 seconds)
NotificationHelper.show('Standard notification', 'info');
```

---

## Common Patterns

### Loading State Management

Disable button and show loading indicator during request:

```javascript
const $btn = $('#submitBtn');

try {
    // Show loading state
    $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Loading...');

    const result = await api.post('endpoint', data);
    NotificationHelper.show(result.message, 'success');

} catch (error) {
    NotificationHelper.show(error.message, 'error');

} finally {
    // Always restore button state
    $btn.prop('disabled', false).html('Submit');
}
```

### Confirmation Dialogs

Confirm before destructive operations:

```javascript
if (confirm('Are you sure? This action cannot be undone.')) {
    try {
        const result = await api.post('app/action/delete-car.php', {
            car_id: carId
        });
        NotificationHelper.show(result.message, 'success');
        // Reload page or update UI
        location.reload();
    } catch (error) {
        NotificationHelper.show(error.message, 'error');
    }
}
```

### Auto-Save with Debouncing

Prevent excessive requests when user is typing:

```javascript
const api = new ElanRegistryAPI();
let saveTimeout;

function autoSave(data) {
    // Cancel pending save
    clearTimeout(saveTimeout);

    // Schedule save after user stops typing
    saveTimeout = setTimeout(async () => {
        try {
            const result = await api.post('app/action/auto-save.php', data);
            console.log('Auto-saved');
        } catch (error) {
            console.error('Auto-save failed:', error.message);
        }
    }, 1000);  // Wait 1 second after last change
}

$('#carForm input').on('input', function() {
    autoSave({
        field: $(this).attr('name'),
        value: $(this).val()
    });
});
```

### Search with Request Cancellation

Cancel previous searches when user types new query:

```javascript
const api = new ElanRegistryAPI();
let searchRequestId = null;

$('#searchInput').on('input', async function() {
    const query = $(this).val();

    if (!query) {
        $('#results').empty();
        return;
    }

    // Cancel previous search
    if (searchRequestId) {
        api.cancel(searchRequestId);
    }

    try {
        searchRequestId = api.generateRequestId();
        const result = await api.get('app/action/search.php', {
            q: query,
            limit: 20
        }, { requestId: searchRequestId });

        // Display results
        displayResults(result.data);

    } catch (error) {
        if (!(error instanceof ApiCancelledError)) {
            NotificationHelper.show(error.message, 'error');
        }
    }
});
```

### Form Submission with Validation

Handle form submission with validation error display:

```javascript
$('#carForm').on('submit', async function(e) {
    e.preventDefault();
    const api = new ElanRegistryAPI();

    try {
        const formData = new FormData(this);
        const data = Object.fromEntries(formData);

        const result = await api.post('app/action/save-car.php', data);

        // Clear form on success
        this.reset();
        NotificationHelper.show(result.message, 'success');

        // Optional: Redirect or update UI
        if (result.data.carId) {
            window.location.href = `/app/owner/cars/details.php?id=${result.data.carId}`;
        }

    } catch (error) {
        if (error instanceof ApiValidationError) {
            // Show field-level errors
            NotificationHelper.showValidationErrors(error.errors);

            // Optional: Highlight error fields
            Object.keys(error.errors).forEach(field => {
                $(`#${field}`).addClass('is-invalid');
            });

        } else if (error.status === 401) {
            NotificationHelper.show('Please log in to continue', 'error');
            window.location.href = '/users/?view=login';

        } else {
            NotificationHelper.show(error.message, 'error');
        }
    }
});
```

---

## Backend Integration

### Endpoint Implementation

Create AJAX endpoints using `ApiResponse`:

```php
<?php
require_once '../../users/init.php';

// Protect page
securePage($php_self);

try {
    // Get input
    $carId = (int)Input::get('car_id');
    $color = Input::get('color');

    // Validate
    if ($carId <= 0) {
        throw new ValidationException('Invalid car ID');
    }
    if (empty($color) || strlen($color) > 50) {
        throw new ValidationException('Invalid color');
    }

    // Process
    $car = new Car($carId);
    if (!$car->exists()) {
        throw new CarNotFoundException('Car not found');
    }

    $car->update(['color' => $color]);

    // Success response
    ApiResponse::success('Car updated successfully')
        ->withData('carId', $carId)
        ->withLogging(
            currentUserId(),
            LogCategories::LOG_CATEGORY_CAR_UPDATE,
            "Updated car $carId color to $color"
        )
        ->send();

} catch (ValidationException $e) {
    ApiResponse::validationError(['field' => $e->getMessage()])
        ->send();

} catch (CarNotFoundException $e) {
    ApiResponse::notFound($e->getUserMessage())
        ->withLogging(currentUserId(), LogCategories::LOG_CATEGORY_CAR_ERRORS, $e->getMessage())
        ->send();

} catch (Exception $e) {
    ApiResponse::serverError('An error occurred')
        ->withLogging(currentUserId(), LogCategories::LOG_CATEGORY_SYSTEM_ERROR, $e->getMessage())
        ->send();
}
```

### Endpoint Locations

Place AJAX endpoints in `/app/action/` directory:

```text
/app/action/
├── update-car.php
├── delete-car.php
├── search-cars.php
├── upload-image.php
└── export-list.php
```

---

## Best Practices

### ✅ DO

- **Use ElanRegistryAPI for all new AJAX calls** - Consistent handling, CSRF protection
- **Handle errors by type** - Different UI for validation vs server errors
- **Show loading states** - Disable buttons, show spinners
- **Include logging** - Track user actions in audit trail
- **Validate on backend** - Never trust frontend validation alone
- **Use NotificationHelper** - XSS-safe, consistent appearance
- **Test error scenarios** - Network failures, timeouts, validation errors

### ❌ DON'T

- **Don't use `$.ajax()` for new endpoints** - Legacy, not recommended
- **Don't skip CSRF validation** - Required for security
- **Don't expose technical errors** - Use user-friendly messages
- **Don't trust frontend input** - Always validate on server
- **Don't show password/token in errors** - Never log sensitive data
- **Don't mix error handling styles** - Use consistent patterns
- **Don't forget `Token::generate()` in forms** - Required for CSRF protection

---

## Troubleshooting

### AJAX Requests Failing Silently

**Check browser console** for JavaScript errors using F12 Developer Tools.

```javascript
// Add detailed logging
const api = new ElanRegistryAPI();

try {
    const result = await api.post('endpoint', data);
    console.log('Success:', result);
} catch (error) {
    console.error('Error type:', error.constructor.name);
    console.error('Error message:', error.message);
    console.error('Error status:', error.status);
}
```

### CSRF Token Missing

**Ensure form has CSRF field:**

```html
<!-- ✅ CORRECT -->
<form id="myForm">
    <input type="hidden" name="csrf" value="<?php echo Token::generate(); ?>">
    <!-- Form fields -->
</form>

<!-- ❌ WRONG - Missing CSRF field -->
<form id="myForm">
    <!-- Form fields -->
</form>
```

### Notifications Not Appearing

**Verify NotificationHelper is loaded** via footer.php and check for JavaScript errors.

```javascript
// Test if NotificationHelper is available
if (typeof NotificationHelper !== 'undefined') {
    NotificationHelper.show('Test notification', 'info');
} else {
    console.error('NotificationHelper not loaded');
}
```

### Request Timing Out

**Increase timeout for slow endpoints:**

```javascript
const api = new ElanRegistryAPI();

try {
    const result = await api.request('slow-endpoint', {
        method: 'POST',
        body: JSON.stringify(data),
        timeout: 60000  // 60 seconds instead of default 30
    });
} catch (error) {
    NotificationHelper.show('Request timeout', 'error');
}
```

---

## Related Documentation

- **[ERROR_HANDLING.md](ERROR_HANDLING.md)** - Backend error patterns, exceptions, logging
- **[QUICK_REFERENCE.md](QUICK_REFERENCE.md)** - Code snippets and examples
- **[CODING_STANDARDS.md](CODING_STANDARDS.md)** - Frontend code standards

---

**Last Updated:** v2.15.0
**Applies to:** v2.12.0+
