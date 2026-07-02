# Strict Type Handling Strategy

## Problem Statement

When using `declare(strict_types=1)`, PHP enforces strict type checking.
However, PDO/mysqli can return database INTEGER columns as strings
depending on:

- PHP version (8.1+ changed default behavior)
- PDO driver configuration
- MySQL driver (mysqlnd vs libmysqlclient)

This causes `TypeError` when passing database values to strict-typed function parameters.

## Current Status

**Files affected:** 30 files use `declare(strict_types=1)`

**Known issues fixed:**

- `BackupManager::__construct(?int $userId)` - Fixed with explicit cast
- Type helper functions (`dbInt()`, `dbIntOrNull()`, `currentUserId()`) added to custom_functions.php

## Type Helper Functions (v2.14.0+)

Three helper functions in `usersc/includes/custom_functions.php` provide safe PDO string-to-int conversion:

**`dbInt(mixed $value, string $property = 'id'): int`**

Extracts an integer value from a database object or scalar. Throws `InvalidArgumentException` if the value cannot be converted to a non-zero integer.

```php
// Extract int from database result object
$userId = dbInt($carData, 'user_id');
$carId = dbInt($row, 'id');

// Error cases - throws InvalidArgumentException
dbInt($row, 'null_field');      // null value
dbInt($row, 'zero_field');      // zero value
dbInt($row, 'invalid_field');   // non-numeric string
```

**`dbIntOrNull(mixed $value, string $property = 'id'): ?int`**

Same as `dbInt()` but returns null for empty/null values instead of throwing an exception. Throws `InvalidArgumentException` only on non-numeric, non-null values.

```php
// Nullable variant - returns null for empty/null
$optionalId = dbIntOrNull($row, 'optional_id');
$managerId = dbIntOrNull($carData, 'manager_id');

// Returns null for null/empty
dbIntOrNull($row, 'null_field');     // Returns null
dbIntOrNull($row, 'zero_field');     // Returns null

// Error case - throws InvalidArgumentException
dbIntOrNull($row, 'invalid_field');  // non-numeric string
```

**`currentUserId(): int`**

Shorthand for `(int) $user->data()->id` with built-in login check. Throws `RuntimeException` if no user is logged in.

```php
// Current user ID shorthand
$adminId = currentUserId();
$loggedInUser = currentUserId();

// Error case - throws RuntimeException
currentUserId();  // When not logged in
```

**Usage Examples:**

Before:

```php
$userId = getUserWithProfile($carData->user_id);
$carId = (int) $row->id;
$createdById = (int) $user->data()->id;
```

After:

```php
$userId = getUserWithProfile(dbInt($carData, 'user_id'));
$carId = dbInt($row, 'id');
$createdById = currentUserId();
```

These helpers ensure safe type conversion with explicit error handling, replacing scattered `(int)` casts with semantic intent.

## Systematic Solutions

### Solution 1: Database Layer Type Casting (Recommended)

**Extend the DB class to handle type casting automatically:**

```php
// In users/classes/DB.php - add after query() method

/**
 * Query with automatic type casting for strict type safety
 * Returns results with properly typed values based on column metadata
 */
public function queryTyped($sql, $params = array())
{
    $result = $this->query($sql, $params);

    if ($result->count() > 0 && $this->_query->columnCount() > 0) {
        // Get column metadata
        $columns = [];
        for ($i = 0; $i < $this->_query->columnCount(); $i++) {
            $meta = $this->_query->getColumnMeta($i);
            $columns[$meta['name']] = $meta;
        }

        // Cast types for each result
        $typedResults = [];
        foreach ($this->_results as $row) {
            $typedRow = clone $row;
            foreach ($row as $key => $value) {
                if (isset($columns[$key])) {
                    $typedRow->$key = $this->castValue($value, $columns[$key]);
                }
            }
            $typedResults[] = $typedRow;
        }
        $this->_results = $typedResults;
    }

    return $this;
}

/**
 * Cast a value based on PDO column metadata
 */
private function castValue($value, array $metadata)
{
    if ($value === null) {
        return null;
    }

    $pdoType = $metadata['pdo_type'] ?? PDO::PARAM_STR;
    $nativeType = $metadata['native_type'] ?? '';

    // Cast based on native MySQL type
    switch ($nativeType) {
        case 'TINY':
        case 'SHORT':
        case 'LONG':
        case 'LONGLONG':
        case 'INT24':
            return (int)$value;

        case 'FLOAT':
        case 'DOUBLE':
        case 'DECIMAL':
        case 'NEWDECIMAL':
            return (float)$value;

        default:
            return $value;
    }
}
```

**Usage:**

```php
// Use queryTyped() for strict-typed code
$result = $db->queryTyped("SELECT id, user_id FROM cars WHERE id = ?", [$carId]);
$car = $result->first();
// $car->id is now int, not string
```

### Solution 2: Value Object/DTO Pattern

Create typed data transfer objects:

```php
class UserData {
    public function __construct(
        public readonly int $id,
        public readonly string $username,
        public readonly string $email,
        // ... other properties
    ) {}

    public static function fromDatabase(object $dbRow): self {
        return new self(
            id: (int)$dbRow->id,
            username: (string)$dbRow->username,
            email: (string)$dbRow->email,
            // ... other properties with explicit casts
        );
    }
}
```

### Solution 3: Coding Standards & Linting

**Add to pre-commit hook:**

```bash
# Check for database values passed to strict-typed parameters
# Flag patterns like: new ClassName($db->query()->first()->id)
# Without explicit (int) cast
```

**Document in CODING_STANDARDS.md:**

See `/docs/development/CODING_STANDARDS.md` for the complete strict type
safety guidelines, including:

- Always cast database integers explicitly with `(int)`, `(float)`, `(bool)`
- Use `queryTyped()` method for strict-typed code
- PDO configuration options (may not work on all servers)
- Examples of correct vs incorrect usage patterns

### Solution 4: Static Analysis

Add PHPStan/Psalm configuration to detect type mismatches:

```neon
# phpstan.neon
parameters:
    level: 6
    paths:
        - app
        - usersc/classes

    # Detect when database strings are passed to int parameters
    checkMissingTypehints: true
    checkExplicitMixedMissingReturn: true
```

## Recommended Implementation Plan

1. **Short-term (Completed):** Add explicit casts where needed ✅
   - Type helper functions (`dbInt()`, `dbIntOrNull()`, `currentUserId()`) available for object properties
2. **Medium-term:** Extend DB class with `queryTyped()` method
3. **Long-term:** Migrate to typed DTOs for critical data
4. **Ongoing:** Add linting rules to pre-commit hook

## Testing Strategy

Create test to verify type handling:

```php
// tests/unit/database/TypeHandlingTest.php
public function testDatabaseReturnsProperTypes(): void
{
    $db = DB::getInstance();
    $result = $db->queryTyped("SELECT id FROM users LIMIT 1");
    $user = $result->first();

    $this->assertIsInt($user->id, 'User ID should be int, not string');
}
```

## Environment Differences

**Why dev works but test fails:**

- Dev (PHP 8.3.14): PDO returns integers natively
- Test (PHP 8.2.29): PDO returns strings

**PDO configuration attempted:**

```php
'options' => [
    // Returns int instead of string
    PDO::ATTR_STRINGIFY_FETCHES => false,
]
```

This works on dev but not on test (server config may override).

## Conclusion

The safest approach is **explicit type casting** combined with extending
the DB class for better developer experience. This ensures compatibility
across all environments regardless of PHP/MySQL configuration.
