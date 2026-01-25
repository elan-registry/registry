# Quick Reference Guide

Quick reference for common development tasks and commands. For detailed
information, see the complete documentation files linked throughout.

## Essential Commands

### Testing

```bash
# PHP Tests
composer test:quick        # Unit tests only (<30s)
composer test:medium       # Unit + Integration (<2min)
vendor/bin/phpunit tests/  # All PHP tests

# UI Tests (requires setup)
npm test                   # Shows setup requirements
npm run test:security      # Security validation tests
npm run test:functionality # Core functionality
npm run test:navigation    # Navigation and redirects
```

### Pre-commit Quality Checks

```bash
# Setup once (RECOMMENDED)
./scripts/setup-git-hooks.sh

# Manual quality check
composer phpcs             # Coding standards
npm run lint:md           # Markdown linting
```

### Git & Deployment

```bash
# Push to remotes
git push origin main       # GitHub repository
git push test main         # Test environment
git push prod main         # Production environment

# Tags
git tag -a v2.10.0 -m "Version 2.10.0"
git push origin --tags
git push prod --tags
```

## Common File Locations

### Application Structure

```text
/app/                      # Main application pages
  /cars/                   # Car listing, details, edit
  /admin/                  # Admin interfaces
  /reports/                # Statistics and reports
  /contact/                # Owner contact functionality
/users/                    # UserSpice authentication
/usersc/                   # UserSpice customizations
  /classes/                # Custom PHP classes
  /includes/               # Custom functions
  /plugins/                # Custom plugins
/tests/                    # PHPUnit and Playwright tests
/docs/                     # Documentation
```

### Key Files

```text
z_us_root.php              # Root path configuration
users/init.php             # UserSpice initialization
.env.enc                   # Encrypted environment variables
VERSION                    # Current version number
```

## Code Patterns

### Database Access

```php
// Singleton pattern
$db = DB::getInstance();

// Query with parameters
$results = $db->query(
    "SELECT * FROM cars WHERE id = ?",
    [$carId]
)->results();

// Update with transaction
$db->query("START TRANSACTION");
try {
    $db->update('cars', $carId, $fields);
    $db->query("COMMIT");
} catch (Exception $e) {
    $db->query("ROLLBACK");
    throw $e;
}
```

### User/Profile Access

```php
// Use existing helper function
$owner = getUserWithProfile($userId);
if ($owner) {
    echo $owner->fname . ' ' . $owner->lname;
    echo $owner->city . ', ' . $owner->state;
}
```

### Error Handling

#### Backend API Responses (AJAX Endpoints)

```php
// Success response with data
ApiResponse::success('Operation successful')
    ->withData('car_id', 123)
    ->withLogging($userId, LogCategories::LOG_CATEGORY_CAR_CREATION, 'Car created')
    ->send();

// Validation error (422)
ApiResponse::validationError([
    'email' => 'Invalid email format',
    'name' => 'Name is required'
])->send();

// Not found (404)
ApiResponse::notFound('Car not found')->send();

// Forbidden (403)
ApiResponse::forbidden('You lack permission')->send();

// Unauthorized (401)
ApiResponse::unauthorized('Authentication required')->send();

// Server error (500)
ApiResponse::serverError('An error occurred')
    ->withLogging($userId, 'SystemError', $e->getMessage())
    ->send();
```

#### Exceptions (Typed Exceptions)

```php
// Throw typed exception with user message
try {
    throw new CarCreationException(
        'Database constraint violation',  // Technical
        0,
        null,
        'Unable to create car'  // User-friendly
    );
} catch (ElanRegistryException $e) {
    ApiResponse::serverError($e->getUserMessage())
        ->withLogging($userId, $e->getLogCategory(), $e->getMessage())
        ->send();
}

// Handle validation exception
try {
    if (empty($data['vin'])) {
        throw new CarValidationException('VIN is required');
    }
} catch (CarValidationException $e) {
    ApiResponse::validationError(['vin' => $e->getUserMessage()])
        ->send();
}
```

#### Logging with LogCategories

```php
// ✅ CORRECT: Use LogCategories constants
logger($userId, LogCategories::LOG_CATEGORY_CAR_CREATION, 'Car created successfully');
logger($userId, LogCategories::LOG_CATEGORY_VALIDATION_ERROR, 'Email validation failed');
logger($userId, LogCategories::LOG_CATEGORY_SYSTEM_ERROR, 'Database connection failed');

// Find all available categories
// grep "const LOG_CATEGORY" usersc/classes/LogCategories.php
```

#### Frontend Error Handling (JavaScript)

```javascript
// Basic API request
const api = new ElanRegistryAPI();

try {
    const result = await api.post('app/action/update-car.php', {
        car_id: 123,
        year: 2020
    });

    NotificationHelper.show(result.message, 'success');

} catch (error) {
    if (error instanceof ApiValidationError) {
        NotificationHelper.showValidationErrors(error.errors);
    } else if (error instanceof ApiCancelledError) {
        console.log('Request cancelled');
    } else {
        NotificationHelper.show(error.message, 'error');
    }
}

// GET request
const result = await api.get('app/action/search.php', {
    query: 'Elan',
    limit: 10
});
console.log(result.data);
```

