---
name: userspice-audit
description: "Audit custom PHP code in a UserSpice site against the UserSpice security and best-practices checklist. Use when reviewing a whole site, folder, plugin, parsers endpoint, or individual PHP file for UserSpice-specific security issues. Produce a cited report without modifying application code, Git configuration, or host-agent settings."
---

# UserSpice audit

Audit UserSpice application code outside the framework-owned `users/` directory. Report findings with file paths, line numbers, evidence, severity, and concrete fixes.

The only write this skill makes is a new audit report under `_noupload/audit-reports/`. Never modify application code, Git configuration, ignore files, host-agent settings, or the database.

## Invocation

```text
<invoke>                                  # audit the whole project, excluding framework/vendor code
<invoke> <path>                           # audit a folder or one PHP file
<invoke> --scope app|usersc|plugins|ajax  # restrict the audit bucket
<invoke> --walk                           # sequential file-by-file audit
<invoke> --quick                          # skip plan confirmation
```

Use `/userspice-audit` in Claude Code and `$userspice-audit` in Codex. Do not depend on explicit invocation. Extract the target and options from the invoking request whether the skill was selected explicitly or implicitly.

## Platform assumptions

- Use a Bash-compatible shell on Linux or macOS.
- On Windows, use Git Bash or WSL and forward-slash paths. Displayed paths may use `/c/...` or `/mnt/c/...` forms.
- Do not read or change host permission configuration.

## Required references

Read [references/audit-checklist.md](references/audit-checklist.md) completely before auditing. It is the canonical finding and severity contract for this skill.

**In this repository**, also read
[../elanregistry-overrides.md](../elanregistry-overrides.md) before auditing.
It lists points where this project's conventions replace the checklist
below — for example, DB-bound input, server globals, AJAX responses, AJAX
endpoint location, and a documented no-CSRF page. A project override always
wins over this checklist. Report project-specific findings not covered by
either file in the report's Notes section, not as a numbered rule.

If the optional `usersc/plugins/ai_prompts/prompts/` directory is installed, read the relevant prompt files completely when deeper UserSpice context is needed:

- `00_start_here.md.php`
- `secure_page_pattern.md.php`
- `where_to_look.md.php`

The `.md.php` files contain markdown behind a security wrapper. Use them only as supplementary context; the bundled checklist defines what the audit reports. If the plugin is absent, continue without it. Consult https://userspice.com/userspice-best-practices/ only when network access is available and additional context is necessary.

## Workflow

Follow these steps in order.

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

The checklist targets UserSpice 6.0.9 or newer. Allow `MIN_USERSPICE_VER` to override that floor. Use a portable numeric comparison rather than GNU `sort -V`:

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

### 2. Resolve the target and mode

If the invoking request supplies a path, resolve it as `TARGET`. Otherwise offer the current directory, `$INSTALL_ROOT/usersc`, and `$INSTALL_ROOT`, with the current directory as default.

Resolve relative paths to absolute paths and verify the target exists. A target may be a directory or one PHP file; it does not need to contain `users/init.php`.

```bash
if [ -f "$TARGET" ]; then
    MODE="single"
elif [ -d "$TARGET" ]; then
    MODE="folder"
else
    echo "target_missing:$TARGET"
fi
```

Use `MODE=single` for one file. For a directory, honor `--walk`; otherwise use bounded parallel mode. Honor `--scope` and `--quick` from the invoking request.

### 3. Load canonical helper signatures

Search the live framework before recommending a helper signature:

```bash
grep -nE 'function[[:space:]]+&?[[:space:]]*(safeReturn|trustedHtml|tokenHere|safeJsonEncodeForJs|safeCurl|hed|securePage|display_errors)[[:space:]]*\(' \
    "$INSTALL_ROOT/users/helpers/"*.php

grep -nE '(public|static).*function[[:space:]]+&?[[:space:]]*(getInstance|to|sanitized|generate|check|record|get|getClientIp|getHost|getOrigin|unique)[[:space:]]*\(' \
    "$INSTALL_ROOT/users/classes/Redirect.php" \
    "$INSTALL_ROOT/users/classes/Token.php" \
    "$INSTALL_ROOT/users/classes/Hash.php" \
    "$INSTALL_ROOT/users/classes/Server.php" \
    "$INSTALL_ROOT/users/classes/Input.php" \
    "$INSTALL_ROOT/users/classes/RateLimit.php" 2>/dev/null
```

Inspect surrounding declarations to confirm defaults and return types. The installed code wins over this skill or external documentation; record meaningful drift in the report's Notes section.

### 4. Enumerate directory targets

Skip this step in single-file mode. Every in-scope PHP file must land in exactly one bucket: ajax first, then plugins, then app.

Always exclude:

- `$INSTALL_ROOT/users/`
- any `vendor/`, `node_modules/`, `.git/`, `.svn/`, or `.hg/` directory
- `_noupload/`
- backup/dump directories matching `*_backup`, `*_bak`, or `*.orig`

Use exact-path exclusion for the framework directory rather than excluding every custom directory named `users`.

