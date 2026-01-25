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
$schemaManager = new EnhancedSchemaManager($db, $settings, (int)$user->data()->id);
$carId = (int)$dbRow->id;
$count = (int)$result->first()->total;

// ❌ WRONG - Missing cast in strict mode
$schemaManager = new EnhancedSchemaManager($db, $settings, $user->data()->id);
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

#### **Custom Exceptions**
Use specific, typed exceptions:

```php
// ✅ REQUIRED - Custom exception classes
class CarValidationException extends Exception
{
    public function __construct(
        string $message,
        public readonly array $validationErrors = [],
        int $code = 0,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }
}

class ImageProcessingException extends Exception {}
class CarCreationException extends Exception {}

```text

#### **Exception Usage Pattern**

```php
public function create(array $fields): bool
{
    try {
        $this->validate($fields);
        return $this->saveToDatabase($fields);
    } catch (ValidationException $e) {
        // Re-throw with context
        throw new CarCreationException(
            "Failed to create car: {$e->getMessage()}",
            previous: $e
        );
    } catch (DatabaseException $e) {
        // Log and re-throw
        $this->logger->error('Car creation failed', [
            'fields' => $fields,
            'error' => $e->getMessage()
        ]);
        throw new CarCreationException(
            'Database error during car creation',
            previous: $e
        );
    }
}

```text

### **Error Handling Patterns**

#### **Function Return Values**
Prefer exceptions over error return values:

```php
// ✅ PREFERRED - Exceptions for errors
public function processImage(string $filepath): ProcessedImage
{
    if (!file_exists($filepath)) {
        throw new ImageNotFoundException("File not found: {$filepath}");
    }
  
    // Process and return result
    return new ProcessedImage($result);
}

// ❌ AVOID - Mixed return types for errors  
public function processImage(string $filepath): ProcessedImage|false
{
    if (!file_exists($filepath)) {
        return false; // Ambiguous error handling
    }
  
    return new ProcessedImage($result);
}

```text

### **Method Naming and Structure**

#### **Method Naming Conventions**
- **Verbs**: `create()`, `update()`, `delete()`, `validate()`

- **Boolean methods**: `exists()`, `isValid()`, `hasPermission()`
- **Getters**: `data()`, `images()`, `history()` (not `getData()`)

```php
// ✅ GOOD - Clear, verb-based naming
public function updateChassis(string $chassis): bool
public function hasValidChassis(): bool  
public function validateChassisFormat(string $chassis): void

// ❌ POOR - Unclear or inconsistent naming
public function chassisUpdate($chassis)
public function checkChassis($chassis)
public function getChassisValidation($chassis)

```text

---

## 🛡️ **Security Standards**

### **Input Validation**
All user input **MUST** be validated and sanitized:

```php
public function updateColor(array &$cardetails): void
{
    $color = Input::get('color');
  
    // ✅ REQUIRED - Validate input
    if (empty($color) || strlen($color) > 50) {
        throw new ValidationException('Invalid color value');
    }
  
    // ✅ REQUIRED - Sanitize for output
    $cardetails['color'] = htmlspecialchars(trim($color), ENT_QUOTES, 'UTF-8');
}

```text

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

/FIX/                          # Administrative maintenance scripts

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

### **Database Optimization**

```php
// ✅ GOOD - Single query with JOIN
public function getCarWithOwner(int $carId): ?array
{
    return $this->db->query(
        'SELECT c.*, u.fname, u.lname
         FROM cars c
         JOIN users u ON c.user_id = u.id
         WHERE c.id = ?',
        [$carId]
    )->first();
}

// ❌ POOR - Multiple queries (N+1 problem)
public function getCarWithOwner(int $carId): ?array
{
    $car = $this->db->query('SELECT * FROM cars WHERE id = ?', [$carId])->first();
    $user = $this->db->query('SELECT * FROM users WHERE id = ?', [$car->user_id])->first();
    return array_merge($car, $user);
}

```text

### **Caching Patterns**

```php
// ✅ GOOD - Object caching to avoid repeated method calls
public function displayCarDetails(int $carId): void
{
    $car = new Car($carId);
  
    // Cache expensive operations
    $carData = $car->data();
    $factoryData = $car->factory();
    $carHistory = $car->history();
  
    // Use cached data in template
    $this->render('car-details', [
        'car' => $carData,
        'factory' => $factoryData,  
        'history' => $carHistory
    ]);
}

```text

### **Memory Management**

```php
// ✅ GOOD - Process large datasets in chunks
public function processLargeDataset(array $items): void
{
    $chunks = array_chunk($items, 100);
  
    foreach ($chunks as $chunk) {
        $this->processBatch($chunk);
  
        // Free memory between chunks
        unset($chunk);
  
        // Optional: Force garbage collection for very large datasets
        if (memory_get_usage() > self::MEMORY_THRESHOLD) {
            gc_collect_cycles();
        }
    }
}

```text

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
- [ ] Custom exceptions are used appropriately

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
