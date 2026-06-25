# Elan Registry: Architecture and Database Design

**Last Updated:** 2026-06-25 (v2.25.0)

## Overview

The Elan Registry is a PHP-based car registry web application built on the UserSpice 6
authentication framework. The system manages a database of Lotus Elan and Elan +2 vehicles
with comprehensive owner, transaction, and technical documentation.
It's hosted at <https://elanregistry.org> with global CDN distribution via Cloudflare.

**Key Facts:**

- **Language:** PHP 8.2+
- **Database:** MySQL 8.0+
- **Template Framework:** Bootstrap 5.3.3 (Customizer template with `elanregistry` child
  theme)
- **Authentication:** UserSpice 6 framework
- **Real-time Features:** WebSocket notifications, live connection status
- **Environment Configuration:** `.env` file via `vlucas/phpdotenv`

---

## Directory Structure

### Root Level

```text
elanregistry/
├── /app/                   # Main application pages and controllers
├── /docs/                  # User-facing documentation system
├── /error/                 # Branded HTTP error pages (403, 404, 500)
├── /users/                 # UserSpice 6 framework (READ-ONLY - never edit)
├── /usersc/                # UserSpice customizations and overrides
├── /userimages/            # User-uploaded car photos
├── /tests/                 # PHPUnit and Playwright test suites
├── /scripts/               # Utility scripts (setup, migrations, tools)
├── /database/              # Database schemas, migrations, seed data
├── /wiki/                  # GitHub wiki pages (separate git repo)
├── z_us_root.php           # UserSpice core configuration
├── header.php              # Global page header with CDN settings
├── footer.php              # Global page footer with API client
└── .env                    # Environment configuration (chmod 600)
```

### /app/ — Application Pages

Main application functionality organized by feature:

```text
/app/
├── /cars/               # Car registry management
│   ├── index.php       # List all cars (public)
│   ├── detail.php      # Single car detail view (public)
│   ├── form.php        # Add/edit car form (authenticated)
│   ├── delete.php      # Delete car action (authenticated)
│   ├── factory.php     # Production Records reference (public)
│   └── /ajax/          # AJAX endpoints for car operations
├── /reports/           # Analytics and statistics
│   └── statistics.php  # Registry statistics dashboard (public)
├── /admin/             # Admin pages and maintenance interface
│   ├── manage-consolidated.php  # Main admin dashboard (car/owner mgmt)
│   ├── manage-maintenance.php    # Maintenance portal (health, backups, etc)
│   ├── /scripts/        # Administrative scripts (one-time + repeatable)
│   │   ├── /fix/        # One-time migration and fix scripts
│   │   │   ├── _TEMPLATE_Fix-Script.php
│   │   │   ├── _ARCHIVE/  # Archived/completed scripts
│   │   │   └── README.md
│   │   └── /maintenance/  # Repeatable maintenance tasks
│   │       ├── 21-Fix-Page-Permissions.php
│   │       ├── 24-Regenerate-Optimized-Thumbnails.php
│   │       └── .htaccess
│   ├── /includes/       # Admin tab content and modal templates
│   │   ├── tab-car_mgmt.php        # Car/owner relationships (consolidated)
│   │   ├── tab-manage_cars.php     # Manage cars interface
│   │   ├── tab-owner_mgmt.php      # Manage owners interface
│   │   ├── tab-health.php          # System health monitoring [NEW v2.20]
│   │   ├── tab-maintenance.php     # Maintenance scripts portal [NEW]
│   │   ├── tab-settings.php        # Configuration settings
│   │   ├── confirmation-modal.php  # Confirmation dialog [NEW v2.20]
│   │   ├── input-modal.php         # Input prompt dialog [NEW v2.20]
│   │   └── *.php (other includes)
│   ├── /assets/         # Admin page assets (CSS, JS, images)
│   └── /verify/         # Car verification workflow
├── /contact/           # Feedback and contact forms
│   └── index.php       # Contact form (authenticated)
└── /ajax/              # General-purpose AJAX endpoints
    └── *.php           # Feature-specific endpoints
```

