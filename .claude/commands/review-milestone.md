---
description: Open the milestone PR, verify CI review posted, and confirm CI is fully green
model: claude-fable-5-1
---

# Review Milestone

Keep output brief — terse status lines, no preamble, no restating of steps.

Open the PR for a gated milestone branch, verify the CI milestone-level
review actually posted a comment, and drive any Blocking/Important findings
to resolution before the branch is handed off to `/release-milestone`.

This command picks up where `/finish-milestone` left off — after the
milestone branch has been reviewed (Steps 9.5–9.9 there) and its
documentation finalized (Steps 5.5–8 there), but before any PR exists.

## Arguments

- `$ARGUMENTS` — the milestone version number (e.g., `v2.17.0`)

## Workflow

### Step 0: Initialize TaskList

Before any other action, create one tracking task per workflow step using
TaskCreate:

1. Locate the milestone branch and re-verify it's review-ready
2. Re-derive merged-PR list, diff, and known-broken-test status
3. Create the PR targeting main
4. Verify CI milestone review posted a comment; re-trigger if missing
5. Fix findings and confirm CI is fully green
6. Output summary

Set each task to `in_progress` when you begin it and `completed` on success.

### Step 1: Locate the milestone branch and re-verify it's review-ready

```bash
git branch -a | grep "milestone/$ARGUMENTS"
git checkout milestone/$ARGUMENTS
git pull origin milestone/$ARGUMENTS
```

If the branch doesn't exist, stop and report the error.

