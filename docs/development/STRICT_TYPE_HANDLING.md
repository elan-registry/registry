# Strict Type Handling Strategy

## Problem Statement

When using `declare(strict_types=1)`, PHP enforces strict type checking.
However, PDO can return database INTEGER columns as strings or integers,
depending on:

- PDO driver configuration
- MySQL driver and fetch configuration

This causes `TypeError` when passing database values to strict-typed function parameters.

## Current Status

The number of files that use `declare(strict_types=1)` changes over time. To
count them, run:

```bash
rg -l 'declare\s*\(\s*strict_types\s*=\s*1\s*\)' --glob '*.php' | wc -l
```

**Known issues fixed:**

- `BackupManager::__construct(?int $userId)` - Fixed with explicit cast
- Type helper functions (`dbInt()`, `currentUserId()`) added to custom_functions.php

## Type Helper Functions (v2.14.0+)

Two helper functions in `usersc/includes/custom_functions.php` provide safe PDO string-to-int conversion:

**`dbInt(mixed $value, string $property = 'id'): int`**

Extracts an integer value from a database object or scalar. Throws
`InvalidArgumentException` if the value is missing, empty, or non-numeric —
zero is a valid result, not an error. The conversion logic lives in
`ElanRegistry\TypeHelpers::toInt()` (`usersc/classes/TypeHelpers.php`);
`dbInt()` is a thin global wrapper for call-site convenience.

```php
// Extract int from database result object
$userId = dbInt($carData, 'user_id');
$carId = dbInt($row, 'id');

// Valid - zero is not an error
dbInt($row, 'zero_field');      // returns 0

// Error cases - throws InvalidArgumentException
dbInt($row, 'null_field');      // null value
dbInt($row, 'empty_field');     // empty string
dbInt($row, 'invalid_field');   // non-numeric string
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
$owner = new Owner($carData->user_id);
$carId = (int) $row->id;
$createdById = (int) $user->data()->id;
```

After:

```php
$owner = new Owner(dbInt($carData, 'user_id'));
$carId = dbInt($row, 'id');
$createdById = currentUserId();
```

These helpers ensure safe type conversion with explicit error handling, replacing scattered `(int)` casts with semantic intent.

See [CODING_STANDARDS.md](CODING_STANDARDS.md) for the full strict type safety guidelines and casting rules.
