# Elan Registry: Architecture and Database Design

**Last Updated:** 2026-04-26 (v2.18.2)

## Overview

The Elan Registry is a PHP-based car registry web application built on the UserSpice 6 authentication framework.
The system manages a database of Lotus Elan and Elan +2 vehicles with comprehensive owner, transaction, and technical documentation.
It's hosted at <https://elanregistry.org> with global CDN distribution via Cloudflare.

**Key Facts:**

- **Language:** PHP 8.2+
- **Database:** MySQL 8.0+
- **Template Framework:** Bootstrap 4.5.3 (migrating to Bootstrap 5)
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
│   ├── details.php     # Single car detail view (public)
│   ├── form.php        # Add/edit car form (authenticated)
│   ├── delete.php      # Delete car action (authenticated)
│   ├── factory.php     # Production Records reference (public)
│   └── /ajax/          # AJAX endpoints for car operations
├── /reports/           # Analytics and statistics
│   └── statistics.php  # Registry statistics dashboard (public)
├── /admin/             # Admin pages
│   ├── manage-consolidated.php  # Unified admin dashboard
│   └── /actions/       # Admin action handlers
├── /contact/           # Feedback and contact forms
│   └── index.php       # Contact form (authenticated)
└── /ajax/              # General-purpose AJAX endpoints
    └── *.php           # Feature-specific endpoints
```

### /docs/ — Documentation System (v2.18.2 Reorganization)

The documentation system has been reorganized into four distinct categories with different access levels and serving mechanisms:

```text
/docs/
├── /guides/                  # User how-to documentation (markdown → guide-viewer.php)
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
│   │   ├── Elan_26_36_Workshop_Manual.pdf
│   │   ├── Elan_S1_S2_Coupe_Masterpartslist.pdf
│   │   ├── 2016 Jan Elan Engine Types.pdf
│   │   └── ...
│   └── /images/            # Reference page images
├── /admin/                  # Admin documentation (markdown → guide-viewer.php, requires admin access)
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
│   ├── DataTables setup documentation
│   ├── CSS customization guide
│   ├── Testing procedures
│   └── /adr/              # Architecture Decision Records
├── /testing/              # Testing documentation
├── /assets/              # Legacy PDF fallback directory (deprecated)
├── /faq/                 # Legacy FAQ directory (deprecated, see /guides/)
├── car-stories.php       # Car stories listing page (public)
├── guide-viewer.php      # Markdown document viewer for guides and admin docs
├── pdf-viewer.php        # PDF viewer (accepts subdir parameter: reference, stories)
├── index.php             # Documentation hub landing page
├── README.md             # Documentation index and navigation guide
└── .htaccess            # URL redirects for documentation reorganization
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

The `DocumentConfig` class (`/usersc/classes/DocumentConfig.php`) centralizes documentation configuration:

```php
DocumentConfig::getCategories() // Returns 'guides' and 'admin' with paths and permissions
DocumentConfig::getDocumentInfo() // Returns document metadata (title, icon, description)
```

Note: The `reference` section uses standalone PHP pages and does not use DocumentConfig routing.

#### URL Redirects (v2.18.2)

Old documentation URLs redirect automatically via `/docs/.htaccess`:

| Old URL | New URL |
| ------- | ------- |
| `/docs/reference-library.php` | `/docs/reference/` |
| `/docs/faq/index.php` | `/docs/guides/index.php` |
| `/docs/faq/paint-colors.php` | `/docs/reference/paint-colors.php` |
| `/docs/faq/admin/index.php` | `/docs/admin/index.php` |
| `/docs/chassis-validation.php` | `/docs/reference/chassis-validation.php` |
| `/docs/embed.php` | `/docs/pdf-viewer.php` |
| `/docs/view.php` | `/docs/guide-viewer.php` |
| `/docs/assets/Elan_26_36_Workshop_Manual.pdf` | `/docs/reference/assets/Elan_26_36_Workshop_Manual.pdf` |
| `/docs/assets/...` (reference PDFs) | `/docs/reference/assets/...` |
| `/docs/assets/...` (story PDFs) | `/docs/stories/assets/...` |
| `/docs/faq/screenshots/*` | `/docs/guides/screenshots/*` |