### /docs/ — Documentation System

The documentation system has been reorganized into four distinct categories with
different access levels and serving mechanisms:

```text
/docs/
├── /guides/                  # User how-to documentation
│   ├── index.php            # Guides index page
│   ├── ADD_CAR_GUIDE.md
│   ├── CAR_TRANSFER_USER_GUIDE.md
│   ├── CAR_TRANSFER_FAQ.md
│   ├── PRIVACY.md
│   └── /screenshots/        # Guide screenshot assets
├── /reference/              # Technical reference (native PHP pages)
│   ├── index.php           # Reference library index
│   ├── identification-guide.php   # Elan identification
│   ├── chassis-validation.php     # VIN/chassis validation
│   ├── paint-colors.php          # Original paint color data
│   ├── workshop.php              # Workshop resources
│   ├── technical-articles.php    # Technical articles collection
│   ├── /assets/            # PDF files for reference section
│   └── /images/            # Reference page images
├── /admin/                  # Admin documentation (admin access required)
│   ├── index.php           # Admin docs index
│   ├── CAR_TRANSFER_ADMIN_GUIDE.md
│   ├── CAR_TRANSFER_ADMIN_QUICK_REFERENCE.md
│   ├── CAR_TRANSFER_TROUBLESHOOTING.md
│   ├── EMAIL_STYLING_GUIDELINES.md
│   └── SPAM_CLEANUP_SYSTEM.md
├── /stories/               # Car history stories (markdown, no index)
│   ├── /assets/            # PDF files for stories section
│   └── *.md files
├── /development/           # Developer documentation (internal only)
│   ├── CLAUDE.md
│   ├── CODING_STANDARDS.md
│   ├── QUICK_REFERENCE.md
│   ├── DATABASE.md
│   ├── ENVIRONMENT.md
│   ├── DEPLOYMENT.md
│   ├── ERROR_HANDLING.md
│   ├── CLASSES.md
│   ├── USERSPICE_FUNCTIONS.md
│   ├── PAGE_LOADING_FLOW.md
│   ├── EMAIL_SYSTEM.md
│   └── /adr/              # Architecture Decision Records
├── /testing/              # Testing documentation
├── /assets/              # Legacy PDF fallback directory (deprecated)
├── /faq/                 # Legacy FAQ directory (deprecated)
├── car-stories.php       # Car stories listing page (public)
├── guide-viewer.php      # Markdown document viewer
├── pdf-viewer.php        # PDF viewer (reference, stories)
├── index.php             # Documentation hub landing page
├── README.md             # Documentation index and navigation guide
└── .htaccess            # URL redirects for documentation
```

#### Documentation Categories

**Guides** (`/docs/guides/`)

- User-facing how-to documentation
- Markdown files rendered via `guide-viewer.php`
- Public access (no authentication required)
- Includes: car registration, transfer procedures, privacy policy

**Reference** (`/docs/reference/`)

- Technical reference content
- Native PHP pages (not markdown)
- Public access
- Includes: identification guides, chassis validation, paint colors, technical articles
- Related to `/app/cars/factory.php` (Production Records)

**Admin** (`/docs/admin/`)

- Administration and maintenance procedures
- Markdown files rendered via `guide-viewer.php`
- Admin authentication required
- Includes: transfer procedures, admin workflows, troubleshooting

**Stories** (`/docs/stories/`)

- Individual car history narratives
- Listed via `/docs/car-stories.php`
- Markdown files
- No central index (individual story links only)

#### Document Routing

The documentation system uses two main viewer pages:

1. **`guide-viewer.php`** — Renders markdown documents with authentication checks
   - Serves: `/docs/guides/` and `/docs/admin/`
   - Uses `DocumentConfig` class for metadata and access control
   - Handles breadcrumbs, titles, and styling

