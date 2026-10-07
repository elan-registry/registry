# Issue-Driven Workflow

How work gets from a signal to a shipped release. This document is the
how-to reference for a developer who runs the workflow: capture, plan, build,
and ship. It states each rule and names the `/command` that runs each step.

The reasons for the design, and the evidence behind it, are in
[ADR-020](adr/ADR-020-issue-driven-workflow.md). A command file in
`.claude/commands/` wins over this document for the exact steps of a command.

## Principles

Every gate in this workflow asks one question: does a real person get
something out of this work?

1. **Capture is free. Commitment is expensive.** Write down anything. Nothing
   earns a slot in a release without passing the theme gate.
2. **A theme, not a list.** Each release states one outcome for one audience.
   Off-theme work is out, whatever its merit.
3. **Silence is a vote against.** An idea that nobody asks about for six
   months ages out of the backlog.
4. **The plan is the gate, not the diff.** Approve the approach before the
   first line of code.
5. **Test the path a user takes and the failure that would be silent.**
   Test nothing else without a named reason.
6. **Ship when the theme is true, not when the list is empty.**

## The four loops

```text
CAPTURE  (continuous)   signal → issue, no filter, no ceremony
   ↓
PLAN     (per release)  theme chosen → issues gated → milestone sealed
   ↓
BUILD    (per issue)    plan approved by human → agent implements → PR green
   ↓
SHIP     (per release)  theme satisfied → release → 5-minute review
```

A **sprint** is a **milestone** is a **release**. It ships when its theme is
true. The content decides the date, not the calendar.

| Loop | Commands, in order |
| --- | --- |
| Capture | `/new-issue`, `/found` (mid-issue finds) |
| Plan | `/plan-milestone`, `/groom-backlog` (on demand), `/start-milestone` |
| Build, per issue | `/start-issue` → `/execute-plan` → `/review-pr` → `/commit-push-pr` → `/address-pr-comments` → `/finish-issue` |
| Ship | `/sprint-status` (any time), `/finish-milestone` → `/review-milestone` → `/release-milestone` |

The full command sequence, with hand-offs, is in `CLAUDE.md`, "Developer
Workflow".

---

## 1. Capture — creating issues

**Rule: never suppress a capture.** The filter is at planning. Friction at
capture loses real signals and gains nothing.

`/new-issue` creates the issue. Each issue gets one **signal** label:

| Label | Meaning | Weight at planning |
| --- | --- | --- |
| `signal:owner` | A named owner asked, by email, contact form, or club conversation | Strongest. A real person is waiting. |
| `signal:analytics` | Logs or usage data show a gap: failed searches, error rates, abandoned flows | Strong. Evidence without opinion. |
| `signal:operator` | Your own friction as admin, editor, or owner of the site | Weakest. Needs a second reason to exist beyond "it annoyed me once." |
| `signal:defect` | Something is broken against its own stated behavior | Bypasses the theme test if a user can see it (see "Interrupts and the hotfix track"). |
| `signal:forced` | Security advisory, dependency end of life, platform change | Bypasses the theme test. Gets the housekeeping slot. |
| `signal:discovered` | Found by you or an agent while working another issue | Neutral. Must be re-justified like any other issue. |

### The signal records origin, not every later measurement

Set the label once, at capture, from where the idea came from. A later
measurement corroborates or refutes an issue. It does not re-originate the
issue and does not change the label. Otherwise every issue that a monitoring
run re-measures drifts to `signal:analytics`, and the label stops showing
which issues evidence produced.

- Record a corroborating measurement as a comment on the issue. Use it at the
  planning gate (Step 2 and Step 3 below).
- Change a label in two cases only:
  1. **A rescue.** A genuinely new signal reopens an issue. The issue takes
     that signal's label.
  2. **A mis-classification**, corrected on evidence and not on impression.
- Never infer `signal:owner` or `signal:analytics`. Apply them only with a
  real message or a real measurement to point at. For `signal:analytics`, the
  measurement must be what produced the issue.
- `/plan-milestone` flags a label that looks wrong. It does not relabel.

### What an issue records at capture

Two things belong in the issue at capture, and nothing else:

- **The one-line beneficiary.** For example, "Owners with no photos on their
  car cannot…". If you cannot finish the sentence about somebody other than
  yourself, write the issue anyway. Expect it to fail the gate.
- **A verbatim quote, if there is one.** Paste the owner's actual words. A
  paraphrase reads more urgent than the original.

Acceptance criteria, technical approach, and estimates are planning work.
Do not write them for issues that may never be selected.

### The occasional contributor

