# CLAUDE.md

This file provides essential guidance to Claude Code (claude.ai/code) when
working with code in this repository.

## Documentation Reference

**Essential reading:**

- `CLAUDE.md` (this file) - Overview and quick reference
- `docs/development/SYSTEM_OVERVIEW.md` - **What the registry does and for whom** —
  capabilities by role, deliberate omissions, and existing features that do
  not work. Read it before you design a feature change.
- `docs/development/UI_STANDARDS.md` - **UI component standards** (color tokens, card hierarchy, component patterns) — read before any UI change
- `docs/development/EMAIL_SYSTEM.md` - Brevo email plugin setup and configuration
- `docs/development/CODING_STANDARDS.md` - Code quality requirements
- `docs/development/QUICK_REFERENCE.md` - Common tasks lookup
- `docs/development/DEPLOYMENT.md` - Production deployment procedures
- `docs/development/ENVIRONMENT.md` - Environment setup and configuration

**Core understanding:**

- [GitHub Wiki: Architecture Guide](https://github.com/elan-registry/registry/wiki/Elan-Registry-Architecture-and-Database-Design) - System architecture and patterns
- `docs/development/DATABASE.md` - Database schema and relationships
- [GitHub Wiki: UserSpice Integration Guide](https://github.com/elan-registry/registry/wiki/Customization-and-Integration-Patterns) - UserSpice integration

**UserSpice context (AI Prompts plugin):** Before any UserSpice task, read the
shipped prompts starting at:
`usersc/plugins/ai_prompts/prompts/00_start_here.md.php`
Then load ElanRegistry-specific augmentation from `custom_prompts/`:

- `elanregistry_overrides` — six places ElanRegistry diverges from standard UserSpice (incl. the `$pageTitle`/`$pageDescription` page-metadata convention)
- `elanregistry_classes` — Car, Owner, ApiResponse, LogCategories, and others
- `elanregistry_directories` — `app/` subtree, `$path` in `z_us_root.php`, parsers location
- `elanregistry_database` — DB Explainer workflow and ElanRegistry-specific tables

**As needed:** See [docs/README.md](docs/README.md) for the full documentation
index. It covers error handling, classes, DataTables, CSS, testing, and more.
Read `usersc/plugins/ai_prompts/prompts/00_start_here.md.php` before you build a
custom solution.

## Architecture Overview

This PHP web application runs the Lotus Elan Registry at
<https://elanregistry.org>. It uses UserSpice 6 (<https://userspice.com>) for
authentication and custom code for car registry functions. Cloudflare provides
edge caching and CDN for global users (US, EU, AU). **Cloudflare Rocket Loader
and Email Obfuscation must remain disabled** — both inject inline scripts that
are incompatible with the strict CSP nonce policy (v2.27.0+). Edge caching and
all other Cloudflare features work normally.

> **For complete architecture, see the
> [GitHub Wiki: Architecture Guide](https://github.com/elan-registry/registry/wiki/Elan-Registry-Architecture-and-Database-Design)**

**Directory Structure:**

- `/app/` - Main application pages (car listings, details, forms, actions)
  - `/app/owner/` - Owner-facing pages: `cars/` (listings, details, edit, factory),
    `contact/` (contact form, contact-owner), `reports/` (statistics), `privacy.php`
  - `/app/api/` - AJAX JSON endpoints, organized by resource: `cars/` (car CRUD and
    validation), `contact/` (contact forms, auth-required), `shared/` (public endpoints:
    statistics, location search, `sitemap.xml`), `admin/` (admin-only settings updates).
    Most endpoints follow the `ApiResponse` JSON format — `shared/sitemap.php` is a
    documented exception. It returns XML and has no authentication, CSRF check,
    or rate limit. Keep it open to search crawlers. See its file header.
  - `/app/views/` - Reusable view partials: `cars/` (car page components), `email/`
    (transactional email templates)
  - `/app/verify/` - Public vericode-authenticated pages (`verify_car.php`):
    reachable with no UserSpice session and no `securePage()`/CSRF, the same
    deliberate no-session shape as `/app/api/webhooks/brevo.php`. Auth is a
    single-use, time-limited token in the URL rather than a login — see the
    file's own header for the full threat-model reasoning before copying
    this pattern elsewhere
- `/docs/` - User-facing documentation: `guides/` (how-to), `reference/` (technical), `stories/` (car histories)
- `/error/` - Branded HTTP error pages (403, 404, 500)
- `/users/` - UserSpice authentication system
- `/usersc/` - UserSpice customizations (templates, plugins, overrides)
- `/usersc/classes/` - Custom application classes (PSR-4: `ElanRegistry\` →
  `usersc/classes/`, `ElanRegistry\Exceptions\` → `usersc/classes/Exceptions/`)
- `/tests/` - PHPUnit and Playwright tests: `unit/` (no DB, includes
  `unit/regression/`, tagged `#[Group('regression')]`), `integration/`
  (real DB), `playwright/` (browser), `manual/`

**Key Integration Points:**

- **Page Security**: All protected pages require `securePage($php_self)` check.
  See [GitHub Wiki: UserSpice Integration Guide](https://github.com/elan-registry/registry/wiki/Customization-and-Integration-Patterns).
- **Role Hierarchy**: The privileged roles are `admin` and `editor`. Most admin
  pages require the `admin` role. Some tools, such as data repair and image
  management, also allow `editor` access. Check the issue scope before you
  default to admin-only access.
- **Car Image Storage**: `cars.image` column is a JSON array of bare filenames
  (e.g. `["abc123.jpg"]`). Files live at `userimages/{carid}/{filename}` with
  resized variants as `{basename}-resized-{size}.{ext}` (sizes: 100, 300, 768,
  1024, 2048). New uploads land in `userimages/temp/` and move to
  `userimages/{carid}/` on success. On car merge, the `CarImageRelocator` moves
  all files (base + variants) from source to target car's directory, renaming on
  collision, and appends the source's filenames to the target's `cars.image`.
  Use `CarImageProcessor` to decode images. Use
  `CarRepository::updateImage()` to write image data. Use `CarImageRelocator`
  to move image files during a merge.
- **New PHP Directories**: Only add a directory to the `$path` array in
  `/z_us_root.php` when it contains files that call `securePage()`. Pure API
  endpoints, action handlers, and partials that do not call `securePage()` are
  **not** added — `app/api/cars/` and `app/api/shared/` are examples of this
  pattern. `app/api/contact/` is an exception. It contains files that call
  `securePage()`, so add it to `$path`.
  New admin scripts go under `app/admin/scripts/fix/` (one-time migrations) or
  `app/admin/scripts/maintenance/` (repeatable maintenance). **After adding any
  new page or admin script, run `21-Fix-Page-Permissions.php` on test then prod
  to register the new path in UserSpice's permission table.**
  Put all cron jobs in `users/cron/`. The `cron.php` dispatcher only looks for
  job files in this directory. A job in another directory cannot run. Read the
  [Cron Transport section](docs/development/DEPLOYMENT.md#cron-transport-userspice-cron-manager)
  before you write a cron job.
- **Database**: MySQL 8.0+ with audit trails via triggers.
  See [DATABASE.md](docs/development/DATABASE.md).
- **Classes**: See [CLASSES.md](docs/development/CLASSES.md) for Car,
  Owner, ApiResponse, and all application classes.

**Template Architecture:**

- The active template is `/usersc/templates/customizer/`. It uses the
  `elanregistry` child theme and Bootstrap 5.3.8. The theme uses UserSpice's
  copies in `users/css` and `users/js`. Do not add another Bootstrap copy to
  `usersc/`. The repository vendors source maps on each `git pull` and deploy
  with `scripts/vendor-bootstrap-maps.php`. See ADR-015.
- UserSpice 6 requires jQuery from `users/js/jquery.php`. Keep it.
- Architecture decisions are in `docs/development/adr/`. Update ADR-018 when
  you change frontend dependencies. ADR-018 supersedes ADR-017, which
  supersedes ADR-015. ADR-015 still covers Bootstrap source-map vendoring.
  Update ADR-016 for navigation changes and ADR-007 for CSP changes. Update
  ADR-019 when you enable or disable CSRF or rate limiting on public API endpoints.

**Template Customization Rules:**

The following directories contain upstream UserSpice files. Do not change
those files, except for the project-owned files listed below.

| Directory | Status | Project-owned files and rules |
| --- | --- | --- |
| `/users/` | Upstream framework | `users/cron/` contains project logging and hook calls in `cron.php`. Put all cron jobs in this directory. See the [Cron Transport section](docs/development/DEPLOYMENT.md#cron-transport-userspice-cron-manager). The `$GLOBALS['config']` array in `users/init.php` reads database and session values from `.env`. These values include `DB_HOST`, `DB_USER`, `DB_PASS`, `DB_NAME`, `SESSION_NAME`, `TOKEN_NAME`, and `REMEMBER_COOKIE_NAME`. This is project configuration. Keep all other files in `/users/` unchanged. Extend the framework through `usersc/classes/`. |
| `usersc/templates/` | Upstream templates | Project files include `customizer/file_nav_custom.php`, the `elanregistry*` and `dashboard.php` child themes, and `customizer.css`. UserSpice requires `customizer/navigation.php`, so Git tracks it. Do not edit it. Add navigation content to `file_nav_custom.php`. |
| `usersc/plugins/` | Upstream plugins | Project files include `hooker/hooks/` and `ai_prompts/custom_prompts/`. |
| `usersc/user_settings.php` | Project-owned override for `users/user_settings.php` | Make changes in this file. Do not change `users/user_settings.php`. |

- To add behavior, create a custom class in `usersc/classes/` under the
  `ElanRegistry\` namespace. Do not change files in `/users/`.
- To add footer content, add JavaScript to `usersc/includes/footer.php`.
  UserSpice includes this file after it renders the footer.
- To add header or navigation content, edit
  `usersc/templates/customizer/file_nav_custom.php`.

## Development Setup

### System Requirements

- `composer.json` requires PHP 8.2.29 or later. This is the compatibility
  floor. Local development, CI, test, and production use PHP 8.4.x. Production
  uses PHP 8.4.25. See the PHP Version section in `ENVIRONMENT.md`.
- MySQL 8.0+
- Uses `vlucas/phpdotenv` for environment variable loading (plaintext `.env`, `chmod 600`)

### Quick Start Commands

```bash
composer install                # PHP dependencies
npm install                     # Node dependencies
npm run build                   # Required after install/clone — usersc/js and usersc/css
                                 # (DataTables, Chart.js, MapLibre GL, FilePond) are gitignored
                                 # build output (ADR-018); the site has no working frontend
                                 # until this runs at least once
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
npm run test:eslint-rules       # RuleTester coverage for local ESLint rules (eslint-rules/)

# Local Playwright tests (requires MAMP at localhost:9999)
npm run playwright:install      # Install browsers
npm run playwright:test         # All local tests, incl. an admin e2e project
                                 # (tests/playwright/e2e/admin.spec.js,
                                 # factory-registry-link.spec.js) that auto-authenticates
                                 # via E2E_DEV_ADMIN_USERNAME/E2E_DEV_ADMIN_PASSWORD
                                 # in .env.local. If those are unset, the auth setup
                                 # step itself skips cleanly, but
                                 # the admin tests still run — unauthenticated, not
                                 # skipped — so some (menu/account tests expecting a logged-
                                 # in session) will fail while others (factory.php, which is
                                 # intentionally public) still pass
npm run playwright:security     # Security tests
npm run playwright:maps         # Maps & charts tests
npm run playwright:csp          # CSP validation tests

# E2E tests
npm run test:e2e                # All E2E on elanregistry.org
npm run test:e2e:test           # All E2E on test.elanregistry.org
npm run test:e2e:dev            # All E2E against local MAMP (http://localhost:9999/ElanRegistry/Registry/)
```

### Pre-commit Quality Checks

Run `./scripts/setup-git-hooks.sh` once per developer. Bypass with `git commit --no-verify`
(emergency only). See [DEPLOYMENT.md](docs/development/DEPLOYMENT.md) for hook details.

## Essential Development Guidelines

See [CODING_STANDARDS.md](docs/development/CODING_STANDARDS.md) for PHP 8+ type requirements, security standards, and PHPDoc.
Read the [UserSpice framework guidance](usersc/plugins/ai_prompts/prompts/00_start_here.md.php)
before you build a custom solution. UserSpice may already provide the required
function.

### Error Handling

Typed exceptions (extend `ElanRegistryException`), `LogCategories` constants for all `logger()` calls, `ApiResponse`
for all AJAX endpoints. See [ERROR_HANDLING.md](docs/development/ERROR_HANDLING.md).

### Input/Output Encoding

Use `ElanRegistry\Input::raw()` for DB storage (never `\Input::get()` — it pre-encodes and causes double-encoding).
Apply `htmlspecialchars()` at the render layer only.

### Frontend API Client

All AJAX endpoints use `ApiResponse` (PHP) + `ElanRegistryAPI` (JS) — response format: `{success, message, ...}`. See [ERROR_HANDLING.md](docs/development/ERROR_HANDLING.md).

### Server Environment Globals (v2.13.0+)

Never use `$_SERVER` directly. The file `usersc/includes/server_globals.php`
initializes these safe globals: `$php_self`, `$is_https`, `$host`,
`$method`, `$request_uri`, `$current_url`, `$current_origin`, `$remote_addr`,
`$referer`, and `$user_agent`. See
[PAGE_LOADING_FLOW.md](docs/development/PAGE_LOADING_FLOW.md).

### Code Quality

**ALWAYS run before completing any task:**

- Use the `software-developer` agent when a change affects three or more files
  or introduces a new pattern. Edit a single-file task directly.
- Run `/security-review` when changes touch forms, SQL queries, auth, or user input
- Resolve lint or type errors before you complete the task. Pre-commit hooks
  run PHPStan on staged files.
- Run appropriate test suites for modified functionality

**PHPStan hygiene:** When you change any PHP file in
`app/`, `usersc/`, or any other path listed in `phpstan.neon`, run PHPStan on
it and resolve **all** errors it reports (the baseline silently suppresses
pre-existing ones, so anything reported is new):

```bash
vendor/bin/phpstan analyse <file>   # check the file you touched
composer phpstan:baseline           # regenerate baseline after fixing
```

Treat pre-existing baseline errors as debt. Resolve them in files you change.
When you resolve an error, `reportUnmatchedIgnoredErrors: true` makes CI reject
the stale baseline entry.
See `docs/development/CODING_STANDARDS.md` — PHPStan Baseline Hygiene.

### Playwright Test Maintenance

When adding, moving, removing, or renaming any page, update tests **in the same PR**:

- **Public pages** → add or update an e2e smoke test in `tests/playwright/e2e/not-logged-in.spec.js`
- **Owner/authenticated pages** → add or update a local Playwright test in `tests/playwright/`
- **Deleted or moved pages** → update any test that uses the old path. A stale path can test a 404 without failing.
- **Moved or renamed DOM elements, classes, and JS globals** → update every guard
  that depends on them **in the same PR**. A defensive guard (a bare `return`
  after `test.skip('reason')`, or `if (await x.count() > 0) { assert }` with no
  `else`) can hide a moved class or renamed global. The test can pass without
  running an assertion. CI will not report a failure (#1949, #1950). Assert
  directly when possible. Use a guard only when the environment requires it,
  such as when local credentials or fixture data are missing. In that case,
  use the two-argument form `test.skip(condition, reason)`. Set `reason` to the
  cause, not the symptom. CI then reports the test as skipped, not passed.
  The `localRules/require-skip-reason` ESLint rule requires two arguments for
  `test.skip(...)` or
  `testInfo.skip(...)` calls with fewer than 2 arguments).

Run `npm run test:e2e` to check public pages against production. See
`playwright.config.prod.js` for the configuration.

### Security Scanning (Semgrep)

GitHub App Managed Scan runs Semgrep on every pull request. The
`semgrep-cloud-platform/scan` check fails when the scan finds new issues. See
[QUICK_REFERENCE.md](docs/development/QUICK_REFERENCE.md#security-scanning-semgrep)
for triage steps and known false positive patterns.

## Developer Workflow

### Milestone Lifecycle (typical)

Most work follows a structured milestone lifecycle with these commands:

```text
/plan-milestone v2.17.0      — Signal review, theme sentence, gate — seal the issue list before branching
/start-milestone v2.17.0     — Create milestone branch, prompt fix-script cleanup, draft release notes
  /start-issue 423            — Branch, research, plan-mode interview, write approved plan file
  /execute-plan                — Implement the approved plan, test, security/architect review
  /simplify                   — Clean up the code (optional, recommended)
  /commit                     — Commit changes locally
  /commit-push-pr             — Push + PR targeting milestone branch
  /address-pr-comments        — Review CI/reviewer comments, fix blocking items
  /finish-issue 423           — Monitor CI, squash-merge, close issue
  (repeat for each issue)
/finish-milestone v2.17.0    — Gate the branch: review it, finalize release notes, update wiki
/review-pr                   — Multi-agent PR review before merge
/review-milestone v2.17.0    — Open the PR to main, verify CI review posted, confirm green
/release-milestone v2.17.0   — Merge, tag, GitHub release, close milestone
```

**Branch structure:** `main` ← `milestone/vX.Y.Z` ← `issue/NNN-slug`

- `/start-issue` handles branch creation, research, and planning, ending in
  an approved plan file at `docs/plans/issues/issue-NNN-slug.md` — it never
  implements, commits, or pushes.
- `/execute-plan` reads the approved plan file and implements the full plan.
  It tests the changes and requests security and architecture reviews. It also
  checks the plan against the current repository state so you can resume or
  rerun it safely. It **does not commit or push**.
- `/address-pr-comments` reads CI annotations and reviewer comments after you
  push a PR. It sorts findings into blocking and advisory groups. It assigns
  blocking resolutions to the `software-developer` agent and checks CI before handoff.
- Create one PR for each issue. Target the milestone branch. `/finish-issue`
  squash-merges the PR to keep the history clean.
- `/finish-milestone` reviews the milestone branch for security and other
  issues. It updates the release notes, wiki, and architecture documents. It
  stops before it creates a PR.
- `/review-milestone` creates the final PR to `main` with all closing
  keywords. It checks for the CI milestone review and requires all CI checks
  to pass.
- `/release-milestone` merges, tags, and publishes the release. Deploy to test
  and production in a separate manual step.

### Ad-Hoc Work (no GitHub issue)

For small tasks, refactoring, or exploratory work not tied to a milestone:

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

- Store sprint plans, triage reports, FRDs, and per-issue plan files in
  `docs/plans/`. Git ignores this directory because the repository is public
  and these files are private working notes. Never commit files from this
  directory. After you apply a plan to GitHub milestones or issues, run `rm`
  on the plan. Do not run `git rm`. GitHub issues, code, and committed
  documents then provide the source of truth.
- **Read `docs/plans/README.md` before reading, writing, or deleting
  anything under `docs/plans/`.** It is the authoritative layout: which
  subdirectory each kind of document goes in, which command writes it, when
  you delete it, and which current files contain sensitive data, such as spike
  captures with member email addresses. Git ignores this directory. It exists
  only on machines that already hold plans. On a fresh clone, use this summary:
  `issues/issue-<NNN>-<slug>.md` (per-issue plans), `sprints/<version>.md`,
  `features/<name>/` (FRDs with mockups), `spikes/<issue>-<slug>/`,
  `analysis/` (one-off reports), `summaries/` (`/summary` HTML pages), and
  `releases/` (deploy sheets). Put summary pages in `summaries/`, not in the
  repository root. Only `README.md` and `HANDOFF.md` belong at the top level.
  Put every other document in a subdirectory. Use `analysis/` when no other
  subdirectory fits.
- For milestone planning, ask the `senior-product-manager`,
  `senior-architect`, and `security-reviewer` agents to analyze the work in
  parallel.

### Other Commands

```text
/new-issue           — Create a well-defined GitHub issue with PM refinement
/address-pr-comments — Triage CI/reviewer comments, fix blocking items
/security-review     — OWASP security audit of recent changes
/found               — Capture a pre-existing issue found mid-task; classify and file or fix
/sprint-status       — Render the current milestone's derived state: theme, issue status, blocked items
/architecture-update — Full wiki architecture documentation refresh
/revise-claude-md    — Update CLAUDE.md with session learnings
/clean_gone          — Prune local branches that no longer exist on remote
```

### Release Notes

Update or create release notes when creating a pull request using the template
at `docs/development/RELEASE_NOTES_TEMPLATE.md`.

### Terminology Standards

- **Users**: Authentication/session context (UserSpice framework, `users` table)
- **Owners**: Car registry business domain (UI elements, business logic)
- Use `(new Owner($userId))->data()` to access user and profile data together.
- See [CLASSES.md](docs/development/CLASSES.md) for Owner patterns

## Quick Deployment Reference

See [DEPLOYMENT.md](docs/development/DEPLOYMENT.md) for full instructions.
**Critical:** Use `git push prod vX.Y.Z`, then run
`git push prod 'vX.Y.Z^{commit}:main'`. These commands deploy to the **live
site**. Do not confuse `prod` with `origin`. Do not push bare `main` to a
deploy remote because it may include commits that merged after the tag.

## GitHub Repository

- **GitHub owner/repo:** `elan-registry/registry` (not `jimboone/elan-registry`)
- Use `gh` CLI for GitHub operations. The MCP GitHub tools require the matching
  owner/repo pair above
- Milestone descriptions should state the goal, not list issue numbers
- Clear closed issues from milestones to keep progress tracking accurate

**gh CLI gotchas:**

- `gh milestone list` does not exist — use `gh api repos/elan-registry/registry/milestones` instead
- `gh issue list --milestone` can silently return empty even with open issues — always use
  `gh api repos/elan-registry/registry/issues?milestone=<number>&state=open` for reliable results

## GitHub Wiki

The wiki is a **separate Git repository**. Clone it once per machine outside
this repository. The path varies by machine. Find it in `.claude.local.md`.
If that file does not exist, copy `.claude.local.md.example` to
`.claude.local.md` and add the path.

**CRITICAL:** ALWAYS use that one permanent clone. NEVER clone to `/tmp/`, a
worktree, or any other temporary location.

The wiki uses two branches. Edit `master-upload`, then fast-forward it to
`master`. Readers see the `master` branch on GitHub. Do not edit or push
`master` directly. Use the wiki repository's `/publish-wiki` command to
publish. Replace `<wiki-clone>` with the path to your clone:

```bash
cd <wiki-clone>
git checkout master-upload
git pull
# edit pages directly in this clone
git add <file>.md
git commit -m "docs: <description>"
```

Run `/publish-wiki` from the wiki clone. The command pushes `master-upload`,
fast-forwards `master`, pushes `master`, and checks the result. To publish
manually, run `git merge master-upload --ff-only`. If this command fails,
stop and review the branch history. The branches do not fast-forward. Do not
force the merge.
