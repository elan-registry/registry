<?php /* UserSpice AI Prompt — protected from HTTP access. Markdown content below. */ __halt_compiler(); ?>

# ElanRegistry — Directory & File Conventions

The shipped `where_to_look` prompt covers `users/` and `usersc/`. This prompt covers
the ElanRegistry-specific subtree under `app/` and the project-level conventions that
aren't generic UserSpice.

---

## Full project layout (ElanRegistry additions in bold)

```
projectroot/
├── users/            # UserSpice core — never edit
├── usersc/           # UserSpice customizations — ElanRegistry classes, plugins, templates
│   ├── classes/      # Custom PHP classes (Car, ElanRegistryOwner, ApiResponse, ...)
│   ├── plugins/      # ai_prompts, db_explainer, and others
│   ├── includes/     # server_globals.php, custom_functions.php, footer.php, ...
│   └── templates/    # customizer/ child theme (bootstrap 5.3.3)
├── app/              # ElanRegistry application pages
│   ├── action/       # Form-submission handlers (non-AJAX mutations)
│   ├── admin/        # Admin-only pages
│   │   ├── assets/   # Admin JS/CSS source files
│   │   ├── includes/ # Admin PHP includes and classes
│   │   └── scripts/
│   │       ├── fix/          # One-time migration scripts (run once, never again)
│   │       └── maintenance/  # Repeatable maintenance scripts (safe to re-run)
│   ├── api/          # AJAX API endpoints
│   │   ├── admin/    # Admin-only API endpoints (process-settings.php)
│   │   ├── cars/     # Car-specific API endpoints (save, history, chassis-validate, etc.)
│   │   ├── contact/  # Contact form API endpoints (send-feedback, send-owner-email)
│   │   └── shared/   # Shared API endpoints (statistics, location-search, location-reverse)
│   ├── assets/       # First-party JS/CSS source files (built → minified in place)
│   ├── cars/         # Car pages (index, details, edit, factory, etc.)
│   ├── contact/      # Contact form pages
│   └── views/        # Reusable view partials
├── docs/             # User-facing docs (guides/, reference/, stories/, admin/)
├── error/            # Branded HTTP error pages (403, 404, 500)
└── z_us_root.php     # Path registry — must be updated for every new directory
```

---

## The `$path` array in `z_us_root.php`

UserSpice's path resolver reads `z_us_root.php` to locate the project root.
**Every new directory you create under the project root must be added to the `$path` array.**
If you add a directory and forget this step, `require_once` statements from files in that
directory will fail silently because `$abs_us_root` and `$us_url_root` won't resolve correctly.

```php
// z_us_root.php — add your new directory to this array
$path = [
    '',
    'users/',
    'usersc/',
    'app/',
    'app/admin/',
    'app/admin/scripts/fix/',
    'app/admin/scripts/maintenance/',
    'app/cars/',
    'app/api/contact/',      // contact endpoints call securePage()
    // ... add 'your/new/directory/' here
];
```

---

## AJAX parsers: where they go in ElanRegistry

The shipped `secure_page_pattern` says AJAX endpoints must live in a `parsers/` subfolder.
In ElanRegistry, **all AJAX API endpoints live under `app/api/`**, organised by domain:

| AJAX endpoint for | Lives in |
|---|---|
| Car operations | `app/api/cars/` |
| Contact form operations | `app/api/contact/` |
| Shared / cross-domain data | `app/api/shared/` |
| Admin settings updates | `app/api/admin/` |

The `parsers/` naming is not required in ElanRegistry. Directories under `app/api/` are generally
**not** added to the `$path` array in `z_us_root.php` because they do not call `securePage()`.
The exception is `app/api/contact/`, which is registered because both contact endpoints call
`securePage()`. Endpoints in `app/api/cars/`, `app/api/shared/`, and `app/api/admin/` must
**not** call `securePage()` — they enforce authentication inline.
All endpoints in `app/api/` must follow the Pattern A / ApiResponse convention.

---

## Fix scripts vs maintenance scripts

`app/admin/scripts/fix/` — **one-time migration scripts**
- Run once against a specific environment and never again
- Examples: backfilling a new column, correcting historical data
- Name them with a date prefix: `2025-06-01_backfill_chassis_format.php`

`app/admin/scripts/maintenance/` — **repeatable maintenance scripts**
- Safe to re-run multiple times
- Examples: rebuilding a cache, pruning orphaned images, re-indexing
- Name them descriptively without a date

---

## First-party JS and CSS

Source files live under `app/assets/` (public) and `app/admin/assets/` (admin).
After editing any source file, run:

```bash
npm run build   # minifies app/assets/js/, app/assets/css/, app/admin/assets/
```

Minified output is committed alongside source. Never edit the `.min.js` / `.min.css`
files directly.

Frontend libraries (Bootstrap, DataTables, etc.) are vendored to `usersc/js/` and
`usersc/css/` — do not use CDN links. See ADR-015 in `docs/development/adr/`.

---

## Template customization constraints

- `usersc/templates/customizer/` is **gitignored by UserSpice upstream** — never modify
  files there. The only tracked exception is `file_nav_custom.php` (project-owned).
- To add to the footer: inject via JS in `usersc/includes/footer.php`.
- To add to the nav: use `usersc/templates/customizer/file_nav_custom.php`.
- To add `<head>` tags: use `usersc/includes/head_tags.php`.

---

## Server globals (available on every page after `init.php`)

ElanRegistry initializes these in `usersc/includes/server_globals.php`.
Use them instead of `$_SERVER` directly.

```php
$scheme       // 'http' or 'https'
$is_https     // bool
$host         // validated HTTP_HOST
$method       // 'GET', 'POST', etc.
$request_uri  // sanitized REQUEST_URI
$current_url  // full URL of the current request
$php_self     // validated PHP_SELF — use in securePage($php_self)
$remote_addr  // client IP (Cloudflare-resolved)
$referer      // HTTP_REFERER or ''
$user_agent   // HTTP_USER_AGENT
```
