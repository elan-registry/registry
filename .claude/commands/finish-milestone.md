---
description: Gate a milestone branch — verify it, review it, and bring documentation up to date before /review-milestone opens a PR
model: claude-fable-5-1
---

# Finish Milestone

Keep output brief — terse status lines, no preamble, no restating of steps.

Verify a milestone branch is complete, run every review pass, and bring
release notes/wiki/CLAUDE.md up to date. This command ends when the branch
is fully vetted — no PR exists yet. `/review-milestone` picks up from here:
it opens the PR, verifies CI review posted, and confirms CI is green.

## Arguments

- `$ARGUMENTS` — the milestone version number (e.g., `v2.17.0`)

## Workflow

### Step 0: Initialize TaskList

Before any other action, create one tracking task per workflow step using
TaskCreate. Suggested task subjects:

1. Verify the milestone branch exists
2. Check for open issues still in the milestone
3. Switch to milestone branch and ensure up to date
3.5. Check for known-broken test exclusions still present
3.6. Check for leftover plan files
4. Gather all merged PRs targeting the milestone branch
5. Get the full diff against main
5.5. Verify milestone scope vs. release notes
6. Finalize release notes
6.6. Render the deploy sheet for review
7. Update wiki documentation (or skip)
8. Update CLAUDE.md if needed
9. Security review (Step 9.5) + local multi-agent review (Step 9.7)
9.8. Local milestone-level deep review (Fable) — mirrors CI, runs pre-PR
9.9. Fresh-checkout smoke test (if build/install steps changed)
10. Output summary and hand off to /review-milestone

Set each task to `in_progress` when you begin it and `completed` on success.

### Step 1: Verify the milestone branch exists

- Check `git branch -a | grep "milestone/$ARGUMENTS"`
- If not found, stop and report error.

### Step 2: Check for open issues still in the milestone

Use the direct API (`gh issue list --milestone` can silently return empty results — the milestone number is already recorded from Step 1):

```bash
gh api "repos/elan-registry/registry/issues?milestone=<MILESTONE_NUM>&state=open&per_page=20" \
  --jq '.[] | {number, title}'
```

- If open issues remain, warn user and list them. Ask if they want to proceed
  or finish remaining issues first.

### Step 3: Switch to milestone branch and ensure up to date

```bash
git checkout milestone/$ARGUMENTS
git pull origin milestone/$ARGUMENTS
```

### Step 3.5: Check for known-broken test exclusions still present

