# UserSpice audit checklist

Read this file completely before auditing. Apply only the rules below. Search patterns are candidate generators; inspect surrounding executable code before reporting a finding.

## Contents

- Rules 02–06: request input, CSRF, SQL, server values, and output escaping
- Rules 07–12: redirects, cryptography, validation, rate limiting, globals, and AJAX endpoints
- Database convention
- False-positive guards
- Reporting contract

## Rule 02 — Input::get over raw request arrays (HIGH)

- Flag direct `$_POST[...]`, `$_GET[...]`, or `$_REQUEST[...]` access outside framework/bootstrap code.
- Do not flag `Validate->check($_POST, ...)`; passing the whole array to the validator is idiomatic.
- Treat rich-text editor fields as a contextual exception with LOW severity. Summernote, TinyMCE, CKEditor, ProseMirror, Quill, and similar editors intentionally submit raw HTML. Replacing that access with `Input::get()` can double-encode saved content.
- Raw `php://input` and `$_FILES` are outside this rule.
- Default fix: `Input::get('field')` or `Input::exists()`.
- **Project override:** in a repository with an `ElanRegistry\Input` class
  (check `usersc/plugins/ai_prompts/custom_prompts/elanregistry_overrides.md.php`),
  the default fix for a value bound for the database is
  `ElanRegistry\Input::raw('field')`, not `\Input::get()` — `\Input::get()`
  HTML-encodes on input, and storing that encoded value causes double
  encoding at render time. Recommend `\Input::get()` only for a value used
  directly in HTML output with no further escaping. See the overrides file
  for the full rule and the `existsPost()`/`existsGet()` replacement for
  `Input::exists()` in files that import the wrapper.

For brownfield applications, do not mechanically replace raw input on an existing save path. New rows may become HTML-encoded while old rows remain raw. Prefer fixing the matching display path with `safeReturn()` or `trustedHtml()` as appropriate, and explain any migration cost. Greenfield code may validate input and escape output.

## Rule 03 — CSRF tokens (HIGH)

- The canonical field name is `csrf`, not `csrf_token`.
- Flag a state-changing POST form with no CSRF field or `tokenHere()` call.
- Flag a POST handler that mutates state without `Token::check(...)`.
- Preferred form fix: `<?= tokenHere(); ?>`.
- Preferred handler fix: `if (!Token::check(Input::get('csrf'))) { ... }`.

## Rule 04 — SQL parameter binding (HIGH)

- Flag user-controlled variables concatenated or interpolated into `$db->query(...)` SQL, including SQL built with `sprintf`.
- Flag user-controlled `ORDER BY`, table names, or other identifiers without a strict whitelist.
- Flag user-controlled `LIMIT` or `OFFSET` without an integer cast.
- Use placeholders for values: `$db->query("... WHERE x = ?", [$value])`.
- Whitelist identifiers with strict `in_array`; cast limits and offsets to integers before interpolation.
- Do not flag constants such as `TABLE_PREFIX` merely because they are concatenated.

## Rule 05 — Server request helpers (MEDIUM)

- Flag direct `$_SERVER[...]` access in application code outside `users/`.
- Use `Server::get('KEY', $default)`, `Server::getClientIp($trustedProxies)`, `Server::getHost($trustedProxies)`, or `Server::getOrigin($trustedProxies)` as appropriate.
- **Project override:** in a repository with `usersc/includes/server_globals.php`,
  the fix is the matching validated global (`$php_self`, `$is_https`, `$host`,
  `$method`, `$request_uri`, `$current_url`, `$current_origin`, `$remote_addr`,
  `$referer`, `$user_agent`), not a `Server::` call. `$remote_addr` is already
  Cloudflare-resolved — do not recommend `Server::getClientIp()` alongside it.

## Rule 06 — Output escaping (MEDIUM)

Select the helper by content type and verify its live signature first:

| Helper | Use for |
|---|---|
| `safeReturn($value)` | Plain text such as names, emails, and titles |
| `safeReturn($value, true)` | Plain text already encoded once on input; recommend only if this overload exists in the installed version |
| `trustedHtml($value)` | Intentionally rendered trusted HTML or parsed Markdown |
| `safeJsonEncodeForJs($value)` | Values embedded inside script blocks |

