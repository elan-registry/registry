---
name: userspice-page-scaffold
description: "Generate one new UserSpice PHP page using installed framework conventions. Use for a guarded display page, self-posting validated form, or AJAX endpoint inside a parsers folder. Verify live helper signatures, preview the complete output, and write only the confirmed target file without changing routes, menus, database schema, Git configuration, or host settings."
---

# UserSpice page scaffold

Generate one new UserSpice PHP file with live, version-correct framework helpers. Supported types are a simple page, a self-posting form, and an AJAX endpoint under a `parsers/` directory.

Write exactly the confirmed target file. Do not modify routes, navigation, menus, database schema, Git configuration, host-agent settings, or any existing file unless the user explicitly confirms replacing the target after seeing the complete preview.

## Invocation

```text
<invoke>                         # gather the missing requirements interactively
<invoke> feedback.php            # use the supplied target and ask only what is missing
<invoke> parsers/save_note.php   # infer an AJAX candidate and confirm the type
```

Use `/userspice-page-scaffold` in Claude Code and `$userspice-page-scaffold` in Codex. Do not depend on explicit invocation. Extract any supplied path, page type, authentication mode, title, fields, and table from the invoking request.

## Platform assumptions

Use a Bash-compatible shell on Linux or macOS. On Windows, use Git Bash or WSL with forward-slash paths. Do not read or modify host permission configuration.

## Canonical context

If `usersc/plugins/ai_prompts/prompts/` exists, read these files completely before scaffolding when relevant:

- `secure_page_pattern.md.php`
- `00_start_here.md.php`

These files contain markdown behind a security wrapper and supplement the live framework source. If they are absent, continue with the bundled patterns. Consult https://userspice.com/userspice-best-practices/ only when network access is available and extra context is needed.

**In this repository**, also read
[../elanregistry-overrides.md](../elanregistry-overrides.md) and
`usersc/plugins/ai_prompts/custom_prompts/elanregistry_overrides.md.php`
completely before scaffolding. This project replaces several bundled
patterns below — DB-bound input, server values, AJAX endpoint location and
response format, and page metadata. A project override always wins over a
pattern in this skill. Apply every override that fits the requested page
even if the user did not name it.

## Workflow

### 1. Locate the UserSpice install and verify its version

Walk up from the current directory looking for `users/init.php`. The first matching ancestor is `INSTALL_ROOT`.

```bash
INSTALL_ROOT=""
DIR="$(pwd)"
while [ "$DIR" != "/" ]; do
    if [ -f "$DIR/users/init.php" ]; then
        INSTALL_ROOT="$DIR"
        break
    fi
    DIR="$(dirname "$DIR")"
done
echo "install_root:${INSTALL_ROOT:-NONE}"
```

If no install is found, ask for an absolute path or `cancel`, then verify `<path>/users/init.php` exists.

Target UserSpice 6.0.9 or newer and allow `MIN_USERSPICE_VER` to override the floor:

```bash
MIN_USERSPICE_VER="${MIN_USERSPICE_VER:-6.0.9}"
VER_FILE="$INSTALL_ROOT/users/includes/user_spice_ver.php"
USER_SPICE_VER=""
[ -f "$VER_FILE" ] && USER_SPICE_VER=$(grep -oE '[0-9]+\.[0-9]+\.[0-9]+' "$VER_FILE" 2>/dev/null | head -1)

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

Continue silently on `ok`. For `BELOW` or `UNKNOWN`, explain the risk and require explicit confirmation; default to stopping.

### 2. Inspect live helper signatures

Before assembling the file, search the installed source:

```bash
grep -nE 'function[[:space:]]+&?[[:space:]]*(safeReturn|tokenHere|securePage|usError|usSuccess|display_errors)[[:space:]]*\(' \
    "$INSTALL_ROOT/users/helpers/"*.php

grep -nE 'function[[:space:]]+&?[[:space:]]*(check|generate|exists|get|to|sanitized|hasPermission|isLoggedIn)[[:space:]]*\(' \
    "$INSTALL_ROOT/users/classes/Token.php" \
    "$INSTALL_ROOT/users/classes/Input.php" \
    "$INSTALL_ROOT/users/classes/Validate.php" \
    "$INSTALL_ROOT/users/classes/Server.php" \
    "$INSTALL_ROOT/users/classes/Redirect.php" \
    "$INSTALL_ROOT/users/classes/User.php" 2>/dev/null
