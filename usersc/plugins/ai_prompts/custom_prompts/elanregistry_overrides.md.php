<?php /* UserSpice AI Prompt — protected from HTTP access. Markdown content below. */ __halt_compiler(); ?>

# ElanRegistry — Where We Diverge from Standard UserSpice

Read the shipped UserSpice prompts first (`00_start_here`, `secure_page_pattern`, etc.).
This file documents the **six places where ElanRegistry does things differently**.
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

### POST/GET presence checks: `existsPost()` / `existsGet()`

The shipped prompts show `Input::exists('post')` / `Input::exists('get')`. In files that
import `ElanRegistry\Input`, use the typed replacements instead:

```php
// ✅ CORRECT in ElanRegistry files
use ElanRegistry\Input;

if (Input::existsPost()) { ... }          // "did a POST request arrive?"
if (Input::existsGet()) { ... }           // "are there any query params?"

// Key-specific form (isset check on the specific key):
if (Input::existsPost('csrf')) { ... }    // "is 'csrf' present in $_POST?"

// ❌ WRONG in files with `use ElanRegistry\Input` — calls removed ElanRegistry method
if (Input::exists('post')) { ... }
```

`\Input::exists('post')` (the UserSpice upstream) still works in files that do **not**
import `ElanRegistry\Input`, but any file using the ElanRegistry wrapper must call
`existsPost()` / `existsGet()` — the `exists()` method was removed from the wrapper in v2.26.1.

---

## 2. Server variables: use validated globals, not `$_SERVER` directly

The shipped `secure_page_pattern` shows `securePage($_SERVER['PHP_SELF'])`.
In ElanRegistry, `$_SERVER` is never accessed directly in page code.

`usersc/includes/server_globals.php` runs on every request and exposes validated,
sanitized versions of the most-used server values as plain globals.

| Instead of `$_SERVER[...]` | Use this global |
| --- | --- |
| `$_SERVER['PHP_SELF']` | `$php_self` |
| `$_SERVER['HTTPS']` | `$is_https` / `$scheme` |
| `$_SERVER['HTTP_HOST']` | `$host` |
| `$_SERVER['REQUEST_METHOD']` | `$method` |
| `$_SERVER['CONTENT_LENGTH']` | `$content_length` (int, 0 when missing) |
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
ElanRegistry uses the `ApiResponse` class for all AJAX endpoints. It enforces the standard
response format (`{success, message, ...data}`), sets the correct HTTP status code, and handles logging atomically.

```php
// ✅ CORRECT — use ApiResponse
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

## 4. PHPStan: fix all errors in files you touch

`phpstan.neon` runs level 5 static analysis over all project-owned PHP files.
Pre-existing errors are captured in `phpstan-baseline.neon`, but `reportUnmatchedIgnoredErrors: true`
means CI **rejects stale baseline entries** — once you fix an error, its entry must be removed.

**Rule:** whenever you modify a PHP file in `app/`, `usersc/`, or any other path covered by
`phpstan.neon`, run PHPStan on it and fix **all** errors it reports before committing. The
baseline silently suppresses pre-existing errors — anything PHPStan reports is new. Then
regenerate the baseline to drop the entries you resolved.

```bash
vendor/bin/phpstan analyse app/api/cars/save.php   # check the file you modified
composer phpstan:baseline                            # drop entries you just fixed
```

This is the "fix-when-you-touch-it" workflow: never leave a file with more errors than it had, and treat each touch as a chance to clear its existing debt.

See `docs/development/CODING_STANDARDS.md` — PHPStan Baseline Hygiene.

---

## 5. PHP type requirements: strict types and typed signatures everywhere

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

---

## 6. Page titles and meta descriptions: set `$pageTitle` and `$pageDescription` before init.php

Every page file should override the generic site-wide `<title>` and meta description with page-specific values.
ElanRegistry pages set two variables, `$pageTitle` and `$pageDescription`. They populate the HTML `<title>` tag
and the social-share metadata (og:title, twitter:title, og:description, twitter:description).

**Rule:** Every page file (any file that calls `securePage()` and renders a page, not action handlers or API
endpoints) assigns `$pageTitle` and `$pageDescription` as string literals at the top, before
`require_once '.../init.php'`. The PHPStan rule `PageMetadataBeforeInitRule` enforces only the order: each
variable has an assignment before init. It does not check that the value is a string literal.

```php
// ✅ CORRECT — set before init.php
$pageTitle = 'Descriptive Title — Key Terms';
$pageDescription = 'One or two sentences summarizing the page for search snippets and social-share previews.';

