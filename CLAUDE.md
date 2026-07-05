# CLAUDE.md

This file provides essential guidance to Claude Code (claude.ai/code) when
working with code in this repository.

## Documentation Reference

**Essential reading:**

- `CLAUDE.md` (this file) - Overview and quick reference
- `docs/development/UI_STANDARDS.md` - **UI component standards** (color tokens, card hierarchy, component patterns) — read before any UI change
- `docs/development/EMAIL_SYSTEM.md` - Brevo email plugin setup and configuration
- `docs/development/CODING_STANDARDS.md` - Code quality requirements
- `docs/development/QUICK_REFERENCE.md` - Common tasks lookup
- `docs/development/DEPLOYMENT.md` - Production deployment procedures
- `docs/development/ENVIRONMENT.md` - Environment setup and configuration

**Core understanding:**

- [GitHub Wiki: Architecture Guide](https://github.com/unibrain1/elanregistry/wiki/Elan-Registry-Architecture-and-Database-Design) - System architecture and patterns
- `docs/development/DATABASE.md` - Database schema and relationships
- [GitHub Wiki: UserSpice Integration Guide](https://github.com/unibrain1/elanregistry/wiki/Customization-and-Integration-Patterns) - UserSpice integration

**UserSpice context (AI Prompts plugin):** Before any UserSpice task, read the
shipped prompts starting at:
`usersc/plugins/ai_prompts/prompts/00_start_here.md.php`
Then load ElanRegistry-specific augmentation from `custom_prompts/`:

- `elanregistry_overrides` — four places ElanRegistry diverges from standard UserSpice
- `elanregistry_classes` — Car, ElanRegistryOwner, ApiResponse, LogCategories, and others
- `elanregistry_directories` — `app/` subtree, `$path` in `z_us_root.php`, parsers location
- `elanregistry_database` — DB Explainer workflow and ElanRegistry-specific tables

**As needed:** See [docs/README.md](docs/README.md) for the complete documentation
index (error handling, classes, DataTables, CSS, testing, UserSpice functions,
etc.). Always check `docs/development/USERSPICE_FUNCTIONS.md` before building
custom solutions.

## Architecture Overview

This is a PHP web application for the Lotus Elan Registry hosted at
<https://elanregistry.org>. Built on UserSpice 6 (<https://userspice.com>) for
authentication, with custom car registry functionality. Cloudflare provides
edge caching and CDN for global users (US, EU, AU).

> **For complete architecture, see the
> [GitHub Wiki: Architecture Guide](https://github.com/unibrain1/elanregistry/wiki/Elan-Registry-Architecture-and-Database-Design)**

**Directory Structure:**

- `/app/` - Main application pages (car listings, details, forms, actions)
  - `/app/owner/` - Owner-facing pages: `cars/` (listings, details, edit, factory),
    `contact/` (contact form, contact-owner), `reports/` (statistics), `privacy.php`
  - `/app/api/` - AJAX JSON endpoints, organized by resource: `cars/` (car CRUD and
    validation), `contact/` (contact forms, auth-required), `shared/` (public endpoints:
    statistics, location search), `admin/` (admin-only settings updates). All endpoints
    follow Pattern A / `ApiResponse`.
  - `/app/views/` - Reusable view partials: `cars/` (car page components), `email/`
    (transactional email templates)
- `/docs/` - User-facing documentation: `guides/` (how-to), `reference/` (technical), `stories/` (car histories)
- `/error/` - Branded HTTP error pages (403, 404, 500)
- `/users/` - UserSpice authentication system
- `/usersc/` - UserSpice customizations (templates, plugins, overrides)
- `/usersc/classes/` - Custom application classes (PSR-4: `ElanRegistry\` →
  `usersc/classes/`, `ElanRegistry\Exceptions\` → `usersc/classes/Exceptions/`)
- `/tests/` - PHPUnit and Playwright tests: `unit/` (mocked, no DB),
  `integration/` (real DB), `regression/`, `playwright/` (browser),
  `manual/`, `fixtures/`

**Key Integration Points:**

- **Page Security**: All protected pages require `securePage($php_self)` check.
  See [GitHub Wiki: UserSpice Integration Guide](https://github.com/unibrain1/elanregistry/wiki/Customization-and-Integration-Patterns).
- **New PHP Directories**: Only add a directory to the `$path` array in
  `/z_us_root.php` when it contains files that call `securePage()`. Pure API
  endpoints, action handlers, and partials that do not call `securePage()` are
  **not** added — `app/action/`, `app/api/cars/`, and `app/api/shared/` are examples of this
  pattern. (`app/api/contact/` is an exception: it contains files that call `securePage()` and
  is therefore included.)
  New admin scripts go under `app/admin/scripts/fix/` (one-time migrations) or
  `app/admin/scripts/maintenance/` (repeatable maintenance).
- **Database**: MySQL 8.0+ with audit trails via triggers.
  See [DATABASE.md](docs/development/DATABASE.md).
- **Classes**: See [CLASSES.md](docs/development/CLASSES.md) for Car,
  ElanRegistryOwner, ApiResponse, and all application classes.

**Template Architecture:**

- Active template: `/usersc/templates/customizer/` with `elanregistry` child theme (Bootstrap 5.3.3)
- US6 reference templates: `journal/` (BS5.2) and `customizer/` (BS5.3) — use
  as patterns for migration
- UserSpice 6 admin uses its own template — independent of ElanRegistry template
- jQuery is a UserSpice 6 dependency (`users/js/jquery.php`) — cannot be removed
- Frontend libraries vendored to `usersc/js/` and `usersc/css/` (ADR-015);
  Bootstrap 5.3.3 loaded via Customizer `header.php` (self-hosted); jQuery via `users/js/jquery.php` (CDN, UserSpice-managed)
- ADRs: `docs/development/adr/` — update ADR-015 when changing frontend dependencies, ADR-016 for nav changes, ADR-007 for CSP changes

**Template Customization Rules:**

- `usersc/templates/customizer/` is **gitignored upstream** — do NOT modify any
  files in this directory. The sole exception is `file_nav_custom.php`, which is
  project-owned and tracked.
- `/users/` is the **UserSpice 6 upstream framework** — do NOT modify files
  here. Extend behavior via custom classes in `usersc/classes/` under the
  `ElanRegistry\` namespace instead.
- To add content to the footer without touching upstream files, inject via JS
  in `usersc/includes/footer.php` (included by UserSpice after the footer renders).
- To add content to the header/nav, use `usersc/templates/customizer/file_nav_custom.php`.

## Development Setup

### System Requirements

- PHP 8.2+ required
- MySQL 8.0+
- Uses `vlucas/phpdotenv` for environment variable loading (plaintext `.env`, `chmod 600`)

### Quick Start Commands

```bash
composer install                # PHP dependencies
npm install                     # Node dependencies (for testing)
./scripts/setup-git-hooks.sh    # Pre-commit quality checks (RECOMMENDED)

# PHP testing
composer test:quick             # Unit tests only (<30s)
composer test:medium            # Unit + Integration (<2min)
composer test:full              # All PHP tests
composer test:coverage          # Coverage report
composer check:php              # PHP coding standards + PHPStan analysis
composer check                  # Full check (PHP standards + PHPStan + ESLint)

# Build (minify first-party JS/CSS — run after editing source files)
npm run build                   # Minify app/assets/js/, app/assets/css/, app/admin/assets/

# Linting
npm run lint                    # ESLint for JavaScript
npm run lint:fix                # ESLint with auto-fix

# Local Playwright tests (requires MAMP at localhost:9999)
npm run playwright:install      # Install browsers
npm run playwright:test         # All local tests
npm run playwright:security     # Security tests
npm run playwright:maps         # Maps & charts tests
npm run playwright:csp          # CSP validation tests

# E2E tests (against deployed environments)
npm run test:e2e                # All E2E on elanregistry.org
npm run test:e2e:test           # All E2E on test.elanregistry.org
```

### Pre-commit Quality Checks

```bash
./scripts/setup-git-hooks.sh    # Setup once per developer
```

Validates PHP coding standards, runs markdown linting, and executes unit tests
on critical file changes. Bypass with `git commit --no-verify` (emergency only).

## Essential Development Guidelines

### PHP 8+ Requirements

- All functions must have complete parameter and return type hints
- New files must include `declare(strict_types=1)`
- Use typed exception classes for error handling
- Complete PHPDoc blocks required for all public methods
- See [CODING_STANDARDS.md](docs/development/CODING_STANDARDS.md) for full details

### UserSpice Framework

Before implementing custom functionality, check
`docs/development/USERSPICE_FUNCTIONS.md` for existing UserSpice functions that
may already provide the needed capability. Avoid duplicating framework
functionality. Key areas: authentication, permissions, database operations,
input handling, session management, CSRF protection, and email.

### Security Requirements

- All forms must use CSRF tokens
- Use prepared statements for all SQL queries
- Input validation and sanitization for all user inputs
- Use environment variables for sensitive configuration

### Error Handling

Use centralized error handling with typed exceptions, LogCategories constants,
and ApiResponse for AJAX endpoints.
See [ERROR_HANDLING.md](docs/development/ERROR_HANDLING.md) for patterns.

### Input/Output Encoding (v2.23.0+)

- **Storage**: Use `ElanRegistry\Input::raw()` for all text fields destined for
  the database — returns the POST value unencoded. Never use UserSpice's
  `\Input::get()` for stored values (it applies `htmlspecialchars()` before
  returning, causing double-encoding on display).
- **Output**: Apply `htmlspecialchars($value, ENT_QUOTES, 'UTF-8')` at the
  render layer only — never before storage.
- See [CODING_STANDARDS.md](docs/development/CODING_STANDARDS.md) for the full
  encode-at-output pattern and field coverage.

### Frontend API Client (Pattern A - v2.12.0+)

All new AJAX endpoints must use `ElanRegistryAPI` client with Pattern A
response format (`{success, message, ...}`). Available globally via `footer.php`.
See [ERROR_HANDLING.md](docs/development/ERROR_HANDLING.md) for usage patterns
and migration guide from jQuery.ajax().

### Server Environment Globals (v2.13.0+)

Use validated server globals instead of direct `$_SERVER` access. Initialized
in `usersc/includes/server_globals.php` and available on every page after
`init.php`.

**Available globals:** `$scheme`, `$is_https`, `$host`, `$method`,
`$request_uri`, `$current_url`, `$current_origin`, `$php_self`,
`$remote_addr`, `$referer`, `$user_agent`

**Never use raw `$_SERVER`** — use the globals above instead.
See [PAGE_LOADING_FLOW.md](docs/development/PAGE_LOADING_FLOW.md) for full
details and usage examples.

### Code Quality

**ALWAYS run before completing any task:**

- Use `software-developer` agent for changes spanning 3+ files or introducing new patterns. For targeted single-file fixes, edit directly.
- Run `/security-review` when changes touch forms, SQL queries, auth, or user input
- Fix any linting or type errors before considering the task complete (pre-commit hooks run PHPStan and phpcs automatically on staged files)
- Run appropriate test suites for modified functionality

### Security Scanning (Semgrep)

Semgrep runs automatically on every PR (GitHub App Managed Scan). New findings
fail the `semgrep-cloud-platform/scan` check. Periodic triage keeps the
dashboard clean — see [QUICK_REFERENCE.md](docs/development/QUICK_REFERENCE.md#security-scanning-semgrep)
for the triage workflow, API commands, and known false positive patterns.

## Developer Workflow

### Milestone Lifecycle (typical)

Most work follows a structured milestone lifecycle with five commands:

```text
/start-milestone v2.17.0     — Create milestone branch, prompt fix-script cleanup, draft release notes
  /start-issue 423            — Branch, plan, implement, test, security review
  /simplify                   — Clean up the code (optional, recommended)
  /commit                     — Commit changes locally
  /commit-push-pr             — Push + PR targeting milestone branch
  /finish-issue 423           — Monitor CI, squash-merge, close issue
  (repeat for each issue)
/finish-milestone v2.17.0    — PR to main, finalize release notes, update wiki
/review-pr                   — Multi-agent PR review before merge
/release-milestone v2.17.0   — Merge, tag, GitHub release, close milestone
```

**Branch structure:** `main` ← `milestone/vX.Y.Z` ← `issue/NNN-slug`

- `/start-issue` handles the full development cycle (branch, plan, implement,
  test, security review) but **does not commit or push**
- Each issue gets its own PR targeting the milestone branch (squash-merged by
  `/finish-issue` for clean history)
- `/finish-milestone` creates the final PR to `main` with all closing keywords
  and updates wiki/architecture docs
- `/release-milestone` merges, tags, and publishes — deployment to test/prod
  is a separate manual step

### Ad-Hoc Work (no GitHub issue)

For quick fixes, refactoring, or exploratory work not tied to a milestone:

```text
/feature-dev        — Guided implementation with codebase exploration
/simplify           — Clean up the code (optional)
/commit             — Commit changes locally
/commit-push-pr     — Push and create PR (if needed)
/code-review        — Review a specific PR
```

`/feature-dev` is a user-level plugin (not project-scoped) for work that
doesn't need the full milestone workflow. It provides its own code exploration
and architecture agents.

### Planning Work

- `plans/` directory is for temporary working documents (sprint plans, triage
  reports) — delete after decisions are applied to GitHub milestones/issues
- For milestone planning, use the `senior-product-manager`, `senior-architect`,
  and `security-reviewer` agents in parallel for comprehensive analysis

### Other Commands

```text
/new-issue          — Create a well-defined GitHub issue with PM refinement
/security-review    — OWASP security audit of recent changes
/release            — Standalone release (hotfixes not tied to a milestone)
/architecture-update — Full wiki architecture documentation refresh
/revise-claude-md   — Update CLAUDE.md with session learnings
/clean_gone         — Delete local branches removed from remote
```

### Release Notes

Update or create release notes when creating a pull request using the template
at `docs/development/RELEASE_NOTES_TEMPLATE.md`.

### Terminology Standards

- **Users**: Authentication/session context (UserSpice framework, `users` table)
- **Owners**: Car registry business domain (UI elements, business logic)
- Use `getUserWithProfile($userId)` for combined user+profile data access
- See [CLASSES.md](docs/development/CLASSES.md) for ElanRegistryOwner patterns

## Quick Deployment Reference

**Use the correct remote for each environment:**

```bash
git push origin main && git push origin --tags   # GitHub
git push test main                                # Staging
git push prod main && git push prod --tags        # PRODUCTION
```

See [DEPLOYMENT.md](docs/development/DEPLOYMENT.md) for complete procedures.

## GitHub Repository

- **GitHub owner/repo:** `unibrain1/elanregistry` (not `jimboone/elan-registry`)
- Use `gh` CLI for GitHub operations — the MCP GitHub tools require the correct
  owner/repo pair above
- Milestone descriptions should state the goal, not list issue numbers
- Remove closed issues from milestones to keep progress tracking accurate

## GitHub Wiki

The wiki is a **separate git repository** at the permanent path:

```text
/Users/jimboone/Documents/Developer/Web/elan-registry-wiki
```

**CRITICAL:** ALWAYS use this exact path. NEVER clone to `/tmp/`, a worktree,
or any other temporary location — there is one permanent clone and it is the
only place to use.

To update the live wiki after editing files in `wiki/` on a branch:

```bash
cp wiki/<file>.md /Users/jimboone/Documents/Developer/Web/elan-registry-wiki/
cd /Users/jimboone/Documents/Developer/Web/elan-registry-wiki
git add <file>.md
git commit -m "docs: <description>"
git push
```