`.github/workflows/tests.yml`'s CI-blocking check runs `composer test:quick:ci`, which
excludes any test tagged `#[Group('known-broken')]` (see `tests/README.md`'s "CI vs. Local
Test Runs" section). This tag exists so a pre-existing, unrelated, already-tracked bug never
blocks landing an otherwise-unrelated PR — but it's meant to be temporary. A milestone should
not finish with tests still silently excluded from its own "all CI gates pass" bar.

Search for any remaining tags:

```bash
grep -rn "Group('known-broken')" tests/ || echo "None found"
```

**If none found**, proceed to Step 4.

**If any are found:**

1. For each match, extract the cited issue number from the inline comment (e.g.
   `// #1470 — fails on Linux CI, root cause under investigation`).
2. Check whether each cited issue is still open:

   ```bash
   gh issue view <NUMBER> --repo elan-registry/registry --json state,title
   ```

3. Present the full list to the user — test name, file, cited issue, and that issue's current
   state (open/closed) — and **ask for explicit confirmation** before proceeding:

   > "N test(s) are still excluded from CI via `#[Group('known-broken')]`, tracked by
   > [issue list]. Finishing this milestone means it ships without full test coverage on
   > these paths. Do you want to (a) resolve them first, (b) proceed anyway with this
   > explicitly accepted, or (c) stop here?"

4. **Do not proceed past this step without an explicit answer.** If the user chooses to
   proceed anyway, record that decision — `/review-milestone` includes it in the
   milestone PR body under a
   "Known Test Exclusions" note, so it's auditable later — matching Step 9.8's pattern for
   explicitly-accepted risk.
5. If a cited issue is already closed but the tag is still present in code, that's likely a
   forgotten cleanup step, not an accepted risk — flag this distinctly and recommend removing
   the tag now (quick fix) rather than treating it as a risk-acceptance decision.

### Step 3.6: Check for leftover plan files

Each issue's `/finish-issue` run deletes its `docs/plans/issue-NNN-*.md` file
as part of closing out that issue (see `/finish-issue`'s Step 8). A file
still present here means that step was skipped — most likely an issue whose
PR was merged some other way (bypassing `/finish-issue`), or an interrupted
run from an older command version.

`docs/plans/` is gitignored, so these files never reach the PR — but they do
accumulate silently on disk, and nothing else is positioned to catch them.
List them directly:

```bash
ls docs/plans/issue-*.md 2>/dev/null
```

**If any files are found:** present them to the user and ask whether to
delete them now (if the corresponding issue is confirmed closed and merged)
or investigate first (if it's unclear whether that issue's work actually
completed). Do not silently delete — a plan file could also mean genuinely
unfinished work that never went through `/finish-issue` at all.

**If none found:** proceed silently — this is the expected state.

### Step 4: Gather all merged PRs targeting the milestone branch

```bash
gh pr list --base milestone/$ARGUMENTS --state merged --json number,title,url
```

### Step 5: Get the full diff against main

```bash
git log main..milestone/$ARGUMENTS --oneline
git diff --stat main..milestone/$ARGUMENTS
```

### Step 5.5: Verify milestone scope vs. release notes

Issues get moved in and out of a milestone over its lifetime — rescoped to a
different milestone, split off, superseded, or consolidated after their PR
already merged and their release-notes entry was already written. The
release notes reflect scope *at the time each issue was worked*, which can
silently drift from the milestone's *current* actual membership by the time
the milestone finishes. Catch this before finalizing, not after — a release
note that credits work to the wrong milestone (or omits real work) is wrong
in a way none of the later review steps are positioned to catch, since they
all take the release notes' existing content as ground truth.

1. Get the milestone's current membership, **all states** (a closed issue
   can still be reassigned to a different milestone afterward):

   ```bash
   MILESTONE_NUM=<from Step 1/2>
   gh api "repos/elan-registry/registry/issues?milestone=${MILESTONE_NUM}&state=all&per_page=100" \
     --jq '.[] | "\(.number)\t\(.title)\t\(.state)"'
   ```

2. Extract the issue numbers currently listed in the release notes' "Issues
   Resolved" section:

   ```bash
   grep -oP '(?<=issues/)\d+' docs/releases/RELEASE_NOTES_$ARGUMENTS.md | sort -un
   ```

3. Diff the two number sets and investigate every mismatch:

   - **In release notes, NOT in current milestone membership** — this issue
     was moved elsewhere after its entry was written. Confirm where it lives
     now:

     ```bash
     gh issue view <N> --repo elan-registry/registry --json milestone,state
     ```

     If it genuinely moved to a different milestone, remove its "Issues
     Resolved" entry and any associated changelog bullet (New
     Features/Improvements/Bug Fixes) — that work no longer ships in this
     release. Also re-check whether the release's headline "Type" line and
     summary still make sense without it (a moved-out issue can invalidate
     the release's stated theme, not just one bullet).

   - **In current milestone membership (closed), NOT in release notes** — a
     real gap. Check why it has no entry:

     ```bash
     gh issue view <N> --repo elan-registry/registry --json state,stateReason,comments \
       --jq '{state, stateReason, comments: [.comments[].body]}'
     ```

     - Comment says **"Consolidated into #X"**: expected, no separate entry
       needed — confirm #X's existing entry actually covers this issue's
       scope, then move on.
     - Comment says **"Superseded by #X"**: check #X's *current* milestone.
       If #X is in this same milestone, its entry already covers this issue
       — no action needed. **If #X is in a different milestone (or its work
       was never actually merged into this milestone branch — verify with
       `git log milestone/$ARGUMENTS --oneline --grep="<X or a keyword>"`),
       this issue is claiming credit for work that doesn't actually ship
       here either.** This is the same drift as the first bullet, one hop
       removed. Do not guess how to resolve it — present it to the user with
       the full chain (this issue → superseded by #X → #X's actual
       milestone) and ask: move this issue to match #X's milestone, list it
       here with a "no code shipped" note, or something else.
     - No such comment: genuine gap. Draft an accurate "Issues Resolved"
       entry (and a changelog bullet, if it represents real shipped
       behavior rather than a purely operational/verification action — e.g.
       "confirmed working after a config redeploy" belongs in Issues
       Resolved but may not need its own user-facing changelog bullet) from
       the issue's title, body, and closing comment.