require_once '../../users/init.php';
```

**Why before init:** `users/includes/loader.php` runs when the page requires init.php. It checks
`isset($pageTitle)` once. If the variable does not exist, the loader sets a fallback title from
`$pageTitleKey` or the `pages` table, or an empty string. The value that the page sets before init is the
page's fallback.

**When the values are read:** the header reads `$pageTitle` and `$pageDescription` at render time, not at init
time. `users/includes/template/header1_must_include.php` first requires `usersc/includes/head_tags.php`, which
reads both variables for the meta and social tags, and later echoes `$pageTitle` into `<title>`. Both files run inside
the page's second require, `require_once '.../elanregistry_prep.php'`
(→ `users/includes/template/prep.php` → the active template's `header.php` →
`header1_must_include.php` → `head_tags.php`). So an assignment after init and
before `elanregistry_prep.php` changes the rendered values. An assignment after `elanregistry_prep.php` has no
effect.

**Per-record titles:** a page may assign `$pageTitle` and `$pageDescription` again after init and before
`elanregistry_prep.php`, when the values depend on a loaded record. Keep the before-init literal as the
fallback. Obey these rules:

- Build the value with a closed-set builder. The builder uses only validated fields (for example a year in a
  fixed range, a model from a fixed list, a chassis number that matches a strict pattern). It emits no
  HTML-special characters (`<`, `>`, `"`, `'`, `&`).
- Never put request data or owner free text (nickname, notes, color) in the value.
- Escape `$pageTitle` with `htmlspecialchars()` in the page. The upstream header echoes `$pageTitle` **without**
  escaping (unlike every other use of these two variables). For the builder's character set the escape changes
  nothing, so `head_tags.php` does not double-encode `og:title`.

```php
// app/owner/cars/details.php
$pageTitle = 'Car Details';                 // fallback, before init
// ...
require_once '../../../users/init.php';
// securePage(), load the car, redirect when it does not exist
$pageTitle = htmlspecialchars(CarView::buildPageTitle($carData, $modelKnown), ENT_QUOTES, 'UTF-8');
$pageDescription = CarView::buildPageDescription($carData, $modelKnown);
require_once $abs_us_root . $us_url_root . 'usersc/includes/elanregistry_prep.php';
```

**Never assign `$pageTitle` from `$_GET`, `$_POST`, or a raw DB column.** That opens an XSS hole in the
`<title>` tag. `docs/pdf-viewer.php` sets a per-document title from a closed dictionary before init. That is
the other approved pattern.

**Actionable instruction:** Whenever a change touches a page file (a file that calls `securePage()` to render
a page, not an action handler or parser), check whether it already sets `$pageTitle` and `$pageDescription`.
If it does not, add them as string literals before init as part of the PR's scope. This improves SEO, search
previews, and social-media sharing for every page.

See `docs/reference/paint-colors.php` for the standard example. Issue #1432 rolled out this pattern to 11 pages.

**`$pageCanonicalUrl` sets the canonical URL.** `head_tags.php` writes one value to `<link rel="canonical">`,
`og:url`, and `twitter:url`. The default is the origin plus the script path, with no query string. A final
`index.php` segment becomes its directory (`/app/owner/cars/index.php` → `/app/owner/cars/`). So tracking
parameters and crafted query strings never reach the canonical.

A page sets `$pageCanonicalUrl` before `elanregistry_prep.php` only when a query parameter identifies the
resource. Build it with `ElanRegistry\CanonicalUrl::build()` from validated values only:

```php
// app/owner/cars/details.php — car_id identifies the car
$pageCanonicalUrl = CanonicalUrl::build($current_origin, $php_self, ['car_id' => (int) $carID]);
```

To find the pages that set it, run `grep -rn 'pageCanonicalUrl =' app docs`. Every other page uses the
default. A login-gated or `noindex` page that reads query parameters keeps the default canonical. Do not set it
from `$current_url` or `$request_uri`. They include the raw query string.

**A third variable, `$pageRobots`, follows the same before-`init.php` convention** (introduced in #1371) for
pages that should carry a non-default `<meta name="robots">` value — e.g. `$pageRobots = 'noindex, follow';`
on pages that are public but not search destinations (`app/owner/cars/factory.php`, `app/owner/privacy.php`).
Unlike `$pageTitle`, `$pageRobots` isn't subject to loader.php's one-time `isset()` check — `head_tags.php`
just reads its live value with a plain `!empty($pageRobots) ? $pageRobots : 'index, follow'` fallback (same
style as the existing `$pageDescription` fallback). The mechanism differs from `$pageTitle`, but the
before-`init.php` requirement still applies in practice: `head_tags.php` doesn't render as part of `init.php`
itself — it's reached via the page's *second* require, `require_once '.../elanregistry_prep.php'`
(the include chain is in "When the values are read" above). Every page in
this convention issues that require immediately after `init.php`, so setting `$pageRobots` any time before it
— same as `$pageTitle`/`$pageDescription` — is what actually matters; "before `init.php`" is the simple,
safe-by-construction rule to follow given that adjacency.