**See Also**: [ERROR_HANDLING.md](ERROR_HANDLING.md) for complete error
handling patterns and migration guide.

### Security

```php
// CSRF protection
$token = Token::generate();
$isValid = Token::check(Input::get('csrf'));

// Input sanitization
$clean = Input::get('field');  // Already sanitized by UserSpice

// Secure page access (REQUIRED for all protected pages)
if (!securePage($_SERVER['PHP_SELF'])) {
    die();
}
// Note: Page must be registered in UserSpice admin panel
//       with appropriate permission levels
```

**Page Security Requirements**:

- All pages requiring authentication MUST include `securePage()` check
- Place at top of file after `init.php` include
- Page must be registered in UserSpice Admin Panel → Page Management
- Set appropriate permission levels (1=User, 2=Admin, etc.)
- `securePage()` checks both authentication and permissions
- Returns `false` if user lacks access (then page should `die()`)

## UserSpice Integration

### Adding New PHP Directories

1. Add path to `$path` array in `/z_us_root.php`:

   ```php
   $path = ['', 'users/', 'usersc/', 'app/', 'app/newdir/', ...];
   ```

2. Register pages using `securePage()` in UserSpice admin panel

### Permission Checks

```php
// Check if user has permission
if (hasPerm([2], $user->data()->id)) {
    // Admin only code
}

// Multiple permission levels
if (hasPerm([2, 3], $user->data()->id)) {
    // Admin or Editor
}
```

## Database Operations

### Audit Logging

```php
// All car operations are automatically logged via triggers
// For manual audit entries:
logger($userId, 'DatabaseMaintenance', 'Operation description');
```

### FIX Scripts

```bash
# Template location
/FIX/_TEMPLATE_Fix-Script.php

# Naming convention
##-Descriptive-Name.php  # e.g., 13-Fix-Something.php

# Required structure
- Description page with start button
- Progress tracking with outputMessage()
- Transaction handling
- Completion summary
```

## Environment Configuration

### Development

```bash
# Check current environment
echo $ELAN_ENVIRONMENT  # development, test, or production

# Key environment variables
ELAN_ENVIRONMENT       # Current environment
ELAN_DB_HOST          # Database host
ELAN_DB_NAME          # Database name
ELAN_GOOGLE_MAPS_KEY  # Google Maps API key
```

See [ENVIRONMENT.md](ENVIRONMENT.md) for complete configuration details.

## Troubleshooting

### Common Issues

**"File has been modified":**

- Git hook or linter modified file
- Re-read file before editing
- Check `.markdownlint.json` settings

**"securePage() redirecting to login":**

- Page not registered in UserSpice admin panel
- Directory not in `$path` array in `z_us_root.php`

**"Database triggers not firing":**

- Only `cars` table has triggers
- Triggers can be bypassed with `@disable_triggers = 1`
- Other tables use application-level logging

**"Tests failing":**

- Check PHP version (8.1+ required, 8.2+ recommended)
- Run `composer install` and `npm install`
- Verify environment variables in `.env.enc`

## Release Process

### Version Numbering

- **Major (x.0.0)**: Breaking changes, major features
- **Minor (x.y.0)**: New features, no breaking changes
- **Patch (x.y.z)**: Bug fixes, security patches

### Creating a Release

```bash
# 1. Update VERSION file
echo "2.10.0" > VERSION

# 2. Update/create release notes
# docs/releases/RELEASE_NOTES_V2.10.0.md

# 3. Commit and tag
git add VERSION docs/releases/
git commit -m "Release v2.10.0"
git tag -a v2.10.0 -m "Version 2.10.0"

# 4. Push to all remotes
git push origin main && git push origin --tags
git push test v2.10.0
git push prod main && git push prod --tags
```

See [DEPLOYMENT.md](DEPLOYMENT.md) for complete release procedures.

## Documentation

### Finding Information

```text
CLAUDE.md                  # Start here - AI assistant guide
docs/development/          # Technical documentation
  ERROR_HANDLING.md       # Error handling patterns & migration
  QUICK_START.md          # Setup and testing
  ARCHITECTURE.md         # System architecture
  DATABASE.md             # Database schema
  PROJECT_CONVENTIONS.md  # Coding standards
  LOG_CATEGORIES.md       # Complete logging categories
docs/faq/                 # User documentation
docs/faq/admin/           # Admin documentation
docs/README.md            # Documentation index
```

### Documentation Standards

- Use markdown format
- 80 character line length (configured in `.markdownlint.json`)
- Cross-reference related documents
- Update when features change

## Getting Help

- **Documentation**: Start with [CLAUDE.md](../../CLAUDE.md)
- **GitHub Issues**: Check existing issues and discussions
- **Code Search**: Use grep/Glob tools to find patterns
- **Tests**: Review test files for usage examples

---

**For detailed information on any topic, see the complete documentation files
in `/docs/development/`.**