4. **Present every discrepancy found to the user before editing anything.**
   Unambiguous cases (confirmed moved to another milestone; confirmed
   consolidated with an entry already present) can be applied directly and
   just noted in the summary. Ambiguous cases (the superseded-elsewhere
   pattern above) require the user's explicit decision — do not proceed on
   your own judgment.

5. Apply the agreed changes to `docs/releases/RELEASE_NOTES_$ARGUMENTS.md`.
   If a GitHub milestone reassignment was part of the resolution (e.g.
   moving an issue to match where its superseding issue actually lives):

   ```bash
   gh issue edit <N> --repo elan-registry/registry --milestone "<target milestone title>"
   ```

If no discrepancies are found, note that in the summary and continue — this
step doesn't need to slow down a milestone where nothing moved.

### Steps 6–8: Independent doc checks — run the assessment phase in parallel

Steps 6, 7, and 8 below are three independent "does this need updating"
assessments — none depends on another's output, and each touches a disjoint
set of files (release notes, the wiki clone, `CLAUDE.md`). Launch their read/assess
phases together (a single message with multiple Explore/Task calls: gather
the `git diff --name-only main...milestone/$ARGUMENTS` file list once and
hand it to all three, read the release notes file, review CLAUDE.md against
the changes), rather than working through them one at a time. Apply the
resulting edits/commits after — commit order between them doesn't matter
since they touch different files.

### Step 6: Finalize release notes at `docs/releases/RELEASE_NOTES_$ARGUMENTS.md`