A second contributor works a separate lane: issues labelled `help-wanted`
only, no plan gate, and you review the PR before merge. They never pick from
the open milestone. This avoids collisions with work that agents are already
running.

---

## 2. Plan — choosing the release

`/plan-milestone <version>` runs one session at the start of each milestone.
This is the only real ceremony in the workflow.

### Step 1 — Read the signals, then pick the theme

Scan what arrived since the last planning: owner contacts, analytics
anomalies, error logs, and new backlog issues. Discover the theme in the
signals. Do not invent it.

A theme is one sentence with an audience and an outcome:

> ✅ "An owner can manage their own car photos without emailing an admin."
> ✅ "A visitor arriving from a search engine lands on something that makes
> sense."
> ❌ "Photo improvements." — no audience, no outcome, no finish line.

A sentence you cannot write this way is a category. Categories never end, so
releases that use them drift.

### Step 2 — Gate every candidate against three questions

Each candidate issue must survive all three:

1. **Who noticed?** Name the signal. If the answer is "nobody, I thought of
   it", the issue is out. It goes back to the backlog to wait for a real
   signal.
2. **What do they do today instead?** If the workaround is acceptable, the
   issue is not a release item.
3. **What breaks if this never ships?** If nothing breaks, close the issue
   and say so in it. Closing an idea is a normal outcome.

### Step 3 — Apply the edge-case test

Apply this to every candidate, and again to every branch inside its plan:

> How many real owners take this path in a year? If we do not handle it, does
> it fail gracefully or badly?

| Users | Failure | Action |
| --- | --- | --- |
| Many | Badly | Build it. |
| Few | Badly | Build the guard, not the feature. A clear error beats a code path maintained for three people. |
| Few | Gracefully | Do not build it. Record the decision in the plan so it stays decided. |

### Step 4 — Seal the milestone

A milestone contains:

- **3–6 theme issues.** Every issue that serves the theme sentence.
- **At most 1 housekeeping issue.** This is `signal:forced` work that fits no
  theme but must happen.
