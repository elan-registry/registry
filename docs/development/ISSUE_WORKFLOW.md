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
| Capture | `/new-issue`, `/found` (finds made while an issue is in progress) |
| Plan | `/plan-milestone`, `/groom-backlog` (on demand), `/start-milestone` |
| Build, per issue | `/start-issue` → `/execute-plan` (runs `/simplify` itself, Step 6.8) → `/commit` → `/review-pr` → `/commit-push-pr` → `/address-pr-comments` → `/finish-issue` |
| Build, hotfix | The same, starting with `/start-issue <N> --hotfix`. Then the patch release in `DEPLOYMENT.md`. `/new-issue` or `/found` names the entry point. |
| Ship | `/sprint-status` (any time), `/finish-milestone` → `/review-milestone` → `/release-milestone` |

The full command sequence, with hand-offs, is in `CLAUDE.md`, "Developer
Workflow".

## Where am I? What do I run next?

Every command stores its state in GitHub, in the repo, or in the plan file.
So you can stop at any point, run `/clear`, and continue. Type the command
yourself. A command starts the next one itself only when both commands
declare the same `model:` (`CLAUDE.md`, "Hand-offs between commands").

| State | Run next | `/clear` first? |
| --- | --- | --- |
| New release to plan | `/plan-milestone <version>` | Yes |
| Milestone sealed, no branch | `/start-milestone <version>` | Yes |
| Milestone branch exists, issue is `status:ready` | `/start-issue <N>` | Yes, at an issue boundary |
| A plan stopped before approval (plan `Draft`) | `/start-issue <N>`. It resumes at the approval step. | No, unless the session holds another issue |
| `/execute-plan` stopped on a deviation (plan back to `Draft`) | `/start-issue <N>`, then `/execute-plan <N>` | No |
| Plan `Approved` | `/execute-plan <N>` | Optional. The plan file holds the state. |
| `/execute-plan` stopped part way | `/execute-plan <N>`. It re-checks the checklist against the repo. | No |
| Plan `Implemented`, work uncommitted | `/commit` | Optional |
| Work committed, not reviewed | `/review-pr` | Optional. The plan file holds the state. |
| Review clean | `/commit-push-pr` | Yes |
| `/commit-push-pr` stopped | `/commit-push-pr`. The script is safe to run again. It reuses an open PR. | No |
| PR open, CI and the automated review running | `/address-pr-comments` after they post | Yes |
| `/address-pr-comments` stopped (CI still red, or the checks were still running) | Fix the cause, then `/address-pr-comments` | No |
| PR clean | `/finish-issue <N>` (the issue number, not the PR number) | Yes |
| `/finish-issue` stopped (CI red, risk flag, a failed step) | Fix the cause, then `/finish-issue <N>`. If the PR already merged, it continues at the close step. | No |
| Hotfix merged | The patch release in `DEPLOYMENT.md`. Then `/start-issue <paused-N>` if `/finish-issue` named a paused issue. Otherwise `/start-issue <next>`. | Yes |
| Issue merged, open issues remain | `/start-issue <next>` | Yes |
| Issue merged, no open issues remain | `/finish-milestone <version>` | Yes |
| `/finish-milestone` stopped, or the branch changed | `/finish-milestone <version>`. It skips each step whose result still matches the branch tip. | No |
| `/finish-milestone` done | `/review-milestone <version>` | Yes |
| `/review-milestone` says the review marker is old | `/finish-milestone <version>` | No |
| `/review-milestone` stopped after the PR opened | `/review-milestone <version>`. It reuses the open PR. | No |
| Milestone PR green | `/release-milestone` | No. `/review-milestone` can start it. |
| `/release-milestone` stopped before the merge | Fix the cause, then `/release-milestone <version>`. The script skips the steps that are done. | No |
| `/release-milestone` stopped after the merge | `/release-milestone <version>`. It finds the merged PR and resumes at the tag step. | No |
| Release created | Deploy from the deploy sheet. Then `/plan-milestone` for the next release. | Yes |
| Production broken, no issue in progress | `/new-issue` (`signal:defect`). Its last step names `/start-issue <N> --hotfix`. | Yes |
| Production broken, an issue in progress | Commit the paused work on its branch. Then `/found`, then `/start-issue <N> --hotfix`. | Yes |

