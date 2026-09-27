# ElanRegistry overrides for UserSpice skills

Read this file before you use `userspice-audit`, `userspice-helper-lookup`, or
`userspice-page-scaffold` in this repository. It lists the points where
ElanRegistry diverges from stock UserSpice. **A project override always wins
over a skill's default UserSpice guidance.**

The full detail lives in two places. Read them when you need more than the
one-line rule below:

- `usersc/plugins/ai_prompts/custom_prompts/elanregistry_overrides.md.php`
- The project's `CLAUDE.md` (repository root)

Both files are markdown behind a `.md.php` security wrapper or a plain `.md`
file. Read the whole file. Do not rely on headings alone.

## Rules

**Input for database storage.** Use `ElanRegistry\Input::raw()`, never
`\Input::get()`. `\Input::get()` runs `htmlspecialchars()` on the way in. A
value stored that way and escaped again at render time shows `&amp;` in
place of `&`. `\Input::get()` is still correct for a value used directly in
HTML output with no further escaping. In a file that imports
`ElanRegistry\Input`, use `Input::existsPost()` / `Input::existsGet()`, not
`Input::exists('post')` — the wrapper removed `exists()` in v2.26.1.

**Server values.** Never read `$_SERVER` or call `Server::get()` /
`Server::getClientIp()` in application code. `usersc/includes/server_globals.php`
sets validated globals on every request: `$php_self`, `$is_https`, `$host`,
`$method`, `$request_uri`, `$current_url`, `$current_origin`, `$remote_addr`
(already Cloudflare-resolved), `$referer`, `$user_agent`. Use
`securePage($php_self)`, not `securePage(Server::get('PHP_SELF'))` or
`securePage($_SERVER['PHP_SELF'])`.

**AJAX responses.** Use the `ApiResponse` class for every endpoint under
`app/api/`, not raw `json_encode()`. Factory methods: `success()`, `error()`,
`validationError()`, `unauthorized()`, `forbidden()`, `notFound()`,
`serverError()`. Builder methods: `->withData()`, `->withDataArray()`,
`->withLogging()`, `->send()`.

**Logging.** Call `logger($userId, LogCategories::LOG_CATEGORY_*, $message)`.
Always pass a `LogCategories` constant. Never pass a raw string.

**AJAX endpoint location.** ElanRegistry does not use a `parsers/` folder.
Endpoints live under `app/api/`, grouped by domain: `app/api/cars/`,
`app/api/contact/`, `app/api/shared/`, `app/api/admin/`. Only
`app/api/contact/` calls `securePage()` and appears in the `$path` array in
`z_us_root.php`. The other `app/api/*` endpoints must NOT call `securePage()`
— they check authentication and permission inline — and must NOT be added to
`$path`. Do not flag a missing `securePage()` call in `app/api/cars/`,
`app/api/shared/`, or `app/api/admin/` as an authentication gap without first
reading the endpoint's own inline check.

**`$path` in `z_us_root.php`.** Add a new directory only when it holds a file
that calls `securePage()`. A pure API, action-handler, or partial directory
stays out of `$path` even if it sits under `app/`.

**Page metadata.** A page file that calls `securePage()` and renders a page
(not an action handler or API endpoint) should set `$pageTitle` and
`$pageDescription` as hardcoded string literals, before the `require_once`
that loads `init.php`. Setting them after has no effect — the loader reads
`isset($pageTitle)` once. Never build `$pageTitle` from request or database
data — it renders into `<title>` without `htmlspecialchars()`.

**Page registration.** After adding a page or admin script that calls
`securePage()`, run `app/admin/scripts/maintenance/21-Fix-Page-Permissions.php`
on test, then production, to register the new path in UserSpice's permission
table.

**Upstream directories — do not edit.** `users/` is framework code. Do not
change it outside the `.env`-backed `$GLOBALS['config']` block in
`users/init.php`. `usersc/templates/` and `usersc/plugins/` hold upstream
files too. The project-owned exceptions are:

| Directory | Project-owned files |
| --- | --- |
| `usersc/templates/customizer/` | `file_nav_custom.php`, `navigation.php` (tracked, do not edit — UserSpice requires it), the `elanregistry*` and `dashboard.php` child themes, `customizer.css` |
| `usersc/plugins/` | `hooker/hooks/`, `ai_prompts/custom_prompts/` |
| `usersc/user_settings.php` | the whole file — it overrides `users/user_settings.php`, which stays untouched |

A finding inside any other path under `usersc/templates/` or `usersc/plugins/`
is an upstream-code finding, not a project one — see the audit skill's
handling of upstream paths.

**`verify_car.php` has no CSRF token by design.** It authenticates a single-use
vericode carried in the URL, not a UserSpice session, so there is no session
to bind a CSRF token to. Treat a missing CSRF check on this file (and on
`app/api/webhooks/brevo.php`, which has the same no-session shape) as
expected, not a finding. Read the file's own header comment before reporting
anything about it.

**PHPStan and strict types.** Every new PHP file starts
`declare(strict_types=1);` and uses full type hints. After changing a PHP
file under `app/`, `usersc/`, or another path in `phpstan.neon`, run
`vendor/bin/phpstan analyse <file>` and fix everything it reports — the
baseline only hides pre-existing errors.
