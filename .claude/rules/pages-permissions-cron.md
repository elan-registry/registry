---
paths:
  - "app/**/*.php"
  - "docs/**/*.php"
  - "z_us_root.php"
  - "users/cron/**"
  - "usersc/classes/Cron/**"
---

# New pages, page permissions, and cron jobs

- Only add a directory to the `$path` array in `/z_us_root.php` when it
  contains files that call `securePage()`. Pure API endpoints, action
  handlers, and partials that do not call `securePage()` are **not** added
  (`app/api/cars/` and `app/api/shared/` are examples). `app/api/contact/` is
  an exception: its files call `securePage()`, so it is in `$path`.
- New admin scripts go under `app/admin/scripts/fix/` (one-time migrations)
  or `app/admin/scripts/maintenance/` (repeatable maintenance).
- **After you add any new page or admin script, run
  `21-Fix-Page-Permissions.php` on test, then prod,** to register the new path
  in UserSpice's permission table.
- Put all cron jobs in `users/cron/`. The `cron.php` dispatcher only looks for
  job files in this directory. Read the
  [Cron Transport section](../../docs/development/DEPLOYMENT.md#cron-transport-userspice-cron-manager)
  before you write a cron job.
- Public exceptions to the usual auth pattern, each documented in its file
  header: `app/api/shared/sitemap.php` (XML, no auth, CSRF, or rate limit, so
  crawlers can read it), `app/verify/verify_car.php` and
  `app/api/webhooks/brevo.php` (no session; auth is a single-use token or a
  webhook secret). Read the header before you copy either pattern. Update
  ADR-019 when you enable or disable CSRF or rate limiting on a public API
  endpoint.
