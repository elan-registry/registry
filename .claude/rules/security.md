---
paths:
  - "**/*.php"
  - "app/assets/js/**"
---

# Security checks

Follow the "Security Standards" section of `docs/development/CODING_STANDARDS.md`.
This file lists the checks for a security review.

## When you review security

### SQL

- Use `$db->query()` with bound parameters. Never build SQL with
  concatenation or interpolation.
- Never put `$_GET`, `$_POST`, or `$_REQUEST` values in a query.

```php
// Correct: bound parameter
$db->query('SELECT * FROM cars WHERE id = ?', [$carId]);

// Wrong: request value in the SQL string
$db->query("SELECT * FROM cars WHERE id = {$_GET['id']}");
```

### XSS

- Escape HTML output with `htmlspecialchars()` and `ENT_QUOTES`.

### CSRF

- Add a `Token::generate()` value to each form.
- Run `Token::check()` on every POST request. This includes AJAX endpoints.

### Input

- Validate input with PHP type hints, `filter_input()`, or `filter_var()`.

### Auth

- Call `securePage($php_self)` on protected pages. Session handling follows
  UserSpice.
- UserSpice hashes passwords with bcrypt.

### Server globals

- Do not read `$_SERVER` directly. Use the server globals. See `php-code.md`.

### AJAX endpoints

- Return `ApiResponse::success()` or `ApiResponse::error()`.
- Check `$method === 'POST'` for each state change.
- Do not put sensitive data in error responses.

### Errors

- Use typed exceptions and `LogCategories` constants. See `ERROR_HANDLING.md`.
