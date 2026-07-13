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
- `elanregistry_classes` — Car, Owner, ApiResponse, LogCategories, and others
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
    follow the `ApiResponse` JSON format.
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
- **Role Hierarchy**: Two privileged roles — `admin` and `editor`. Most admin
  pages are admin-only; some tools (e.g. data repair, image management) grant
  access to both. Always check the issue scope before defaulting to admin-only.
- **Car Image Storage**: `cars.image` column is a JSON array of bare filenames
  (e.g. `["abc123.jpg"]`). Files live at `userimages/{carid}/{filename}` with
  resized variants as `{basename}-resized-{size}.{ext}` (sizes: 100, 300, 768,
  1024, 2048). Unassigned images accumulate in `userimages/orphan/`.
  Use `CarImageProcessor` to decode; `CarRepository::updateImage()` to write.
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
  Owner, ApiResponse, and all application classes.

**Template Architecture:**

- Active template: `/usersc/templates/customizer/` with `elanregistry` child theme (Bootstrap 5.3.3)
- jQuery is a UserSpice 6 dependency (`users/js/jquery.php`) — cannot be removed
- ADRs: `docs/development/adr/` — update ADR-015 when changing frontend dependencies, ADR-016 for nav changes, ADR-007 for CSP changes

**Template Customization Rules:**

The following directories are **upstream UserSpice — do NOT modify** any files
except those explicitly listed as project-owned:

| Directory | Status | Project-owned exceptions (tracked by git) |
| --- | --- | --- |
| `/users/` | Upstream framework | none — extend via `usersc/classes/` instead |
| `usersc/templates/` | Upstream templates | `customizer/file_nav_custom.php` (project nav additions), `customizer/assets/child_themes/elanregistry*` (project child theme), `customizer.css` (project styles); `customizer/navigation.php` is tracked because UserSpice's template loader requires it — do not edit it, add nav content via `file_nav_custom.php` instead |
| `usersc/plugins/` | Upstream plugins | `hooker/hooks/` (project hooks), `ai_prompts/custom_prompts/` (Claude AI context prompts) |

- To add new behavior, extend via custom classes in `usersc/classes/` under the
  `ElanRegistry\` namespace instead of modifying `/users/`.
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

# Database migrations
composer migrate                # Apply pending migrations
composer migrate:status         # Show pending and applied migrations
composer migrate:dry-run        # Preview pending migrations without applying
composer migrate:rollback       # Roll back the most recent migration

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

Run `./scripts/setup-git-hooks.sh` once per developer. Bypass with `git commit --no-verify`
(emergency only). See [DEPLOYMENT.md](docs/development/DEPLOYMENT.md) for hook details.

## Essential Development Guidelines

See [CODING_STANDARDS.md](docs/development/CODING_STANDARDS.md) for PHP 8+ type requirements, security standards, and PHPDoc.
Check [USERSPICE_FUNCTIONS.md](docs/development/USERSPICE_FUNCTIONS.md) before building custom solutions — UserSpice likely has it already.

### Error Handling

Typed exceptions (extend `ElanRegistryException`), `LogCategories` constants for all `logger()` calls, `ApiResponse`
for all AJAX endpoints. See [ERROR_HANDLING.md](docs/development/ERROR_HANDLING.md).

### Input/Output Encoding

Use `ElanRegistry\Input::raw()` for DB storage (never `\Input::get()` — it pre-encodes and causes double-encoding).
Apply `htmlspecialchars()` at the render layer only.

### Frontend API Client

All AJAX endpoints use `ApiResponse` (PHP) + `ElanRegistryAPI` (JS) — response format: `{success, message, ...}`. See [ERROR_HANDLING.md](docs/development/ERROR_HANDLING.md).

### Server Environment Globals (v2.13.0+)

Never use `$_SERVER` directly. Validated globals (`$php_self`, `$is_https`, `$host`, `$method`, `$request_uri`,
`$current_url`, `$current_origin`, `$remote_addr`, `$referer`, `$user_agent`) are initialized in
`usersc/includes/server_globals.php`. See [PAGE_LOADING_FLOW.md](docs/development/PAGE_LOADING_FLOW.md).

### Code Quality

**ALWAYS run before completing any task:**

- Use `software-developer` agent for changes spanning 3+ files or introducing new patterns. For targeted single-file fixes, edit directly.
- Run `/security-review` when changes touch forms, SQL queries, auth, or user input
- Fix any linting or type errors before considering the task complete (pre-commit hooks run PHPStan and phpcs automatically on staged files)
- Run appropriate test suites for modified functionality

**PHPStan hygiene (fix-when-you-touch-it):** When modifying any PHP file in
`app/` or `usersc/`, run PHPStan on it and fix **all** errors not already in
the baseline:

```bash
vendor/bin/phpstan analyse <file>   # check the file you touched
composer phpstan:baseline           # regenerate baseline after fixing
```

Pre-existing baseline errors are tracked debt — clear them for files you touch.
`reportUnmatchedIgnoredErrors: true` ensures CI rejects stale entries once fixed.
See `docs/development/CODING_STANDARDS.md` — PHPStan Baseline Hygiene.

### Playwright Test Maintenance

When adding, moving, removing, or renaming any page, update tests **in the same PR**:

- **Public pages** → add or update an e2e smoke test in `tests/playwright/e2e/not-logged-in.spec.js`
- **Owner/authenticated pages** → add or update a local Playwright test in `tests/playwright/`
- **Removed or moved pages** → update any test referencing the old path — stale paths silently test 404s without failing

Run `npm run test:e2e` to verify public pages against production. See `playwright.config.prod.js` for config.

### Security Scanning (Semgrep)

Semgrep runs automatically on every PR (GitHub App Managed Scan). New findings fail the `semgrep-cloud-platform/scan`
check. See [QUICK_REFERENCE.md](docs/development/QUICK_REFERENCE.md#security-scanning-semgrep) for triage workflow
and known false positive patterns.

## Developer Workflow

### Milestone Lifecycle (typical)

Most work follows a structured milestone lifecycle with these commands:

```text
/start-milestone v2.17.0     — Create milestone branch, prompt fix-script cleanup, draft release notes
  /start-issue 423            — Branch, plan, implement, test, security review
  /simplify                   — Clean up the code (optional, recommended)
  /commit                     — Commit changes locally
  /commit-push-pr             — Push + PR targeting milestone branch
  /address-pr-comments        — Review CI/reviewer comments, fix blocking items
  /finish-issue 423           — Monitor CI, squash-merge, close issue
  (repeat for each issue)
