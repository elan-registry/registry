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
milestone branch has been tested (Step 3.7 there), reviewed (Steps 9.5–9.9
there), documented (Steps 5.5–8 there), and pushed (Step 10 there).

The user can type `/review-milestone $ARGUMENTS` again after a run stops.
Step 3 reuses an open milestone PR, and Step 1 accepts the fix commits
that Step 4 pushed.

## Arguments

- `$ARGUMENTS` — the milestone version number (e.g., `v2.17.0`)

## Workflow

### Step 0: Initialize TaskList

Before any other action, create one tracking task per workflow step using
TaskCreate:

1. Locate the milestone branch and re-verify it's review-ready
2. Re-derive merged-PR list, diff, and known-broken-test status
3. Create the PR targeting main, or reuse the open one
4. Verify CI milestone review posted with zero unresolved findings; recover
   and fix as needed; confirm CI is fully green
5. Output summary

Set each task to `in_progress` when you begin it and `completed` on success.

### Step 1: Locate the milestone branch and re-verify it's review-ready

```bash
git branch -a | grep "milestone/$ARGUMENTS"
git status --porcelain
```

If the branch doesn't exist, stop and report the error. If `git status`
prints anything, stop. Show the list and ask the user to commit or stash
the changes. A checkout carries them onto the milestone branch.

```bash
git checkout milestone/$ARGUMENTS
git pull --ff-only origin milestone/$ARGUMENTS
```

If the checkout or the pull fails, stop and report the error.