```

Inspect surrounding declarations and confirm at least:

- the one-argument `safeReturn` form;
- the installed `Token::check` parameters;
- `Input::exists('post')` behavior;
- the installed `Server::get` parameters;
- `securePage` in `users/helpers/permissions.php`.

The installed source wins over this skill and external documentation. Adapt the generated file to confirmed signatures and disclose meaningful drift after writing.

### 3. Gather only missing requirements

Derive as much as possible from the invoking request, then ask one question at a time for missing values.

#### Target path

Require a PHP path relative to `INSTALL_ROOT`. Resolve it as `TARGET`, reject absolute escapes and `..` segments, and verify the resolved target remains under `INSTALL_ROOT`.

If `TARGET` exists, default to cancel. Replace it only after the user explicitly chooses overwrite and later confirms the complete preview.

#### Page type

- `simple`: display page without a form.
- `form`: self-posting form with CSRF, validation, and redirect-after-POST.
- `ajax`: JSON endpoint. In stock UserSpice this must live in a `parsers/`
  path. **In this repository**, it must live under `app/api/<domain>/`
  instead — check the project overrides file for the domain grouping
  (`cars/`, `contact/`, `shared/`, `admin/`) and ask which domain fits when it
  is not obvious. Do not propose a `parsers/` path in this repository.

Default to `form` when the type cannot be inferred. For an AJAX target outside the project's normal endpoint location (`parsers/` in stock UserSpice, `app/api/<domain>/` in this repository), propose the correct location and require confirmation.

#### Authentication

- `public`: no login or permission guard.
- `login`: require an authenticated UserSpice page or endpoint.
- `permission N`: require authentication and `$user->hasPermission(N)`.

Default to `login`. Explain that `securePage` already enforces admin-configured page permissions; the numeric mode adds an explicit code-level check.

**In this repository**, most `app/api/*` endpoints (all domains except
`app/api/contact/`) must NOT call `securePage()` and must NOT be added to
`$path` in `z_us_root.php` — they check authentication and permission inline
instead. Check the project overrides file for the exact rule before adding a
`securePage()` call to a generated AJAX endpoint under `app/api/`.

#### Title

For non-AJAX pages, use the supplied title or derive one from the filename and confirm it.

Encode the confirmed title as a PHP string literal before substituting it into the template. Do not place raw user text between hardcoded quotes; use an equivalent of `var_export($title, true)` so quotes and backslashes cannot break generated PHP.

#### Fields

For form and AJAX types, accept validated `name:type` pairs. Supported types are `text`, `email`, `password`, `textarea`, `number`, `checkbox`, `select(opt1|opt2)`, and `hidden`. Field names may contain only letters, digits, and underscores and may not begin with a digit.

#### Database write

For a form page, ask for a table name or `none`. Validate a supplied table name as a simple identifier before placing it in generated PHP. Use a `$db->insert(...)` block when supplied; otherwise insert a TODO. AJAX endpoints always receive a handler TODO because the requested operation must be designed explicitly.

**In this repository**, read a field for database storage with
`ElanRegistry\Input::raw('field')`, not `Input::get('field')` — see the
project overrides file. Escape only at the render layer.

### 4. Load and apply the scaffold patterns

Read [references/scaffold-patterns.md](references/scaffold-patterns.md) completely. Select the matching template and field mappings only after requirements are known.

Compute the bootstrap path from the target file's directory to `INSTALL_ROOT`, then append `users/init.php`:

```bash
DEPTH=$(printf '%s' "$REL_PATH" | tr -cd '/' | wc -c | tr -d ' ')
INIT_REL=""
i=0
while [ "$i" -lt "$DEPTH" ]; do
    INIT_REL="../$INIT_REL"
    i=$((i+1))
done
INIT_REL="${INIT_REL}users/init.php"
echo "$INIT_REL"
```

Use this relative path only for the initial bootstrap. After `init.php`, use `$abs_us_root . $us_url_root` for framework includes.

Substitute all placeholders. Omit page and AJAX auth placeholders for `public`. Insert both authentication and permission blocks for `permission N`. Never leave an unresolved placeholder in the preview.

Encode generated labels and other user-provided fixed strings as PHP literals wherever they appear in generated PHP. Validate identifiers separately; string escaping is not a substitute for identifier validation.

### 5. Preview and confirm

Show the complete proposed file with line numbers. State the absolute target and whether it is new or replacing an existing file. Ask for `yes`, `no`, or an edit request.

- On edit, update the in-memory content, show the complete preview again, and reconfirm.
- On no, stop without writing.
- Treat an unanswered confirmation as no action. Do not infer approval merely because the file was previewed.

### 6. Write one file

After explicit confirmation, create the parent directory if needed and write only `TARGET`. Do not add a generated-by header.

Then report:

- the relative target and line count;
- a suggested browser path when applicable;
- a host-appropriate audit suggestion (`/userspice-audit <path>` or `$userspice-audit <path>`);
- any live-signature drift incorporated into the generated file.

## Safety contract

- Write exactly one target file; parent-directory creation is allowed when required for that target.
- Never modify menus, routes, database schema, Git configuration, ignore files, host-agent settings, or other source files.
- Do not perform the database operation or browse to the new page as part of scaffolding.
- If the request requires a second file, explain that it is outside this skill's scope and stop after the confirmed target.
- Never invent a helper or validation rule. Adapt only to behavior confirmed in the installed UserSpice source.