/finish-milestone v2.17.0    — PR to main, finalize release notes, update wiki
/review-pr                   — Multi-agent PR review before merge
/release-milestone v2.17.0   — Merge, tag, GitHub release, close milestone
```

**Branch structure:** `main` ← `milestone/vX.Y.Z` ← `issue/NNN-slug`

- `/start-issue` handles the full development cycle (branch, plan, implement,
  test, security review) but **does not commit or push**
- `/address-pr-comments` fetches all CI check annotations and reviewer comments
  after a PR is pushed, triages blocking vs. advisory findings, fixes blocking
  items with a software-developer agent, and re-verifies CI before handoff
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
/new-issue           — Create a well-defined GitHub issue with PM refinement
/address-pr-comments — Triage CI/reviewer comments, fix blocking items
/security-review     — OWASP security audit of recent changes
/release             — Standalone release (hotfixes not tied to a milestone)
/architecture-update — Full wiki architecture documentation refresh
/revise-claude-md    — Update CLAUDE.md with session learnings
/clean_gone          — Delete local branches removed from remote
```

### Release Notes

Update or create release notes when creating a pull request using the template
at `docs/development/RELEASE_NOTES_TEMPLATE.md`.

### Terminology Standards

- **Users**: Authentication/session context (UserSpice framework, `users` table)
- **Owners**: Car registry business domain (UI elements, business logic)
- Use `(new Owner($userId))->data()` for combined user+profile data access (`getUserWithProfile()` was removed in v2.26.2)
- See [CLASSES.md](docs/development/CLASSES.md) for Owner patterns

## Quick Deployment Reference

See [DEPLOYMENT.md](docs/development/DEPLOYMENT.md) for complete procedures. **Critical:**
`git push prod main` pushes to the **live site** — never confuse `prod` with `origin`.

## GitHub Repository

- **GitHub owner/repo:** `unibrain1/elanregistry` (not `jimboone/elan-registry`)
- Use `gh` CLI for GitHub operations — the MCP GitHub tools require the correct
  owner/repo pair above
- Milestone descriptions should state the goal, not list issue numbers
- Remove closed issues from milestones to keep progress tracking accurate

**gh CLI gotchas:**

- `gh milestone list` does not exist — use `gh api repos/unibrain1/elanregistry/milestones` instead
- `gh issue list --milestone` can silently return empty even with open issues — always use
  `gh api repos/unibrain1/elanregistry/issues?milestone=<number>&state=open` for reliable results

## GitHub Wiki

The wiki is a **separate git repository** at the permanent path:

```text
/Users/jimboone/Developer/Web/elan-registry-wiki
```

**CRITICAL:** ALWAYS use this exact path. NEVER clone to `/tmp/`, a worktree,
or any other temporary location — there is one permanent clone and it is the
only place to use.

To update the live wiki after editing files in `wiki/` on a branch:

```bash
cp wiki/<file>.md /Users/jimboone/Developer/Web/elan-registry-wiki/
cd /Users/jimboone/Developer/Web/elan-registry-wiki
git add <file>.md
git commit -m "docs: <description>"
git push
```