2. **`pdf-viewer.php`** — Embeds PDF documents
   - Accepts `subdir` parameter (allowlisted: `reference`, `stories`)
   - Maps legacy `/docs/assets/` URLs to new locations
   - Provides PDF.js viewer with controls

#### DocumentConfig Class

The `DocumentConfig` class (`/usersc/classes/DocumentConfig.php`) centralizes
documentation configuration:

```php
DocumentConfig::getCategories() // Returns 'guides' and 'admin' with paths
DocumentConfig::getDocumentInfo() // Returns document metadata
```

Note: The `reference` section uses standalone PHP pages and does not use
DocumentConfig routing.

#### URL Redirects

Old documentation URLs redirect automatically via `/docs/.htaccess`.

### /usersc/ — Custom Application Code

Contains all custom classes, helpers, plugins, and template overrides:

```text
/usersc/
├── /classes/              # Custom application classes
│   ├── Car.php           # Car registry entity
│   ├── ElanRegistryOwner.php   # Owner profile entity
│   ├── ApiResponse.php   # Standardized API response format
│   ├── DocumentConfig.php   # Documentation metadata and routing
│   ├── LocationService.php   # OpenStreetMap integration
│   ├── Logger.php        # Application logging
│   ├── LogCategories.php   # Log category constants
│   └── ...
├── /plugins/             # UserSpice plugins (Brevo email, etc.)
├── /templates/           # Template overrides and customizations
│   └── /customizer/      # Active template (Bootstrap 5.3.3)
│       └── file_nav_custom.php  # Project-owned navigation
├── /js/                  # Self-hosted third-party JS libraries
├── /css/                 # Self-hosted third-party CSS libraries
├── /includes/            # Include files and helpers
│   ├── server_globals.php   # Validated $_SERVER globals
│   ├── init.php           # Core initialization
│   └── ...
└── /customization/       # Framework hooks and customizations
```

### /users/ — UserSpice Framework (Read-Only)

The UserSpice 6 framework directory. **Never edit files in `/users/`** — modifications
break framework compatibility during updates. Use `/usersc/` for all customizations.

Key framework locations:

- `/users/admin.php` — UserSpice admin dashboard
- `/users/account.php` — User account management
- `/users/login.php`, `/users/join.php`, `/users/logout.php` — Authentication

---

## Admin Panel Architecture (v2.20.0)

The admin interface has been split into two focused portals:

### Registry Management (`manage-consolidated.php`)

**Purpose:** Day-to-day car and owner administration.

**Access:** Admin authentication required via UserSpice permission system.

**Tabs:**

- **Car/Owner Relationships** — Link cars to owners, handle transfers, manage
  relationships
- **Manage Cars** — Full car record editing, deletion, status management
- **Manage Owners** — Owner profile management, contact info, preferences

**Includes:**

- `tab-car_mgmt.php` — Car/owner relationship interface
- `tab-manage_cars.php` — Car management interface (86 KB)
- `tab-owner_mgmt.php` — Owner management interface (57 KB)
- Plus supporting include files for form processing

### Registry Maintenance (`manage-maintenance.php`) [NEW v2.20.0]

**Purpose:** System maintenance, monitoring, backups, and configuration.

**Access:** Admin authentication required via UserSpice permission system.

**Tabs:**

- **Health** (`tab-health.php`) [NEW] — Read-only system health monitoring
  - Database status and statistics
  - Backup storage monitoring
  - Pending migration scripts list (from `/app/admin/scripts/fix/`)
  - All data is read-only; no state changes

- **Maintenance** (`tab-maintenance.php`) [NEW] — Maintenance and backup
  operations
  - One-time fix scripts (from `/app/admin/scripts/fix/`)
  - Repeatable maintenance tasks (from `/app/admin/scripts/maintenance/`)
  - Database backup/restore operations
  - Schema operations and migrations
  - State-changing operations performed via AJAX endpoints

- **Configuration** (`tab-settings.php`) — ElanRegistry settings
  - CDN URLs (bootstrap, jQuery, fonts, custom CSS)
  - Media and storage settings
  - Email service configuration

