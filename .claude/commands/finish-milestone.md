---
description: Create a PR to merge a completed milestone branch into main, finalize docs and release notes
model: claude-fable-5-1
---

# Finish Milestone

Keep output brief — terse status lines, no preamble, no restating of steps.

Create a PR to merge a completed milestone branch into main, finalize release
notes, update wiki documentation, and prepare for release.

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
7. Update wiki documentation (or skip)
8. Update CLAUDE.md if needed
9. Security review (Step 9.5) + local multi-agent review (Step 9.7)
9.8. Local milestone-level deep review (Fable) — mirrors CI, runs pre-PR
10. Create the PR targeting main
11. Verify CI milestone review posted a comment; re-trigger if missing
12. Output summary

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
   proceed anyway, record that decision in the milestone PR body (Step 10) under a
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
  - Ensure deployment instructions, verification steps are complete
  - Ensure "Required Actions After Deployment" is accurate
  - Scope accuracy (issue membership vs. milestone) was already verified in
    Step 5.5 — this pass is about content completeness/wording, not re-doing
    that cross-check
- Commit the finalized release notes if changes were made (or amend the
  Step 5.5 commit if it hasn't been pushed yet, to keep history clean)

### Step 6.5: Release retrospective — three questions

Five minutes, appended to the release notes under a `## Retrospective`
heading. One line each is enough; the value is entirely in answering the first
one honestly.

Ask the user, one at a time:

1. > "What did we ship in this release that nobody needed?"

   Be specific — name the issue. This is the only feedback loop that improves
   the planning gate, and a few honest answers will do more to stop make-work
   than any rule in the workflow.

2. > "What did we learn about this theme's audience?"

3. > "What signal arrived during this milestone that we ignored — and was that
   > right?"

Record the answers in the release notes and carry question 1's answer into the
next `/start-milestone` Step 4.4, so the theme is chosen knowing what the last
one over-built.

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
| `.php` with forms/SQL | + security-reviewer |
| New PHP classes/types | + type-design-analyzer |
| Test files changed | pr-test-analyzer |
| Docs/comments changed | comment-analyzer + independent fact-check (see `/review-pr` Step 4.5 — fresh, context-free agent re-derives each factual claim from source rather than trusting the diff) |

Launch only the applicable agents in parallel. Skip agents for file types not present in the diff.

Focus areas at milestone level:

- Cross-issue integration (did two PRs introduce contradictions?)
- Release-notes accuracy vs. the merged PR list
- Aggregated security surface

If Critical or Important issues surface, **stop and fix them before creating the PR**.

Once the local review is clean, proceed to Step 9.8.

### Step 9.8: Local milestone-level deep review (mirrors CI, runs before the PR exists)

Step 9.7 reviews individual files by type. This step instead runs the same
**aggregate, milestone-level** analysis that the CI `milestone-review` job
(`claude-code-review.yml`) performs — but locally, before the PR is even
created, so problems surface at the earliest possible point rather than after
the milestone branch is already public and under review.

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
finding to the user, regardless of severity, and do not proceed to Step 10
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
backstop/audit trail (Step 11), not a decision point.

This step duplicates the CI job's analysis by design. It runs once per
milestone, so the extra cost is worth catching problems before the milestone
branch is exposed as a PR rather than after.

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

### Step 10: Create the PR targeting main

```bash
gh pr create \
  --base main \
  --head milestone/$ARGUMENTS \
  --title "$ARGUMENTS — <milestone name>" \
  --body "$(cat <<'EOF'
## Summary

<1-2 sentence description of the milestone's purpose>

## Issues Resolved

<List each merged PR with closing keywords>

Closes #NNN — Issue title (PR #NN)
Closes #NNN — Issue title (PR #NN)

## Release Notes

See `docs/releases/RELEASE_NOTES_$ARGUMENTS.md` for complete release notes.

<!-- Include this section ONLY if Step 3.5 found known-broken-tagged tests and the user
     chose to proceed with them explicitly accepted (option (b) in that step). Omit entirely
     if Step 3.5 found nothing, or everything was resolved/removed before this PR. -->

## Known Test Exclusions

The following tests are excluded from the CI-blocking run via `#[Group('known-broken')]` and
were explicitly accepted as a known gap for this release (see Step 3.5):

- `<test name>` (`<file path>`) — tracked by #`<issue number>` (`<open/closed>`)

## Test Plan

- [ ] All issue PRs were reviewed and merged into milestone branch
- [ ] Pre-commit hooks pass on all changed files
- [ ] Unit tests pass (`composer test:quick`)
- [ ] Integration tests pass (`composer test:medium`)
- [ ] Browser tests pass where applicable (`npm run playwright:test`)
- [ ] Manual verification of key user flows
- [ ] Security review completed (run before this PR was created)

🤖 Generated with [Claude Code](https://claude.com/claude-code)
EOF
)"
```

**CRITICAL**: The PR body MUST include `Closes #NNN` for every issue in the
milestone. Individual issue PRs target the milestone branch (not main), so
their closing keywords won't auto-close issues. Only this final PR merged into
main triggers auto-closure.

Fill in actual data from steps 4 and 5.

### Step 11: Verify CI milestone review posted a comment (backstop + audit trail)

Once the PR is open, the `claude-code-review.yml` workflow is *expected* to
run the same milestone-level analysis (Fable) against `main` that Step 9.8
already ran locally, and post the result as a visible PR comment. **Do not
assume this happened — verify it.**

PR-open events are not guaranteed to trigger Actions runs at all: GitHub's
abuse/rate throttle can silently suppress webhook-triggered runs (this
happened on PR #1718 — see #1724). And even when a run *is* triggered, a job
`conclusion: success` does not prove a review was posted: the
`claude-code-action@v1` step can complete without ever calling `gh pr
comment` — most commonly because the action's own workflow-file-must-match-
default-branch validation silently skips execution on PRs that modify
`claude-code-review.yml` itself (documented in that file's header comment
block — this is intentional security behavior, not a bug, but it still means
no review posted). A "successful" job is not evidence of a posted review;
only the comment itself is.

**Verify by checking for the comment, not the job status:**

```bash
PR_NUM=<pr-number>
gh api "repos/elan-registry/registry/issues/${PR_NUM}/comments" \
  --jq '[.[] | select(.body | test("#{1,6}\\s+Strengths|\\*\\*Strengths\\*\\*"))] | length'
```

This is the same "Strengths"-heading pattern `claude-code-review.yml`'s own
gate step uses to detect a real posted review (both the `pr-to-milestone-
review` and `milestone-review` jobs' final steps check for it) — reuse it as
the ground truth here rather than inventing a separate signal.

Poll every ~30s for up to ~5 minutes (Fable milestone reviews run longer than
the lightweight Sonnet per-push reviews). If a matching comment appears,
**the review ran successfully** — note this in the Step 12 summary and move
on.

**If no matching comment appears after the poll window**, first check
whether the PR opted out of review — `milestone-review` deliberately skips on
titles containing `[skip-review]` (see `claude-code-review.yml`'s `if:`
condition, which applies even to the label-triggered event):

```bash
gh pr view "$PR_NUM" --json title -q .title --repo elan-registry/registry
```

If the title contains `[skip-review]`, no comment is the **correct**,
by-design outcome, not a failure — report "review intentionally skipped per
title tag" and proceed to Step 12. (Applying the `deep-review` label in this
case is harmless — the job's `if:` still blocks on the title tag even for
the labeled event, so it would silently no-op rather than force a review —
but doing so anyway just wastes a poll cycle for no benefit; skip straight to
reporting instead.)

If the title carries neither tag, determine which failure mode this is
before recovering:

```bash
HEAD_SHA=$(gh pr view "$PR_NUM" --json headRefOid -q .headRefOid --repo elan-registry/registry)
gh run list --workflow=claude-code-review.yml --repo elan-registry/registry \
  --json databaseId,headSha,status,conclusion,event \
  --jq --arg sha "$HEAD_SHA" '[.[] | select(.headSha == $sha)]'
```

- **No matching run at all** — never triggered. This is the #1724 throttle
  case. Recover:

  ```bash
  gh pr edit "$PR_NUM" --add-label "deep-review" --repo elan-registry/registry
  ```

  Then re-poll for the comment the same way as above.

- **A run exists but produced no comment** — check whether this PR's diff
  touches `.github/workflows/claude-code-review.yml`:

  ```bash
  gh pr diff "$PR_NUM" --name-only --repo elan-registry/registry | grep -Fx '.github/workflows/claude-code-review.yml'
  ```

  If it does, this is the documented self-referential workflow-file skip —
  the `deep-review` label will **not** fix it; the workflow file only takes
  effect once merged to `main`. Report this to the user distinctly (do not
  silently re-trigger). If the diff does not touch that file, treat it the
  same as "never triggered" above (apply the `deep-review` label, re-poll)
  since `claude-code-review.yml` already has a fallback-post step for
  turn-exhaustion (it posts Claude's last result text directly — see the
  workflow's own comment referencing PR #1529), so a run that completed with
  zero comment and an untouched workflow file more likely means that
  fallback step itself failed to post (e.g. a `gh pr comment` / API error,
  or an empty execution file) than plain turn-exhaustion. Either way the
  recovery action is the same — re-trigger and re-poll.

**Never report this step as complete without a confirmed comment or an
explicit, reported reason recovery isn't applicable.** This verify-then-
recover loop replaces the previous assumption that PR-open automatically
produces a review — that assumption is exactly what failed on PR #1718.

### Step 11.5: Fix findings and confirm CI is fully green before handoff

Finding a comment exists (Step 11) is not the same as the milestone being
ready to release. Read the comment's actual content and check for any
`Blocking` or `Important` heading — not just whether the comment exists.

**This step exists because of a real incident**: on v2.29.4, Step 11 verified
a review posted and stopped there. The posted review had 3 `Important`
findings (a two-push deploy-window gap, an unverified prod host, and
`node_modules` persisting in the deployed docroot). None were fixed before
`/finish-milestone` handed off — they were only discovered and fixed later,
*during* `/release-milestone`, forcing a second review round and a live
merge-in-progress fix cycle. `/release-milestone` is the point of no return;
finding and fixing problems there is strictly worse than finding them here.

**Procedure:**

1. Fetch the posted comment(s) and check for `## Blocking` or `## Important`
   headings with actual content (not just an empty section or "none found").
2. **If any Blocking or Important finding exists:** fix it the same way
   Step 9.8 requires — apply the fix as a commit on the milestone branch,
   push it (this updates the still-open PR), then **re-verify CI is green
   and re-check for a fresh review comment** (a push may trigger
   `pr-to-milestone-review`, or you may need to re-apply the `deep-review`
   label to get a fresh `milestone-review` pass against the fixed diff).
   Repeat until a review comment shows zero unresolved Blocking/Important
   items.
3. **Also verify all CI checks are green at this point** — not just that a
   review comment exists. `gh pr checks <pr-number>` must show every check
   passed (skipped checks that are correctly gated off, per this workflow's
   own design, are fine — an actual failure or a still-pending required
   check is not).
4. Do not proceed to Step 12 until both (2) and (3) are satisfied. If a fix
   turns out to require user input or a judgment call (e.g. the prod-host
   verification advisory from the incident above, which needs live SSH
   access only the user has), present it via AskUserQuestion and get an
   explicit decision — "defer to a tracked follow-up" is an acceptable
   resolution, but it must be an explicit choice recorded in the PR, not a
   silent skip.

**The bar for calling `/finish-milestone` complete:** the milestone branch,
as it exists on `main`'s target commit right this moment, should need zero
further code changes before `/release-milestone` runs. `/release-milestone`
merges, tags, and publishes — it is not a place to discover or fix problems.

### Step 12: Output summary

- The PR number and URL
- List of merged issue PRs included
- Known-broken test exclusions status (none found, or resolved, or explicitly accepted with issue references)
- Milestone-scope corrections from Step 5.5 (none found, or list issues added/removed/reassigned and why)
- Release notes status (finalized or needs attention)
- Wiki updates status (updated, committed, or skipped)
- CLAUDE.md update status (updated or skipped)
- CI milestone review status (from Step 11): "posted normally" / "no run was
  triggered — re-triggered via deep-review label, now posted" / "ran but
  posted nothing — self-referential workflow-file change, requires merge to
  main first" / etc. — never omit this line
- Remind: if wiki pages were updated, confirm they were published via
  `/publish-wiki` in the wiki clone — this repo's PR does not carry them
- Note as plain text (informational, not a runnable choice): "To re-run the
  deep review later, label the PR `deep-review` or comment `@claude
  deep-review`" and "Release notes are at
  `docs/releases/RELEASE_NOTES_$ARGUMENTS.md`"
- Use AskUserQuestion for the actual next step, since `/release-milestone`
  is runnable right now — it merges the PR itself (that's its Step 8), it
  does not wait for a human to merge on GitHub first:
  - Question: "Milestone PR ready. What next?"
  - Options: `Run /release-milestone $ARGUMENTS` (recommended — merges the
    PR, tags, and publishes the release), `Ask more questions / review the
    PR myself first`
  - If the user picks `/release-milestone`, invoke it immediately via the
    Skill tool. If they pick the discuss option, drop into normal
    conversation and don't re-offer until they ask what's next.

## Important

- **Closing keywords are critical** — without them in the PR body, issues
  won't auto-close on merge
- The PR MUST target `main`, not any other branch
- Wiki updates are made directly in the separate wiki git repo's permanent
  local clone (path in `.claude.local.md`), on `master-upload`, and published
  via that repo's `/publish-wiki` command — never staged or committed in
  this repo
- Do not push to any remote — this command only creates the PR on GitHub
- If release notes still have WIP markers, flag this prominently before
  creating the PR