---

## 1. Capture — creating issues

**Rule: never suppress a capture.** The filter is at planning. Friction at
capture loses real signals and gains nothing.

`/new-issue` is capture only. It searches for a duplicate, records the issue,
and stops. It does not ask a product interview, launch expert agents, write
acceptance criteria, estimate size, or set a milestone. Each issue gets one
**signal** label:

| Label | Meaning | Weight at planning |
| --- | --- | --- |
| `signal:owner` | A named owner asked, by email, contact form, or club conversation | Strongest. A real person is waiting. |
| `signal:analytics` | Logs or usage data show a gap: failed searches, error rates, abandoned flows | Strong. Evidence without opinion. |
| `signal:operator` | Your own friction as admin, editor, or owner of the site | Weakest. Needs a second reason to exist beyond "it annoyed me once." |
| `signal:defect` | Something is broken against its own stated behavior | Bypasses the theme test if a user can see it (see Step 2 in section 2). |
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

`/new-issue` records four things, and nothing else:

- **A title** with a type prefix. A defect gets `bug:`, because it has no
  acceptance criteria yet.
- **One signal label.** The issue also gets `triage`, and `bug` or
  `enhancement`.
- **The one-line beneficiary.** For example, "Owners with no photos on their
  car cannot…". If you cannot finish the sentence about somebody other than
  yourself, write the issue anyway. Expect it to fail the gate.
- **A verbatim quote or the evidence, if there is one.** Paste the owner's
  actual words, or the measurement or error. A paraphrase reads more urgent
  than the original.

Acceptance criteria, technical approach, and estimates are planning work.
`/plan-milestone` Step 4 writes the acceptance criteria, and only for the
issues it selects. A captured issue has no milestone. It cannot start through
`/start-issue` until a planning session seals it into one. `/new-issue` ends
with plain text that says the next `/plan-milestone` weighs the issue. It does
not offer `/start-issue`. The one exception is a production break (see
"Interrupts and the hotfix track").

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

A **candidate** is an open issue that has no milestone, or is in the
`Backlog` milestone. The `Backlog` milestone is not a release commitment. An
issue in any other milestone belongs to that release and is never a
candidate. If no open milestone has the version you give,
`/plan-milestone` offers to create it. You give the full title. It must start
with the version.

`/plan-milestone` also reads the newest release retrospective
(`docs/plans/releases/<version>-retro.md`, see "4. Ship") and shows its three
answers before you choose the theme. The first answer, the work that nobody
needed, raises the bar at Step 2: a candidate of the same kind must show a
stronger signal to stay.

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

A `signal:defect` candidate that a user can see skips these questions and the
edge-case test in Step 3. A user is an owner, a visitor, or an admin or editor
in the site UI. The defect does not have to serve the theme. It counts toward
the 3–6 theme issues in Step 4. A defect that no user can see goes through
the gate like any other candidate.

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

- **3–6 theme issues.** Every issue that serves the theme sentence. A
  user-visible `signal:defect` issue counts here, theme or not.
- **At most 1 housekeeping issue.** This is `signal:forced` work that fits no
  theme but must happen.
- **Every open `gate-critical` issue.** These are uncapped and do not count
  against the housekeeping slot (see "Gate-critical issues bypass the theme
  test").
- **Nothing else.** No free slots. No "while we are in there."

Then `/plan-milestone` does this:

1. Writes the theme sentence into the milestone description. It does not
   write an issue list. The description is the acceptance criterion for the
   release.
2. For each selected issue, writes the acceptance criteria into the issue body
   as an `## Acceptance criteria` section of `- [ ]` lines. This is the first
   time the effort is worth it. An issue without this section does not get
   `status:ready`.
3. Gives the issue a scoped title. A captured `bug:` prefix means "not yet
   scoped". The command changes it to the type of work that ships (`fix:`,
   `feat:`, and so on).
4. Assigns the milestone, adds `status:ready`, and removes `triage`.
5. For an issue that the edge-case test scoped down to the guard, posts a
   comment that states the reduced scope. The criteria cover the guard only.
6. For issues that touch the same code and must land as one PR, posts
   `Combine into one PR with #A, #B (planned in <version>).` on each issue of
   the group. You approve the groups first.
7. Closes a cut candidate that failed all three questions. Any other cut
   candidate stays in the backlog with one comment that gives the reason.