- Flag raw PHP output such as `<?= $var ?>` when the value is not demonstrably safe.
- Treat `htmlspecialchars()` as functional but noncanonical; recommend the matching UserSpice helper without overstating severity.
- Do not replace intentional rich HTML rendered through `trustedHtml()` with `safeReturn()`.
- **Project override:** a repository may require a JSON response wrapper
  class (for example, `ApiResponse`) for every AJAX endpoint instead of raw
  `json_encode()`, and a logging-category constants class (for example,
  `LogCategories`) for every `logger()` call instead of a raw string. Flag a
  raw `json_encode()` response or a raw-string `logger()` category as a
  MEDIUM finding when the project's overrides file names such a requirement,
  and cite the required class in the fix.

## Rule 07 — Redirect safety (HIGH)

- Flag `header('Location: ' . $value)` and similar dynamic redirect construction.
- Use `Redirect::to('account.php')` for fixed destinations.
- Use the installed `Redirect::sanitized(...)` signature for user-supplied destinations, with same-origin enforcement when supported.

## Rule 08 — Cryptography and tokens (HIGH)

- Flag `md5(uniqid())`, `mt_rand()`, `rand()`, or `str_shuffle` when used for tokens, reset codes, or cookie secrets.
- Flag reversible `spiceEncrypt(...)` when used for passwords or other values that require one-way hashing.
- Use the installed `Hash::unique()` helper for unguessable tokens.
- Use `password_hash()` and `password_verify()` for passwords.

## Rule 09 — Validate class (LOW)

- Treat chains of manual empty checks across three or more form fields as a soft signal.
- Prefer `(new Validate)->check($_POST, [...])`, followed by canonical error rendering.
- Do not report a small, clear custom validation as inherently unsafe.

## Rule 10 — Rate limiting on authentication endpoints (HIGH)

- Identify login, registration, password-reset, magic-link, 2FA, and email-verification handlers by behavior as well as filename.
- Flag an authentication-sensitive handler that lacks the installed `RateLimit` check and record pattern.
- Verify live method signatures before suggesting a fix.

## Rule 11 — Framework-global shadowing (HIGH)

- Flag assignments to `$config`, `$user`, `$db`, `$settings`, `$abs_us_root`, or `$us_url_root` at global scope when they replace framework globals.
- Do not flag a local assignment inside a function, including `$config = $GLOBALS['config']` inside a function.
- Fix by renaming the application variable.

## Rule 12 — AJAX endpoints (HIGH on auth gaps)

For each PHP AJAX endpoint (a `parsers/` folder in stock UserSpice; check
whether the project uses a different convention — see Project override
below), verify all applicable controls:

1. Check `Token::check(Input::get('csrf'))` before any state change.
2. Re-check authentication with `securePage(...)`, `$user->isLoggedIn()`, or an equivalent installed pattern.
3. Re-check any permission enforced by the parent page.

Flag missing CSRF, authentication, or required permission checks as HIGH. Also flag an AJAX endpoint outside the project's normal endpoint location when the UserSpice URL rewriter would mangle it.

- **Project override:** a repository may put AJAX endpoints somewhere other
  than `parsers/` (for example, `app/api/*`) and may deliberately keep most
  of them out of `securePage()` and out of `$path` in `z_us_root.php`,
  checking authentication and permission inline instead. Read that project's
  overrides file, if one exists, before treating a missing `securePage()` call
  as a finding — confirm the endpoint truly lacks an inline check before
  flagging it.
- **Documented no-session exception:** an endpoint may authenticate a
  single-use, time-limited token carried in the URL instead of a UserSpice
  session (for example, a public verification link or a webhook receiver).
  Such a file has no session to bind a CSRF token to, so a missing CSRF check
  is by design. Read the file's own header comment fully before flagging a
  missing CSRF token or `securePage()` call — if it states this shape
  deliberately, do not report it as a finding.

## Database convention — global database handle (LOW)

- In application functions, flag `DB::getInstance()` as a convention issue when the project standard is `global $db;`.
- Do not describe this as a security vulnerability.

## False-positive guards

Before reporting any finding:

- Confirm the match is executable code, not a comment, documentation string, or unrelated literal.
- Trace SQL variables far enough to determine whether they can contain user input.
- Require CSRF only for state-changing requests; GET/search forms do not need it.
- Distinguish global-scope framework-variable replacement from local variables.
- When context remains ambiguous, use LOW severity and say what requires manual verification.

## Reporting contract

- Report only; never modify the audited application code.
- Do not invent numbered rules. Put observations outside this checklist in an unnumbered Notes section.
- Include file, line, actual snippet, issue, severity, and concrete fix for every finding.
- Explicitly identify clean buckets so silence is not mistaken for an incomplete audit.