- Check for any remaining `WIP:` prefixes in the "Issues Resolved" section:

  ```bash
  grep -n "WIP:" docs/releases/RELEASE_NOTES_$ARGUMENTS.md
  ```

  Each one means an issue's `/finish-issue` run never stripped it — either
  that issue's PR never actually merged (contradicts Step 2's "no open
  issues remain" check, so investigate that discrepancy first) or its
  `/finish-issue` run skipped Step 8 for some other reason. Do not strip a
  remaining `WIP:` prefix yourself as a shortcut — confirm the issue is
  genuinely closed and merged (cross-check against Step 4's merged-PR list)
  before removing it, since this prefix is the one signal that distinguishes
  "planned" from "actually shipped" in this document.
- Use the `technical-documentation-writer` agent to finalize:
  - Fill in any remaining template placeholders
  - Keep entries in "Issues Resolved", "User-Facing Changes", and
    "Admin-Facing Changes" to **one sentence each** — full detail lives in
    the issue/PR itself; the release notes are a pointer, not a changelog
    essay
  - Scope accuracy (issue membership vs. milestone) was already verified in
    Step 5.5 — this pass is about content completeness/wording, not re-doing
    that cross-check
  - Deployment steps (migrations, new env vars, admin-script/permission
    registration, manual verification procedures) do **not** belong in this
    file — they go in the deploy sheet, rendered in Step 6.6 below
- Commit the finalized release notes if changes were made (or amend the
  Step 5.5 commit if it hasn't been pushed yet, to keep history clean)

### Step 6.5: Release retrospective — three questions

Five minutes, appended to the release notes under a `## Retrospective`
heading. One line each is enough.

Ask the user, one at a time:

1. > "What did we ship in this release that nobody needed?"

   Be specific — name the issue.

2. > "What did we learn about this theme's audience?"

3. > "What signal arrived during this milestone that we ignored — and was that
   > right?"

Record the answers in the release notes and carry question 1's answer into the
next `/start-milestone` Step 4.4, so the theme is chosen knowing what the last
one over-built.

### Step 6.6: Render the deploy sheet for review

Deployment steps used to live in the release notes' "Required Actions After
Deployment" section; they now live in a standalone deploy sheet, generated
here — early, while the milestone branch is still under review — rather than
first at `/release-milestone` time.

1. Gather the inputs from the diff:

   ```bash
   git diff --name-only main...milestone/$ARGUMENTS
   ```

   Specifically determine:
   - New files under `database/migrations/`, and whether any contains
     `CREATE TRIGGER` (grep the diff for it, not just new files — an existing
     migration file is never edited, but check anyway defensively)
   - Whether `scripts/server-hooks/post-receive` changed
   - New files calling `securePage(` — these are new pages needing
     `21-Fix-Page-Permissions.php` registration
   - New files under `app/admin/scripts/fix/` or `app/admin/scripts/maintenance/`
   - Whether `.env.example` changed — list the new keys and their purpose
     (read the surrounding comment in the diff)
   - Any manual verification procedure a merged PR's own description
     documents (e.g. a webhook registration/capture-script dance, a spike
     script that needs deploying and then deleting) — these come from reading
     the individual issue PRs' bodies (Step 4's list), not from the release
     notes

2. Read `.claude.local.md` § "Deployment hosts" for the ssh alias and
   docroots; if the section is missing, stop and ask the user to add it
   (copy the block from `.claude.local.md.example`).

3. Render `docs/development/RELEASE_INSTRUCTIONS_TEMPLATE.md` for this
   release, following its "Rendering rules" exactly (fill placeholders,
   include only `<!-- IF -->` blocks whose condition holds, drop the markers,
   keep step numbering continuous). Write the rendered result to
   `docs/plans/releases/$ARGUMENTS-deploy.md` — that directory is gitignored,
   so this file is never committed, the same as an issue's plan file.

   Also write a sidecar stamp recording the commit this was rendered
   against, so `/release-milestone` and `/review-milestone` can mechanically
   detect staleness instead of eyeballing `git log` output:

   ```bash
   git rev-parse milestone/$ARGUMENTS > docs/plans/releases/$ARGUMENTS-deploy.md.sha
   ```

4. If the file already exists (e.g. this step is being re-run after fixing a
   Step 9.8 finding that changes the deploy inputs), overwrite both it and
   its `.sha` stamp — it always reflects the milestone branch's current
   state, not a stale earlier draft.

5. Tell the user the deploy sheet is ready for review at that path. Do not
   print its full contents into the conversation (it names ssh hosts and
   docroots) — the user reads the file directly, or imports it into Apple
   Notes per the template's own formatting rules.

`/release-milestone` reuses this same file at actual release time rather than
generating its own — if anything changed on the milestone branch between now
and then (e.g. a fix from Step 9.8 or 11.5), re-run this step to refresh it,
don't hand-edit the deploy sheet directly.

### Step 7: Update wiki documentation

**Default: skip.** Wiki updates are only needed when the milestone changes architecture, database schema, PHP classes, external integrations, or user-visible flows.

Get the changed source files:

```bash
git diff --name-only main...milestone/$ARGUMENTS
```

**Skip wiki update if** changes are only: bug fixes, config tweaks, docs reorganization, CSS/JS tweaks, or SQL seed data with no schema change.

**Run wiki update if** changed files include: `usersc/classes/`, `database/*.sql` (schema
changes), new user flows, new env variables, or changes to how UserSpice is integrated.

If update is needed, the wiki is a separate git repo — its permanent local
clone path is developer-specific, in `.claude.local.md`. Never clone it into
this repo or a temporary location.

1. Confirm the wiki clone path from `.claude.local.md`; `cd` into it, checkout
   `master-upload`, and pull
2. Read only the affected wiki pages (not all pages)
3. Launch `technical-documentation-writer` agent (haiku) to update only those
   pages, writing directly into the wiki clone
4. Commit in the wiki clone on `master-upload`:

   ```bash
   git -C <wiki-clone> add <file>.md
   git -C <wiki-clone> commit -m "docs: update wiki pages for $ARGUMENTS milestone changes"
   ```

5. Publish with the wiki repo's own `/publish-wiki` command (pushes
   `master-upload`, fast-forwards `master`, pushes and verifies) — do not
   push `master` directly