**Includes:**

- `tab-health.php` — System health monitoring
- `tab-maintenance.php` — Maintenance scripts portal
- `tab-settings.php` — Configuration management
- `confirmation-modal.php` [NEW] — Confirmation dialog for destructive operations
- `input-modal.php` [NEW] — Input prompt dialog for backup naming

### Administrative Scripts (v2.20.0)

Scripts are organized into two directories under `/app/admin/scripts/`:

#### Fix Scripts (`/app/admin/scripts/fix/`)

**Purpose:** One-time migration and cleanup scripts that run at most once per
installation.

**Storage:** Database table `fix_script_runs` tracks completion status.

**Scripts:**

- Numbered format: `##-Descriptive-Name.php`
- Template: `_TEMPLATE_Fix-Script.php` for new scripts
- Archive: `_ARCHIVE/` directory for completed/obsolete scripts

**Access:**

- Via tab-maintenance.php interface (recommended)
- Direct URL access via numbered filenames
- `.htaccess` blocks access to `_TEMPLATE*.php` and directory listing
- Requires UserSpice authentication

**Features:**

- Progress tracking in `fix_script_runs` table
- Run status indicators (✅ run, ➖ never run)
- Transaction support for atomic operations
- Comprehensive error handling and logging
- Completion reporting

#### Maintenance Tasks (`/app/admin/scripts/maintenance/`)

**Purpose:** Repeatable maintenance operations that run multiple times (not just
once).

**Scripts:**

- Named format: `##-Descriptive-Name.php`
- No completion tracking in database (can run repeatedly)

**Access:**

- Via tab-maintenance.php interface (recommended)
- Direct URL access via numbered filenames
- `.htaccess` prevents directory listing and blocks non-PHP files
- Requires UserSpice authentication

**Features:**

- Can be run multiple times without completion tracking
- Suitable for periodic cleanup, optimization, and sync operations
- Same error handling and logging as fix scripts

### New Modal Components (v2.20.0)

Two reusable modal components for admin operations:

**`confirmation-modal.php`**

- Asks user to confirm destructive operations (delete, reset, etc.)
- Displays warning message and requires explicit confirmation
- Returns confirmation status to calling code

**`input-modal.php`**

- Prompts user for input (e.g., backup name, migration parameters)
- Validates input before submitting
- Returns input value to calling code

---

## Navigation Architecture (v2.18.2)

Navigation has been converted from database-driven to file-based configuration.

### File-Based Navigation

**Location:** `/usersc/templates/customizer/file_nav_custom.php`

This file defines the main navigation structure and is included by the Customizer
template. It is the only project-tracked file in the `customizer/` directory.

### Top-Level Menu Items (6 items)

1. **List Cars** — Public car listing
   - `app/cars/index.php`

2. **Statistics** — Registry statistics
   - `app/reports/statistics.php`

3. **Reference** (dropdown) — Technical reference with 5 sub-items
   - Reference Library (`docs/reference/index.php`)
   - Identification Guide (`docs/reference/identification-guide.php`)
   - Chassis Validation (`docs/reference/chassis-validation.php`)
   - Production Records (`app/cars/factory.php`)
   - Paint Colors (`docs/reference/paint-colors.php`)

4. **Car Stories** — Car history narratives
   - `docs/car-stories.php`

5. **Guides** — User how-to documentation
   - `docs/guides/index.php`

6. **Account Dropdown** (when logged in)
   - Account settings (`users/account.php`)
   - Feedback (`app/contact/index.php`)
   - Logout (`users/logout.php`)

### Navigation Changes from Previous Version

- **Removed:** "Home" link (logo links to home)
- **Renamed:** "Technical Resources" → "Reference" dropdown
- **Renamed:** "FAQ" → "Guides"
- **Moved:** "Feedback" → Account dropdown (was top-level)
- **Renamed:** "Factory Data" → "Production Records" (in Reference dropdown)
- **Consolidated:** 9 items reduced to 6 items