**Do not trust that `/finish-milestone` actually finished** — verify against
repo state rather than assume the handoff was clean (same principle
`/execute-plan` Step 3 applies to a plan file's checkboxes):

- Release notes have no remaining `WIP:` markers:

  ```bash
  grep -n "WIP:" docs/releases/RELEASE_NOTES_$ARGUMENTS.md
  ```

  If any remain, stop. Tell the user `/finish-milestone` Step 6 hasn't
  actually finished — re-run `/finish-milestone $ARGUMENTS` before continuing.

- The deploy sheet exists:

  ```bash
  ls docs/plans/releases/$ARGUMENTS-deploy.md
  ```

  If missing, stop. Tell the user: "No deploy sheet found — run
  `/finish-milestone`'s Step 6.6 (or re-run `/finish-milestone $ARGUMENTS`)
  to generate one first." Do not render it yourself here — that
  responsibility belongs to `/finish-milestone`.

- **The review steps (9.5, 9.7, 9.8, 9.9) actually ran** — the deploy sheet
  and clean release notes only prove Steps 6/6.6 finished; they say nothing
  about whether review happened, since those steps run later. An
  interrupted `/finish-milestone` run (crash, cancelled session, context
  limit) could otherwise leave both file checks above passing with zero
  local review having occurred. Check for the completion marker:

  ```bash
  cat docs/plans/releases/$ARGUMENTS-review.done
  ```

  If missing, or if any line reads anything other than `clean`,
  `findings-fixed`, `ran-clean`, or `not-applicable` (e.g. it's absent
  entirely, or a step's line is missing), stop. Tell the user:
  "`/finish-milestone` doesn't show as having completed its review steps —
  re-run `/finish-milestone $ARGUMENTS` to finish Steps 9.5–9.9 before
  opening a PR." Do not proceed on the assumption that review "probably"
  happened — this check exists specifically for the case where it didn't.

### Step 2: Re-derive merged-PR list, diff, and known-broken-test status

Re-run these fresh rather than assume anything from an earlier `/finish-milestone`
run is still current — the branch may have changed since:

```bash
gh pr list --base milestone/$ARGUMENTS --state merged --json number,title,url
git log main..milestone/$ARGUMENTS --oneline
git diff --stat main..milestone/$ARGUMENTS
```

Re-check for any remaining known-broken test tags:

```bash
grep -rn "Group('known-broken')" tests/ || echo "None found"
```

**If none found**, proceed to Step 3.

**If any are found**, this is either a tag `/finish-milestone` Step 3.5
already surfaced and the user accepted, or one that appeared since. Don't
assume which — re-run the same decision live: present the list (test name,
file, cited issue, that issue's current state) and ask the user to (a)
resolve first, (b) proceed with this explicitly accepted (record it for
Step 3's PR body), or (c) stop here. Do not proceed without an explicit
answer.

### Step 3: Create the PR targeting main

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

<!-- Include this section ONLY if Step 2 found known-broken-tagged tests and the user
     chose to proceed with them explicitly accepted. Omit entirely if Step 2 found
     nothing, or everything was resolved/removed before this PR. -->

## Known Test Exclusions

The following tests are excluded from the CI-blocking run via `#[Group('known-broken')]` and
were explicitly accepted as a known gap for this release (see Step 2):

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

Fill in actual data from Step 2.

### Step 4: Verify CI milestone review posted a comment (backstop + audit trail)

Once the PR is open, the `claude-code-review.yml` workflow is *expected* to
run the same milestone-level analysis (Fable) against `main` that
`/finish-milestone` Step 9.8 already ran locally, and post the result as a
visible PR comment. **Do not assume this happened — verify it.**

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
scripts/poll-review-posted.sh <pr-number> 30 300
```

30s interval, 5min timeout (Fable milestone reviews run longer than the
lightweight Sonnet per-push reviews). Same underlying `check-review-posted.sh`
check `/address-pr-comments`, `/finish-issue`, and `/execute-plan` use — see
its header for why this, not job status, is the ground truth, and note it
also mirrors (but can't literally share code with) the "Strengths"-heading
check inlined in `claude-code-review.yml`'s own gate steps.

If a matching comment appears, **the review ran successfully** — note this
in the Step 6 summary and move on.

**If no matching comment appears after the poll window**, first check
whether the PR opted out of review — `milestone-review` deliberately skips on
titles containing `[skip-review]` (see `claude-code-review.yml`'s `if:`
condition, which applies even to the label-triggered event):

```bash
gh pr view "$PR_NUM" --json title -q .title --repo elan-registry/registry
```

If the title contains `[skip-review]`, no comment is the **correct**,
by-design outcome, not a failure — report "review intentionally skipped per
title tag" and proceed to Step 6. (Applying the `deep-review` label in this
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

### Step 5: Fix findings and confirm CI is fully green before handoff

Finding a comment exists (Step 4) is not the same as the milestone being
ready to release. Read the comment's actual content and check for any
`Blocking` or `Important` heading — not just whether the comment exists.

**This step exists because of a real incident**: on v2.29.4, the prior
verify step confirmed a review posted and stopped there. The posted review
had 3 `Important` findings (a two-push deploy-window gap, an unverified prod
host, and `node_modules` persisting in the deployed docroot). None were fixed
before handoff — they were only discovered and fixed later, *during*
`/release-milestone`, forcing a second review round and a live
merge-in-progress fix cycle. `/release-milestone` is the point of no return;
finding and fixing problems there is strictly worse than finding them here.

**Procedure:**

1. Check for unresolved findings using the same hardened detection CI's own
   merge gate uses (not a raw eyeball over comment text — a recap of an
   already-resolved finding must not be mistaken for a live one, #1843):

   ```bash
   scripts/check-blocking-findings.sh <pr-number> --include-important
   ```

   Exit 0 = clean. Exit 1 = an unresolved finding exists (see its output for
   which heading). Exit 2 = no posted review comment found — treat as
   "can't verify," not "clean."
2. **If any Blocking or Important finding exists:** fix it the same way
   `/finish-milestone` Step 9.8 requires — apply the fix as a commit on the
   milestone branch, push it (this updates the still-open PR), then
   **re-verify CI is green and re-check for a fresh review comment** (a push
   may trigger `pr-to-milestone-review`, or you may need to re-apply the
   `deep-review` label to get a fresh `milestone-review` pass against the
   fixed diff). Repeat until a review comment shows zero unresolved
   Blocking/Important items.
3. **Also verify all CI checks are green at this point** — not just that a
   review comment exists. `gh pr checks <pr-number>` must show every check
   passed (skipped checks that are correctly gated off, per this workflow's
   own design, are fine — an actual failure or a still-pending required
   check is not).
4. Do not proceed to Step 6 until both (2) and (3) are satisfied. If a fix
   turns out to require user input or a judgment call (e.g. the prod-host
   verification advisory from the incident above, which needs live SSH
   access only the user has), present it via AskUserQuestion and get an
   explicit decision — "defer to a tracked follow-up" is an acceptable
   resolution, but it must be an explicit choice recorded in the PR, not a
   silent skip.

**The bar for calling `/review-milestone` complete:** the milestone branch,
as it exists on `main`'s target commit right this moment, should need zero
further code changes before `/release-milestone` runs. `/release-milestone`
merges, tags, and publishes — it is not a place to discover or fix problems.
If a fix here changed the deploy inputs (a new migration, a new admin
script, a new env var), tell the user to re-run `/finish-milestone`'s Step
6.6 to refresh the deploy sheet before releasing.

### Step 6: Output summary

- The PR number and URL
- List of merged issue PRs included
- Known-broken test exclusions status (none found, or resolved, or explicitly accepted with issue references)
- CI milestone review status (from Step 4): "posted normally" / "no run was
  triggered — re-triggered via deep-review label, now posted" / "ran but
  posted nothing — self-referential workflow-file change, requires merge to
  main first" / etc. — never omit this line
- Note as plain text (informational, not a runnable choice): "To re-run the
  deep review later, label the PR `deep-review` or comment `@claude
  deep-review`", "Deploy sheet is at `docs/plans/releases/$ARGUMENTS-deploy.md`
  — `/release-milestone` reuses this file rather than generating its own"
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
- Do not push to any remote — this command only creates the PR on GitHub
- The deploy sheet lives at `docs/plans/releases/<version>-deploy.md` —
  gitignored, never committed or printed in full to the conversation (it
  names ssh hosts and docroots)
- This command does not render release notes, the deploy sheet, or update
  wiki/CLAUDE.md — those are `/finish-milestone`'s responsibility. If Step 1
  finds any of that incomplete, send the user back there rather than doing
  it here.
