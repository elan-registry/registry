# Release Instructions Template

`/release-milestone` renders this file for each release and prints the result;
the rendered copy is **not committed** (it names hosts and paths that stay out
of the public repo). The template is the single owner of the release sequence;
the mechanics it relies on live in [DEPLOYMENT.md](DEPLOYMENT.md).

## Rendering rules

Placeholders, filled from `.claude.local.md` § "Deployment hosts" and the release:

| Placeholder | Source |
| --- | --- |
| `<version>` | Tag being released, e.g. `v2.30.0` |
| `<ssh-host>` | `.claude.local.md` — ssh host alias |
| `<test-docroot>` / `<prod-docroot>` | `.claude.local.md` — docroots |
| `<repo-path>` | Absolute path of the local checkout |

Conditional blocks are marked `<!-- IF: condition -->` … `<!-- END IF -->`.
Include a block only when its condition holds for **this** release, and drop
the markers; never print a block with an unmet condition. Conditions are
derived from `git diff --name-only <last-tag>...<version>` and the release
notes' "Required Actions After Deployment":

| Condition | Test |
| --- | --- |
| `migration` | any file added under `database/migrations/` |
| `trigger-migration` | a new migration contains `CREATE TRIGGER` |
| `hook-changed` | `scripts/server-hooks/post-receive` in the diff |
| `new-pages` | any new file that calls `securePage(` |
| `admin-scripts` | any new file under `app/admin/scripts/fix/` or `maintenance/` |
| `env-vars` | `.env.example` in the diff |
| `release-actions` | release notes list Required Actions other than the above |

Steps are numbered continuously across sections. Items marked **(you — admin
UI)** are done in the browser, not the shell. Everything else is a command to
run from the local machine.

When the user asks for the sheet as a file (it is normally imported into Apple
Notes), write it to `docs/plans/releases/<version>-deploy.md`. That directory
is gitignored, so the sheet is never committed — it carries deployment
specifics that do not belong in a public repo. Format for Notes: `##` per section and `###` per step; every
command, SQL statement and crontab line in a fenced block (Notes renders these
as a grey box; a bare `*/10 * * * *` italicises); no `---` rules (they render
as a stub); no inline backticks in prose (they render as pink highlights);
and at least one `- [ ]` item under every step so each step is tickable.

---

## Rendered output starts here