`/start-milestone` then creates the milestone branch and the draft release
notes. If `/start-milestone` runs on a milestone that `/plan-milestone` did
not seal, its Step 4.4 and Step 4.5 apply a lighter version of the same
gate. It treats a milestone as sealed when the description is a theme
sentence and every open issue has `status:ready`. The lighter gate also
scopes each issue that stays. It writes the acceptance criteria, gives the
title its scoped type, adds `status:ready`, and removes `triage`. `/start-issue`
needs these marks.

### Issue order and blockers

No file stores the approved issue order. `/start-milestone` Step 5 recommends
an order for that session, and you approve it. It then writes down only the
facts that later sessions need, on GitHub:

- **A combine group.** The comment `Combine into one PR with #A, #B`.
  `/start-issue` reads it and asks whether to start the combined scope.
- **An order conflict.** The `status:blocked` label and a comment
  `Blocked by #X (planned in <version>).` on the issue that must wait.

`/finish-issue` Step 9 picks the next issue: the lowest-numbered open issue in
the milestone without `status:blocked`. It reminds you to remove
`status:blocked` from an issue whose blocker closed. It never removes the label
itself, because the issue can have other blockers.

---

## 3. Build — developing and testing an issue

### The plan gate (the only human checkpoint)

For each issue, `/start-issue <N>` researches the issue and writes a plan file
in `docs/plans/issues/`. You approve the plan or send it back. You do not
review the diff by default.

Before it plans, `/start-issue` checks that the issue is ready. The issue
needs three marks: a milestone, the `status:ready` label, and an
`## Acceptance criteria` section in the body. If the milestone or the label is
missing, the command stops and names `/plan-milestone`. If only the section is
missing, you choose: stop and scope the issue with `/plan-milestone`, or
write the criteria at the plan gate. A hotfix skips this check.

The plan names the problem, the approach, the files, the tests, the database
and security considerations, and the checklist of actions. Its section list is
in `/start-issue` Step 9. Four rules apply to every plan. The command
enforces each one:

- **The plan has acceptance criteria.** The plan has an
  `## Acceptance criteria` section of `- [ ]` lines. The command copies them
  word for word from the issue. If the issue has none, the command writes
  them at the plan gate and posts them to the issue body when you approve.
  The command does not mark a plan approved while the section is empty.
- **The plan names what it chooses not to build.** The plan has a
  `## Not doing` section. The edge cases and nearby temptations from the
  edge-case test are written down and refused. The section needs at least one
  line. The command does not mark a plan approved while the section is empty.
- **The plan carries a risk flag.** The header has `**Risk flag:** yes` or
  `**Risk flag:** no`. The flag is `yes` when the change touches auth,
  sessions or permissions, a database migration, an API endpoint contract, or
  payments.
- **A `yes` flag gets a human diff review before merge.** `/commit-push-pr`
  copies the flag into the PR body. `/finish-issue` reads it there, before the
  merge question. When the flag is `yes`, `unknown`, or missing, it asks you to
  confirm that you reviewed the diff (`I reviewed the diff` or `Stop`). This is
  the one exception to "you do not review the diff."

If the issue is in a combine group, `/start-issue` asks whether to plan the
combined scope. One branch, one plan and one PR then cover all the issues.
The plan header records the choice.

After you approve, `/execute-plan` runs the plan to work that is ready to
commit. In order, it:

1. Re-checks the plan checklist against the repo.
2. Implements the checklist, in parallel where the plan allows.
3. Writes and runs the tests. Each new test must fail with the change
   reverted. The plan file records the result.
4. Checks PHPStan baseline hygiene, then the cleanup ledger (Step 6.6).
5. Updates the draft release notes (not for a hotfix).
6. Runs the `/simplify` pass (Step 6.8). Step 7 then reviews the simplified
   code, so the review fingerprint stays valid.
7. Runs the single review round (Step 7) and records the review fingerprint.
8. Sets the plan status to `Implemented — pending commit/PR`.

It never commits, pushes, or opens a PR. It ends with plain text: type
`/commit`, then `/review-pr`. It does not start either command. `/review-pr`
refuses uncommitted changes, so `/commit` comes first.