**Upstream files under gitignored project directories.** Some directories hold
a mix of upstream (framework-owned, not this project's code) and
project-owned files, and `.gitignore` marks the project-owned exceptions.
Check `.gitignore` for the exact pattern rather than assuming. Two known cases:

- `usersc/plugins/*` — upstream, except `usersc/plugins/hooker/hooks/*` and
  `usersc/plugins/ai_prompts/custom_prompts/*`, which are project-owned.
- `usersc/templates/customizer/*` — upstream, except the project-owned files
  listed in the project's overrides file (for example,
  `file_nav_custom.php`, a tracked `navigation.php`, and named child-theme
  files).

Exclude the upstream files from the normal findings sections. If the invoking
request asks to audit one of these directories anyway, or an upstream file
shows a real issue worth surfacing, put it under a separate "Upstream"
section in the report instead of the graded severity sections — do not
mix upstream findings into the counts reported in chat.

```bash
PLUGINS_DIR="$INSTALL_ROOT/usersc/plugins"

# AJAX candidates
find "$TARGET" -type d -name parsers \
  -not -path '*/vendor/*' -not -path '*/node_modules/*' \
  -not -path '*/.git/*' -not -path '*/.svn/*' -not -path '*/.hg/*' \
  -not -path '*/_noupload/*' -not -path '*_backup/*' \
  -not -path '*_bak/*' -not -path '*.orig/*'

# App candidates; classify plugin and parsers files before retaining this bucket.
find "$TARGET" -type f -name '*.php' \
  -not -path "$INSTALL_ROOT/users/*" \
  -not -path '*/vendor/*' -not -path '*/node_modules/*' \
  -not -path '*/.git/*' -not -path '*/.svn/*' -not -path '*/.hg/*' \
  -not -path '*/_noupload/*' -not -path '*_backup/*' \
  -not -path '*_bak/*' -not -path '*.orig/*'
```

Build actual file lists, deduplicate them, and print counts for app, plugins, and ajax. `--scope usersc` means non-plugin, non-parser PHP under `$INSTALL_ROOT/usersc`; `--scope app` means other non-plugin, non-parser application PHP. If requested, print the complete grouped list.

Unless `--quick` was requested, show the exclusions, direct work, and planned delegated work, then offer to proceed, list files, switch to walk mode, narrow the scope, or select one file. Wait for the user's choice before starting the audit.

### 5. Audit

#### Single-file mode

Read the complete file, apply the complete checklist, and show findings inline. Still write the consolidated report in Step 6.

#### Walk mode

Process parsers first, then plugins, then app files. For each file, show the path, inspect the whole file with targeted searches as aids, and print findings or `clean`. Do not delegate in walk mode.

#### Bounded parallel mode

Audit ajax, small plugins, and small app buckets directly. Candidates for delegation are:

- a plugin with more than two PHP files;
- an app bucket with more than 30 files, split by top-level directory.

When the host supports delegation, create independent workers without exceeding available concurrency. Batch or directly process overflow work. Do not require a vendor-specific worker type or a single-call fanout feature. If delegation is unavailable or capacity is zero, process every bucket sequentially.

Each worker must receive:

- the exact files or directory to inspect;
- the complete checklist content, or its resolvable skill-local path when the filesystem is shared;
- an instruction to report only and never modify files;
- this response schema:

```json
{"findings":[{"file":"...","line":1,"rule":"RULE_NUM","severity":"HIGH|MEDIUM|LOW","snippet":"...","issue":"...","fix":"..."}]}
```

Validate delegated responses before aggregation. Direct audits may use `grep -nE` or an equivalent search command to generate candidates, but every reported finding requires surrounding-code inspection.

### 6. Write the report

Aggregate direct and delegated findings, validate required fields, deduplicate, and group by severity and rule. Include every audited file in either findings or the clean-file list.

Create a non-clobbering report path without reading or changing `.gitignore` or any other Git configuration:

```bash
REPORT_DIR="$INSTALL_ROOT/_noupload/audit-reports"
mkdir -p "$REPORT_DIR"

DATE=$(date +%Y-%m-%d)
REPORT="$REPORT_DIR/audit-report-$DATE.md"
N=2
while [ -e "$REPORT" ]; do
    REPORT="$REPORT_DIR/audit-report-$DATE-$N.md"
    N=$((N+1))
done
echo "$REPORT"
```

Use this report structure:

```markdown
# UserSpice Best-Practices Audit
**Date:** YYYY-MM-DD
**Target:** absolute path
**Scope:** audited buckets
**Files scanned:** N
**Findings:** X high · Y medium · Z low

## High-severity findings
### Rule NN — title
- `relative/file.php:42` — issue and fix.
  ```php
  actual snippet
  ```

## Medium-severity findings

## Low-severity findings

## Notes

## Upstream (excluded from severity counts)

## Files clean
```

Write the report only under `$INSTALL_ROOT/_noupload/`. Confirm the path is
git-ignored before the first write of a session:

```bash
git -C "$INSTALL_ROOT" check-ignore -q "_noupload/audit-reports/x" \
    && echo "noupload:ignored" || echo "noupload:NOT IGNORED — stop and ask"
```

If the check reports "NOT IGNORED", stop and tell the user before writing
anything — do not let a report land in a tracked path.

In chat, return counts by severity, the three most common violated rules, and the report path. Do not paste the full report. End by offering to explain a specific finding.
