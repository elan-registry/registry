# Coding Standards for Elan Registry

**Version:** 1.0  
**Updated:** September 7, 2025  
**Target:** PHP 8.1+, Modern Web Standards

---

## 📋 **Table of Contents**

- [🎯 Overview](#overview)
- [🔧 PHP 8+ Requirements](#php-8-requirements)
- [🏗️ Code Architecture](#code-architecture)
- [🛡️ Security Standards](#security-standards)
- [📝 Documentation Standards](#documentation-standards)
- [📂 File Organization](#file-organization)
- [🚀 Performance Guidelines](#performance-guidelines)
- [✅ Code Review Checklist](#code-review-checklist)

---

## 🎯 **Overview**

This document establishes coding standards for the Elan Registry PHP web
application to ensure consistency, maintainability, and security across
the codebase.

### **Core Principles**

- **Security First**: All code must follow secure coding practices

- **Type Safety**: Leverage PHP 8+ type system for better error prevention
- **Consistency**: Uniform code style across all files  

- **Documentation**: Comprehensive inline and external documentation
- **Performance**: Optimize for both development and runtime efficiency

---

## 🔧 **PHP 8+ Requirements**

### **Strict Typing Declaration**

All new PHP files **MUST** include strict typing:

```php
<?php

declare(strict_types=1);

/**
 * Class or file description
 */

```text

### **Type Declarations**

#### **Function Parameters and Return Types**
All functions **MUST** have complete type declarations:

```php
// ✅ REQUIRED - Complete type declarations
public function updateCar(array $data, int $userId): bool
{
    // Implementation
}

private function validateInput(string $value, int $maxLength = 100): string
{
    // Implementation
}

// ❌ PROHIBITED - Missing type declarations
public function updateCar($data, $userId)
{
    // Legacy style - not allowed in new code
}

```text

#### **Property Type Declarations**
Class properties **MUST** be typed:

```php
class Car
{
    private string $tableName = 'cars';
    private int $id;
    private ?string $chassis = null;
    private array $allowedColumns = [];
    private readonly string $imageDir;
}

```text

#### **Union Types and Nullable Types**
Use modern PHP 8+ type features:

```php
// Union types
private function processValue(string|int $value): bool

// Nullable types  
private function findUser(int $id): ?User

// Mixed type (use sparingly)
private function handleLegacyData(mixed $data): array

```text

#### **Strict Type Safety with Database Values**

⚠️ **CRITICAL**: When using `declare(strict_types=1)`, database INTEGER colum
ns may be returned as strings depending on PHP/MySQL configuration.

**Always cast database values explicitly when passing to strict-typed parameters:**

```php
// ✅ CORRECT - Explicit type casting
$backupManager = new BackupManager($db, $backupDir, (int)$user->data()->id);
$carId = (int)$dbRow->id;
$count = (int)$result->first()->total;

// ❌ WRONG - Missing cast in strict mode
$backupManager = new BackupManager($db, $backupDir, $user->data()->id);
// TypeError: Argument #3 ($userId) must be of type ?int, string given

```text

**Common database value casts:**

```php
// Integer columns
$userId = (int)$user->data()->id;
$carId = (int)$row->car_id;
$count = (int)$result->count;

// Boolean columns (TINYINT)
$isActive = (bool)$row->active;
$isAdmin = (bool)$row->is_admin;

// Float/Decimal columns
$price = (float)$row->price;
$latitude = (float)$row->lat;

// Nullable integers
$optionalId = $row->optional_id ? (int)$row->optional_id : null;

```text

**Type helper functions (preferred for object properties):**

```php
// Extract int from database result object — throws on invalid input
$userId = dbInt($carData, 'user_id');
$carId = dbInt($row, 'id');

// Current user ID shorthand — throws RuntimeException if not logged in
$adminId = currentUserId();
```

These helpers are defined in `usersc/includes/custom_functions.php`.
Use `dbInt()` when extracting integer properties from PDO result objects.
Use direct `(int)` casts for simple scalar conversions.

```text

**Why this is necessary:**

```php
// PDO/mysqli behavior varies by configuration:
// - PHP 8.3.14 (dev): Returns int from INT columns
// - PHP 8.2.29 (test): Returns string from INT columns
//
// With declare(strict_types=1), string ≠ int
// Solution: Always cast explicitly for cross-environment compatibility

```text

**See also:** `/docs/development/STRICT_TYPE_HANDLING.md` for comprehensive strategy.

### **Modern PHP Features**

#### **Constructor Property Promotion**
Use constructor property promotion where appropriate:

```php
// ✅ PREFERRED - Constructor promotion
class CarValidator
{
    public function __construct(
        private readonly DatabaseInterface $db,
        private readonly LoggerInterface $logger,
        private array $rules = []
    ) {}
}

// ❌ LEGACY - Verbose constructor
class CarValidator
{
    private $db;
    private $logger;
  
    public function __construct(DatabaseInterface $db, LoggerInterface $logger)
    {
        $this->db = $db;
        $this->logger = $logger;
    }
}

```text

#### **Named Arguments**
Design methods to support named arguments:

```php
// ✅ GOOD - Named argument friendly
public function resizeImage(
    string $filepath,
    int $width,
    int $height,
    string $mode = 'auto',
    int $quality = 85
): bool

// Usage with named arguments
$resize->resizeImage(
    filepath: $image,
    width: 800,
    height: 600,
    quality: 90
);

```text

---

## 🏗️ **Code Architecture**

### **Exception Handling**

All exceptions **MUST** extend `ElanRegistryException` base class (26 domain-specific types). Each exception carries an HTTP status code, log category, and separate technical/user-friendly messages.

**Key rules:**

- Never throw generic `Exception` - use typed exceptions (e.g., `CarValidationException`, `CarCreationException`)
- Domain base classes group related exceptions (e.g., `CarException` for all car operations)
- Separate technical messages (for logs) from user-safe messages (for UI)
- Catch with domain base class (e.g., `CarException`) or `ElanRegistryException` as fallback after specific types

**See [ERROR_HANDLING.md](ERROR_HANDLING.md#exception-hierarchy)** for the complete exception hierarchy table, usage patterns, and migration guide.

### **Error Handling Patterns**

#### **API Response Requirements** (MANDATORY for AJAX endpoints)

All AJAX endpoints **MUST** return Pattern A format via `ApiResponse` class with logging.

**Factory Methods**: `success()`, `error()`, `validationError()`, `unauthorized()`, `forbidden()`, `notFound()`, `serverError()`.

See [ERROR_HANDLING.md](ERROR_HANDLING.md#backend-error-handling) for complete examples and usage patterns.

#### **Log Category Requirements** (MANDATORY)

All `logger()` calls **MUST** use LogCategories constants (never hardcoded strings).

**Discovery**: `grep "const LOG_CATEGORY" usersc/classes/LogCategories.php`

See [LOG_CATEGORIES.md](LOG_CATEGORIES.md) and [ERROR_HANDLING.md](ERROR_HANDLING.md#logcategories) for complete reference.

### **Method Naming and Structure**

**Conventions**:
- **Verbs**: `create()`, `update()`, `delete()`, `validate()`
- **Boolean methods**: `exists()`, `isValid()`, `hasPermission()`
- **Getters**: `data()`, `images()`, `history()` (not `getData()`)

---

## 🛡️ **Security Standards**

### **Input Validation**
All user input **MUST** be validated and sanitized:

```php
use ElanRegistry\Input;

public function updateColor(array &$cardetails): void
{
    $color = Input::raw('color');

    // ✅ REQUIRED - Validate input
    if (empty($color) || strlen($color) > 50) {
        throw new ValidationException('Invalid color value');
    }

    // Store plain text; escape at output with htmlspecialchars()
    $cardetails['color'] = $color;
}

```

### **Input Handling and Output Encoding**

Store plain text via `Input::raw()`; escape at the **output** context (templates, email).

```php
// ✅ CORRECT — plain text in DB, escaped at render time
<?= htmlspecialchars($car->color, ENT_QUOTES, 'UTF-8') ?>

// ❌ WRONG — encodes at storage (double-encoding bug)
$color = \Input::get('color');
$cardetails['color'] = htmlspecialchars($color, ENT_QUOTES, 'UTF-8');
```

**Rules:**

- `Input::raw()` (via `use ElanRegistry\Input`) → values going to the database
- `\Input::get()` → legacy pattern only (value used directly in HTML, no further escaping)
- `htmlspecialchars()` → always at output (HTML templates, email templates)
- Parameterised queries handle SQL safety; encoding at storage is never a SQL defence

### **Database Operations**

Always use parameterized queries:

```php
// ✅ REQUIRED - Parameterized queries
public function findByChassis(string $chassis): ?array
{
    $query = $this->db->query(
        'SELECT * FROM cars WHERE chassis = ? LIMIT 1',
        [$chassis]
    );
  
    return $query->first() ?: null;
}

// ❌ PROHIBITED - String concatenation
public function findByChassis(string $chassis): ?array
{
    // NEVER DO THIS - SQL injection vulnerability
    $query = "SELECT * FROM cars WHERE chassis = '{$chassis}'";
    return $this->db->query($query);
}

```text

### **CSRF Protection**
All forms **MUST** include CSRF tokens:

```php
// ✅ REQUIRED - CSRF validation
if (!empty($_POST)) {
    $token = Input::get('csrf');
    if (!Token::check($token)) {
        throw new SecurityException('Invalid CSRF token');
    }
  
    // Process form data
}

```text

### **File Upload Security**
Implement comprehensive file validation:

```php
public function validateFileUpload(array $file): void
{
    // Required validations
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new FileUploadException("Upload error: {$file['error']}");
    }

    // Size validation
    if ($file['size'] > self::MAX_FILE_SIZE) {
        throw new FileUploadException('File too large');
    }

    // MIME type validation
    $mimeType = $this->getMimeType($file['tmp_name']);
    if (!in_array($mimeType, self::ALLOWED_MIME_TYPES)) {
        throw new FileUploadException("Invalid file type: {$mimeType}");
    }

    // Verify upload integrity
    if (!is_uploaded_file($file['tmp_name'])) {
        throw new FileUploadException('Invalid file upload');
    }
}

```text

### **Error Logging Standards**

**CRITICAL:** All error conditions MUST use UserSpice `logger()` function for centralized visibility and audit trails in the admin panel. Never use PHP `error_log()` in application code.

#### **When to Use logger()**

Use the `logger()` function for **all** error conditions in the application (web context):

```php
// ✅ REQUIRED - Use logger() for error conditions
try {
    $result = riskyOperation();
} catch (Exception $e) {
    logger(
        $user->data()->id ?? 0,
        LogCategories::LOG_CATEGORY_SYSTEM_ERROR,
        'Operation failed: ' . $e->getMessage()
    );
    throw new OperationException('User-friendly message');
}

// ✅ REQUIRED - Use logger() for validation errors
if (empty($requiredField)) {
    logger(
        $user->data()->id ?? 0,
        LogCategories::LOG_CATEGORY_VALIDATION_ERROR,
        'Required field missing: fieldName'
    );
    throw new ValidationException('Field is required');
}

// ❌ PROHIBITED - Never use error_log() in web context
error_log("Error: " . $e->getMessage());  // Don't do this!

```text

#### **LogCategories Constants**

All `logger()` calls **MUST** use standardized constants from the `LogCategories` class. Never use hardcoded strings.

**Location:** `usersc/classes/LogCategories.php`

**140+ categories organized by functional domain:**
- Car Management (CarActions, CarCreation, CarUpdate, CarDeletion, CarTransfer, etc.)
- User/Owner Management (OwnerActions, UserDeletion, UserCreation, etc.)
- Authentication (Login, LoginFail, PasswordReset, etc.)
- Database Operations (DatabaseError, DatabaseMaintenance, BackupManager, etc.)
- Email/Communications (EmailSuccess, EmailError, etc.)
- System & File Operations (SystemError, FileError, ValidationError, etc.)
- Admin & Management (AdminVerification, SettingsUpdate, etc.)
- And more functional domains

```php
// ✅ CORRECT - Use LogCategories constants
logger($user->data()->id, LogCategories::LOG_CATEGORY_DATABASE_ERROR, 'Query failed');
logger($user->data()->id, LogCategories::LOG_CATEGORY_CAR_CREATION, 'Car created successfully');
logger($user->data()->id, LogCategories::LOG_CATEGORY_SYSTEM_ERROR, 'Backup failed');

// ❌ INCORRECT - Never use hardcoded strings
logger($user->data()->id, 'SystemError', 'message');  // Don't do this!

```text

**Discovery:** Find available constants:
```bash
grep "const LOG_CATEGORY" usersc/classes/LogCategories.php
```

#### **Exception: CLI Scripts**

The `error_log()` function is **allowed** in CLI scripts (scripts/ directory) that
run outside the web context:

```php
// ✅ ACCEPTABLE - CLI scripts may use error_log()
// scripts/menu-sync.php
if (!function_exists('logger')) {
    error_log("Running outside web context");
}

```text

#### **User ID Handling**

Use safe user ID handling in exception contexts:

```php
// ✅ CORRECT - Safe user ID extraction with fallback
logger(
    $user->data()->id ?? 0,
    LogCategories::LOG_CATEGORY_SYSTEM_ERROR,
    'Error message'
);

// Also acceptable when user availability varies
logger(
    $currentUserId ?? $user->data()->id ?? 0,
    LogCategories::LOG_CATEGORY_DATABASE_ERROR,
    'Database error'
);

```text

#### **Complete Error Details**

Provide comprehensive error information for debugging:

```php
// ✅ GOOD - Complete error context
catch (Exception $e) {
    $errorDetails = "Operation failed\n";
    $errorDetails .= "Error: " . $e->getMessage() . "\n";
    $errorDetails .= "File: " . $e->getFile() . " (Line " . $e->getLine() . ")\n";
    $errorDetails .= "Stack trace:\n" . $e->getTraceAsString();

    logger(
        $user->data()->id ?? 0,
        LogCategories::LOG_CATEGORY_SYSTEM_ERROR,
        $errorDetails
    );
}

```text

---

## 📝 **Documentation Standards**

### **PHPDoc Requirements**
All classes, methods, and properties **MUST** have PHPDoc blocks:

```php
/**
 * Car management class for the Lotus Elan Registry
 *
 * Handles car data operations including creation, updates, validation,
 * and relationship management with users and factory data.
 *
 * @author Elan Registry Development Team
 * @version 2.7.1
 * @since 1.0.0
 */
class Car
{
    /**
     * Maximum allowed chassis suffix length for validation
     */
    private const CHASSIS_SUFFIX_LENGTH = 5;
  
    /**
     * Update car color with validation and audit trail
     *
     * Validates color input, sanitizes for safe storage, and creates
     * an audit trail entry for the change.
     *
     * @param array $cardetails Car data array (passed by reference)
     * @param string $newColor The new color value to set
     * @return void
     *
     * @throws ValidationException If color value is invalid
     * @throws DatabaseException If database update fails
     *
     * @example
     * $car = new Car(123);
     * $car->updateColor($data, 'British Racing Green');
     */
    public function updateColor(array &$cardetails, string $newColor): void
    {
        // Implementation
    }
}

```text

### **Inline Comments**
Use inline comments for complex logic only:

```php
// ✅ GOOD - Complex business logic explanation
// Apply EXIF orientation correction before resizing to ensure
// mobile uploads display correctly while preserving privacy
$correctedImage = $this->correctOrientation($filename, $image);

// ❌ POOR - Obvious code explanation
// Set the color variable
$color = Input::get('color');

```text

### **TODO and FIXME Comments**
Use structured TODO/FIXME comments:

```php
// TODO: Issue #278 - Add type declarations to legacy functions
// FIXME: Issue #240 - Optimize database query performance
// NOTE: This workaround addresses PHP 8.1 compatibility issue

```text

---

## 📂 **File Organization**

### **Directory Structure**

```text
/app/                          # Application pages and logic
  /cars/                       # Car-related functionality
    /actions/                  # Form processing endpoints
    /views/                    # Display templates (future)
  /contact/                    # Contact and email functionality
  /reports/                    # Statistics and reporting

/usersc/                       # UserSpice customizations
  /classes/                    # PHP classes and business logic
  /templates/                  # UI templates and layouts
  /scripts/                    # UserSpice hooks and customizations
  /plugins/                    # Plugin configurations

/docs/                         # Documentation
  /development/                # Developer documentation
  /faq/                        # User/owner documentation
  /faq/admin/                  # Admin documentation
  /testing/                    # Testing strategy and execution
  /releases/                   # Release notes

/tests/                        # Testing infrastructure
  /unit/                       # PHPUnit tests
  /playwright/                 # Browser tests

/app/admin/scripts/fix/        # One-time admin migration / fix scripts
/app/admin/scripts/maintenance/  # Repeatable system maintenance scripts

```text

### **File Naming Conventions**

#### **PHP Classes**
- **PascalCase**: `Car.php`, `ImageProcessor.php`, `UserManager.php`

- **One class per file**
- **Descriptive names**: `CarValidationException.php` not `Exception.php`

#### **Script Files**  
- **snake_case**: `edit_car.php`, `send_email.php`

- **Descriptive action names**: `validate-chassis.php` not `check.php`

#### **Template Files**
- **Purpose-based naming**: `car-details.php`, `user-profile.php`

- **Consistent prefixes**: `_partial-name.php` for partials

### **Class Organization**

```php
<?php

declare(strict_types=1);

/**
 * Class documentation
 */
class ExampleClass
{
    // 1. Constants (public first, then private)
    public const PUBLIC_CONSTANT = 'value';
    private const PRIVATE_CONSTANT = 'value';
  
    // 2. Properties (public first, then protected, then private)
    public string $publicProperty;
    protected int $protectedProperty;
    private array $privateProperty = [];
  
    // 3. Constructor
    public function __construct() {}
  
    // 4. Public methods (alphabetical order preferred)
    public function create(): bool {}
    public function delete(): bool {}
    public function update(): bool {}
  
    // 5. Protected methods
    protected function validateInput(): void {}
  
    // 6. Private methods (alphabetical order preferred)
    private function saveToDatabase(): bool {}
    private function sendNotification(): void {}
}

```text

---

## 🚀 **Performance Guidelines**

**Key principles**: Minimize database queries, cache results, process large datasets in chunks.

For detailed patterns and examples, see [QUICK_REFERENCE.md](QUICK_REFERENCE.md).

---

## ✅ **Code Review Checklist**

### **Security Checklist**
- [ ] All user input is validated and sanitized

- [ ] Database queries use parameterized statements  
- [ ] CSRF tokens are validated on form submissions

- [ ] File uploads include comprehensive validation
- [ ] No sensitive information in error messages or logs

- [ ] Authentication and authorization checks are present

### **Code Quality Checklist**
- [ ] All functions have complete type declarations

- [ ] Strict typing is enabled (`declare(strict_types=1)`)
- [ ] Typed exceptions extend ElanRegistryException (never generic Exception)

- [ ] AJAX endpoints use ApiResponse (success/error/validationError/etc.)
- [ ] All logger() calls use LogCategories constants (NO hardcoded strings)
- [ ] User-friendly and technical messages separated in exceptions
- [ ] Error handling follows established patterns
- [ ] Code follows naming conventions

- [ ] No code duplication (DRY principle)

### **Documentation Checklist**
- [ ] All classes have PHPDoc headers

- [ ] All public methods have complete PHPDoc blocks
- [ ] Complex logic has inline comments

- [ ] TODO/FIXME comments reference specific issues
- [ ] README or documentation updated if needed

### **Performance Checklist**  
- [ ] Database queries are optimized (no N+1 problems)

- [ ] Expensive operations are cached when appropriate
- [ ] Memory usage is considered for large datasets

- [ ] File operations are optimized
- [ ] No unnecessary object creation in loops

---

## 📚 **References**

- [ERROR_HANDLING.md](ERROR_HANDLING.md) - Comprehensive error handling guide
  with patterns and migration strategies
- [LOG_CATEGORIES.md](LOG_CATEGORIES.md) - Complete reference of 140+
  standardized log categories
- [PHP 8+ Type System Documentation](https://www.php.net/manual/en/language.types.declarations.php)
- [PSR Standards](https://www.php-fig.org/psr/)

- [UserSpice Framework Documentation](https://userspice.com/documentation/)
- [OWASP Secure Coding Practices](https://owasp.org/www-project-secure-coding-practices-quick-reference-guide/)

---

**Last Updated:** September 7, 2025  
**Next Review:** December 2025  
**Maintainer:** Elan Registry Development Team

This document is a living standard that evolves with the codebase and PHP 
ecosystem. All developers must follow these standards for new code and 
gradually apply them to legacy code during maintenance.