**Deviation rule.** If the work needs anything outside the approved plan (a
file the plan does not list, a different approach, a dropped requirement, a new
dependency), `/execute-plan` stops. It adds a `## Deviation` section to the
plan, posts a comment on the issue that states the deviation and asks for
re-approval, and sets the plan status back to `Draft — pending approval`. It
leaves the work done so far uncommitted. You type `/start-issue <N>`. That
command finds the plan and the branch, shows the deviation first, and resumes
at the approval step. Then you type `/execute-plan <N>`. Nothing absorbs the
change silently.

**The PR body shows the difference.** `/commit-push-pr` writes
`## Delta from plan` in the PR body: files that changed but the plan did not
list, checklist items marked N/A with their reason, or `none`. An N/A item
keeps its `[ ]` and ends with a fixed marker. `/review-pr` and
`/commit-push-pr` find the item by this form:

```text
- [ ] <item> — N/A: <reason>
```

### Discoveries mid-issue

Run `/found <description>` when you find a pre-existing problem during an
issue. It classifies the find in two steps. First, containment: is the fix in
a file this PR already edits? Then, for a find that the current issue does not
need, defect or cleanup. `/start-issue` Step 5.5 applies the same matrix to
what its exploration finds.

| Containment | Class | Action |
| --- | --- | --- |
| In scope | Needed for the acceptance criteria | Fix it in this PR. Note it under **Found in passing** in the plan. |
| In scope | Not needed, defect | New issue, `signal:discovered`. |
| In scope | Not needed, cleanup | Cleanup ledger. |
| Out of scope | Production broken, data at risk, or security exposure | Hotfix track. |
| Out of scope | Not an emergency, needed for the acceptance criteria | Deviation. `/execute-plan` stops and re-gates the plan. |
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

A cleanup find does not get its own issue. The **cleanup ledger** is the
committed file `docs/development/CLEANUP_LEDGER.md`. It has one heading per
file, with one `- [ ]` line for each open item under the heading. A cleanup
item costs least when a change already has the file open. The file's "Rules"
section is the reference for the format.

- **Add an item** by editing the file. Put the line under the file's heading.
  Add the heading in path order if the file has none. Do not add an item whose
  text is already there. The edit goes in the PR of the branch you are on.
- **Fix an item** by deleting its line in the same PR that fixes it. Delete
  the heading too when it has no items left. The file has no `- [x]` lines.
  Git history is the record.
- **Find the items for a change** with `scripts/ledger-items-for-files.sh`.
  It reads one path per line on stdin and prints `path: item text`.
- **Move or delete a file** only with its ledger group. `composer check:docs`
  fails when a heading names a path that does not exist.
- A cleanup find with no named benefit is dropped, not recorded. `/found`
  writes a one-line `Considered, dropped: <reason>` record under **Found in
  passing** in the plan (and in the PR body if a PR is open). A later review
  then does not raise the find again.

Each command touches the ledger at one step:

| Command | What it does with the ledger |
| --- | --- |
| `/found` | Adds a cleanup find to the file with an edit. |
| `/start-issue` Step 9 ("Pull ledger items") | Copies the items for each file the plan edits into the plan's **Ledger items** section and into the checklist. The plan gate approves them with the rest of the plan. |
| `/execute-plan` Step 5, 6.6, 8 | Deletes the line of each item it fixes, and ticks it in the plan. Step 6.6 checks the files it edited for items that the plan does not list. For each one it offers: fix now, add to plan and fix now, or leave. Step 8 checks that no ticked item is still in the file. |
| `/review-pr` Steps 5 and 6 | Reports each open item for a changed file as a Recommendation. **Fix now** deletes the line. **Defer** on a ledger item changes nothing and records `Deferred: ledger`. Any other deferred Recommendation goes to a new issue if it is a defect, or to the ledger (an edit) if it is cleanup. |
| `/commit-push-pr` | Information only. It lists the open items for the touched files in its final summary. It asks nothing and writes nothing to the PR body. |
| `/address-pr-comments` Steps 6 and 7 | Deletes the line of an item that one of its fixes completes. A deferred Advisory item goes to a new issue if it is a defect, or to the ledger if it is cleanup. It commits the file edit with the fixes. |
| `/finish-issue` Step 6.5 | Information only. It reports the open items that remain in the files the PR edited. |

