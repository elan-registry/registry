# Issue-Driven Workflow

How work gets from a signal to a shipped release. This document defines the
whole loop: capture, planning, development, testing, and sprint management.

## The problem this design solves

Execution is not the bottleneck here. Issues are already scoped to be
workable and test coverage is already good. The failure mode this workflow is
built to prevent is **spending real effort on work nobody needed** — features
built for edge cases that no owner will ever hit, and make-work issues that
exist because they were easy to write down rather than because someone wanted
them.

Every gate below exists to answer one question: *does a real person get
something out of this?* Where a rule doesn't serve that question, it isn't
here.

## Principles

1. **Capture is free, commitment is expensive.** Write down anything. Nothing
   earns a slot in a release without passing the theme gate.
2. **A theme, not a list.** Each release states one outcome for one audience.
   Coherence is the filter — off-theme work is out regardless of merit.
3. **Silence is a vote against.** An idea nobody has asked about in six
   months is not a backlog item, it's an opinion. It ages out.
4. **The plan is the gate, not the diff.** Scope creep and gold-plating enter
   before the first line of code. Approve the approach; let agents execute.
5. **Test the path a user takes and the failure that would be silent.**
   Nothing else, unless there's a named reason.
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

A **sprint = a milestone = a release**. It ships when its theme is satisfied.
That is typically about a week, but the content decides, not the calendar.

---

## 1. Capture — creating issues

**Rule: never suppress a capture.** Friction here costs you real signals and
buys nothing, because the filter is at planning.

Every issue records one mandatory field beyond the existing templates — the
**signal**, applied as a label:

| Label | Meaning | Weight at planning |
| --- | --- | --- |
| `signal:owner` | A named owner asked, by email, contact form, or club conversation | Strongest. A real person is waiting. |
| `signal:analytics` | Behaviour in logs or usage data shows a gap — failed searches, error rates, abandoned flows | Strong. Evidence without opinion. |
| `signal:operator` | Your own friction as admin/editor/owner of the site | **Weakest, and the one to watch.** This is where make-work is born. |
| `signal:defect` | Something is broken against its own stated behaviour | Bypasses the theme test if it's user-visible (see Hotfix track). |
| `signal:forced` | Security advisory, dependency EOL, platform change | Bypasses the theme test; gets the housekeeping slot. |
| `signal:discovered` | Found by you or an agent while working another issue | Neutral. Must be re-justified like anything else. |

`signal:operator` issues are legitimate — you run the site and your friction
is real — but they carry a higher bar at planning: they need a second reason
to exist beyond "it annoyed me once."

### The signal records origin, not every later measurement

The label is set once, at capture, from where the idea came from. A later
measurement **corroborates or refutes** an issue; it does not re-originate it,
and it does not change the label.

This matters because the weekly monitoring run re-measures a standing set of
issues every time it fires. If measurement rewrote provenance, every issue it
ever touched would drift to `signal:analytics` within a few weeks and the
label would stop discriminating between an issue produced by evidence and one
merely checked against it — which is the only distinction it exists to make.

Worked example, the 2026-09-01 run. It measured five open issues:

| Issue | What the run measured | Origin | Label |
| --- | --- | --- | --- |
| #1689 | `/.git/` probes: 0 | Monitoring's own finding, 2026-08-17 baseline | `signal:analytics` |
| #1817 | Not reproduced, no `history.php`-referred 404 | Monitoring's own finding, 2026-08-28 run | `signal:analytics` |
| #1401 | 2 requests to `/.well-known/passkey-endpoints` | Filed 2026-07-29, before monitoring existed | `signal:operator` — unchanged |
| #1474 | Type 26 mirror: 1 request, 200, no `_over.gif` 404s | Filed 2026-08-03, before monitoring existed | `signal:defect` — unchanged |
| #1779 | One login → one `login` + one `Security` row | `/found` capture while working #1760 | `signal:discovered` — unchanged |

Only the first two were *produced* by a measurement. The other three were
verified by one.

**Corroboration is evidence for the planning gate, not a relabel.** Record it
as a comment on the issue and use it at Step 4.5 — that is where a measurement
does its work:

