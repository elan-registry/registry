# CLAUDE.md

Guidance for Claude Code in this repository. Topic rules that apply only to
some files are in `.claude/rules/`. Claude Code loads each one when it reads a
matching file: upstream UserSpice files, frontend, car images, pages and
cron, Playwright tests, PHPStan, CI workflows, and `docs/plans/`.

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

`/users/` is upstream UserSpice — do not edit.
Shell tests: `bash tests/hooks/test-*.sh`.

**Key Integration Points:**

- **Page Security:** all protected pages require a `securePage($php_self)` check.
- **Roles:** the privileged roles are `admin` and `editor`. Most admin pages
  require `admin`. Some tools (data repair, image management) also allow
  `editor`. Check the issue scope before you default to admin-only.
- **Database:** MySQL 8.0+ with audit trails via triggers. See `DATABASE.md`.
- **Classes:** see `CLASSES.md` for Car, Owner, ApiResponse, and the rest.

## Development Setup

- PHP: `composer.json` requires 8.5 or later (the floor). Local, CI, test,
  and production use PHP 8.5.x. MySQL 8.0+.
- `vlucas/phpdotenv` loads a plaintext `.env` (`chmod 600`).
- Docker is the only local environment. Start it with `docker compose up -d`.
  `DB_HOST=db` resolves only inside the Compose network, so run each command
  that needs the database in the app container:
  `docker compose exec -u www-data app <command>`. The container has no Node.js
  or git, so npm and git-hooks setup run on the host. Setup and ports:
  `ENVIRONMENT.md` "Docker Dev Environment".
- Integration tests need the sendinblue plugin set up as in `ENVIRONMENT.md`,
  "Brevo Plugin Local Setup". Without it, the Brevo tests fail.

```bash
docker compose exec -u www-data app composer install
npm install
npm run build                   # Required after install/clone — frontend is build output
./scripts/setup-git-hooks.sh    # Pre-commit quality checks (once per developer)

scripts/run-verification-suite.sh  # Unit + integration (in the container) + docs + PHPStan

# Database commands — run in the app container:
docker compose exec -u www-data app composer test:integration  # Integration tests
docker compose exec -u www-data app composer test:full         # All PHP tests
docker compose exec -u www-data app composer migrate           # Also :status, :dry-run, :rollback
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
- **Server globals:** never use `$_SERVER` directly. Use the variables that
  `usersc/includes/server_globals.php` sets. See `PAGE_LOADING_FLOW.md`.
- **Terminology:** *Users* = authentication/session context (UserSpice,
  `users` table). *Owners* = car registry business domain. Use
  `(new Owner($userId))->data()` for user and profile data together.

### Code Quality

**Always, before you complete a task:**

- Use the `flow:software-developer` agent when a change affects three or more
  files or introduces a new pattern. Edit a single-file task directly.
- Run `/flow:security-review` when changes touch forms, SQL queries, auth, or user
  input.
- Resolve lint and type errors. Pre-commit hooks run PHPStan on staged files.
- Run the test suites for the changed functionality.

Semgrep (GitHub App managed scan) runs on every PR; the
`semgrep-cloud-platform/scan` check fails on new issues. Triage steps are in
`QUICK_REFERENCE.md#security-scanning-semgrep`.

## Registry workflow notes

The branch model, the command order, and the hand-off rule are in the flow
block at the end of this file. The full method is in the `flow:workflow`
skill. These notes are for Registry only.

- `/commit` is the plugin skill `commit-commands:commit`. This project has no
  `/commit` command. `.claude/settings.json` denies the plugin skill
  `commit-commands:commit-push-pr`, so use `/flow:commit-push-pr`.
- Cleanup finds (no change in behavior) go in the committed
  `docs/development/CLEANUP_LEDGER.md`. Fix an item and delete its line in
  the same PR. `composer check:docs` fails when a ledger heading names a file
  that no longer exists.
- At an issue or milestone boundary, recommend `/clear` first. In v2.30.4,
  one session that held the earlier milestone read about 5 times more tokens
  per turn than a cleared session.
- For milestone planning, ask `flow:senior-product-manager`,
  `flow:senior-architect`, and `flow:security-reviewer` to analyze the work
  in parallel.
- Deploying to test and production is a separate manual step. Update
  release notes with a PR, from `docs/development/RELEASE_NOTES_TEMPLATE.md`.
- Planning documents live in the gitignored `docs/plans/`. Never commit
  them. One named file, `docs/plans/releases/v2.30-deploy.md`, is a
  committed exception (see `.gitignore` and
  `.claude/rules/planning-docs.md`). It is not a general rule for
  `docs/plans/releases/`.

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

<!-- flow:begin rev 1 -->
## Developer workflow (flow)

Branches: `main` ← `milestone/vX.Y.Z` ← `issue/NNN-slug`. One PR for each
issue, to the milestone branch. A hotfix branches from `main` (the
`flow:hotfix` skill).

```text
/flow:plan-milestone → /flow:start-milestone
  per issue: /flow:start-issue → /flow:execute-plan → /commit → /flow:review-pr
             → /flow:commit-push-pr → /flow:address-pr-comments → /flow:finish-issue
/flow:finish-milestone → /flow:review-milestone → /flow:release-milestone
```

Hand-offs: start the next command through the Skill tool only when both
commands declare the same `model:`. Otherwise, tell the user to type it.
At an issue or milestone boundary, recommend `/clear` first.

Project facts for these commands are in `.claude/project.md`. The full
method is in the `flow:workflow` skill.
<!-- flow:end -->