A hotfix plan takes no ledger items. `/start-issue`, `/execute-plan`
Step 6.6, and `/review-pr` Step 5 each skip the ledger for a hotfix. A hotfix
changes only what the emergency needs.

See "Cleanup ledger" in `scripts/README.md` for the reader script.

`.claude/settings.json` denies the plugin skill
`commit-commands:commit-push-pr`. This makes `/commit-push-pr` always the
project command. The plugin skill cannot replace it for issue PRs, because it
does not write the PR body sections that `/finish-issue` reads (risk flag,
delta from plan, review decisions).

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
- The PR body has a `## Delta from plan` section.
- When the plan's risk flag is `yes`, you reviewed the diff.

---

## 3b. Review and CI

Review runs once, on a finished artifact, before the push. The reasoning and
the evidence are in ADR-020. Every review pass stays in the workflow. The
review fingerprint makes sure that no pass runs twice on the same code.

### Rule 1 — One round, all reviewers at once, before the push

`/execute-plan` runs the `/simplify` pass first (Step 6.8). Then its Step 7
launches every applicable reviewer in parallel, against the same commit,
before the push. These are the code review, test analysis, silent-failure,
comment, type-design, and UX reviewers, and the architecture and security
reviewers. Collect every finding into one list. Triage it once. Fix it in one
commit. Then push. Reviewers receive the plan's claims as claims to verify,
not as facts.

When the round is clean, Step 7 records a fingerprint of the branch in the
plan file (`scripts/review-fingerprint.sh`). The line names the lanes that
reported no Blocking finding: `code-reviewer`, `silent-failure-hunter`,
`comment-analyzer`, and `pr-test-analyzer`.

`/review-pr` runs the same agents on the full branch diff. It refuses to run
with uncommitted changes, so run `/commit` first. It skips each lane that the
plan's fingerprint line names, when the branch still has the same
fingerprint. The `spec` lane, the `simplify` aspect, and the verification
suite always run. When the review is clean and no fix changed a file, it
writes a new fingerprint.

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
| **Advisory** | Real, but not this PR's job | New issue (defect, `signal:discovered`) or cleanup ledger (no change in behavior). |
| **Note** | Wording, style, docs nuance | Fix only if the change already touches that line. |

Record each declined finding in a `## Review decisions` section. Each line
has one of three forms:

```text
- `<file:line>` — <issue> — False positive: <reason>
- `<file:line>` — <suggestion> — Skipped: <reason>
- `<file:line>` — <suggestion> — Deferred: ledger | issue #<n>
```

- `/review-pr` Step 6 asks about one Recommendation at a time. It writes a line
  for each deferred or skipped one, and for each Blocking item that you decide
  is a false positive. The line goes under `## Review decisions` in the plan.
  When a PR already exists, `/review-pr` also writes the line to the PR body.
  `/commit-push-pr` copies the plan section into a new PR body.
- `/address-pr-comments` writes the same lines to the PR body, for each
  Blocking false positive, and each Advisory item that you defer or skip.

### Rule 4 — The local gate must be the CI gate

A change that passes locally and fails in CI means the two gates differ. Treat
the difference as a defect in the gates. Close the gap. Do not retry CI.

The known differences, in `.github/workflows/tests.yml` and `.githooks/`:
CI runs `composer test:quick:ci`, which excludes the `known-broken`,
`requires-upstream-install`, and `regression` groups. The integration suite
and the Playwright suite do not run in CI. CodeQL and Semgrep have no local
equivalent. `/review-pr` Step 1 and `/finish-milestone` Step 3.7 run the
integration suite locally (`scripts/run-verification-suite.sh`).

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
2. `/finish-milestone` gates the branch. In order, it:
   - lists the issues still open in the milestone. For each, you choose to
     finish it first or leave it out. If you finish any issue first, the
     command stops before it removes anything. Run the per-issue commands, then
     `/finish-milestone` again. It asks about each issue that is still open.
     A left-out issue loses its milestone and its `WIP:` release-notes
     entry.
   - checks for tests still tagged `known-broken`, and for leftover plan
     files.
   - runs the verification suite on the merged tree
     (`scripts/run-verification-suite.sh`).
   - checks the milestone scope against the release notes
     (`scripts/check-milestone-scope-drift.sh`).
   - finalizes the release notes, and asks the retrospective questions
     (Step 6.5).
   - renders the deploy sheet.
   - updates the wiki and `CLAUDE.md` when the change needs it.
   - runs the cross-PR security check, the local multi-agent review, and the
     milestone-level deep review. Step 9.9 adds a fresh-checkout smoke test
     when build or install tooling changed.
   - pushes `milestone/<version>` to `origin`, and writes the completion
     marker `docs/plans/releases/<version>-review.done`. The marker records
     the pushed commit.