- **#1474** — one request in the window, and the defect did not fire. That is
  the edge-case test's third row exactly: few users, fails gracefully → don't
  build it. Close or defer.
- **#1401** — "evaluate and enable Passkey support" is an L-sized feature
  whose entire demand evidence is two probe requests. It fails *who noticed?*
  cleanly.

Two things do change a label, and only these two:

1. **A rescue.** An issue reopened by a genuinely new signal takes that
   signal's label — the new evidence is now why it is open.
2. **A mis-classification**, corrected on evidence, not on impression.

Never infer `signal:owner` or `signal:analytics`. Apply them only with a real
message or a real measurement to point at, and for `signal:analytics` only
when that measurement is what produced the issue.

Two more things belong in the issue at capture time, and nothing else:

- **The one-line beneficiary.** "Owners with no photos on their car cannot…"
  If you can't finish that sentence about somebody other than yourself, write
  the issue anyway, but expect it to fail the gate.
- **Verbatim quote, if there is one.** Paste the owner's actual words. Six
  weeks later, a paraphrase always reads more urgent than the original.

Everything else — acceptance criteria, technical approach, estimates — is
**planning work, not capture work.** Don't do it up front for issues that may
never be selected.

### The occasional contributor

A second contributor works a separate lane: issues labelled `help-wanted`
only, no plan gate, PR reviewed by you before merge. They never pick from the
open milestone — that avoids collisions on work agents are already running.

---

## 2. Plan — choosing the release

One session, roughly 30 minutes, at the start of each milestone. This is the
only real ceremony in the workflow, and it is where the money is.

### Step 1 — Read the signals, then pick the theme

Scan what arrived since the last planning: owner contacts, analytics
anomalies, error logs, and the new issues in the backlog. Look for the
cluster. Themes should be **discovered in the signals**, not invented.

A theme is a sentence with an audience and an outcome:

> ✅ "An owner can manage their own car photos without emailing an admin."
> ✅ "A visitor arriving from a search engine lands on something that makes
> sense."
> ❌ "Photo improvements." — no audience, no outcome, no finish line.

A theme you can't state that way isn't a theme, it's a category — and
categories never end, which is exactly how releases drift.

### Step 2 — Gate every candidate against three questions

Pull candidate issues that could serve the theme. Each must survive:

1. **Who noticed?** Name the signal. If the honest answer is "nobody, I
   thought of it" → out (or back to the backlog to wait for a real signal).
2. **What do they do today instead?** If the workaround is acceptable, this
   isn't a release item.
3. **What breaks if this never ships?** If nothing does, close it. Say so in
   the issue — it is not a failure to close an idea, it's the point.

### Step 3 — Apply the edge-case test

For every candidate, and again for every branch inside its plan:

> **How many real owners take this path in a year? And if we don't handle
> it, does it fail gracefully or badly?**

- Many users, fails badly → build it.
- Few users, fails badly → build the *guard*, not the feature. A clear error
  beats a code path maintained forever for three people.
- Few users, fails gracefully → **do not build it.** Write the decision in
  the plan's Not-doing list so it stays decided.

This one test is the highest-leverage rule in this document. Most gold-plating
is the third row treated as if it were the first.

### Step 4 — Seal the milestone

A milestone contains:

- **3–6 theme issues** — everything that serves the theme sentence.
- **At most 1 housekeeping issue** — `signal:forced` work (security,
  dependency, migration) that never fits any theme but has to happen.
- **Every open `gate-critical` issue** — uncapped and not counted against
  the housekeeping slot (see "Gate-critical issues bypass the theme test").
- **Nothing else.** No free slots, no "while we're in there."

Then write the theme sentence into the milestone description — not a list of
issue numbers. The description is the acceptance criterion for the release.

