---
name: userspice-helper-lookup
description: "Look up UserSpice framework helpers and class methods from the installed source. Use for a helper name, a class method, or a task such as CSRF fields, escaping, redirects, rate limiting, validation, or request handling. Return the live signature, source location, intended use, example, and relevant warnings."
---

# UserSpice helper lookup

"What's the right UserSpice helper for X?" Answers come from the actual installed codebase — not from MEMORY, not from KB pages that may have drifted. This skill exists because line numbers and signatures move between releases, and recommending a stale signature is worse than recommending none at all.

## Usage

```text
<invoke>                          # interactive topic selection
<invoke> safeReturn               # helper name
<invoke> Token::check             # class method
<invoke> "escape html for js"     # task description
```

Use `/userspice-helper-lookup` in Claude Code and `$userspice-helper-lookup` in Codex. Do not depend on explicit invocation; extract the lookup query from the invoking request.

## Platform notes

Use a Bash-compatible shell on Linux or macOS. On Windows, use Git Bash or WSL with forward-slash paths. Do not read or modify host-agent permission configuration.

## Project overrides

**In this repository**, read
[../elanregistry-overrides.md](../elanregistry-overrides.md) before answering
a lookup about input handling, server values, AJAX responses, or logging. It
lists project classes that replace the stock UserSpice helper for that task —
for example, `ElanRegistry\Input::raw()` in place of `\Input::get()` for a
value bound for the database, and the `$php_self`-style globals in place of
`Server::get()`. When a project override exists for the topic asked about,
lead the answer with it and name the stock helper only as background.

## What this skill is for

UserSpice ships helpers and classes that handle CSRF, escaping, SQL safety, redirects, rate limiting, and outbound HTTP. This skill resolves the correct helper from the live codebase, so the signature remains current even when model memory or documentation has drifted.

Other UserSpice skills may inline the same live-source lookup pattern, but must remain independently installable. Never require this skill as a runtime dependency merely to inspect a helper signature.

## Canonical patterns reference

The **ai_prompts** UserSpice plugin (`usersc/plugins/ai_prompts/`) ships agent-readable prompts for UserSpice patterns. If installed, it's the best place to read about how a helper is used in context (which other helpers usually go with it, what the canonical page recipe expects, etc.). Check once at the start of the run:

```bash
PROMPTS_DIR=""
[ -d "$INSTALL_ROOT/usersc/plugins/ai_prompts/prompts" ] && \
    PROMPTS_DIR="$INSTALL_ROOT/usersc/plugins/ai_prompts/prompts"
echo "prompts_dir:${PROMPTS_DIR:-NONE}"
```

If set, the most relevant files for this skill are:
- `secure_page_pattern.md.php` — shows helpers in their natural context (form handler, AJAX endpoint)
- `00_start_here.md.php` — gotchas that affect helper behavior (`Input::exists()` takes a type, not a field; `Input::get()` returns `""` on miss; etc.)

The `.md.php` extension is a security wrapper; the files contain markdown. Read a selected file completely rather than anchoring on headings, since the structure evolves across plugin versions.

If `PROMPTS_DIR` is empty, continue with the live source and bundled Topic Map. Consult https://userspice.com/userspice-best-practices/ only when network access is available and more context is needed.

The live grep against `users/` is still the source of truth for **signatures**. The plugin only supplements with usage context.

## What You Must Do When Invoked

### Step 0a — Locate the UserSpice install and verify version

Walk up from the current directory looking for `users/init.php`. The first ancestor (or cwd itself) that has it is the **install root**.

```bash
INSTALL_ROOT=""
DIR="$(pwd)"
while [ "$DIR" != "/" ]; do
    if [ -f "$DIR/users/init.php" ]; then
        INSTALL_ROOT="$DIR"; break
    fi
    DIR="$(dirname "$DIR")"
done
echo "install_root:${INSTALL_ROOT:-NONE}"
```

If no install was found, ask: "Couldn't find a UserSpice install (no `users/init.php` in this dir or any ancestor). Where is it? (paste an absolute path, or `cancel`)". Verify the path; cancel = stop.

#### Version check