3. `/review-milestone` re-checks the branch. It stops when the release notes
   have a `WIP:` entry, when the deploy sheet is missing, when the marker is
   missing, or when the marker's commit is not the branch tip. In each case you
   type `/finish-milestone <version>`. When the checks pass, it opens the PR
   to `main`, or reuses the PR that is already open. It confirms that the CI
   review posted and CI is green. It fixes a Blocking or Important finding on
   the milestone branch, because `/release-milestone` fixes nothing. After each
   fix that passes `scripts/run-verification-suite.sh` and is pushed, its
   Step 4 moves the marker's `sha:` line to the pushed commit. A commit that
   did not go through this loop needs `/finish-milestone`.
4. `/release-milestone` checks again that no Blocking or Important finding is
   open, that the milestone scope matches the release notes, and that the
   version is newer than the last tag. It also checks that the deploy sheet
   is fresh. After you confirm, `scripts/release-milestone.sh` merges the PR,
   tags the merge commit, and creates a draft GitHub release. The release
   stays a draft until you deploy.

**Run `/finish-milestone` again** after it stops, or after the branch
changes. It starts at the top and skips a step when that step's result
exists for the current branch tip. A new commit makes the review steps run
again. The retrospective, and the deploy sheet while it is fresh, are not
redone.

**Run `/release-milestone` again** after the script stops. Fix the cause
first. The script skips the work that is done: the merge, the tag, the release.
Run `/review-milestone` again after it stops. It reuses the open PR.

If the script stopped after the merge, the milestone branch and its release
notes are gone. `/release-milestone <version>` then finds the merged PR. It
skips its checks, asks you to confirm, and the script continues with the tag,
the draft release, and the milestone close.

The retrospective has three questions. One line each is enough. The answers go
to `docs/plans/releases/<version>-retro.md`. `/plan-milestone` reads the
newest file at its Step 1, and `/start-milestone` reads it too when it has to
run the gate itself.

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

A hotfix has two entry points. Which one you use depends on whether an issue is
in progress.

**No issue in progress.** Run `/new-issue` with a `signal:defect` issue. It
adds the labels `bug` and `triage`. Its last step then names
`/start-issue <N> --hotfix`. Do not use `/found` here. `/found` is for finds
made while an issue is in progress.

**An issue in progress.** Run `/found`. It creates the hotfix issue (labels
`bug`, `triage`, `signal:defect`) and writes `Paused for hotfix: #<N>` in the
hotfix issue body. The number in that line names the paused issue. Then save
the paused work:

- **Commit it on the issue branch.** Prefer this. `/start-issue` and
  `/execute-plan` resume from a committed branch. `/start-issue` stops while
  uncommitted changes exist.
- **Or stash it.** Before the paused issue resumes, check out its branch and
  run `git stash pop` on that branch.

Run `/clear` after you save the work. The hotfix is a new issue.

The hotfix sequence, for both entry points:

1. `/start-issue <N> --hotfix` branches from `origin/main`. The plan gate
   applies. The plan records `none (hotfix)` as the milestone and `main` as
   the PR base. The command skips the readiness check. It writes the
   acceptance criteria in the plan, and posts them to the issue when you
   approve. It pulls no ledger items.
2. `/execute-plan` → `/commit` → `/review-pr` → `/commit-push-pr` →
   `/address-pr-comments`. `/commit-push-pr` reads the PR base line in the
   plan and opens the PR against `main`.
3. `/finish-issue <N>` sees the `main` base and runs its hotfix mode. It
   merges into `main`. It skips the release-notes update. If the PR base is
   wrong, it asks you to retarget the PR or proceed as a hotfix.