Every selected issue gets its acceptance criteria written *now* (this is the
first time it's worth the effort) and moves to `status:ready`. Unselected
issues go back to the backlog untouched.

---

## 3. Build — developing and testing an issue

### The plan gate (the only human checkpoint)

For each issue, an agent researches and produces a plan. You approve it or
send it back. **You do not review the diff by default.**

A plan is approvable in two minutes because it is always the same seven
things:

```markdown
1. Problem        — one sentence, and the signal
2. Beneficiary    — who is better off, specifically
3. Approach       — max 5 bullets
4. Files touched  — the actual list
5. Tests          — per the tier rules below, listed explicitly
6. NOT doing      — the edge cases and adjacent temptations, named and refused
7. Risk flag      — auth / data migration / public API / payments? yes or no
```

Section 6 is mandatory and must not be empty. If an agent can't name anything
it chose not to build, it hasn't thought about scope.

**Approve → the agent runs to a green PR unsupervised:** implement, tests,
then the single review round below, then push.

**Deviation rule.** If implementation needs anything outside the approved
plan — a new file, a new dependency, a behaviour change, an extra branch —
the agent stops and posts a one-line re-gate request on the issue. It never
absorbs the change silently. This is what keeps "gate the plan" honest.

**Risk flag = yes** is the one exception: those PRs get a human diff review
before merge.

### Discoveries mid-issue

Anything found while working an issue becomes a **new issue** labelled
`signal:discovered`, with the context that made it visible. It is fixed inline
**only** if the current issue's acceptance criteria cannot be met without it.
Otherwise it queues for the next planning session and passes the same gate as
everything else. No exceptions — this is the second-biggest source of scope
growth after edge cases.

### Test tier rules

Tests are decided by a standing rule, not per issue. The rule maps change type
to obligation, so neither you nor an agent negotiates coverage each time.

| Change type | Required | Explicitly not required |
| --- | --- | --- |
| Domain logic in `usersc/classes/` | Unit test per behaviour named in the acceptance criteria | Permutations of inputs that map to the same branch |
| Bug fix | One regression test in `tests/unit/regression/` reproducing the report | Tests for adjacent untested behaviour |
| New / changed API endpoint | Integration test: happy path + auth-failure path | Every validation permutation |
| New page or route | Playwright smoke: loads, correct auth, no console errors | Full interaction coverage |
| Auth, CSRF, input handling | The **negative** case, always | — |
| DB migration | Integration test against migrated schema, plus rollback verified | — |
| UI copy, CSS, layout | None | — |
| Refactor with no behaviour change | None new; existing suite must stay green | — |

Two rules govern the gaps:

- **One test per acceptance criterion, plus the negative case for anything
  security-relevant.** Beyond that, a test needs a named reason in the plan.
- **Never test a branch a real user cannot reach.** If the edge-case test said
  don't build it, it also says don't test it.

Before a PR is opened the agent runs `composer check` and the tier-appropriate
suite (`test:quick` for unit-only changes, `test:medium` when the DB is
involved) and reports the actual output. Red CI is the agent's problem to fix,
not yours to discover.

### Definition of done

An issue is done when: acceptance criteria are demonstrably met, tests per the
tier rules exist and pass, CI is green, docs touched by the change are updated,
and the PR body states any delta from the approved plan. Not before.

---

## 3b. Review and CI

### What the evidence shows

Four consecutive issue PRs, sampled end to end:

| PR | Commits | Shape |
| --- | --- | --- |
| #1838 | 2 | implement → `/review-pr` fact-check + coverage → fix |
| #1845 | 3 | implement → review → fix → review → fix |
| #1841 | 3 | implement → review → fix → **review caught a regression in that fix** |
| #1860 | 5 | implement → test-analyzer → code-reviewer (2 Blocking) → **ESLint on the fix** → CI review |

Every PR was **implemented once and reviewed two to four times, serially,
after the push** — each round producing its own commit. Two of the four had a
round whose only job was to repair a defect the previous round's fix
introduced.

Round count is not a proxy for quality. #1838 passed two review rounds; its
one production-consequential defect — an unguarded `LogCategories::` reference
that would fatal `error/500.php` exactly when the autoloader is missing, which
is precisely when a branded error page matters — survived both and was caught
days later by the milestone-level review (`e920d29`). The two rounds it did
get produced a comment-accuracy correction and test-coverage additions.

The cause is structural: **review is running as a phase that repeats after the
work, rather than a gate that runs once on a finished artifact.** Each fix
commit is the least-scrutinised code in the PR, and it reopens the artifact
for the next round.

### Rule 1 — One round, all reviewers at once, before the push

Fan every applicable reviewer — code review, test analysis, silent-failure,
security when the change touches auth or input — **in parallel, against the
same commit, before pushing**. Collect every finding into one list, triage it
once, fix it in one commit, then push.

Serial reviewers each see a different artifact, which guarantees that round
N+1 finds something round N never looked at. Parallel reviewers see the same
artifact once.

### Rule 2 — The two-round ceiling

After the fix commit, re-check **only the fix**, scoped to its own diff, with
only the reviewers whose findings it addressed. That is round two, and it is
cheap — it is also exactly where #1841's and #1860's self-inflicted defects
would have been caught.

**A third round means the plan was wrong.** Stop patching, return to the plan
gate, and re-scope. Three rounds of fixes on one PR is a planning failure
wearing a review costume.

### Rule 3 — Severity contract

Every reviewer, local or CI, sorts findings into exactly three buckets:

| Bucket | Test | Action |
| --- | --- | --- |
| **Blocking** | Verified, reproducible, and in this diff | Fix now, this PR |
| **Advisory** | Real, but not this PR's job | New issue, `signal:discovered` |
| **Note** | Wording, style, docs nuance | Fix only if already touching that line |

PR #1860's final round was a CLAUDE.md wording correction. That is a Note. It
should not have produced a commit, and it certainly should not have gated a
merge.

### Rule 4 — The local gate must be the CI gate, exactly

CI catches things locally-green branches didn't, because the two gates aren't
the same gate. Today's asymmetries:

| Gap | Local | CI |
| --- | --- | --- |
| Coding standards | Staged files only, in a temp dir | Whole repository |
| Unit tests | Only if a `.php`/`.json`/`phpunit.xml` file is staged | Always |
| Unit test groups | `test:quick` — runs everything | `test:quick:ci` — excludes `known-broken`, `requires-upstream-install`, `regression` |
| Integration suite | Pre-push | **Never runs** |
| Playwright | Local only | **Never runs** |
| Markdown lint | Pre-commit | **Never runs** |
| CodeQL, Semgrep | **No local equivalent** | Every PR |

A change to a `.js`, `.html`, `.htaccess` or spec file runs no unit tests
locally and the full suite in CI. `test:quick` and `test:quick:ci` cannot
agree by construction. Both of those are green-locally-red-in-CI generators.

Fix: **one command, `composer ci:local`, that runs precisely what CI runs, in
CI's configuration** — `test:quick:ci`, `test:regression:ci`, PHPStan, coding
standards over the whole repo, ESLint, `check:docs`. Pre-push runs that and
nothing else. Then move the one-sided gates onto both sides: markdownlint into
CI, and the integration and Playwright suites into CI (they are the two
suites that today can only fail on your machine).

### Rule 5 — CI review is a backstop, so it should report, not block

The Claude review runs on **every push** and a `Blocking` heading fails the
build. It is the one CI gate with no local equivalent and non-deterministic
output — a new diff produces new findings, indefinitely. The workflow's own
comments record the failure mode: on #1688 the same unverified hypothesis
blocked the merge three rounds running, and the review itself said it could
not check the library source.

Once Rule 1 puts every reviewer in front of the artifact before the push, the
CI reviewer is a second opinion on already-reviewed code. Recommendation:

- **Issue PR → milestone: advisory.** It posts, it doesn't fail the build.
- **Milestone → main: blocking, as today.** One PR, a release at stake, and
  the level of abstraction where it demonstrably earns its keep — `e920d29`
  is a bug only the milestone review found.

If that's too loose, the narrower version: keep it blocking, but restrict
`Blocking` to an enumerated list — security, data loss, breaking API change.
Everything else posts as Advisory or Note. Under that rule #1860's wording nit
never gates a merge and #1838's autoloader fatal still would.

### Rule 6 — The test suite is production code

Of the twelve most recent non-dependabot PRs, eight were test-infrastructure
repair or coverage: locator drift, fixture drift, timeouts, a missing CSRF
token, environment drift, and a Playwright project that never existed and
silently collected zero tests. That last class is the dangerous one — a suite
that collects nothing passes.

- A spec failing for a non-product reason is a **defect in the suite**, not a
  flake. It is fixed in the same PR when it blocks, and filed as
  `signal:defect` when it doesn't.
- **Assert expected collection counts** so a suite that silently collects
  nothing fails instead of passing.
- Suite repair competes for milestone slots like everything else — but it
  bypasses the theme test, because a suite you don't trust makes every other
  gate in this document decorative.

## 4. Ship — closing the milestone

**Release when the theme sentence is true.** If two issues remain open but an
owner can now manage their photos without emailing you, ship it and return
those issues to the backlog. If the list is empty but the theme sentence is
still false, the milestone isn't done.

This inversion matters: it makes "finished" a statement about users, and it
removes the incentive to pad a release with whatever is left over.

Then spend five minutes on three questions. Write the answers into the
release notes or a running log — one line each is enough:

1. **What did we ship that nobody needed?** Be specific. This is the only
   feedback loop that improves the gate.
2. **What did we learn about this theme's audience?**
3. **What signal arrived that we ignored, and was that right?**

Question 1, answered honestly a handful of times, will do more to stop
make-work than any process rule in this document.

---

## Interrupts and the hotfix track

Only one thing interrupts a milestone: **production is broken, data is at
risk, or there's a security exposure.**

That work branches from `main`, ships as a patch release outside the
milestone, and does not join the current milestone or change its scope. The
open milestone keeps its theme and its issue list intact.

**Everything else queues**, without exception — an owner's feature request
mid-milestone is captured, acknowledged, and waits for the next planning
session. Acknowledging quickly is the courtesy; reordering the release is not.

---

## Backlog hygiene

Free capture means the backlog grows faster than it drains, and a large
backlog is where make-work hides: old ideas start to read like commitments.

### Age-out with evidence rescue

- An issue **created more than 180 days ago** with no rescuing activity gets a
  `stale` warning label and a comment.
- **14 days later** it closes as `stale-no-demand`, with a comment explaining
  that silence was treated as a vote against and that a new signal reopens it.
- **Exempt:** anything in a milestone, `signal:forced`, security issues, and
  anything labelled `gate-critical` (see below).
- **Rescue:** a new signal — an owner asks, analytics show the gap — reopens
  the issue with the new evidence attached.

### Age from creation, not from last activity

The obvious implementation — GitHub's stale action, which keys on
`updated_at` — does not work on this repository, and the backlog proves it.

At the time of writing the oldest `updated_at` across 136 open issues is 28
days old, so a 180-day activity rule would never fire once. The timestamps
show why: seventeen issues share `2026-08-25T21:03–21:04`, seven share
`2026-08-10T15:33`, four share `2026-08-31T16:07` — issues created weeks or
months apart, updated within seconds of each other. Those are bulk label and
milestone operations. **Housekeeping resets the clock on everything it
touches**, which makes an activity-based rule self-defeating.

So:

- Age from **`created_at`**.
- **Label-only and milestone-only edits are not activity.** Only a human
  comment, or a new signal, counts as a rescue.
- Bot comments are not activity either.

### The rule is prospective — it cannot clean up what you already have

Even keyed on creation date, the oldest open issue is ~128 days old, so the
first age-outs are months away. Decay stops the backlog re-accumulating; it
does nothing about the backlog that already exists.

That needs a **one-time cull**, in one sitting, before the workflow starts.

1. **Cluster first.** Merge symptoms into root causes — this is mechanical and
   needs no judgement about value. Worked examples from the current backlog:
   `DB::query()` not throwing (#1761) is the root cause of #1719, #1720 and
   probably #1700 — four issues, one fix. The PHPStan level ladder (#1323 →
   #1524 → #1525) is one decision filed as three. #1512/#1513/#1514 are three
   files from a single review sweep. #1462 and #1655 are both titled
   "low-severity maintainability bundle" — bundles of bundles.
2. **Run the three planning questions over the clusters**, not issue by issue.
3. **Keep** anything that is a defect that fails *badly*, a security issue, or
   load-bearing for a standing gate.
4. **Close the rest** as `stale-no-demand`, so a new signal reopens them.

Expect to close a lot. "Remove dead `Car::owner()` accessor — no callers
anywhere" is a fine observation and a fine PR if you are already in the file;
as a tracked commitment competing for release slots it fails question three
cleanly.

### Gate-critical issues bypass the theme test

Two kinds of work skip the theme gate: `signal:forced`, and anything a
standing gate depends on, marked with the `gate-critical` label. A gate you do
not trust makes every other rule in this document decorative, so its repairs
are never "someday". `gate-critical` is applied by hand — there's no signal
that implies it — and, like `signal:forced`, exempts the issue from backlog
age-out regardless of how old it gets. Current holders: **#1752** (decouple
the integration suite from `DB::getInstance()` so CI can actually run the
integration gate — the prerequisite for Rule 4 in §3b) and **#1843** (the
review gate false-fails on prose headings beginning with "Blocking" — the
Rule 5 mechanism misfiring).