---

## Database Architecture

### Schema Overview

The system uses MySQL 8.0+ with comprehensive schema for car registry, owner
management, transaction tracking, and audit logs.

**Core Tables:**

- **`cars`** — Vehicle records with denormalized owner info for performance (includes `chassis_override` flag as of v2.25.0)
- **`owners`** — Owner profiles with contact and preference data
- **`cars_hist`** — Audit trail of all car table modifications via trigger (includes `chassis_override` flag as of v2.25.0)
- **`car_user_hist`** [NEW v2.25.0] — Trigger-written audit trail of all `car_user` relationship changes (INSERT/UPDATE/DELETE)
- **`users`** — UserSpice authentication (3rd-party framework)
- **`notifications`** — User notification queue (WebSocket support)
- **`settings`** — Application configuration (33+ custom columns)
- **`contact_form_submissions`** — Feedback and contact form data
- **`email_log`** — Email transmission log for Brevo integration
- **`fix_script_runs`** [NEW v2.20.0] — Tracks completion of one-time fix scripts

**Audit Trail:** Automatic capture via database triggers on cars table. See
`docs/development/DATABASE.md` for complete schema.

### Performance Optimization

**Data Denormalization:** Owner information (name, email, etc.) is cached in the
cars table to avoid expensive joins. The denormalized data is kept in sync via
trigger on owner updates.

**Caching:** LocationService implements 5-minute server-side caching for
OpenStreetMap API responses.

---

## Security Architecture

The system employs **defense in depth** with multiple overlapping security layers:

1. **Authentication & Sessions**
   - UserSpice 6 framework handles user authentication
   - Session-based with secure cookie flags
   - Password hashing via PHP's `password_*` functions

2. **Authorization**
   - `securePage($php_self)` check on protected pages
   - Role-based menu visibility (admin, users, public)
   - Admin panel requires `checkMenu(2, $userId)` permission
   - Admin scripts require UserSpice authentication via `.htaccess`

3. **Input Validation & Encoding** (v2.23.0+)
   - All user input validated and sanitized
   - Prepared statements for all SQL queries
   - White-list input validation where possible
   - **Encode-at-output pattern**: text fields stored raw via
     `ElanRegistry\Input::raw()` and escaped with
     `htmlspecialchars($v, ENT_QUOTES, 'UTF-8')` at the render layer only.
     Never use UserSpice's `\Input::get()` for values destined for storage —
     it applies `htmlspecialchars()` before returning, which causes
     double-encoding on display. See
     `docs/development/CODING_STANDARDS.md` for field coverage.

4. **CSRF Protection**
   - CSRF tokens on all forms
   - Token validation via UserSpice framework
   - Token generation in both main admin pages
   - Fix scripts require POST + CSRF token to execute (as of v2.25.0)

5. **Security Headers**
   - Content Security Policy (CSP) with script/style allowlist
   - HSTS for HTTPS enforcement
   - X-Frame-Options to prevent clickjacking
   - X-Content-Type-Options: nosniff
   - See `docs/development/adr/ADR-007-Content-Security-Policy.md`

6. **HTTPS & CDN**
   - Cloudflare edge caching with automatic HTTPS
   - Server certificate management via Let's Encrypt
   - Global CDN distribution (US, EU, AU)

7. **Directory Protection**
   - `.htaccess` rules prevent direct file browsing
   - Admin scripts require authentication
   - `/docs/assets/` protected from directory listing
   - Environment files protected from access

8. **Audit Logging**
   - Application-level logging via Logger class (90+ categories)
   - Database-level audit via triggers on sensitive tables
   - Email transmission logged for compliance
   - Admin script runs logged in `fix_script_runs` table

---

## Key Integration Points

### Page Security

All protected pages require the `securePage()` check:

```php
<?php
securePage($php_self);  // Redirect if not authenticated
// Page code continues...
```