The signatures in the **Topic Map** below were verified against UserSpice **6.0.9**. Older installs may have different signatures (or be missing helpers entirely). Override with `MIN_USERSPICE_VER` if a query needs a newer feature.

```bash
MIN_USERSPICE_VER="${MIN_USERSPICE_VER:-6.0.9}"
VER_FILE="$INSTALL_ROOT/users/includes/user_spice_ver.php"
USER_SPICE_VER=""
[ -f "$VER_FILE" ] && USER_SPICE_VER=$(grep -oE "[0-9]+\.[0-9]+\.[0-9]+" "$VER_FILE" 2>/dev/null | head -1)

ver_lt() {
    awk -v lhs="$1" -v rhs="$2" 'BEGIN {
        left_n = split(lhs, left, ".")
        right_n = split(rhs, right, ".")
        max_n = left_n > right_n ? left_n : right_n
        for (i = 1; i <= max_n; i++) {
            left_v = i <= left_n ? left[i] + 0 : 0
            right_v = i <= right_n ? right[i] + 0 : 0
            if (left_v < right_v) exit 0
            if (left_v > right_v) exit 1
        }
        exit 1
    }'
}

if [ -z "$USER_SPICE_VER" ]; then
    echo "version:UNKNOWN (min:$MIN_USERSPICE_VER)"
elif ver_lt "$USER_SPICE_VER" "$MIN_USERSPICE_VER"; then
    echo "version:$USER_SPICE_VER BELOW $MIN_USERSPICE_VER"
else
    echo "version:$USER_SPICE_VER ok"
fi
```

- **`ok`** — silent, continue.
- **`BELOW`** — warn and ask: "⚠️ Detected `<X.Y.Z>`, this skill targets **<MIN>+**. Some helpers below may not exist or have different signatures on your install. Continue anyway? (y/N)" Default no.
- **`UNKNOWN`** — same shape, "Couldn't determine UserSpice version (read failed at `<path>`). Continue anyway? (y/N)" Default no.

### Step 1 — Classify the query

Extract `QUERY` from the invoking request. Exclude only the explicit invocation token when one is present. Classify the remaining request into one of four modes:

| Pattern | Mode |
|---------|------|
| Empty (no query supplied) | `interactive` |
| Matches `^[A-Z][A-Za-z0-9_]*::[a-zA-Z_][a-zA-Z0-9_]*$` (e.g. `Token::check`, `Redirect::sanitized`) | `class-method` |
| Single identifier matching `^[a-z_][a-zA-Z0-9_]*$` (e.g. `safeReturn`, `tokenHere`) | `name` |
| Multi-word, contains spaces or punctuation (e.g. `"escape html for js"`) | `topic` |

Classify the natural-language request directly; do not assume skill inputs appear as shell positional parameters.

### Step 2 — Look it up

#### MODE=interactive

Print the **Topic Map** category headers (just the bold lines, not the entries). Then:

> Which area, or what are you trying to do? Type a category name, helper name, or describe the task. `cancel` to exit.

Wait. Recurse into Step 1 with the answer.

#### MODE=name

Grep the helpers folder for a function declaration:

```bash
grep -nE "function[[:space:]]+&?[[:space:]]*${ARG}[[:space:]]*\\(" "$INSTALL_ROOT/users/helpers/"*.php
```

If exactly one hit, inspect at least five surrounding lines for the full signature. Format the result in Step 3.

If multiple hits (rare — same name in different files), return all and let the user disambiguate.

If no hits, fall through to MODE=topic with `ARG` as the query — maybe the user remembered the name wrong.

#### MODE=class-method

```bash
CLASS="${ARG%%::*}"
METHOD="${ARG##*::}"
CLASS_FILE="$INSTALL_ROOT/users/classes/${CLASS}.php"

if [ ! -f "$CLASS_FILE" ]; then
    echo "No class \`${CLASS}\` in users/classes/. Available:"
    ls "$INSTALL_ROOT/users/classes/"*.php | xargs -n1 basename | sed 's/\.php$//'
    exit  # or fall through to topic
fi

grep -nE "function[[:space:]]+&?[[:space:]]*${METHOD}[[:space:]]*\\(" "$CLASS_FILE"
```

Inspect at least five surrounding lines around the hit. If the method is not found, list all methods in the class:

```bash
grep -nE 'function[[:space:]]+&?[[:space:]]*[a-zA-Z_][a-zA-Z0-9_]*[[:space:]]*\(' "$CLASS_FILE"
```

#### MODE=topic

Match the query (lowercased) against the **Topic Map**. Use loose word matching — "escape html for js" should hit "embed value in `<script>` block". If the query contains words like "csrf", "redirect", "ip", "host", "rate", "validate", etc., those are strong category signals.

Return up to 3 best-fit matches. If none of the topic-map entries are a clear match, fall through to a literal grep across helpers and classes:

```bash
grep -rniF -- "$KEYWORD" \
    "$INSTALL_ROOT/users/helpers/" \
    "$INSTALL_ROOT/users/classes/" 2>/dev/null | head -20
```

Show up to 5 candidate matches with their signatures and let the user pick.

### Step 3 — Format the result

For each helper returned:

```
<name>(<args from grep>)                              [<relative path>:<line>]
  Use when: <one-line use-this-when from the Topic Map or your judgment>
  Example:
    <1-3 line code example>
  Notes:
    <only if there are gotchas — bugs, deprecations, version requirements>
```

Compact. No prose paragraphs. Multiple results = multiple blocks separated by blank lines. Path should be relative to `$INSTALL_ROOT` (e.g. `users/helpers/us_helpers.php:1941`), not absolute.

If the looked-up helper appears in **Known Weak Helpers** below, ALWAYS include the warning in Notes — don't suppress it just because the user asked specifically for that helper.

End with: "Need a worked example or another lookup? Ask away."

---

## Topic Map

The curated mapping of common tasks to helpers, grouped by area. **Verify each entry by grep before recommending** — if your grep returns something different from what's listed here, trust the grep.

### Output / escaping

- "escape html for plain text" → `safeReturn($var)` — `users/helpers/us_helpers.php`
- "escape value already encoded once on input" → `safeReturn($var, true)` (decodes via `hed()` first, then re-escapes — avoids `Tom&amp;amp;Jerry` artifacts)
- "render trusted HTML (Summernote/TinyMCE/CKEditor/parsed Markdown)" → `trustedHtml($html)` — identity function with `@psalm-taint-escape html`
- "embed value inside `<script>` block" → `safeJsonEncodeForJs($val)`
- "decode HTML entities" → `hed($string)` (or `hed($s, true)` to strip tags first)

### Input

- "read POST/GET field" → `Input::get('field')` — trims and `htmlspecialchars`-encodes.
  **In this repository, use `ElanRegistry\Input::raw('field')` instead when the
  value is bound for the database** — `\Input::get()` pre-encodes, and storing
  that value causes double encoding at render time. See the project overrides
  file.
- "check whether form was submitted" → `Input::exists('post')` (or `'get'`).
  **In a file that imports `ElanRegistry\Input`, use `Input::existsPost()` /
  `Input::existsGet()` instead** — the wrapper removed `exists()` in v2.26.1.
- "validate a form" → `(new Validate)->check($_POST, [...rules...])` — passing `$_POST` directly is idiomatic here
- "render validation errors" → `display_errors($v->errors())`

### CSRF

- "emit CSRF field in a form" → `<?= tokenHere(); ?>` — emits the full hidden input
- "check CSRF in a handler" → `if (!Token::check(Input::get('csrf'))) { ... }` — field name is `csrf`, not `csrf_token`
- "generate token manually" → `Token::generate()`

### SQL

- "parameterized query" → `$db->query("... WHERE x = ?", [$var])`
- "insert/update/delete row" → `$db->insert($table, $data)` / `->update(...)` / `->delete(...)`
- "get \$db inside a function" → `global $db;` (NOT `DB::getInstance()`)

### Server vars / request context

- "get HTTP host" → `Server::getHost($trustedProxies)` — proxy-aware
- "get request origin" → `Server::getOrigin($trustedProxies)`
- "get client IP" → `Server::getClientIp($trustedProxies)`
- "get any \$_SERVER value" → `Server::get('KEY', $default)`