4. Do the patch release in `DEPLOYMENT.md`,
   [Patch Release from main](DEPLOYMENT.md#patch-release-from-main). It ends
   with a merge of `main` into the open milestone branch.
5. Run `/clear`. Then type the command that `/finish-issue` names. If the
   hotfix issue has a `Paused for hotfix:` line, that command is
   `/start-issue <paused-N>`. It resumes the paused issue at its approval step,
   or tells you to type `/execute-plan`. Otherwise the command is
   `/start-issue <next>`.

The milestone work resumes after the patch release.

---

## Backlog hygiene

Free capture makes the backlog grow faster than it drains. In a large backlog,
old ideas start to read like commitments. These rules keep it small.

### Age-out with evidence rescue

`/groom-backlog` applies this rule at Step 3.5. No scheduled job applies it.
The command proposes. It changes nothing until you approve.

- **Warn.** An open issue **created more than 180 days ago**, with no human
  comment in that time, gets the `stale` label and a comment that starts with
  `Marked stale:`.
- **Close.** An issue that has had the `stale` label for 14 days or more,
  with no human comment since the label, closes as `stale-no-demand`. The
  comment says that silence was treated as a vote against, and that a new
  signal reopens it.
- **Remove the label.** An issue with the `stale` label that is now exempt,
  or that has a human comment since the label, loses the label.
- **Exempt:** any issue in a milestone other than `Backlog`, and any issue
  that has the label `signal:forced`, `component: security`, or
  `gate-critical`. The `Backlog` milestone is not a release commitment, so it
  does not exempt an issue.
- **Rescue:** a new signal reopens the issue with the new evidence attached.
  An owner asks, or analytics show the gap. The issue takes the new signal's
  label.

### Age from creation, not from last activity

- Age an issue from `created_at`.
- Label, milestone, and title changes are not activity. Bulk housekeeping
  resets `updated_at` on every issue that it touches.
- Only a human comment or a new signal counts as a rescue. A bot comment does
  not. The `Marked stale:` warning does not count either.

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
milestone placement, proposes the age-out actions, and acts on one approval.
It does not sweep the cleanup ledger. `composer check:docs` catches a ledger
group whose file is gone.

---

## Tracking — where sprint state lives

Sprint state is the milestone, derived state, and one manual label. There is
no project board and no sprint file. A board or a file is a second source of
truth that decays when nobody maintains it. GitHub already knows most of the
state:

| State | How it is known |
| --- | --- |
| Backlog | Open, and either no milestone or in the `Backlog` milestone |
| Ready | In the open milestone, `status:ready`, no branch |
| In progress | A branch exists for the issue |
| In review | A PR is open |
| **Blocked** | **`status:blocked` label. This is the only state you set by hand.** |
| Done | Closed |

Blocked is the only state that git cannot show. "Waiting on an owner to reply"
leaves no trace in the repo. The `Blocked by #X` comment names the blocker.
The `Combine into one PR with #A, #B` comment names a combine group. See
"Issue order and blockers" in section 2.

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
| Plan gate | Does `## Not doing` name what the plan refuses to build? Is the risk flag set? | Send the plan back. |
| The work needs more than the plan | Did the plan approve this? | Stop. `/execute-plan` re-gates the plan. |
| Mid-issue | Does the acceptance criteria need this? | New issue or ledger. Next planning. |
| Cleanup find | What gets better if it is fixed? | Nothing: drop it with a `Considered, dropped` line. |
| Fixing a ledger item | Did I delete its line in this PR? | The ledger keeps a fixed item. Delete it. |
| Testing | Can a real user reach this branch? | Do not test it. |
| Before pushing | Did every reviewer see this same commit, at once? | Serial review gives a ladder of rounds. |
| After a fix commit | Is this the third round? | The plan was wrong. Re-gate it. |
| Any finding | Verified, in this diff, and this PR's job? | Advisory or Note, not Blocking. |
| A run measures an old issue | Did this measurement produce the issue? | It corroborates. Comment. Do not relabel. |
| CI red | Does the failing CI command also fail locally? | The gates differ. Close the gap. |
| Merge, risk flag is `yes` | Did I review the diff myself? | Review it. `/finish-issue` asks. |
| Production broken | Is data at risk, or is there a security exposure? | If no: it queues. If yes: hotfix track. |
| Release | Is the theme sentence true? | Not yet: keep working. Yes: ship. |
| Retro | What did we ship that nobody needed? | Feed the answer into the gate. |