Closing an idea is a normal, healthy outcome here, not an admission of
failure.

---

## Tracking — where sprint state lives

**Recommendation: milestone + derived state + one manual label. No board.**

A Projects board is a second source of truth that one person maintains by
hand, and it decays the first busy week. GitHub already knows almost
everything:

| State | How it's known |
| --- | --- |
| Backlog | Open, no milestone |
| Ready | In the open milestone, `status:ready`, no branch |
| In progress | Branch exists for the issue |
| In review | PR open |
| **Blocked** | **`status:blocked` label — the only state you set by hand** |
| Done | Closed |

Blocked is the only state that isn't derivable from git, because "waiting on
an owner to reply" leaves no trace in the repo. Everything else is a query.

A `/sprint-status` command renders the readable view on demand: theme
sentence, issues by derived state, what's waiting on you, what's blocked and
why, and whether the theme sentence is true yet. The report is the artifact
you read at a check-in — there's nothing to keep in sync.

---

## Mechanics to build

Small, and mostly one-time:

1. **Labels** — `signal:*` (6), `status:ready`, `status:blocked`, `stale`,
   `stale-no-demand`, `help-wanted`, `gate-critical`. Done as of 2026-09-02 —
   all twelve exist, `signal:*` recoloured out of default grey, and
   `gate-critical` applied to #1752 and #1843.
