# Coding Standards for Elan Registry

**Updated:** September 7, 2025 | **Target:** PHP 8.2+

---

## PHP 8+ Requirements

All new files require `declare(strict_types=1)`, full type hints on all parameters/returns/properties, typed exceptions, and PHPDoc on all public methods.

### Strict Type Safety with Database Values

⚠️ **CRITICAL**: When using `declare(strict_types=1)`, database INTEGER columns may be returned as strings depending on PHP/MySQL configuration.

**Always cast database values explicitly when passing to strict-typed parameters:**

```php
// ✅ CORRECT - Explicit type casting
$backupManager = new BackupManager($db, $backupDir, (int)$user->data()->id);
$carId = (int)$dbRow->id;
$count = (int)$result->first()->total;

// ❌ WRONG - Missing cast in strict mode
$backupManager = new BackupManager($db, $backupDir, $user->data()->id);
// TypeError: Argument #3 ($userId) must be of type ?int, string given
```

**Common casts:**

```php
$userId = (int)$user->data()->id;   // integer columns
$isActive = (bool)$row->active;     // TINYINT boolean
$optionalId = $row->optional_id ? (int)$row->optional_id : null;
```

**Type helper functions** (preferred for object properties):

```php
$userId = dbInt($carData, 'user_id');  // throws on invalid input
$adminId = currentUserId();            // throws RuntimeException if not logged in
```

Defined in `usersc/includes/custom_functions.php`. Use `dbInt()` for PDO result objects; use `(int)` for simple scalars.

**Why**: PDO returns INT columns as strings on PHP 8.2/test but as int on PHP 8.3/dev. With strict types, `string ≠ int` — always cast explicitly.

**See also:** `/docs/development/STRICT_TYPE_HANDLING.md` for comprehensive strategy.

---

## Code Architecture

### Exception Handling

All exceptions extend `ElanRegistryException` (26 domain types). Never throw generic `Exception`. Each carries
an HTTP status code, log category, and separate technical/user-facing messages.
See [ERROR_HANDLING.md](ERROR_HANDLING.md#exception-hierarchy).

- All AJAX endpoints **MUST** use `ApiResponse` — factory methods: `success()`, `error()`, `validationError()`,
  `unauthorized()`, `forbidden()`, `notFound()`, `serverError()`. See [ERROR_HANDLING.md](ERROR_HANDLING.md#backend-error-handling).
- All `logger()` calls **MUST** use `LogCategories` constants (never hardcoded strings).
  Discover: `grep "const LOG_CATEGORY" usersc/classes/LogCategories.php`

### Method Naming

- **Verbs**: `create()`, `update()`, `delete()`, `validate()`
- **Boolean methods**: `exists()`, `isValid()`, `hasPermission()`
- **Getters**: `data()`, `images()`, `history()` (not `getData()`)

---

## Security Standards

### Input Handling and Output Encoding

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
- `Input::existsPost()` / `Input::existsGet()` → POST/GET presence checks in files that import
  `ElanRegistry\Input` — `ElanRegistry\Input::exists()` was removed in v2.26.1
- `\Input::get()` → legacy pattern only (value used directly in HTML, no further escaping)
- `htmlspecialchars()` → always at output (HTML templates, email templates)
- Parameterised queries handle SQL safety; encoding at storage is never a SQL defence
- `Input::raw()` second parameter is a **trim flag** (`bool $trim`), not a default value — use `Input::raw('field') ?? 'fallback'` to supply a default

### Database Operations

Always use parameterized queries — never string concatenation:

```php
// ✅ Parameterized
$query = $this->db->query('SELECT * FROM cars WHERE chassis = ? LIMIT 1', [$chassis]);

// ❌ Never do this
$query = "SELECT * FROM cars WHERE chassis = '{$chassis}'";
```

### CSRF Protection

All forms require a CSRF token. Validate with `Token::check(Input::get('csrf'))` before processing POST data.

### Error Logging Standards

All error conditions in web context **MUST** use `logger()` — never `error_log()`.
Use `$user->data()->id ?? 0` for the user ID. `error_log()` is allowed in CLI scripts only.

```php
logger($user->data()->id ?? 0, LogCategories::LOG_CATEGORY_SYSTEM_ERROR, 'Operation failed: ' . $e->getMessage());
```

All `logger()` calls **MUST** use `LogCategories` constants — never hardcoded strings. Discover available constants: `grep "const LOG_CATEGORY" usersc/classes/LogCategories.php`

---

## Documentation Standards

PHPDoc required on all classes and public methods: class summary, `@param`, `@return`, `@throws`.
Comments on complex logic only — never explain what the code obviously does.

---

## File Organization

### File Naming

- **Classes**: `PascalCase` — `Car.php`, `CarValidationException.php`, one class per file
- **Scripts/pages**: `snake_case` — `edit_car.php`, `send_email.php`
- **Partials**: `_partial-name.php`

See [CLAUDE.md](../../CLAUDE.md) for directory structure.

---

## Code Review Checklist

### Security

- [ ] Input validated and sanitized at system boundaries
- [ ] All DB queries parameterized
- [ ] CSRF token validated on all POST handlers
- [ ] No sensitive info in error messages or logs
- [ ] `securePage($php_self)` present on protected pages

### Code Quality

- [ ] `declare(strict_types=1)` present, full type hints on all signatures
- [ ] DB integer values cast with `(int)` or `dbInt()` before passing to typed params
- [ ] Typed exceptions extend `ElanRegistryException` (never generic `Exception`)
- [ ] AJAX endpoints use `ApiResponse`
- [ ] All `logger()` calls use `LogCategories` constants
- [ ] User-facing and technical messages separated in exceptions

### Documentation

- [ ] PHPDoc on all classes and public methods
- [ ] Complex logic commented; obvious code is not

---

## PHPStan Baseline Hygiene

The project runs a single `phpstan.neon` config at level 5. It analyses an
explicit list of project-owned paths (see the `paths` block in the config);
`users/` upstream and `tests/` are out of scope. Pre-existing errors are
captured in `phpstan-baseline.neon`.

**When you touch a file in `app/` or `usersc/`:** run PHPStan on it and fix
**all** errors it reports that are not already in the baseline. Then regenerate
the baseline to drop the entries you resolved.

```bash
# Check the file you modified
vendor/bin/phpstan analyse app/api/cars/save.php

# After fixing, remove the resolved baseline entries
composer phpstan:baseline
```

`reportUnmatchedIgnoredErrors: true` enforces this mechanically: if you fix an
error but leave its baseline entry, CI fails. The only safe path is to fix the
error and regenerate.

The goal is to reduce the baseline over time. Target: below 100 entries → upgrade
to level 8 (nullable property/method access checks).

---

## References

- [ERROR_HANDLING.md](ERROR_HANDLING.md) — ApiResponse, exception hierarchy, ElanRegistryAPI
- [LOG_CATEGORIES.md](LOG_CATEGORIES.md) — 140+ standardized log category constants
- [STRICT_TYPE_HANDLING.md](STRICT_TYPE_HANDLING.md) — DB value casting strategy
