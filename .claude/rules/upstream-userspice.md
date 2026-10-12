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
| `usersc/templates/` | Upstream templates | Project files include `customizer/file_nav_custom.php`, the `elanregistry*` and `dashboard.php` child themes, and `customizer.css`. UserSpice requires `customizer/navigation.php`, so Git tracks it. Do not edit it by hand. On a UserSpice upgrade, replace it with the upstream version (see the list below). Add navigation content to `file_nav_custom.php`. |
| `usersc/plugins/` | Upstream plugins | Project files include `hooker/hooks/` and `ai_prompts/custom_prompts/`. Git tracks no `sendinblue/` file. The project uses sendinblue 1.8.0 or later as upstream ships it, which includes the tags option ([UserSpice bug 2411](https://bugs.userspice.com/issue/2411/)) and the `configure.php` CSRF fix ([UserSpice bug 2412](https://bugs.userspice.com/issue/2412/)). The dev-only mock-Brevo host is set in project code (`BrevoDevOverride::applyToSdk()`), not in the plugin. |
| `usersc/user_settings.php` | Project-owned override for `users/user_settings.php` | Make changes in this file. Do not change `users/user_settings.php`. |

- To add behavior, create a custom class in `usersc/classes/` under the
  `ElanRegistry\` namespace. Do not change files in `/users/`.
- To add footer content, add JavaScript to `usersc/includes/footer.php`.
  UserSpice includes this file after it renders the footer.
- To add header or navigation content, edit
  `usersc/templates/customizer/file_nav_custom.php`.
- The project overrides these upstream pages with `usersc/` copies:
  `login.php`, `join.php`, `user_settings.php`, and `account.php`.
  UserSpice serves the `usersc/` copy, so an upstream fix to the
  `users/` page does not reach the site. On each UserSpice upgrade, diff
  each of these upstream pages between the old and the new version. Then
  port each change into the `usersc/` copy by hand.
- Diff these other `usersc/` copies of upstream files on each upgrade too:
  `usersc/includes/system_messages_header.php`,
  `usersc/includes/system_messages_footer.php`,
  `usersc/includes/user_manager_columns.php`,
  `usersc/templates/customizer/navigation.php`, and
  `usersc/oauth_server/login_forms/default_login.php`. For the last two,
  take the upstream version.
- `users/init.php` also has one project line outside the config array:
  `ini_set('session.use_strict_mode', '1')`. Upstream sets it in
  `init.example.php`, not in an existing `init.php`. Keep it after each
  upgrade.
- A finding in a gitignored upstream file cannot be fixed in this repo (the
  fix does not deploy, and the next UserSpice update overwrites it). Report
  it upstream.