- **Every open `gate-critical` issue.** These are uncapped and do not count
  against the housekeeping slot (see "Gate-critical issues bypass the theme
  test").
- **Nothing else.** No free slots. No "while we are in there."

Then:

1. Write the theme sentence into the milestone description. Do not write an
   issue list. The description is the acceptance criterion for the release.
2. Write the acceptance criteria for each selected issue now. This is the
   first time the effort is worth it. Move each issue to `status:ready`.
3. Leave unselected issues in the backlog, untouched.

`/start-milestone` then creates the milestone branch and the draft release
notes. If `/start-milestone` runs on a milestone that `/plan-milestone` did
not seal, its Step 4.4 and Step 4.5 apply a lighter version of the same gate.

---

## 3. Build — developing and testing an issue

### The plan gate (the only human checkpoint)

For each issue, `/start-issue <N>` researches the issue and writes a plan file
in `docs/plans/issues/`. You approve the plan or send it back. You do not
review the diff by default.

The plan names the problem, the approach, the files, the tests, the database
and security considerations, and the checklist of actions. Its section
list is in `/start-issue` Step 9. Two rules apply to every plan:

- **The plan names what it chooses not to build.** The edge cases and nearby
  temptations from the edge-case test are written down and refused. If a plan
  names nothing it refused, send it back.
- **A plan that touches auth, a data migration, a public API, or payments
  gets a human diff review before merge.** This is the one exception to "you
  do not review the diff."

After you approve, `/execute-plan` runs the plan to a PR that is ready to
commit: it implements, tests, runs the single review round, and stops. It
never commits, pushes, or opens a PR.

**Deviation rule.** If the work needs anything outside the approved plan (a
new file, a new dependency, a behavior change, an extra branch), stop. Post a
one-line re-gate request on the issue. Never absorb the change silently.

### Discoveries mid-issue

Run `/found <description>` when you find a pre-existing problem during an
issue. It classifies the find in two steps. First, containment: is the fix in
a file this PR already edits? Then, for a find that the current issue does not
need, defect or cleanup.

| Containment | Class | Action |
| --- | --- | --- |
| In scope | Needed for the acceptance criteria | Fix it in this PR. Note it under **Found in passing** in the plan. |
| In scope | Not needed, defect | New issue, `signal:discovered`. |
| In scope | Not needed, cleanup | Cleanup ledger. |
| Out of scope | Production broken, data at risk, or security exposure | Hotfix track. |
| Out of scope | Anything else, defect | New issue, `triage` label, no milestone. |
| Out of scope | Anything else, cleanup | Cleanup ledger. |

Two rules sit behind the table:

- **Fix size is not a test.** A defect is fixed inline only if the
  acceptance criteria cannot be met without it. "It is only 30 minutes" is how
  a diff outgrows its plan.
- **A defect** gives a user or an operator a wrong result: a wrong value, lost
  data, a failed request, a missing email, a misleading message. **Cleanup**
  changes no behavior: dead code, duplication, naming, comments, type
  annotations.

#### The cleanup ledger

A cleanup find does not get its own issue. The **cleanup ledger** is the one
open issue with the `cleanup-ledger` label. It has one heading per file, with
checkbox items under each heading. A cleanup item costs least when a change
already has the file open.

- `/found` adds an item with `scripts/ledger-add-item.sh`. `/review-pr`
  Step 6 uses the same script when you defer a Recommendation to the ledger.
- A cleanup find with no named benefit is dropped, not recorded. `/found`
  writes a one-line `Considered, dropped: <reason>` record under **Found in
  passing** in the plan (and in the PR body if a PR is open). A later review
  then does not raise the find again.
- `/start-issue` copies the ledger items for each file the plan edits into
  the plan's **Ledger items** section. The plan gate approves them with the
  rest of the plan.

These commands check the ledger around the PR:

| Command | What it does with the ledger |
| --- | --- |
| `/execute-plan` Step 6.6 | Checks the files it edits for items added after you approved the plan. Offers each new item: fix now, add to plan and fix, or leave. |
| `/review-pr` | Reports each open item for a changed file that the plan does not list. Each one is a Recommendation. |
| `/commit-push-pr` | Records the items that the PR completes in a `## Ledger items` section of the PR body. It adds an item without asking only when the plan's `## Ledger items` section shows it ticked (`- [x]`). For every other item, you decide. On an existing PR, it adds new items to that section. |
| `/address-pr-comments` | Adds each ledger item that one of its fixes completes to the same section. |
| `/finish-issue` Step 6.5 | After the merge, ticks the items in the PR body's `## Ledger items` section. It then reports the open items that remain in the files the PR edited. A PR body with no such section ticks nothing. |
| `/groom-backlog` | Sweeps orphaned items (headings whose files no longer exist) with `scripts/ledger-orphans.sh`. It re-files or ticks each one. |

See "Cleanup ledger" in `scripts/README.md` for the scripts.

`.claude/settings.json` denies the plugin skill
`commit-commands:commit-push-pr`. This makes `/commit-push-pr` always the
project command, with its ledger step. The plugin skill cannot replace it for
issue PRs.

### Test tier rules

A standing rule maps change type to test obligation. Nobody negotiates
coverage per issue.

| Change type | Required | Explicitly not required |
| --- | --- | --- |
| Domain logic in `usersc/classes/` | Unit test per behavior named in the acceptance criteria | Permutations of inputs that map to the same branch |
| Bug fix | One regression test in `tests/unit/regression/` that reproduces the report | Tests for adjacent untested behavior |
| New or changed API endpoint | Integration test: happy path and auth-failure path | Every validation permutation |
| New page or route | Playwright smoke test: loads, correct auth, no console errors | Full interaction coverage |
| Auth, CSRF, input handling | The **negative** case, always | — |
| DB migration | Integration test against the migrated schema, and rollback verified | — |
| UI copy, CSS, layout | None | — |
| Refactor with no behavior change | None new. The existing suite stays green. | — |

- **One test per acceptance criterion**, plus the negative case for anything
  that affects security. Any other test needs a named reason in the plan.
- **Never test a branch that a real user cannot reach.** If the edge-case test
  says "do not build it", it also says "do not test it."
- **Each new test must fail with the change reverted.** `/execute-plan`
  Step 6 requires the plan file to record this for each new test.
- Before a PR opens, run `composer check` and the suite for the tier:
  `composer test:quick` for unit-only changes, `composer test:medium` when the
  database is involved. Report the real output. Red CI is the author's
  problem to fix.

### Definition of done

An issue is done when all of these are true:

- The acceptance criteria are demonstrably met.
- Tests exist per the tier rules, and they pass.
- CI is green.
- The change updates the docs that it touches.
- The PR body states any delta from the approved plan.

---

## 3b. Review and CI

Review runs once, on a finished artifact, before the push. The reasoning and
the evidence are in ADR-020.

### Rule 1 — One round, all reviewers at once, before the push

`/execute-plan` Step 7 launches every applicable reviewer in parallel,
against the same commit, before the push. These are the code review, test
analysis, silent-failure, type-design, and UX reviewers, and the architecture
and security reviewers. Collect every finding into one list. Triage it once.
Fix it in one commit. Then push. Reviewers receive the plan's claims as
claims to verify, not as facts.

`/review-pr` runs the same agents on the full branch diff. It skips a lane
when the branch still has the review fingerprint that `/execute-plan`
recorded for that lane.

### Rule 2 — The two-round ceiling

After the fix commit, re-check only the fix: its own diff, with only the
reviewers whose findings it addressed. That is round two. A fresh agent, not
the author's session, triages round two's findings.

**A third round means the plan was wrong.** Stop patching. Return to the plan
gate and re-scope. `/execute-plan` asks you to choose: re-scope the plan,
split the rest into a follow-up issue, or keep fixing with a stated reason.

### Rule 3 — Severity contract

Every reviewer, local or CI, sorts each finding into one of three buckets:

| Bucket | Test | Action |
| --- | --- | --- |
| **Blocking** | Verified, reproducible, and in this diff | Fix now, in this PR. |
| **Advisory** | Real, but not this PR's job | New issue (`signal:discovered`) or cleanup ledger. |
| **Note** | Wording, style, docs nuance | Fix only if the change already touches that line. |

Record each declined finding:

- `/review-pr` Step 6 walks the Recommendations one at a time. For each
  deferred or skipped one, it writes a line under `## Review decisions` in the
  plan. `/commit-push-pr` copies that section into a new PR body. When a PR
  already exists, `/review-pr` also writes the line to the PR body.
- `/address-pr-comments` writes each declined Advisory item under
  `## Advisory items declined` in the PR body.

### Rule 4 — The local gate must be the CI gate

A change that passes locally and fails in CI means the two gates differ. Treat
the difference as a defect in the gates. Close the gap. Do not retry CI.

The known differences, in `.github/workflows/tests.yml` and `.githooks/`:
CI runs `composer test:quick:ci`, which excludes the `known-broken`,
`requires-upstream-install`, and `regression` groups. The integration suite
and the Playwright suite do not run in CI. CodeQL and Semgrep have no local
equivalent.

### Rule 5 — Treat CI review as a backstop

The CI review on an issue PR is a second opinion on code that local reviewers
already saw. The CI review on a milestone PR to `main` is the deep review and
it blocks. A CI `Blocking` finding follows the same test as any other: verified,
reproducible, and in this diff. See the calibration comment at the top of
`.github/workflows/claude-code-review.yml`.

### Rule 6 — The test suite is production code

- A spec that fails for a non-product reason is a defect in the suite, not a
  flake. Fix it in the same PR when it blocks the PR. File it as
  `signal:defect` when it does not.
- Assert expected collection counts, so a suite that collects nothing fails
  instead of passing.
- Suite repair bypasses the theme test (see "Gate-critical issues bypass the
  theme test").

---

## 4. Ship — closing the milestone

**Release when the theme sentence is true.** If two issues remain open but an
owner can now manage their photos without emailing you, ship. Return the open
issues to the backlog. If the list is empty but the theme sentence is still
false, the milestone is not done.

1. `/sprint-status` shows the theme, the issues by derived state, and your
   judgment of whether the theme sentence is true yet.
2. `/finish-milestone` verifies the branch, finalizes the release notes, and
   asks the retrospective questions (Step 6.5).
3. `/review-milestone` opens the PR to `main` and confirms that CI is green.
4. `/release-milestone` merges, tags, and publishes the release.

The retrospective has three questions. One line each is enough. The answers go
to `docs/plans/releases/<version>-retro.md`, and the next `/start-milestone`
reads that file.

1. What did we ship that nobody needed? Name the issue.
2. What did we learn about this theme's audience?
3. What signal arrived that we ignored, and was that right?

Question 1 is the only feedback loop that improves the gate.

---

## Interrupts and the hotfix track

Only one thing interrupts a milestone: production is broken, data is at risk,
or there is a security exposure. That work is a **hotfix**. It branches from
`main`, ships as a patch release outside the milestone, and does not join the
milestone or change its scope.

**Everything else queues.** Capture an owner's feature request, acknowledge it,
and let it wait for the next planning session. Acknowledge quickly. Do not
reorder the release.

The hotfix sequence:

1. `/found` creates the issue (labels `bug`, `triage`, `signal:defect`) and
   pauses the current task. Commit or stash your work. Run `/clear`.
2. `/start-issue <N> --hotfix` branches from `origin/main`. The plan gate
   applies. The plan records `none (hotfix)` as the milestone and `main` as
   the PR base.
3. `/execute-plan`, then `/commit-push-pr`. The PR base is `main`. If
   `/commit-push-pr` picks a milestone branch, change the base with
   `gh pr edit <pr-number> --repo elan-registry/registry --base main`.
4. `/finish-issue <N>` sees the `main` base and runs its hotfix mode. It
   merges into `main`. It skips the release-notes update and the sprint plan
   update.
5. Do the patch release in `DEPLOYMENT.md`,
   [Patch Release from main](DEPLOYMENT.md#patch-release-from-main). It ends
   with a merge of `main` into the open milestone branch.

The milestone work resumes after the patch release.

---

## Backlog hygiene

Free capture makes the backlog grow faster than it drains. In a large backlog,
old ideas start to read like commitments. These rules keep it small.

### Age-out with evidence rescue

- An issue **created more than 180 days ago** with no rescuing activity gets a
  `stale` warning label and a comment.
- **14 days later**, it closes as `stale-no-demand`. The comment says that
  silence was treated as a vote against, and that a new signal reopens it.
- **Exempt:** any issue in a milestone, `signal:forced` issues, security
  issues, and `gate-critical` issues.
- **Rescue:** a new signal reopens the issue with the new evidence attached.
  An owner asks, or analytics show the gap. The issue takes the new signal's
  label.

No scheduled job applies this rule today. Apply it by hand during a
`/groom-backlog` sweep.

### Age from creation, not from last activity

- Age an issue from `created_at`.
- Label-only and milestone-only edits are not activity. Bulk housekeeping
  resets `updated_at` on every issue that it touches.
- Only a human comment or a new signal counts as a rescue. A bot comment does
  not.

### Gate-critical issues bypass the theme test

Two kinds of work skip the theme gate: `signal:forced` work, and work that a
standing gate depends on. Mark the second kind with the `gate-critical`
label. A gate that nobody trusts makes every other rule decorative, so its
repairs are never "someday".

- Apply `gate-critical` by hand. No signal implies it.
- `gate-critical` issues are exempt from age-out, like `signal:forced`.
- The label is the list. To see the current issues, run
  `gh issue list --repo elan-registry/registry --label gate-critical --state open`.

### Run the sweep

`/groom-backlog` sweeps open issues on demand. It applies the three planning
questions from Step 2, skips the exempt issues, recommends closures and
milestone placement, and acts on one approval. It also sweeps orphaned ledger
items.

---

## Tracking — where sprint state lives

Sprint state is the milestone, derived state, and one manual label. There is
no project board. A board is a second source of truth that decays when nobody
maintains it. GitHub already knows most of the state:

| State | How it is known |
| --- | --- |
| Backlog | Open, no milestone |
| Ready | In the open milestone, `status:ready`, no branch |
| In progress | A branch exists for the issue |
| In review | A PR is open |
| **Blocked** | **`status:blocked` label. This is the only state you set by hand.** |
| Done | Closed |

Blocked is the only state that git cannot show. "Waiting on an owner to reply"
leaves no trace in the repo.

Run `/sprint-status [version]` at any time. It prints the theme, the issues by
derived state, what waits on you, and what is blocked. It adds one line that
judges whether the theme sentence is true yet. It is read-only.

---

## Quick reference

| Moment | Question to ask | A wrong answer means |
| --- | --- | --- |
| Capture | — | Never block a capture. |
| Planning: theme | Can I state an audience and an outcome? | It is a category. Pick again. |
| Planning: candidate | Who noticed? What do they do today? What breaks if it never ships? | Close it. |
| Planning: any branch | Few users and fails gracefully? | Do not build it. Record it in the plan. |
| Plan gate | Does the plan name what it refuses to build? | Send the plan back. |
| Mid-issue | Does the acceptance criteria need this? | New issue or ledger. Next planning. |
| Cleanup find | What gets better if it is fixed? | Nothing: drop it with a `Considered, dropped` line. |
| Testing | Can a real user reach this branch? | Do not test it. |
| Before pushing | Did every reviewer see this same commit, at once? | Serial review gives a ladder of rounds. |
| After a fix commit | Is this the third round? | The plan was wrong. Re-gate it. |
| Any finding | Verified, in this diff, and this PR's job? | Advisory or Note, not Blocking. |
| A run measures an old issue | Did this measurement produce the issue? | It corroborates. Comment. Do not relabel. |
| CI red | Does the failing CI command also fail locally? | The gates differ. Close the gap. |
| Production broken | Is data at risk, or is there a security exposure? | If no: it queues. If yes: hotfix track. |
| Release | Is the theme sentence true? | Not yet: keep working. Yes: ship. |
| Retro | What did we ship that nobody needed? | Feed the answer into the gate. |