This step never touches this repo's own git history — nothing about a wiki
update is staged or committed here.

### Step 8: Update CLAUDE.md if needed

Review CLAUDE.md against the milestone's changes. Check whether any updates
are needed for:

- New environment variables or configuration
- New commands or scripts
- New important files or directories
- Changed architectural rules or patterns
- New testing requirements or conventions
- Changes to deploy process or CI/CD

If updates are needed, make targeted edits and commit:

```bash
git add CLAUDE.md
git commit -m "docs: update CLAUDE.md for $ARGUMENTS milestone changes"
```

If no updates needed, skip.

### Step 9.5: Cross-PR Security Integration Check

By the time this step runs, every individual issue PR has already passed:
security-reviewer in `/start-issue`, CodeQL CI, and Claude Code Review CI.
Do **not** re-run a full OWASP pass over already-reviewed files.

Instead, run a targeted cross-PR integration check. Get the full diff:

```bash
git diff main...milestone/$ARGUMENTS -- '*.php' '*.js'
```

Launch the `security-reviewer` agent with this scoped prompt:
> "Review only for cross-PR security interactions introduced by combining
> these changes. Focus on: (1) new code paths where output from one changed
> file flows into input handling in another changed file; (2) changes to
> shared auth, session, or CSRF middleware; (3) any file touched by 3+ PRs
> that may have accumulated risk across changes. Skip file-level OWASP
> checks — those were done per-issue. Report only findings that could not
> have been caught by reviewing each PR in isolation."

- If **Critical or High** cross-integration findings are found, **stop** and
  tell the user to fix them before proceeding.
- If only Medium/Low or no findings, note in summary and proceed.

### Step 9.7: Local multi-agent review (before opening the PR)

Run a scoped `/review-pr` against `main` on the milestone branch. Scope the agents to the file types changed — don't run all agents unconditionally.

Determine which agents apply based on `git diff --name-only main...milestone/$ARGUMENTS`:

| Changed file types | Agents to run |
| --- | --- |
| `.php` files | code-reviewer, silent-failure-hunter |
| `.php` with forms/SQL | + security-reviewer (file-level review only — Step 9.5 already covered cross-PR interaction effects; don't re-derive those here) |
| New PHP classes/types | + type-design-analyzer |
| Test files changed | pr-test-analyzer |
| Docs/comments changed | comment-analyzer + independent fact-check (see `/review-pr` Step 4.5 — fresh, context-free agent re-derives each factual claim from source rather than trusting the diff) |

Launch only the applicable agents in parallel. Skip agents for file types not present in the diff.

Focus areas at milestone level:

- Cross-issue integration (did two PRs introduce contradictions?)
- Release-notes accuracy vs. the merged PR list
- Aggregated security surface

If Critical or Important issues surface, **stop and fix them before creating the PR**.

Once the local review is clean, record a short list of what Step 9.7 found
and fixed (file:line + one-line description per item, or "none" if the
review was clean on the first pass) — Step 9.8 needs this to avoid
re-flagging the same issues as new findings.

Proceed to Step 9.8.

### Step 9.8: Local milestone-level deep review (mirrors CI, runs before the PR exists)

Step 9.7 reviews individual files by type. This step instead runs the same
**aggregate, milestone-level** analysis that the CI `milestone-review` job
(`claude-code-review.yml`) performs — but locally, before the PR is even
created.

Build the inputs (the merged PR list from Step 4 and the diff from Step 5 are
already available):

```bash
gh pr list --base milestone/$ARGUMENTS --state merged --limit 100 \
  --json number,title,mergedAt,author \
  --jq '.[] | "#\(.number) \(.title) (by @\(.author.login))"'
git diff main...milestone/$ARGUMENTS
```

Launch a single agent via the Agent tool with `subagent_type: "senior-architect"`
and `model: "fable"` (matching the CI job's tier — this is an infrequent,
once-per-milestone deep analysis, not a per-push check). Provide it with:

- The merged PR list (from the command above)
- The full diff `main...milestone/$ARGUMENTS`
- The finalized release notes at `docs/releases/RELEASE_NOTES_$ARGUMENTS.md`
- Step 9.7's resolved-findings list (or "none" if it was clean) — tell the
  agent these were already found and fixed at the file level, so it should
  not re-report the same issue as a new finding here. This step's value is
  catching what per-file review *can't* see (cross-PR interactions,
  aggregate surface) — items 9.7 already closed are not that.