### /usersc/ — Custom Application Code

Contains all custom classes, helpers, plugins, and template overrides:

```text
/usersc/
├── /classes/              # Custom application classes
│   ├── Car.php           # Car registry entity
│   ├── ElanRegistryOwner.php   # Owner profile entity
│   ├── ApiResponse.php   # Standardized API response format
│   ├── DocumentConfig.php   # Documentation metadata and routing
│   ├── LocationService.php   # OpenStreetMap integration with caching
│   ├── Logger.php        # Application logging
│   ├── LogCategories.php   # Log category constants
│   └── ...
├── /plugins/             # UserSpice plugins (Brevo email, etc.)
├── /templates/           # Template overrides and customizations
│   └── /ElanRegistry/    # Active template (Bootstrap 4.5.3)
│       ├── /assets/functions/
│       │   ├── nav.php          # File-based navigation menu (v2.18.2)
│       │   ├── header.php
│       │   ├── footer.php
│       │   └── ...
│       ├── /css/
│       ├── /js/
│       └── /views/
├── /includes/            # Include files and helpers
│   ├── server_globals.php   # Validated $_SERVER globals
│   ├── init.php           # Core initialization
│   └── ...
└── /customization/       # Framework hooks and customizations
```

### /users/ — UserSpice Framework (Read-Only)

The UserSpice 6 framework directory. **Never edit files in `/users/`** — modifications break framework compatibility during updates. Use `/usersc/` for all customizations.

Key framework locations:

- `/users/admin.php` — UserSpice admin dashboard
- `/users/account.php` — User account management
- `/users/login.php`, `/users/join.php`, `/users/logout.php` — Authentication

---

## Navigation Architecture (v2.18.2)

Navigation has been converted from database-driven to file-based configuration.

### File-Based Navigation

**Location:** `/usersc/templates/ElanRegistry/assets/functions/nav.php`

This file defines the main navigation structure and is included in the template header. Changes to navigation structure are made directly to this file.

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

The system uses MySQL 8.0+ with comprehensive schema for car registry, owner management, transaction tracking, and audit logs.

**Core Tables:**

- **`cars`** — Vehicle records with denormalized owner info for performance
- **`owners`** — Owner profiles with contact and preference data
- **`cars_hist`** — Audit trail of all car table modifications via trigger
- **`users`** — UserSpice authentication (3rd-party framework)
- **`notifications`** — User notification queue (WebSocket support)
- **`settings`** — Application configuration (33+ custom columns)
- **`contact_form_submissions`** — Feedback and contact form data
- **`email_log`** — Email transmission log for Brevo integration

**Audit Trail:** Automatic capture via database triggers on cars table. See `docs/development/DATABASE.md` for complete schema.

### Performance Optimization

**Data Denormalization:** Owner information (name, email, etc.) is cached in the cars table to avoid expensive joins.
The denormalized data is kept in sync via trigger on owner updates.

**Caching:** LocationService implements 5-minute server-side caching for OpenStreetMap API responses.

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

3. **Input Validation**
   - All user input validated and sanitized
   - Prepared statements for all SQL queries
   - White-list input validation where possible

4. **CSRF Protection**
   - CSRF tokens on all forms
   - Token validation via UserSpice framework

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
   - `/docs/assets/` protected from directory listing
   - Environment files protected from access

8. **Audit Logging**
   - Application-level logging via Logger class (90+ categories)
   - Database-level audit via triggers on sensitive tables
   - Email transmission logged for compliance

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