2. **Issue templates** — add a required signal dropdown and the
   one-line-beneficiary field to both existing templates; remove the priority
   dropdown (priority is decided at planning against a theme, so a
   capture-time priority is noise that ages badly). Done as of 2026-09-02.
3. **Stale Action** — a scheduled GitHub Action implementing the 180+14 day
   rule with the exemptions above.
4. **Three commands** — `/plan-milestone` (signal review → theme → gate →
   seal), `/plan-issue` (produce the seven-section plan for approval — already
   exists as `/start-issue`), and `/sprint-status` (render derived state).
   `/plan-milestone` and `/sprint-status` done as of 2026-09-02.

## Quick reference

| Moment | Question to ask | Wrong answer means |
| --- | --- | --- |
| Capture | — | Never block a capture |
| Planning: theme | Can I state an audience and an outcome? | It's a category; pick again |
| Planning: candidate | Who noticed? What do they do today? What breaks if never? | Close it |
| Planning: any branch | Few users + fails gracefully? | Don't build it; write it in Not-doing |
| Plan gate | Is the Not-doing list empty? | Send the plan back |
| Mid-issue | Is this needed for the acceptance criteria? | New issue, next planning |
| Testing | Can a real user reach this branch? | Don't test it |
| Before pushing | Did every reviewer see this same commit, at once? | Serial review — you'll get a ladder |
| After a fix commit | Is this the third round? | The plan was wrong — re-gate it |
| Any finding | Verified, in this diff, and this PR's job? | Advisory or Note, not Blocking |
| A run measures an old issue | Did this measurement *produce* the issue? | Corroboration — comment, don't relabel |
| CI red | Does `composer ci:local` reproduce it? | The gates have drifted — close the gap |
| Release | Is the theme sentence true? | Ship anyway / not yet, per the answer |
| Retro | What did we ship that nobody needed? | Feed it back into the gate |
