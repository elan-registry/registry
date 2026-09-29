---
paths:
  - "users/**"
  - "usersc/templates/**"
  - "usersc/plugins/**"
  - "usersc/includes/**"
  - "usersc/user_settings.php"
---

# Upstream UserSpice files

These directories contain upstream UserSpice files. Do not change those
files, except for the project-owned files listed below.
(`.claude/hooks/guard-upstream-paths.sh` also blocks edits to them.)

| Directory | Status | Project-owned files and rules |
| --- | --- | --- |
| `/users/` | Upstream framework | `users/cron/` contains project logging and hook calls in `cron.php`. Put all cron jobs in this directory. See the [Cron Transport section](../../docs/development/DEPLOYMENT.md#cron-transport-userspice-cron-manager). The `$GLOBALS['config']` array in `users/init.php` reads database and session values from `.env`. These values include `DB_HOST`, `DB_USER`, `DB_PASS`, `DB_NAME`, `SESSION_NAME`, `TOKEN_NAME`, and `REMEMBER_COOKIE_NAME`. This is project configuration. Keep all other files in `/users/` unchanged. Extend the framework through `usersc/classes/`. |
| `usersc/templates/` | Upstream templates | Project files include `customizer/file_nav_custom.php`, the `elanregistry*` and `dashboard.php` child themes, and `customizer.css`. UserSpice requires `customizer/navigation.php`, so Git tracks it. Do not edit it. Add navigation content to `file_nav_custom.php`. |
| `usersc/plugins/` | Upstream plugins | Project files include `hooker/hooks/` and `ai_prompts/custom_prompts/`. |
| `usersc/user_settings.php` | Project-owned override for `users/user_settings.php` | Make changes in this file. Do not change `users/user_settings.php`. |

- To add behavior, create a custom class in `usersc/classes/` under the
  `ElanRegistry\` namespace. Do not change files in `/users/`.
- To add footer content, add JavaScript to `usersc/includes/footer.php`.
  UserSpice includes this file after it renders the footer.
- To add header or navigation content, edit
  `usersc/templates/customizer/file_nav_custom.php`.
- A finding in a gitignored upstream file cannot be fixed in this repo (the
  fix does not deploy, and the next UserSpice update overwrites it). Report
  it upstream.