See [UserSpice Integration Guide](https://github.com/unibrain1/elanregistry/wiki/Customization-and-Integration-Patterns) for details.

### Adding New PHP Directories

When adding a new PHP directory (e.g., `/app/new-feature/`), update the `$path` array in `/z_us_root.php` to register it with UserSpice:

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

Use prepared statements for all queries. See `docs/development/DATABASE.md` for schema details and `docs/development/CLASSES.md` for ORM patterns.

**Example:**

```php
$stmt = $this->pdo->prepare("SELECT * FROM cars WHERE id = ?");
$stmt->execute([$carId]);
$car = $stmt->fetch();
```

### Email Integration

Email is handled by Brevo plugin with UserSpice `sendMail()` function. See `docs/development/EMAIL_SYSTEM.md` for configuration and template setup.

### External Services

The system integrates with:

- **Cloudflare** — CDN and edge caching
- **OpenStreetMap** — Location services with client caching
- **Google Maps** — Map display (API key in settings)
- **reCAPTCHA** — Contact form protection
- **Brevo** — Transactional email service

All external service credentials stored in database settings (`$settings` global) and configurable via admin panel without code deployment.

---

## Template Architecture

### Active Template

**Location:** `/usersc/templates/ElanRegistry/`

**Framework:** Bootstrap 4.5.3 (actively migrating to Bootstrap 5)

**Dependencies:**

- jQuery (required by UserSpice 6, cannot be removed)
- Bootstrap CSS/JS
- Font Awesome icons
- Custom Elan Registry CSS

### Template References

**UserSpice 6 Reference Templates:**

- `/users/journal/` (Bootstrap 5.2) — Reference for BS5 migration
- `/users/customizer/` (Bootstrap 5.3) — Modern reference implementation

Use these templates as patterns when migrating components to Bootstrap 5.

### CDN Configuration

CDN URLs are stored in database settings with HTML entity encoding:

- `elan_bootstrap_cdn` — Bootstrap CSS/JS
- `elan_jquery_cdn` — jQuery (UserSpice 6 dependency)
- `elan_fontawesome_cdn` — Font Awesome icons
- `elan_custom_css_cdn` — Custom CSS

**Note:** Decoded via `html_entity_decode()` in `header.php` for security.

### Architecture Decision Records

Frontend dependency changes and CSP updates require updating ADRs:

- `docs/development/adr/ADR-006-Frontend-Dependencies.md`
- `docs/development/adr/ADR-007-Content-Security-Policy.md`

---

## Application Classes

Key classes are documented in `docs/development/CLASSES.md`:

- **`Car`** — Car entity with properties and methods
- **`ElanRegistryOwner`** — Owner profile with contact/preference data
- **`ApiResponse`** — Standardized response format for AJAX endpoints
- **`DocumentConfig`** — Documentation metadata and routing
- **`LocationService`** — OpenStreetMap integration with server-side caching and logged I/O error handling
- **`PagePermissionClassifier`** (`usersc/classes/admin/`) — Classifies pages into permission tiers (admin-only, admin+editor,
  private user, special no-perms) for the Fix Page Permissions maintenance script
- **`Logger`** — Application event logging
- **`LogCategories`** — Logging category constants

---

## Server Environment Globals

**Available Since:** v2.13.0

Validated server environment globals are initialized in `usersc/includes/server_globals.php` and available on every page after `init.php`:

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

**IMPORTANT:** Never use raw `$_SERVER` directly. Use these validated globals instead. See `docs/development/PAGE_LOADING_FLOW.md` for usage examples.

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

### PHP Testing

```bash
composer test:quick     # Unit tests only (~30s)
composer test:medium    # Unit + Integration (~2min)
composer test:full      # All PHP tests
composer test:coverage  # Coverage report
```

### Code Quality

```bash
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

- [Architecture Guide](https://github.com/unibrain1/elanregistry/wiki/Elan-Registry-Architecture-and-Database-Design) (this page)
- [UserSpice Integration Guide](https://github.com/unibrain1/elanregistry/wiki/Customization-and-Integration-Patterns)
- [Development Workflow](https://github.com/unibrain1/elanregistry/wiki/Development-Workflow)

---

## Version History

| Version | Date | Changes |
| ------- | ---- | ------- |
| 2.18.2 | 2026-04-26 | Documentation reorganization: guides/reference/admin/stories split, file-based navigation, DocumentConfig updates |
| 2.18.0+ | 2026-03-20+ | Server environment globals, improved AJAX patterns, CSP refinements |
| Earlier | — | Previous architecture versions |