```text
--------------------------------------------------------------------
RELEASE <version> — DEPLOY SHEET
--------------------------------------------------------------------

Order: PRE-DEPLOY → TEST → validate → PROD → verify → publish.
Do not start PROD until every VALIDATE TEST item is ticked.

--------------------------------------------------------------------
PRE-DEPLOY
--------------------------------------------------------------------

1. Local state

cd <repo-path>
git fetch origin --tags
git rev-parse '<version>^{commit}'   # the commit that will be deployed — nothing else
git status --porcelain             # expect empty

Deploys push the TAG, never the current main. main may already hold commits
merged after the tag; they are not part of this release and must not ride
along. The '<version>^{commit}:main' refspec below (quoted — zsh) sets the
host's main ref to exactly the tagged commit, so the post-receive hook runs against it and VERSION
reads <version> with no -N-g suffix.

<!-- IF: migration -->
2. Database backup (rollback path for the migration)

Before pushing to each host, export the tables the migration touches through
the host's phpMyAdmin (Export → Custom → <tables> → SQL, structure and data).
This export — not `composer migrate:rollback` — is how you roll back if the
migration misbehaves against real data.
<!-- END IF -->

<!-- IF: trigger-migration -->
3. Trigger privileges on the target database

Run on each host's database before pushing:

SHOW GRANTS FOR CURRENT_USER();                  -- must include TRIGGER
SHOW VARIABLES LIKE 'log_bin';                    -- if ON, then:
SHOW VARIABLES LIKE 'log_bin_trust_function_creators';   -- must be ON (or the user needs SUPER)
<!-- END IF -->

<!-- IF: env-vars -->
4. New environment variables

Add to each host's .env (see .env.example diff): <list the keys>
<!-- END IF -->

<!-- IF: release-actions -->
5. Other pre-deploy actions from the release notes

<one line per item, verbatim from Required Actions>
<!-- END IF -->

--------------------------------------------------------------------
DEPLOY TEST
--------------------------------------------------------------------

6. Push the tag, then deploy exactly the tagged commit
   (hook writes VERSION from `git describe` during the second push)

cd <repo-path>
git push test <version>
git push test '<version>^{commit}:main'

<!-- IF: hook-changed -->
7. Two-push hook rule (post-receive changed in this release)

The first push runs the OLD hook and only installs the new one. Push again,
immediately, with a genuine new commit:

# A repeat of the same push is a client-side no-op, so deliver a throwaway
# commit on a temporary branch (never merged anywhere), then put main back:
git checkout -q -b tmp/hook-rerun <version>
git commit --allow-empty -m "chore: trigger post-receive hook rerun"
git push test tmp/hook-rerun:main
git push --force test '<version>^{commit}:main'   # main back on the tag; hook re-runs the new logic
git checkout -q main && git branch -D tmp/hook-rerun
<!-- END IF -->

--------------------------------------------------------------------
VALIDATE TEST  (gate — all items before PROD)
--------------------------------------------------------------------

8. Deploy output and assets

Read the push output: no ERROR lines, migration step (if any) reports applied.

ssh <ssh-host> '
echo "--- VERSION ---";    cat <test-docroot>/VERSION
echo "--- usersc/js ---";  ls <test-docroot>/usersc/js/
echo "--- usersc/css ---"; ls <test-docroot>/usersc/css/
'
VERSION must read <version>. usersc/js and usersc/css must be non-empty.

<!-- IF: migration -->
9. Migration applied

On the test database:
SELECT version FROM phinxlog WHERE version = <migration-version>;   -- 1 row
<!-- END IF -->

<!-- IF: trigger-migration -->
10. Audit triggers intact

SHOW TRIGGERS LIKE 'cars';   -- 3 rows: cars_insert, cars_update, cars_delete
<!-- END IF -->

<!-- IF: new-pages -->
11. Register new pages (you — admin UI)

Run 21-Fix-Page-Permissions.php from the Maintenance page on test.
<!-- END IF -->

<!-- IF: admin-scripts -->
12. Run new admin scripts (you — admin UI)

<one line per script, from the Maintenance page on test>
<!-- END IF -->

13. Smoke test

- Home page, a car details page, cars list (DataTable loads, no console errors)
- Log in; account page renders
- Admin → Logs: no new errors since the deploy timestamp; a CronRequest entry
  within the last 10 minutes
- npm run test:e2e:test   (from <repo-path>)

<!-- IF: release-actions -->
14. Release-specific checks

<one line per item, from Required Actions / Deployment Verification Checklist>
<!-- END IF -->

--------------------------------------------------------------------
DEPLOY PROD
--------------------------------------------------------------------

15. Push the tag, then deploy exactly the tagged commit

cd <repo-path>
git push prod <version>
git push prod '<version>^{commit}:main'

<!-- IF: hook-changed -->
16. Two-push hook rule (again, independently for prod)

git checkout -q -b tmp/hook-rerun <version>
git commit --allow-empty -m "chore: trigger post-receive hook rerun"
git push prod tmp/hook-rerun:main
git push --force prod '<version>^{commit}:main'   # main back on the tag; hook re-runs the new logic
git checkout -q main && git branch -D tmp/hook-rerun
<!-- END IF -->

--------------------------------------------------------------------
POST-DEPLOY VERIFICATION (prod)
--------------------------------------------------------------------

17. Deploy output and assets

ssh <ssh-host> '
echo "--- VERSION ---";    cat <prod-docroot>/VERSION
echo "--- usersc/js ---";  ls <prod-docroot>/usersc/js/
echo "--- usersc/css ---"; ls <prod-docroot>/usersc/css/
'

<!-- IF: migration -->
18. Migration applied

SELECT version FROM phinxlog WHERE version = <migration-version>;   -- 1 row
<!-- END IF -->

<!-- IF: trigger-migration -->
19. Audit triggers intact

SHOW TRIGGERS LIKE 'cars';   -- 3 rows
<!-- END IF -->

<!-- IF: new-pages -->
20. Register new pages (you — admin UI)

Run 21-Fix-Page-Permissions.php from the Maintenance page on prod.
<!-- END IF -->

<!-- IF: admin-scripts -->
21. Run new admin scripts (you — admin UI)

<one line per script, from the Maintenance page on prod>
<!-- END IF -->

22. Smoke test

Same as step 13 against elanregistry.org; npm run test:e2e.
Footer version reads <version>.

--------------------------------------------------------------------
PUBLISH
--------------------------------------------------------------------

23. Publish the GitHub release (it is a draft until prod is live)

gh release edit <version> --draft=false --repo elan-registry/registry

24. Housekeeping

- Delete the sprint plan for <version> in the Plans repo, if still present
- Confirm the milestone is closed: gh api repos/elan-registry/registry/milestones --jq '.[] | select(.title|startswith("<version>"))'

Recovery if a migration aborts on either host: fix the privileges in step 3,
then ssh in and run `composer migrate` in the docroot — every step is
idempotent. Full checklist: docs/development/DEPLOYMENT.md § Deployment
Verification Checklist.
```