**Note:** For admin pages, additional permission check is needed:

```php
if (!checkMenu(2, $user->data()->id)) {
    die('Admin access required');
}
```

See [UserSpice Integration
Guide](https://github.com/unibrain1/elanregistry/wiki/Customization-and-Integration-Patterns)
for details.

### Adding New PHP Directories

When adding a new PHP directory (e.g., `/app/new-feature/`), update the `$path`
array in `/z_us_root.php` to register it with UserSpice:

```php
$path = [
    'app',
    'app/cars',
    'app/reports',
    'app/admin',
    'app/contact',
    'app/new-feature',  // Add new directory here
];
```

### Database Operations

Use prepared statements for all queries. See `docs/development/DATABASE.md` for
schema details and `docs/development/CLASSES.md` for ORM patterns.

**Example:**

```php
$stmt = $this->pdo->prepare("SELECT * FROM cars WHERE id = ?");
$stmt->execute([$carId]);
$car = $stmt->fetch();
```

### Email Integration

Email is handled by Brevo plugin with UserSpice `sendMail()` function. See
`docs/development/EMAIL_SYSTEM.md` for configuration and template setup.

### External Services

The system integrates with:

- **Cloudflare** — CDN and edge caching
- **OpenStreetMap / Nominatim** — Location geocoding with client caching
- **VersaTiles** (`tiles.versatiles.org`) — Map tile server for self-hosted MapLibre GL JS; no API key required
- **reCAPTCHA** — Contact form protection
- **Brevo** — Transactional email service

All external service credentials stored in database settings (`$settings` global)
and configurable via admin panel without code deployment.

---

## Template Architecture

### Active Template

**Location:** `/usersc/templates/customizer/` (upstream Customizer template) with
`elanregistry` child theme

**Framework:** Bootstrap 5.3.3

**Dependencies:**

- jQuery (required by UserSpice 6, cannot be removed; loaded via
  `users/js/jquery.php`)
- Bootstrap 5.3.3 CSS/JS (loaded by Customizer `header.php`; self-hosted in
  `usersc/css/` and `usersc/js/`)
- Font Awesome icons (self-hosted in `usersc/css/`)
- DataTables (self-hosted in `usersc/js/` and `usersc/css/`)
- Flatpickr date picker (self-hosted in `usersc/js/` and `usersc/css/`)
- FilePond 4.x + plugins (self-hosted in `usersc/js/` and `usersc/css/`)
- Chart.js (self-hosted in `usersc/js/`)
- MapLibre GL JS 4.7.1 (self-hosted in `usersc/js/` and `usersc/css/`; map style JSON at `usersc/js/versatiles-colorful.json`)

**Template Customization Rule:** `usersc/templates/customizer/` is gitignored
upstream. The **only** project-tracked file in this directory is
`file_nav_custom.php`. To add header/nav content, edit that file. To inject
footer content, use `usersc/includes/footer.php`.

### Self-Hosted Frontend Libraries (v2.19.0+)

All third-party JS/CSS libraries are source-controlled and versioned in:

- `usersc/js/` — Third-party JavaScript
- `usersc/css/` — Third-party CSS

This replaces the previous database-driven CDN configuration. Benefits: Dependabot
security alerts for JS/CSS dependencies, offline-capable, version-controlled
upgrades.

### Build Pipeline (v2.19.0+)

First-party JS and CSS are minified with **esbuild** and committed to git as
`.min.js` / `.min.css` files:

```bash
npm run build       # Regenerate all minified assets
npm run lint        # ESLint for JavaScript
npm run lint:fix    # ESLint with auto-fix
```

The pre-commit hook auto-rebuilds minified files when source files change.

### Architecture Decision Records

Frontend dependency changes and CSP updates require updating ADRs:

- `docs/development/adr/ADR-015-Self-Hosted-Frontend-Libraries.md` (supersedes
  ADR-006)
- `docs/development/adr/ADR-016-File-Based-Navigation.md`
- `docs/development/adr/ADR-007-Content-Security-Policy.md`

---

## Application Classes

Key classes are documented in `docs/development/CLASSES.md`:

- **`Car`** — Car entity with properties and methods. As of v2.25.0, `delete(string $reason, string $token)` requires
  a CSRF token — the parameter is no longer nullable or optional
- **`ElanRegistryOwner`** — Owner profile with contact/preference data
- **`ApiResponse`** — Standardized response format for AJAX endpoints
- **`LocationService`** — OpenStreetMap integration with server-side caching and logged I/O error handling
- **`PagePermissionClassifier`** (`usersc/classes/admin/`) — Classifies pages into permission tiers (admin-only, admin+editor,
  private user, special no-perms) for the Fix Page Permissions maintenance script
- **`ElanRegistry\Input`** (v2.23.0+, `usersc/classes/Input.php`) —
  Storage-safe POST/GET reader. `Input::raw()` returns the value unmodified,
  bypassing UserSpice's `htmlspecialchars()` pre-encoding so text fields
  reach the database raw and can be escaped at output. Mandatory for any
  field destined for storage.
- **`CarValidator`** — Validates car form input; includes `chassis_override` field validation (coerces to 0 or 1 integer)
- **`CarVerificationManager`** — Handles `markVerified()` and `markSold()` with overflow-date rejection
- **`Logger`** — Application event logging
- **`LogCategories`** — Logging category constants

---

## Server Environment Globals

**Available Since:** v2.13.0

Validated server environment globals are initialized in
`usersc/includes/server_globals.php` and available on every page after `init.php`:

```php
$scheme         // http or https
$is_https       // boolean
$host          // Domain name
$method        // GET, POST, etc.
$request_uri   // Full request URI
$current_url   // Complete current URL
$current_origin // Scheme + host
$php_self      // Current PHP file
$remote_addr   // Client IP
$referer       // HTTP Referer header
$user_agent    // Client User-Agent
```

**IMPORTANT:** Never use raw `$_SERVER` directly. Use these validated globals
instead. See `docs/development/PAGE_LOADING_FLOW.md` for usage examples.

---

## Frontend API Client

**Pattern A (v2.12.0+)** is the standard for all AJAX endpoints.

**Available Globally:** Via `footer.php` as `ElanRegistryAPI`

**Response Format:**

```json
{
  "success": true/false,
  "message": "Status or error message",
  "data": { /* Additional data */ }
}
```

See `docs/development/ERROR_HANDLING.md` for:

- Pattern A usage examples
- Migration guide from `jQuery.ajax()`
- Error handling patterns

---

## Testing

### PHP Testing (PHPUnit 12)

```bash
composer test:quick     # Unit tests only (~30s)
composer test:medium    # Unit + Integration (~2min)
composer test:full      # All PHP tests
composer test:coverage  # Coverage report
```

### Build & Code Quality

```bash
npm run build           # Minify all first-party JS/CSS (esbuild)
composer check:php      # PHP standards + PHPStan
composer check          # Full: PHP standards + PHPStan + ESLint
npm run lint            # ESLint for JavaScript
npm run lint:fix        # ESLint with auto-fix
```

### End-to-End Testing

**Local (MAMP at localhost:9999):**

```bash
npm run playwright:install      # Install browsers
npm run playwright:test         # All tests
npm run playwright:security     # Security checks
npm run playwright:maps         # Maps & charts
npm run playwright:csp          # CSP validation
npm run playwright:bs5          # Bootstrap 5 JS API regression tests
```

**Against Deployed:**

```bash
npm run test:e2e                # elanregistry.org
npm run test:e2e:test           # test.elanregistry.org
```

---

## Development Requirements

**PHP:** 8.2+ with `declare(strict_types=1)` required for all new files

**Type Hints:** Complete parameter and return type hints required

**Documentation:** PHPDoc blocks for all public methods

See `docs/development/CODING_STANDARDS.md` for complete standards.

---

## Quick Reference

**Key Documentation Files:**

| Document | Purpose |
| -------- | ------- |
| `CLAUDE.md` | AI assistant guidance and quick reference |
| `docs/development/CODING_STANDARDS.md` | Code quality requirements |
| `docs/development/DATABASE.md` | Schema and queries |
| `docs/development/CLASSES.md` | Application classes |
| `docs/development/ERROR_HANDLING.md` | Error patterns and AJAX |
| `docs/development/QUICK_REFERENCE.md` | Common tasks lookup |
| `docs/development/DEPLOYMENT.md` | Production procedures |
| `docs/development/ENVIRONMENT.md` | Setup and configuration |
| `docs/development/USERSPICE_FUNCTIONS.md` | UserSpice API reference |

**GitHub Resources:**

- [Architecture Guide](https://github.com/unibrain1/elanregistry/wiki)
- [UserSpice Integration
  Guide](https://github.com/unibrain1/elanregistry/wiki/Customization-and-Integration-Patterns)
- [Development Workflow](https://github.com/unibrain1/elanregistry/wiki/Development-Workflow)

---

## Version History

| Version | Date | Changes |
| ------- | ---- | ------- |
| 2.25.0 | 2026-06-25 | Security & data integrity: IDOR ownership checks on contact and car-edit endpoints; sender impersonation fix; car website server-side scheme validation; fix-script CSRF hardening; `Car::delete()` CSRF token now required; `chassis_override` column on `cars`/`cars_hist`; `car_user` audit triggers and indexes; overflow-date rejection in `markSold()`; duplicate `cars_hist` row on deletion removed |
| 2.24.1 | 2026-06-23 | Guide rendering hotfix: replaced `league/commonmark` runtime rendering with static PHP pages; `guide-viewer.php` deleted; Transfer FAQ (`docs/guides/car-transfer-faq.php`) is the sole survivor; `MarkdownRenderer`, `DocumentConfig`, `DocumentationException` classes removed; `docs/admin/` section removed; `document-content.css` added for scoped guide typography |
| 2.24.0 | 2026-06-22 | UX improvements: factory records context paragraph, paint colors page (larger swatches, filter tabs), statistics timeline charts moved above fold, add/edit car form UX fixes (native date pickers, premature validation icons), identification guide two-column card layout |
| 2.23.0 | 2026-05-11 | Encode-at-output reform: `ElanRegistry\Input::raw()` added in `usersc/classes/Input.php` for storage-safe input; double-encoding fixed across car, owner, and user/profile text fields, outbound emails, and templates; one-time migration script `03-Decode-All-HTML-Encoded-Fields.php` decodes legacy encoded data in `cars`, `users`, `profiles`; encode-at-output regression suite added |
| 2.22.0 | 2026-05-06 | Google Maps replaced with self-hosted MapLibre GL JS 4.7.1 + VersaTiles Colorful; Google Geocoding API code removed (LocationGeocoder.php deleted); elan_google_maps_key and elan_google_geo_key settings dropped; Google domains removed from CSP |
| 2.20.0 | 2026-05-01 | Admin panel split: manage-consolidated.php (car/owner mgmt) + manage-maintenance.php (system health/backups/settings); FIX scripts restructured to app/admin/scripts/fix/ (one-time) + app/admin/scripts/maintenance/ (repeatable); new tabs: tab-health.php, tab-maintenance.php; new modals: confirmation-modal.php, input-modal.php; fix_script_runs table |
| 2.19.0 | 2026-04-29 | Bootstrap 5.3.3 Customizer template; self-hosted frontend libraries; esbuild build pipeline; FilePond replaces Dropzone; `edit.php` → `form.php`; PHPUnit 12 |
| 2.18.2 | 2026-04-26 | Documentation reorganization: guides/reference/admin/stories split, file-based navigation |
| 2.18.0+ | 2026-03-20+ | Server environment globals, improved AJAX patterns, CSP refinements |
| Earlier | — | Previous architecture versions |
