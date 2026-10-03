---
description: Open the milestone PR, verify CI review posted, and confirm CI is fully green
model: sonnet
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
4. Verify CI milestone review posted with zero unresolved findings; recover
   and fix as needed; confirm CI is fully green
5. Output summary

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
  scripts/check-wip-markers.sh $ARGUMENTS
  ```

  Exit 1 means one or more markers remain (see stdout). Stop. Tell the user
  `/finish-milestone` Step 6 hasn't actually finished — re-run
  `/finish-milestone $ARGUMENTS` before continuing. Exit 2 means the
  release notes file is missing entirely — same stop, same instruction.

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
scripts/check-known-broken-tests.sh
```

**If exit 0 (none found)**, proceed to Step 3.

**If exit 1 or 2 (one or more found — see stdout for file, line, cited
issue, and that issue's current state)**, this is either a tag
`/finish-milestone` Step 3.5 already surfaced and the user accepted, or one
that appeared since. Don't assume which — re-run the same decision live:
present the list and ask the user to (a) resolve first, (b) proceed with
this explicitly accepted (record it for Step 3's PR body), or (c) stop
here. Do not proceed without an explicit answer. On exit 2, a row reading
`(lookup-failed)` means the issue's state couldn't be confirmed — resolve
that before treating the row as accepted or not.

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

### Step 4: Verify CI milestone review posted, with zero unresolved findings

`claude-code-review.yml` is *expected* to run the same milestone-level
analysis `/finish-milestone` Step 9.8 already ran locally, against `main`,
and post it as a PR comment — but a job `conclusion: success` is never
proof that happened (webhook throttle, the action's own workflow-file-match
guard, or turn exhaustion can each complete a job while posting nothing —
see #1724). Verify the comment itself, and its content, in one call:

```bash
scripts/verify-ci-review.sh <pr-number> 30 300 --trigger=label \
  --include-important --check-skip-tag
```

(30s/5min: Fable milestone reviews run longer than per-push Sonnet reviews.)

- **Exit 0** — comment confirmed, zero unresolved Blocking/Important
  findings. Note "posted normally" in the Step 5 summary and proceed.
- **Exit 1** — could not verify (`gh` auth/network/rate-limit). Report the
  error and resolve it before re-running; do not treat as "no review."
- **Exit 2** — comment confirmed, but an unresolved Blocking or Important
  finding remains (see stdout for the heading(s)). This is the case that
  burned v2.29.4: a review posted, 3 Important findings went unfixed, and
  they surfaced later mid-`/release-milestone`, forcing a second review
  round there — strictly worse than fixing them here. Fix each finding as a
  commit on the milestone branch, push (re-triggers `pr-to-milestone-review`
  or needs a fresh `deep-review` label), and re-run this script until it
  exits 0. If a finding needs user judgment or access only they have (e.g. a
  prod-host check), use AskUserQuestion — "defer to a tracked follow-up" is
  acceptable, but must be an explicit recorded choice, never a silent skip.
- **Exit 3** — PR title carries `[skip-review]`; no comment is the correct,
  by-design outcome. Report "review intentionally skipped per title tag"
  and proceed to Step 5.
- **Exit 4** — no comment even after the script's one recovery attempt (or
  recovery couldn't apply — see its stderr). Report to the user; do not
  proceed to Step 5 without an explicit reason recovery doesn't apply here.

Also confirm all CI checks are green at this point, not just the review:
`gh pr checks <pr-number>` (skipped-by-design checks are fine; an actual
failure or pending required check is not).

**The bar for calling this command complete:** the milestone branch, as it
sits on `main`'s target commit right now, needs zero further code changes
before `/release-milestone` runs — that command merges, tags, and publishes;
it is not a place to discover or fix problems. If a fix here changed deploy
inputs (new migration, admin script, env var), tell the user to re-run
`/finish-milestone` Step 6.6 to refresh the deploy sheet first.

### Step 5: Output summary

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