Ask it to perform the same five checks the CI job does:

1. **Release notes accuracy** — compare the merged PR list against the release
   notes. Flag missing, duplicated, or mis-categorized entries.
2. **Architecture drift** — is the aggregated shape coherent with CLAUDE.md?
   Any cross-cutting changes that warrant a wiki update?
3. **Cross-issue integration** — interactions between merged PRs: shared
   types, shared DB schema, shared JS globals, API contract drift between
   endpoints touched by different PRs.
4. **Security surface of the aggregate** — does the *sum* introduce a new
   vector that no single PR would have shown in isolation?
5. **Deployment readiness** — any new env vars, migrations, feature flags, or
   pre-deploy steps missing from the release notes?

**This step blocks on any finding, not just Critical/High.** Present every
finding to the user, regardless of severity, and do not proceed to Step 9.9
until each one is explicitly resolved or the user explicitly accepts it as
non-blocking. Do not silently wave through Medium/Low items — noting them and
proceeding without the user's say is exactly what defeats the point of
running this before the PR exists.

For each finding:

- **Fix it** — apply the fix, then re-run this step's agent on the corrected
  diff to confirm it's clean.
- **User explicitly accepts the risk** — record the acceptance decision in
  the plan/PR description so it's auditable later; only then proceed.

Do not rely on the CI job as a substitute for resolving these — it runs after
the PR is already open, which is a worse place to discover them, and it is a
backstop/audit trail (`/review-milestone` Step 4), not a decision point.

Once every finding is resolved or explicitly accepted, proceed to Step 9.9.

### Step 9.9: Fresh-checkout smoke test (only when build/install steps changed)

Every review above — Step 9.7, Step 9.8, CI's own diff-based reviews — reads
diffs and file contents. None of them *execute* anything against a truly
clean checkout. That gap let a real bug ship undetected on v2.29.4:
`scripts/build.js` never created `usersc/js`/`usersc/css` before writing into
them, so a fresh clone's first build threw `ENOENT` and silently produced no
vendored frontend assets — invisible to every diff review because every
existing local checkout already had those directories on disk from before the
change. It surfaced only by accident, well into `/release-milestone`, when a
live page happened to be checked in a browser.

**Run this step whenever the milestone touched**: `scripts/build.js` (or any
build/install tooling), `package.json`/`composer.json` dependency tiers,
`.gitignore` (new ignored generated-output paths), or `scripts/server-hooks/`
(deploy-hook logic). Skip it for milestones with no build/install/deploy
tooling changes — it exists for exactly that class of bug, not as a general
smoke test.

**Procedure:**

