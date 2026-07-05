# ADR-001: Adopt UserSpice as the Authentication and Authorization Framework

## Status

**Accepted** (retroactive)

## Date

Retroactive -- documented 2026-02-25

## Context

The Lotus Elan Registry is a web application that tracks ownership and history
of Lotus Elan cars manufactured between 1963 and 1974. It serves a global
community of car owners, researchers, and enthusiasts. The application requires
a mature authentication and authorization system to support:

- **User registration and login** for car owners worldwide
- **Role-based access control** with distinct permission tiers: public visitors,
  registered owners, editors, and administrators
- **Page-level permissions** to restrict access to specific application pages
- **CSRF protection** on all forms
- **Session management** with remember-me functionality
- **Password reset and email verification** workflows
- **Audit logging** of security-relevant events
- **Admin panel** for user and permission management

Building authentication from scratch is a well-documented source of security
vulnerabilities. The OWASP Top 10 consistently includes authentication and
access control failures. The project needed a production-ready authentication
system without the overhead of a full-stack MVC framework, while remaining
customizable enough to support car-registry-specific business logic layered on
top.

The application is a traditional server-rendered PHP application of moderate
size (approximately 30-40 pages), hosted on standard shared or VPS hosting.

## Decision

Adopt **UserSpice 5.x** (<https://userspice.com>) as the authentication,
authorization, and application scaffolding framework.

UserSpice provides the following capabilities used by the Elan Registry:

| Category | Capabilities |
| --- | --- |
| **Authentication** | Login, registration, password reset, email verification, remember-me, TOTP 2FA, passkeys |
| **Authorization** | Permission/role system with page-level access control |
| **Session & Security** | Session management, CSRF protection (Token class), rate limiting, IP ban system |
| **Database** | PDO-based DB abstraction (DB class singleton) |
| **Administration** | Admin panel for user, permission, and page management |
| **Email** | PHPMailer integration |
| **Logging** | Logger/audit system |
| **Input Handling** | Input and Validate classes for form processing |
| **Extensibility** | Template system, plugin system (Hooker), lifecycle hook scripts, i18n |
| **UI Infrastructure** | Database-driven menus, view includes |

### Integration Architecture

The integration follows a strict two-directory separation:

```text
/users/          Core UserSpice framework (NEVER modified, gitignored*)
/usersc/         Application extensions (fully version-controlled)
```

*Exception: `users/cron/` and `users/images/logo.png` are tracked in Git.

This separation is the key architectural insight: `/usersc/` acts as an override
layer on top of `/users/`. UserSpice checks `/usersc/` first for templates,
scripts, classes, and includes, falling back to `/users/` defaults when no
override exists.

#### /users/ Directory -- Core Framework

Contains all UserSpice core files. These are treated as vendor code:

- `init.php` -- application entry point, loaded by every page
- `login.php`, `join.php`, `logout.php`, `forgot_password.php` -- auth pages
- `admin.php` -- admin panel
- `classes/` -- DB, User, Token, Input, Validate, Session, Cookie, Hash, Config
- `helpers/` -- helpers.php, permissions.php, users.php
- `includes/`, `views/`, `lang/`

#### /usersc/ Directory -- Application Extensions

All project-specific code lives here:

- `classes/` -- Car, CarView, ElanRegistryOwner, ChassisValidator, ApiResponse,
  LogCategories, EmailTemplate, typed exception classes, and more
- `includes/` -- custom_functions.php, security_headers.php,
  server_globals.php, loader.php, footer.php, pre_footer.php (35+ files)
- `templates/ElanRegistry/` -- full custom template
- `scripts/` -- 17 lifecycle hook scripts (token_error.php,
  after_user_deletion.php, during_user_creation.php, etc.)
- `plugins/hooker/hooks/` -- page-specific hooks
- `views/` -- 8 custom email templates
- `login.php`, `join.php`, `user_settings.php` -- page overrides

#### Page Loading Sequence

Every page request follows a four-phase loading sequence that initializes 40-60+
PHP files:

```text
Phase 1: Core Init (users/init.php)
  Autoloaders -> Session -> DB connection -> $user object -> Environment -> Loader

Phase 2: Template Prep (prep.php)
  Header -> Navigation -> Container open

Phase 3: Page Content
  securePage($php_self) check -> Business logic -> HTML output

Phase 4: Footer
  Toast notifications -> Plugin hooks -> ElanRegistryAPI client -> Container close
```

#### The securePage() Pattern

Every protected page follows this structure:

```php
<?php
require_once '../../users/init.php';
require_once $abs_us_root . $us_url_root . 'users/includes/template/prep.php';
if (!securePage($php_self)) { die(); }

// Page-specific business logic
?>

<!-- HTML content -->

<?php require_once $abs_us_root . $us_url_root . 'users/includes/html_footer.php'; ?>
```

Pages must be registered in the UserSpice `pages` database table, and any new
PHP directories must be added to the `$path` array in `/z_us_root.php`.

#### Key Globals

UserSpice establishes several global variables available on every page:

| Variable | Type | Purpose |
| --- | --- | --- |
| `$db` | `DB` | Database singleton (PDO wrapper) |
| `$user` | `User` | Current user instance |
| `$settings` | `object` | Site configuration from database |
| `$abs_us_root` | `string` | Absolute filesystem root |
| `$us_url_root` | `string` | URL root path |
| `$lang` | `array` | Internationalization strings |

#### Custom Bridge Functions

The application defines bridge functions in `usersc/includes/custom_functions.php`
that wrap UserSpice internals with type-safe, domain-appropriate interfaces:

- `getUserWithProfile(int $user_id): ?object` -- joins `users` and `profiles`
  tables
- `isRegistryAdmin(int|string|null $userId = null): bool` -- wraps
  `hasPerm([2, 3])`
- `currentUserId(): int` -- type-safe current user ID extraction
- `dbInt()` -- type-safe database value extraction

## Consequences

### Positive

- **No custom auth code to maintain.** Registration, login, password reset,
  2FA, session management, and CSRF protection are handled by the framework.
  This eliminates an entire category of security vulnerabilities.
- **Clean vendor/custom separation.** The `/users/` vs `/usersc/` split means
  framework upgrades do not conflict with application code, and application code
  is fully version-controlled.
- **Override pattern enables deep customization.** Login pages, email templates,
  lifecycle hooks, and the full visual template are customized without modifying
  framework source.
- **Admin panel out of the box.** User management, permission assignment, page
  registration, and site settings are available immediately.
- **Battle-tested security.** CSRF token management, session fixation
  protection, rate limiting, and IP banning are production-proven.
- **Low hosting requirements.** Runs on standard PHP hosting without
  containerization or specialized infrastructure.

### Negative

- **No ORM.** All database queries are built manually using the DB class. Complex
  joins require hand-written SQL. There is no migration system; schema changes
  are managed through SQL scripts and database triggers.
- **Global state.** The `$db`, `$user`, and `$settings` singletons accessed as
  globals make unit testing harder. Test isolation requires mocking or resetting
  global state.
- **No compiled templates or auto-escaping.** The template system uses
  `require_once` for includes. Output escaping is manual (application uses
  `htmlspecialchars()` and the `e()` helper).
- **Monolithic page loading.** Every request loads 40-60+ PHP files. OPcache is
  required for acceptable production performance.
- **Not Composer-distributed.** UserSpice is installed by copying files into the
  project, not via Composer. Updates require manual filesystem operations and
  careful diffing.
- **Session-based auth only.** There is no native support for API authentication
  (JWT, API keys, OAuth tokens). Any future API requiring stateless auth would
  need a separate solution.

### Risks

| Risk | Likelihood | Impact | Mitigation |
| --- | --- | --- | --- |
| UserSpice breaking changes to hook/override API | Medium | High | Pin to known-good version; test on staging; `/usersc/` override layer buffers |
| UserSpice project discontinuation | Low-Medium | High | Self-contained in `/users/`; can run indefinitely; auth code forkable |
| Password hashing migration (bcrypt to Argon2id) | Low | Medium | Would require changes in `/users/` core; monitor PHP defaults and UserSpice roadmap |
| Performance degradation as page count grows | Low | Low | OPcache mitigates file loading overhead; application size is inherently bounded |

## Alternatives Considered

### Laravel

A full-stack PHP MVC framework with built-in authentication (Breeze/Fortify/
Jetstream).

**Rejected because:**

- Full MVC overhead (routing, Eloquent ORM, Blade templates, Artisan CLI) is
  disproportionate for a 30-40 page application
- Mandates its own conventions for routing, database access, and templating
- Hosting requirements are more complex (Composer autoloading, artisan commands,
  queue workers for some features)
- Migration cost from existing plain PHP pages would be substantial

### Symfony

A component-based PHP framework with a standalone Security component.

**Rejected because:**

- Even more configuration overhead than Laravel for a small application
- The Security component requires extensive setup for authentication flows
- No admin panel -- would need to build or integrate a separate package
- Same migration cost concerns as Laravel

### Custom Authentication

Building authentication and authorization from scratch using PHP sessions and
PDO.

**Rejected because:**

- Highest security risk of all options. Authentication is the most
  security-critical code in any web application, and custom implementations
  routinely contain vulnerabilities.
- Would require building: login/registration flows, password hashing, session
  management, CSRF protection, remember-me tokens, email verification, password
  reset, rate limiting, admin panel, permission system, and audit logging
- Ongoing maintenance burden for security patches and best-practice updates
- No benefit over adopting a proven solution

### Headless Authentication Services (Auth0, Firebase Auth, Clerk)

SaaS authentication providers with API/SDK integration.

**Rejected because:**

- Introduces an external SaaS dependency for a core application function
- Recurring subscription cost disproportionate for a registry with hundreds
  (not thousands) of users
- JavaScript-first SDKs are a poor fit for a server-rendered PHP application
- Data sovereignty concerns with storing user PII on third-party infrastructure
- Adds network latency to every authentication check

## References

- **UserSpice**: <https://userspice.com>
- **Page Loading Flow**: [docs/development/PAGE_LOADING_FLOW.md](../development/PAGE_LOADING_FLOW.md)
- **UserSpice Functions Reference**: [docs/development/USERSPICE_FUNCTIONS.md](../development/USERSPICE_FUNCTIONS.md)
- **Coding Standards**: [docs/development/CODING_STANDARDS.md](../development/CODING_STANDARDS.md)
- **Class Documentation**: [docs/development/CLASSES.md](../development/CLASSES.md)
- **Error Handling**: [docs/development/ERROR_HANDLING.md](../development/ERROR_HANDLING.md)
- **Custom Functions**: [usersc/includes/custom_functions.php](../../usersc/includes/custom_functions.php)
- **Root Path Configuration**: [z_us_root.php](../../z_us_root.php)
- **Git Ignore Strategy**: [.gitignore](../../.gitignore) (`users/**` exclusion with exceptions)
- **OWASP Top 10**: <https://owasp.org/www-project-top-ten/>