**Do not trust that `/finish-milestone` actually finished** — verify against
repo state rather than assume the handoff was clean (same principle
`/execute-plan` Step 3 applies to a plan file's checkboxes):

- Release notes have no remaining `WIP:` markers:

  ```bash
  scripts/check-wip-markers.sh $ARGUMENTS
  ```

  Exit 1 means one or more markers remain (see stdout). Stop. Tell the user
  `/finish-milestone` Step 6 hasn't actually finished — type
  `/finish-milestone $ARGUMENTS` to run it again before continuing. Exit 2 means the
  release notes file is missing entirely — same stop, same instruction.

- The deploy sheet exists:

  ```bash
  ls docs/plans/releases/$ARGUMENTS-deploy.md
  ```

  If missing, stop. Tell the user: "No deploy sheet found. Render it by hand
  from `/finish-milestone` Step 6.6's instructions, or type
  `/finish-milestone $ARGUMENTS` to run the whole command again." Do not
  render it yourself here — that responsibility belongs to
  `/finish-milestone`.

- **The verification and review steps (3.7, 9.5, 9.7, 9.8, 9.9) actually
  ran** — the deploy sheet
  and clean release notes only prove Steps 6/6.6 finished; they say nothing
  about whether review happened, since those steps run later. An
  interrupted `/finish-milestone` run (crash, cancelled session, context
  limit) could otherwise leave both file checks above passing with zero
  local review having occurred. Check for the completion marker:

  ```bash
  cat docs/plans/releases/$ARGUMENTS-review.done
  ```

  If missing, or if any of the five step lines reads anything other than
  `clean`, `findings-fixed`, `ran-clean`, or `not-applicable` (e.g. it's
  absent entirely, or a line for one of the five steps is missing), stop.
  Tell the user:
  "`/finish-milestone` doesn't show as having completed its review steps —
  type `/finish-milestone $ARGUMENTS` to run it again before opening a
  PR." Do not proceed on the assumption that review "probably"
  happened — this check exists specifically for the case where it didn't.

- **The marker covers the branch as it is now.** Its `sha:` line records the
  last reviewed tip. `/finish-milestone` Step 10 writes it, and Step 4 here
  moves it to each fix commit that Step 4 verified and pushed. A commit
  after that tip had no review:

  ```bash
  grep '^sha: ' docs/plans/releases/$ARGUMENTS-review.done
  git rev-parse origin/milestone/$ARGUMENTS
  git rev-list --count origin/milestone/$ARGUMENTS..milestone/$ARGUMENTS
  ```

  The `sha:` value must equal the `origin` tip, and the count must be `0`
  (`gh pr create --head` in Step 3 does not push, so a local-only commit is
  not in the PR). If the `sha:` line is missing or differs, or the count is
  not `0`, stop. Show the commits after the marker
  (`git log --oneline <sha>..milestone/$ARGUMENTS`). Tell the user to type
  `/finish-milestone $ARGUMENTS`. It skips the review steps whose results
  still match the tip, and reviews the new commits.

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
issue, and that issue's current state)**, read
`docs/plans/releases/$ARGUMENTS-known-broken.md`, which `/finish-milestone`
Step 3.5 writes when the user accepts the tags:

- The file exists, starts with `decision: accepted`, and its rows have the
  same file and issue columns as the script's output now — the user already
  accepted these tags. Do not ask again. Use the current rows for Step 3's
  PR body.
- Otherwise a tag appeared or changed since that decision. Present the list
  and ask the user to (a) resolve first, (b) proceed with this explicitly
  accepted, or (c) stop here. Do not proceed without an explicit answer. On
  (b), write the file in the same format: a first line
  `decision: accepted`, then the script's output rows unchanged.

On exit 2, a row reading `(lookup-failed)` means the issue's state couldn't
be confirmed — resolve that before treating the row as accepted or not.

### Step 3: Create the PR targeting main, or reuse the open one

Look for an open milestone PR from an earlier run first:

```bash
gh pr list --base main --head milestone/$ARGUMENTS --state open \
  --json number,url,body
```

- **One PR** — reuse it. Record its number and URL. Build the body below
  from Step 2's data. If the "Issues Resolved", "Known Test Exclusions" or
  "Accepted Risks" section differs from the PR's body, replace the body:
  `gh pr edit <pr-number> --body-file <file> --repo elan-registry/registry`.
  Then go to Step 4.
- **More than one PR** — stop and ask the user which PR to use.
- **No PR** — create it:

```bash
gh pr create \
  --base main \
  --head milestone/$ARGUMENTS \
  --title "$ARGUMENTS — <milestone name>" \
  --body "$(cat <<'EOF'
## Summary

<1-2 sentence description of the milestone's purpose>

## Issues Resolved

<One line for each issue that a merged PR resolved>

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

<!-- Include this section ONLY if docs/plans/releases/$ARGUMENTS-accepted-risks.md
     exists (written by /finish-milestone Step 9.8). Copy its lines. -->

## Accepted Risks

- <finding> — <reason> (<follow-up issue, if any>)

## Test Plan

- [ ] All issue PRs were reviewed and merged into milestone branch
- [ ] Pre-commit hooks pass on all changed files
- [ ] Verification suite (unit + integration + docs + PHPStan) passed on the
      merged tree (`scripts/run-verification-suite.sh`, `/finish-milestone` Step 3.7)
- [ ] Browser tests pass where applicable (`npm run playwright:test`)
- [ ] Manual verification of key user flows
- [ ] Security review completed (run before this PR was created)

🤖 Generated with [Claude Code](https://claude.com/claude-code)
EOF
)"
```

**CRITICAL**: The PR body MUST include `Closes #NNN` for every issue that a
merged PR in Step 2's list resolved, and for no other issue. Individual
issue PRs target the milestone branch (not main), so their closing keywords
won't auto-close issues. Only this final PR merged into main triggers
auto-closure. An issue with no merged PR is not done. Do not write a
`Closes` line for it: the merge would close it. `/finish-milestone` Step 2
already removed such issues from the milestone.

Fill in actual data from Step 2.

### Step 4: Verify CI milestone review posted, with zero unresolved findings

`claude-code-review.yml` is *expected* to run the same milestone-level
analysis `/finish-milestone` Step 9.8 already ran locally, against `main`,
and post it as a PR comment — but a job `conclusion: success` is never
proof that happened (webhook throttle, the action's own workflow-file-match
guard, or turn exhaustion can each complete a job while posting nothing —
see #1724). Verify the comment itself, and its content, in one call:

Run it with the Bash tool `timeout: 600000`:

```bash
scripts/verify-ci-review.sh <pr-number> 30 300 --trigger=label \
  --include-important --check-skip-tag
```

(30s/5min: Fable milestone reviews run longer than per-push Sonnet reviews.)

- **Exit 0** — comment confirmed, zero unresolved Blocking/Important
  findings. Note "posted normally" in the Step 5 summary and proceed.
- **Exit 1** — could not verify (`gh` auth/network/rate-limit). Do not
  treat it as "no review." Stop and report the error. Tell the user to fix
  the cause (for example, `gh auth login`), then type
  `/review-milestone $ARGUMENTS`. Step 3 reuses the open PR.
- **Exit 2** — comment confirmed, but an unresolved Blocking or Important
  finding remains (see stdout for the heading(s)). Fix it here:
  `/release-milestone` does not fix findings. For each fix:

  1. Commit it on the milestone branch.
  2. Run `scripts/run-verification-suite.sh` (run with the Bash tool
     `timeout: 600000`). Exit 1 blocks the push. Exit 2 means the
     integration suite could not run — fix the environment and run it
     again. Do not push until it exits 0.
  3. Push it: `git push origin milestone/$ARGUMENTS`.
  4. Move the marker's `sha:` line to the pushed tip. The fix went through
     this loop (verification suite, then a new CI review), so Step 1 must
     accept it when the command runs again:

     ```bash
     f=docs/plans/releases/$ARGUMENTS-review.done
     { echo "sha: $(git rev-parse origin/milestone/$ARGUMENTS)"; grep -v '^sha: ' "$f"; } > "$f.tmp" && mv "$f.tmp" "$f"
     ```

     Only this step moves the `sha:` line. A commit that did not go through
     steps 1–3 here still needs `/finish-milestone $ARGUMENTS`.

  A push does not start a new review. Neither review job runs on a
  `synchronize` event for a `milestone/*` → `main` PR. Start one with a
  fresh `deep-review` label. Remove it first, so the add fires a new
  `labeled` event. If the PR does not have the label, skip the remove:

  ```bash
  gh pr edit <pr-number> --remove-label deep-review --repo elan-registry/registry
  gh pr edit <pr-number> --add-label deep-review --repo elan-registry/registry
  ```

  Wait for the `milestone-review` check to finish
  (`gh pr checks <pr-number> --watch`, run with the Bash tool
  `timeout: 600000`), then run this script again. Until
  the new review posts, the script still reads the old comment. Repeat
  until it exits 0. If a finding needs user judgment or access only they
  have (e.g. a prod-host check), use AskUserQuestion — "defer to a tracked
  follow-up" is acceptable, but must be an explicit recorded choice, never
  a silent skip.
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
it is not a place to discover or fix problems. If this step pushed a fix,
check the deploy sheet:

```bash
scripts/check-deploy-sheet-fresh.sh $ARGUMENTS
```

Exit 1 (a deploy input changed) or exit 2 means the deploy sheet needs a
refresh. Step 5 then does not offer `/release-milestone`.

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
- If Step 4 found the deploy sheet stale (exit 1 or 2), end with plain
  text, not a menu. Tell the user to refresh the deploy sheet by hand from
  `/finish-milestone` Step 6.6's instructions (the sheet and its stamp).
  Then tell them to run `/clear` and type `/release-milestone $ARGUMENTS`.
- Otherwise, use AskUserQuestion for the actual next step, since `/release-milestone`
  is runnable right now — it merges the PR itself (that's its Step 6), it
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
  won't auto-close on merge. Use them only for issues that a merged PR
  resolved
- The PR MUST target `main`, not any other branch
- Push only `milestone/$ARGUMENTS`, only to `origin`, and only for finding
  fixes in Step 4. After each verified fix push, Step 4 moves the marker's
  `sha:` line to the new tip. Never push `main`, `prod`, or `test`
- The deploy sheet lives at `docs/plans/releases/<version>-deploy.md` —
  gitignored, never committed or printed in full to the conversation (it
  names ssh hosts and docroots)
- This command does not render release notes, the deploy sheet, or update
  wiki/CLAUDE.md — those are `/finish-milestone`'s responsibility. If Step 1
  finds any of that incomplete, send the user back there rather than doing
  it here.
