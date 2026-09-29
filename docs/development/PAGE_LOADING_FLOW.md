# Page Loading Flow

This document describes the request flow for a standard application page. The
UserSpice files under `users/` are installed separately from this repository.
Their implementation can change when UserSpice is updated. Check the installed
files when you need an exact execution order.

## Request flow

1. **Load `users/init.php`.** UserSpice registers its core class loader and
   starts the session. The project then loads the root Composer autoloader from
   `vendor/autoload.php`. The PSR-4 rules in `composer.json` map the
   `ElanRegistry` namespaces to `usersc/classes/`.
2. **Load helpers and configuration.** UserSpice loads
   `usersc/includes/custom_functions.php` and enabled plugin overrides. It
   loads `.env` with `vlucas/phpdotenv`, creates the database connection, and
   initializes the current user. The root Composer autoloader provides project
   classes and Composer dependencies.
3. **Run the framework loader.** `users/init.php` loads
   `users/includes/loader.php`. That file loads site settings, security
   headers, language and access checks, then calls
   `usersc/includes/loader.php`. The project loader reads its configuration
   and initializes server globals, Turnstile, and local rate-limit overrides.
4. **Prepare the page template.** Most application pages load
   `usersc/includes/elanregistry_prep.php`. It selects the `elanregistry`
   child theme and loads `users/includes/template/prep.php`. The Customizer
   template loads its header, navigation, and content container. Pages can
   call `securePage($php_self)` to enforce the registered page permissions.
5. **Run page logic and render content.** The page handles its request,
   accesses project classes, and outputs its content inside the template.
   Application code uses `ApiResponse` for JSON endpoints where that response
   format applies. Read [ERROR_HANDLING.md](ERROR_HANDLING.md) for the API
   conventions.
6. **Close the template.** `users/includes/html_footer.php` loads the active
   template footer and the UserSpice footer hooks. The Customizer footer closes
   the content container and loads `users/includes/page_footer.php`.

## Project initialization hooks

| File | Purpose |
| --- | --- |
| `usersc/includes/custom_functions.php` | Global project helpers, loaded by UserSpice's helper file |
| `usersc/includes/loader.php` | Project initialization after UserSpice settings load |
| `usersc/includes/server_globals.php` | Validated request and URL globals, loaded by the project loader |
| `usersc/includes/elanregistry_prep.php` | Selects the site child theme and prepares the page template |
| `usersc/includes/pre_footer.php` | Project footer hook loaded by UserSpice |
| `usersc/templates/customizer/header.php` | Loads framework CSS/JavaScript and the active child-theme stylesheet |
| `usersc/templates/customizer/footer.php` | Closes the content container and loads footer handling |

## Asset loading

The Customizer template loads framework assets from the installed UserSpice
tree. It reads `usersc/templates/customizer/assets/css/revision.php` to select
the active base stylesheet and child-theme stylesheet.

Project assets are built by `scripts/build.js`. The build writes generated
dependencies to `usersc/js/` and `usersc/css/`. Those files are Git-ignored
and the deployment hook rebuilds them. See
[CSS_AND_ASSETS.md](CSS_AND_ASSETS.md) and
[ADR-018](adr/ADR-018-build-at-deploy-for-frontend-vendoring.md).

## Where to look when a request fails

- **Bootstrap or project class is missing:** check `composer.json`, run
  `composer install`, and confirm `vendor/autoload.php` exists.
- **Environment or database setup fails:** check `.env`, then follow
  [ENVIRONMENT.md](ENVIRONMENT.md).
- **A page denies access:** check `securePage()`, its UserSpice permission
  registration, and `PagePermissionClassifier` for project API routes.
- **Template or CSS is wrong:** check the active template setting,
  `elanregistry_prep.php`, and the Customizer revision file.
- **A generated JavaScript or CSS file is missing:** run `npm ci` and
  `npm run build` in the project root.

## Related documents

- [SYSTEM_OVERVIEW.md](SYSTEM_OVERVIEW.md) — application capabilities and roles
- [ENVIRONMENT.md](ENVIRONMENT.md) — local and deployed configuration
- [CLASSES.md](CLASSES.md) — project class responsibilities
- [ERROR_HANDLING.md](ERROR_HANDLING.md) — API responses and exceptions