```bash
# Use a scratch worktree, not your working checkout — the goal is to
# reproduce what a genuinely fresh clone/deploy sees, with nothing left
# over from prior local state.
git worktree add /tmp/milestone-smoke-$ARGUMENTS milestone/$ARGUMENTS
cd /tmp/milestone-smoke-$ARGUMENTS

composer install --no-dev --optimize-autoloader   # mirrors deploy's actual install flags
npm ci --omit=dev                                  # mirrors deploy's actual install flags (adjust flags to match this milestone's build.js/post-receive invocation)
npm run build                                      # or whatever the deploy hook actually runs

# Confirm the build's expected output actually exists on disk — don't just
# check the exit code, since a partial-then-crash run can exit non-zero
# after already producing some files (masking that other expected files
# are missing).
```

Check the exit code AND the actual file listing of whatever the build is
supposed to produce (e.g. `ls usersc/js usersc/css` for this milestone's
vendored-asset build). A clean exit with missing expected output is exactly
the bug this step exists to catch.

If anything fails or produces incomplete output, fix it on the milestone
branch (same fix-then-re-verify loop as Step 9.8), then re-run this step
against the fixed commit. Clean up the worktree when done:

```bash
cd -
git worktree remove /tmp/milestone-smoke-$ARGUMENTS
```

Once clean, proceed to Step 10.

### Step 10: Output summary and hand off to `/review-milestone`

Write a completion marker before summarizing — `/review-milestone` Step 1
checks for this rather than trusting that this command actually reached
Step 10 (a crashed/interrupted/cancelled run could otherwise leave the
deploy sheet and clean release notes in place with zero review having
happened, since those are written earlier, in Steps 6/6.6, before 9.5-9.9
run):

```bash
cat > docs/plans/releases/$ARGUMENTS-review.done <<EOF
9.5: <clean|findings-fixed>
9.7: <clean|findings-fixed>
9.8: <clean|findings-fixed>
9.9: <ran-clean|not-applicable>
EOF
```

Use `not-applicable` for 9.9 only when Step 9.9's own trigger conditions
didn't apply to this milestone (no build/install/deploy tooling changed) —
not as a stand-in for skipping it when it should have run.

Then summarize:

- Known-broken test exclusions status (none found, or resolved, or explicitly accepted with issue references)
- Milestone-scope corrections from Step 5.5 (none found, or list issues added/removed/reassigned and why)
- Release notes status (finalized or needs attention)
- Deploy sheet status (rendered at `docs/plans/releases/$ARGUMENTS-deploy.md`, ready for review)
- Wiki updates status (updated, committed, or skipped)
- CLAUDE.md update status (updated or skipped)
- Review results (Steps 9.5, 9.7, 9.8, 9.9): clean, or list what was found and fixed
- Remind: if wiki pages were updated, confirm they were published via
  `/publish-wiki` in the wiki clone — this repo's PR does not carry them

Use AskUserQuestion for the next step:

- Question: "Milestone gated and documented. Run `/review-milestone $ARGUMENTS` now?"
- Options: `Run /review-milestone $ARGUMENTS` (recommended — opens the PR,
  verifies CI review posted, confirms green), `Ask more questions first`
- If the user picks `/review-milestone`, invoke it immediately via the
  Skill tool. If they pick the discuss option, drop into normal
  conversation and don't re-offer until they ask what's next.

## Important

- Wiki updates are made directly in the separate wiki git repo's permanent
  local clone (path in `.claude.local.md`), on `master-upload`, and published
  via that repo's `/publish-wiki` command — never staged or committed in
  this repo
- Do not push to any remote, and do not create a PR — that's `/review-milestone`'s job
- If release notes still have WIP markers, flag this prominently before
  handing off
- The deploy sheet lives at `docs/plans/releases/<version>-deploy.md` —
  gitignored, same as an issue's plan file, and never committed or printed in
  full to the conversation (it names ssh hosts and docroots)
- Deployment procedure content (migrations, new env vars, admin-script/
  permission registration, manual verification runbooks) belongs in the
  deploy sheet, not in `docs/releases/RELEASE_NOTES_<version>.md` — that file
  is user-/admin-facing changelog plus a one-sentence-per-issue index
