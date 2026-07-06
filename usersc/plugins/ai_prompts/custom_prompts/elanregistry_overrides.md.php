<?php /* UserSpice AI Prompt — protected from HTTP access. Markdown content below. */ __halt_compiler(); ?>

# ElanRegistry — Where We Diverge from Standard UserSpice

Read the shipped UserSpice prompts first (`00_start_here`, `secure_page_pattern`, etc.).
This file documents the **four places where ElanRegistry does things differently**.
When the shipped prompts and this file conflict, **this file wins**.

---

## 1. Input: use `ElanRegistry\Input::raw()`, not `\Input::get()` for stored values

The shipped `secure_page_pattern` shows `Input::get()` being used to read POST values before
writing them to the database. **Do not do this in ElanRegistry.**

UserSpice's `\Input::get()` applies `htmlspecialchars()` before returning. If you store that
encoded value and then escape it again at render time, the user sees `&amp;` instead of `&`.

**Rule:** read POST values for storage with `ElanRegistry\Input::raw()`; escape only at output.

```php
// ✅ CORRECT — plain text stored, escaped at render
use ElanRegistry\Input;

$color = Input::raw('color');           // returns raw POST value, no encoding
$db->insert('cars', ['color' => $color]);

// In template:
<?= htmlspecialchars($row->color, ENT_QUOTES, 'UTF-8') ?>

// ❌ WRONG — double-encoding bug
$color = \Input::get('color');          // already HTML-encoded by UserSpice
$db->insert('cars', ['color' => $color]); // stored as &amp; etc.
```

`\Input::get()` is still fine for values used **directly in HTML output** without further
escaping (the UserSpice convention). For anything going to the database, always use
`ElanRegistry\Input::raw()`.

See `docs/development/CODING_STANDARDS.md` — Input Handling and Output Encoding.

---

## 2. Server variables: use validated globals, not `$_SERVER` directly

The shipped `secure_page_pattern` shows `securePage($_SERVER['PHP_SELF'])`.
In ElanRegistry, `$_SERVER` is never accessed directly in page code.

`usersc/includes/server_globals.php` runs on every request and exposes validated,
sanitized versions of the most-used server values as plain globals.

| Instead of `$_SERVER[...]` | Use this global |
|---|---|
| `$_SERVER['PHP_SELF']` | `$php_self` |
| `$_SERVER['HTTPS']` | `$is_https` / `$scheme` |
| `$_SERVER['HTTP_HOST']` | `$host` |
| `$_SERVER['REQUEST_METHOD']` | `$method` |
| `$_SERVER['REQUEST_URI']` | `$request_uri` |
| `$_SERVER['REMOTE_ADDR']` | `$remote_addr` (Cloudflare-aware) |
| `$_SERVER['HTTP_REFERER']` | `$referer` |

```php
// ✅ CORRECT
if (!securePage($php_self)) { die(); }

// ❌ WRONG — raw $_SERVER, skip in ElanRegistry
if (!securePage($_SERVER['PHP_SELF'])) { die(); }
```

`$remote_addr` is already resolved through Cloudflare's proxy headers — do not call
`Server::getClientIp()` with Cloudflare CIDR lists; the global already handles that.

See `docs/development/PAGE_LOADING_FLOW.md` for the full global list and initialization details.

---

## 3. AJAX responses: use `ApiResponse`, not raw `json_encode`

The shipped `secure_page_pattern` shows bare `json_encode(['success' => false, ...])` in parsers.
ElanRegistry uses the `ApiResponse` class for all AJAX endpoints. It enforces Pattern A
(`{success, message, ...data}`), sets the correct HTTP status code, and handles logging atomically.

```php
// ✅ CORRECT — Pattern A via ApiResponse
use ElanRegistry\Exceptions\CarNotFoundException;

require_once $abs_us_root . $us_url_root . 'users/init.php';
header('Content-Type: application/json');

if (!Token::check(Input::get('csrf'))) {
    ApiResponse::forbidden('Invalid CSRF token.')->send();
}
if (!securePage($php_self)) { exit; }

try {
    $car = new Car((int)Input::get('id'));
    ApiResponse::success('Car loaded.')
        ->withData('car', $car->data())
        ->send();
} catch (CarNotFoundException $e) {
    ApiResponse::notFound($e->getUserMessage())->send();
} catch (\Exception $e) {
    ApiResponse::serverError('An unexpected error occurred.')
        ->withLogging($userId, LogCategories::LOG_CATEGORY_SYSTEM_ERROR, $e->getMessage())
        ->send();
}

// ❌ WRONG — raw json_encode, no logging, no status code
echo json_encode(['success' => false, 'message' => 'Not found.']);
```

`ApiResponse` factory methods: `success()`, `error()`, `validationError()`,
`unauthorized()`, `forbidden()`, `notFound()`, `serverError()`.
Builder methods: `->withData()`, `->withDataArray()`, `->withLogging()`, `->send()`.

See `docs/development/ERROR_HANDLING.md` for complete usage and the ElanRegistryAPI
frontend client that pairs with these responses.

---

## 4. PHP type requirements: strict types and typed signatures everywhere

The shipped prompts show untyped function signatures throughout. ElanRegistry requires
PHP 8.2+ strict typing on all new files.

```php
// ✅ REQUIRED in every new PHP file
<?php
declare(strict_types=1);

// ✅ REQUIRED — full type hints
public function findByChassis(string $chassis): ?array { ... }

// ❌ NOT ALLOWED — untyped
public function findByChassis($chassis) { ... }
```

**Database integer cast pitfall with `declare(strict_types=1)`:** PDO may return INT columns
as strings depending on PHP/MySQL configuration. Cast explicitly:

```php
$carId = (int)$row->id;                // simple scalars
$userId = dbInt($row, 'user_id');      // object properties (throws on invalid)
```

`dbInt()` is defined in `usersc/includes/custom_functions.php`.

See `docs/development/CODING_STANDARDS.md` and `docs/development/STRICT_TYPE_HANDLING.md`.
