# CLAUDE.md

Guidance for Claude Code in this repository. Topic rules that apply only to
some files are in `.claude/rules/`. Claude Code loads each one when it reads a
matching file: upstream UserSpice files, frontend, car images, pages and
cron, Playwright tests, PHPStan, and `docs/plans/`.

## Documentation Reference

- `docs/development/SYSTEM_OVERVIEW.md` — **what the registry does and for
  whom**, deliberate omissions, and features that do not work. Read it before
  you design a feature change.
- `docs/development/CODING_STANDARDS.md` — code quality requirements
- `docs/development/ERROR_HANDLING.md`, `CLASSES.md`, `DATABASE.md`,
  `QUICK_REFERENCE.md`, `DEPLOYMENT.md`, `ENVIRONMENT.md`, `EMAIL_SYSTEM.md`,
  `UI_STANDARDS.md` (read before any UI change)
- [docs/README.md](docs/README.md) — full documentation index
- Wiki: [Architecture Guide](https://github.com/elan-registry/registry/wiki/Elan-Registry-Architecture-and-Database-Design),
  [UserSpice Integration Guide](https://github.com/elan-registry/registry/wiki/Customization-and-Integration-Patterns)

**UserSpice context (AI Prompts plugin):** before any UserSpice task, read
`usersc/plugins/ai_prompts/prompts/00_start_here.md.php`, then the
ElanRegistry augmentation in `usersc/plugins/ai_prompts/custom_prompts/`:
`elanregistry_overrides` (where ElanRegistry diverges from standard
UserSpice, incl. `$pageTitle`/`$pageDescription`), `elanregistry_classes`,
`elanregistry_directories`, `elanregistry_database`. UserSpice may already
provide the function you need.

## Architecture Overview

PHP application for the Lotus Elan Registry at <https://elanregistry.org>.
UserSpice 6 provides authentication; custom code provides the car registry.
Cloudflare provides edge caching and CDN (US, EU, AU users).

**Directory Structure:**

- `/app/` — application pages: `owner/` (owner-facing pages), `api/` (AJAX
  JSON endpoints by resource: `cars/`, `contact/`, `shared/`, `admin/`),
  `views/` (partials), `verify/` (public vericode pages), `admin/`
- `/docs/` — user-facing documentation: `guides/`, `reference/`, `stories/`
- `/error/` — branded 403, 404, 500 pages
- `/users/` — UserSpice framework (upstream — do not edit)
- `/usersc/` — UserSpice customizations; `/usersc/classes/` holds application
  classes (PSR-4: `ElanRegistry\` → `usersc/classes/`,
  `ElanRegistry\Exceptions\` → `usersc/classes/Exceptions/`)
- `/tests/` — `unit/` (no DB, incl. `unit/regression/` tagged
  `#[Group('regression')]`), `integration/` (real DB), `playwright/`, `manual/`

**Key Integration Points:**

- **Page Security:** all protected pages require a `securePage($php_self)` check.
- **Roles:** the privileged roles are `admin` and `editor`. Most admin pages
  require `admin`. Some tools (data repair, image management) also allow
  `editor`. Check the issue scope before you default to admin-only.
- **Database:** MySQL 8.0+ with audit trails via triggers. See `DATABASE.md`.
- **Classes:** see `CLASSES.md` for Car, Owner, ApiResponse, and the rest.

## Development Setup

- PHP: `composer.json` requires 8.2.29 or later (compatibility floor). Local,
  CI, test, and production use PHP 8.4.x (production 8.4.25). MySQL 8.0+.
- `vlucas/phpdotenv` loads a plaintext `.env` (`chmod 600`).
- Docker is the only local environment. Start it with `docker compose up -d`.
  `DB_HOST=db` resolves only inside the Compose network, so run each command
  that needs the database in the app container:
  `docker compose exec -u www-data app <command>`. The container has no Node.js
  or git, so npm and git-hooks setup run on the host. Setup and ports:
  `ENVIRONMENT.md` "Docker Dev Environment".

```bash
docker compose exec -u www-data app composer install
npm install
npm run build                   # Required after install/clone — frontend is build output
./scripts/setup-git-hooks.sh    # Pre-commit quality checks (once per developer)

composer test:quick             # Unit tests (<30s), host
composer check:php              # Coding standards + PHPStan, host
composer check                  # PHP standards + PHPStan + ESLint, host
scripts/run-verification-suite.sh  # Unit + integration (in the container) + docs + PHPStan

# Database commands — run in the app container:
docker compose exec -u www-data app composer test:integration  # Integration tests
docker compose exec -u www-data app composer test:full         # All PHP tests
docker compose exec -u www-data app composer migrate           # Also :status, :dry-run, :rollback

npm run lint                    # ESLint (lint:fix to auto-fix)
npm run playwright:test         # Local Playwright tests (also :security, :maps, :csp)
npm run test:e2e                # E2E on elanregistry.org (:test for test site, :dev for local)
```

Bypass the pre-commit hooks with `git commit --no-verify` in an emergency
only. See `DEPLOYMENT.md` for hook details.

## Essential Development Guidelines

- **Error handling:** typed exceptions (extend `ElanRegistryException`),
  `LogCategories` constants for all `logger()` calls, `ApiResponse` for all
  AJAX endpoints. See `ERROR_HANDLING.md`.
- **Input/output encoding:** use `ElanRegistry\Input::raw()` for DB storage.
  Never use `\Input::get()` for that — it pre-encodes and causes double
  encoding. Apply `htmlspecialchars()` at the render layer only.
- **Frontend API client:** AJAX endpoints use `ApiResponse` (PHP) +
  `ElanRegistryAPI` (JS); response format `{success, message, ...}`.
- **Server globals:** never use `$_SERVER` directly.
  `usersc/includes/server_globals.php` sets `$php_self`, `$is_https`, `$host`,
  `$method`, `$request_uri`, `$current_url`, `$current_origin`,
  `$remote_addr`, `$referer`, `$user_agent`. See `PAGE_LOADING_FLOW.md`.
- **Terminology:** *Users* = authentication/session context (UserSpice,
  `users` table). *Owners* = car registry business domain. Use
  `(new Owner($userId))->data()` for user and profile data together.

### Code Quality

**Always, before you complete a task:**

- Use the `software-developer` agent when a change affects three or more
  files or introduces a new pattern. Edit a single-file task directly.
- Run `/security-review` when changes touch forms, SQL queries, auth, or user
  input.
- Resolve lint and type errors. Pre-commit hooks run PHPStan on staged files.
- Run the test suites for the changed functionality.

Semgrep (GitHub App managed scan) runs on every PR; the
`semgrep-cloud-platform/scan` check fails on new issues. Triage steps are in
`QUICK_REFERENCE.md#security-scanning-semgrep`.

## Developer Workflow

Branches: `main` ← `milestone/vX.Y.Z` ← `issue/NNN-slug`. One PR per issue,
targeting the milestone branch. Each command describes its own steps.
`/simplify` is the built-in Claude Code skill, not a project command.

```text
/plan-milestone → /start-milestone
  per issue: /start-issue → /execute-plan → /simplify → /commit → /commit-push-pr
             → /address-pr-comments → /finish-issue
/finish-milestone → /review-pr → /review-milestone → /release-milestone
Other: /new-issue, /found, /security-review, /sprint-status, /revise-claude-md, /clean_gone
```

For milestone planning, ask `senior-product-manager`, `senior-architect`, and
`security-reviewer` to analyze the work in parallel. Deploying to test and
production is a separate manual step. Update release
notes with a PR, from `docs/development/RELEASE_NOTES_TEMPLATE.md`.
Planning documents live in the gitignored `docs/plans/` — never commit them.
One named file, `docs/plans/releases/v2.30-deploy.md`, is a committed
exception (see `.gitignore` and `.claude/rules/planning-docs.md`); it is not
a general rule for `docs/plans/releases/`.

## Quick Deployment Reference

See `DEPLOYMENT.md`. **Critical:** run `git push prod vX.Y.Z`, then
`git push prod 'vX.Y.Z^{commit}:main'`. These deploy to the **live site**. Do
not confuse `prod` with `origin`. Do not push bare `main` to a deploy remote:
it may include commits merged after the tag.

## GitHub

- Repository: `elan-registry/registry` (not `jimboone/elan-registry`). Use
  the `gh` CLI; MCP GitHub tools need this owner/repo pair.
- Milestone descriptions state the goal, not issue numbers. Clear closed
  issues from milestones.
- **gh gotchas:** `gh milestone list` does not exist — use
  `gh api repos/elan-registry/registry/milestones`. `gh issue list --milestone`
  can silently return empty — use
  `gh api repos/elan-registry/registry/issues?milestone=<number>&state=open`.

## GitHub Wiki

The wiki is a separate Git repository with one permanent clone per machine
(path in `.claude.local.md`; copy `.claude.local.md.example` if it is
missing). Never clone it anywhere else. Edit `master-upload`, commit, then
run `/publish-wiki` from the wiki clone. Never edit or push `master`
directly. If `git merge master-upload --ff-only` fails, stop — do not force it.