**In this repository**, a project may set validated globals
(`$php_self`, `$is_https`, `$host`, `$method`, `$request_uri`, `$remote_addr`,
`$referer`, `$user_agent`) on every request instead of using `Server::` calls
directly. Check for `usersc/includes/server_globals.php` and prefer the
matching global when it exists — see the project overrides file.

### Redirect

- "redirect to fixed page" → `Redirect::to('account.php')`
- "redirect to user-supplied URL safely" → `Redirect::sanitized(Input::get('next'), null, 302, ['same_origin' => true])`

### Crypto / tokens

- "generate unguessable token (reset code, magic link, cookie value)" → `Hash::unique()` — DO NOT use `random_password()` for this
- "hash a password" → `password_hash($pw, PASSWORD_DEFAULT)` (PHP built-in; the `User` class already uses this)
- "verify a password" → `password_verify($input, $stored)` (PHP built-in)

### Rate limiting

- "throttle login/register/reset/2FA" → `(new RateLimit)->check('action', ['ip' => Server::getClientIp(), 'email' => $email])`, then `->record(...)` after
- check returns `false` if locked → bail to login screen with a friendly message

### Outbound HTTP

- "outbound curl with safety" → `safeCurl($url)` — sanitizes the URL before passing to curl

### AJAX responses / logging (project-specific)

These are project classes, not stock UserSpice helpers. Verify against the
installed source the same way as any other lookup in this skill.

- "respond from an AJAX endpoint" → **in this repository**, use `ApiResponse`
  (`usersc/classes/ApiResponse.php`), not raw `json_encode()`. Factory
  methods: `success()`, `error()`, `validationError()`, `unauthorized()`,
  `forbidden()`, `notFound()`, `serverError()`. Builder methods:
  `->withData()`, `->withDataArray()`, `->withLogging()`, `->send()`.
- "log an event or error" → **in this repository**, call
  `logger($userId, LogCategories::LOG_CATEGORY_*, $message)`
  (`usersc/classes/LogCategories.php`). Always pass a `LogCategories`
  constant, never a raw string.

### Auth / page guards

- "require login on a page" → `securePage($_SERVER['PHP_SELF'])` at top of file
- "user-facing error message" → `usError($msg)` (sets the error flash)
- "user-facing success message" → `usSuccess($msg)`

---

## Known Weak Helpers

ALWAYS include the warning in Notes when these come up. The fix recommendation goes in the same Notes block.

| Helper | Weakness | Use instead |
|--------|----------|-------------|
| `random_password()` | Uses `str_shuffle` — not a CSPRNG, predictable output | `Hash::unique()` for tokens; `password_hash()` for passwords |
| `Cookie::delete()` | Bug: extends cookie life instead of deleting | `setcookie($name, '', time()-3600, '/')` directly |
| `err()` | No-op — does nothing | `usError()` or `display_errors()` |
| `write_php_ini()` | Wrong-name guard prevents the function from running as expected | Avoid; write config another way |
| `spiceDecrypt($enc, $iv, $tag)` | Ignores `$iv` and `$tag` (vestigial parameters) | Pass any value for those; or migrate off |

These come from a KB-documentation pass through `users/helpers/`. If the install is on a UserSpice version newer than 6.0.9, re-verify before flagging — fixes may have landed.

## Dead Helpers

These exist but have no callers and aren't loaded. If a user looks them up, say so:

- `users/helpers/folders.php`
- `users/helpers/form_submit_button.php`

---

## For other UserSpice skills

Other UserSpice skills should inline this lookup recipe before recommending a helper signature. Keep each skill independently installable; do not introduce a runtime dependency on this skill. The installed codebase wins over model memory, web pages, and skill documentation.

The minimum-viable inline recipe:

```bash
# Helper function: grep helpers/ for `function NAME`, then read context
grep -nE "function[[:space:]]+&?[[:space:]]*${NAME}[[:space:]]*\\(" "$INSTALL_ROOT/users/helpers/"*.php

# Class method: grep classes/<Class>.php for `function METHOD`
grep -nE "function[[:space:]]+&?[[:space:]]*${METHOD}[[:space:]]*\\(" "$INSTALL_ROOT/users/classes/${CLASS}.php"
```

Then inspect at least five lines around the match to capture parameter defaults and return types.

If documented guidance contradicts the live declaration, trust the declaration and note the drift in the output.
