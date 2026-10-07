# ADR-020: Issue-Driven Workflow: Theme Gate, Plan Gate, and Single Review Round

## Status

Accepted

## Date

2026-09-02

## Origin

PR #1936 introduced the workflow as a design proposal. This ADR keeps the
decision record and the evidence from that proposal. The how-to is
[ISSUE_WORKFLOW.md](../ISSUE_WORKFLOW.md). Issue numbers in this ADR are
historical evidence. The how-to does not repeat them.

## Context

### The problem this design solves

Execution is not the bottleneck on this project. Issues are already scoped to
be workable and test coverage is already good. The failure mode that the
workflow must prevent is **spending real effort on work nobody needed**:
features built for edge cases that no owner will ever hit, and make-work
issues that exist because they were easy to write down and not because
someone wanted them.

Every gate in the workflow asks one question: does a real person get
something out of this? A rule that does not serve that question is not in the
workflow.

### What the evidence shows

Four consecutive issue PRs were sampled end to end:

| PR | Commits | Shape |
| --- | --- | --- |
| #1838 | 2 | implement → `/review-pr` fact-check and coverage → fix |
| #1845 | 3 | implement → review → fix → review → fix |
| #1841 | 3 | implement → review → fix → **review caught a regression in that fix** |
| #1860 | 5 | implement → test-analyzer → code-reviewer (2 Blocking) → **ESLint on the fix** → CI review |

Every PR was implemented once and reviewed two to four times, serially, after
the push. Each round produced its own commit. Two of the four had a round
whose only job was to repair a defect that the previous round's fix
introduced.

Round count is not a proxy for quality. PR #1838 passed two review rounds. Its
one defect with a production consequence survived both. This was an unguarded
`LogCategories::` reference that would fatal `error/500.php` exactly when the
autoloader is missing, which is when a branded error page matters. The
milestone-level review caught it days later (`e920d29`). The two rounds that
the PR did get produced a comment-accuracy correction and test-coverage
additions.

The cause is structural. Review ran as a phase that repeats after the work,
and not as a gate that runs once on a finished artifact. Each fix commit is
the least-scrutinized code in the PR, and it reopens the artifact for the
next round.

## Decision

Adopt a four-loop workflow (capture, plan, build, ship) that the principles
below govern. Document the how-to in `ISSUE_WORKFLOW.md`, and keep the
reasoning here.

### The principles and their reasoning

1. **Capture is free, commitment is expensive.** Friction at capture loses
   real signals and gains nothing, because the filter is at planning.
   Nothing earns a release slot without passing the theme gate.
2. **A theme, not a list.** A theme has an audience and an outcome. A
   category such as "photo improvements" has no finish line, and a release
   without a finish line drifts until the list is empty. Coherence is the
   filter, so off-theme work is out whatever its merit.
3. **Silence is a vote against.** An idea that nobody asked about in six
   months is an opinion, not a backlog item. Old ideas start to read like
   commitments, so they age out.
4. **The plan is the gate, not the diff.** Scope creep and gold-plating enter
   before the first line of code. A two-minute plan approval is cheaper than
   a diff review, and it stops the problem earlier.
5. **Test the path a user takes and the failure that would be silent.**
   Coverage for branches that no user can reach is maintenance with no
   benefit.
6. **Ship when the theme is true, not when the list is empty.** This makes
   "finished" a statement about users. It removes the incentive to pad a
   release with leftovers.

Four more decisions follow from the principles.

#### The signal records origin

The signal label is set once, at capture. A weekly monitoring run re-measures
a standing set of issues each time it fires. If a measurement rewrote
provenance, every issue that it touched would drift to `signal:analytics`
within weeks. The label would then stop separating an issue that evidence
produced from an issue that evidence merely checked. That is the only
distinction the label exists to make.

The 2026-09-01 monitoring run measured five open issues. Only two were
produced by a measurement:

| Issue | What the run measured | Origin | Label |
| --- | --- | --- | --- |
| #1689 | `/.git/` probes: 0 | Monitoring's own finding, 2026-08-17 baseline | `signal:analytics` |
| #1817 | Not reproduced, no `history.php`-referred 404 | Monitoring's own finding, 2026-08-28 run | `signal:analytics` |
| #1401 | 2 requests to `/.well-known/passkey-endpoints` | Filed 2026-07-29, before monitoring existed | `signal:operator`, unchanged |
| #1474 | Type 26 mirror: 1 request, 200, no `_over.gif` 404s | Filed 2026-08-03, before monitoring existed | `signal:defect`, unchanged |
| #1779 | One login gave one `login` row and one `Security` row | `/found` capture while working #1760 | `signal:discovered`, unchanged |

The other three were verified by a measurement. They keep their labels. The
measurement is evidence for the planning gate:

- **#1474:** one request in the window, and the defect did not fire. This is
  the third row of the edge-case test exactly (few users, fails gracefully).
  Close or defer.
- **#1401:** "evaluate and enable Passkey support" is an L-sized feature
  whose only demand evidence is two probe requests. It fails *who noticed?*
  cleanly.

#### Age from creation, not from last activity

The obvious implementation is GitHub's stale action, which keys on
`updated_at`. It does not work on this repository. When the proposal was
written, the oldest `updated_at` across 136 open issues was 28 days old. A
180-day activity rule would never have fired.

The timestamps show why. Seventeen issues shared `2026-08-25T21:03–21:04`,
seven shared `2026-08-10T15:33`, and four shared `2026-08-31T16:07`. These
issues were created weeks or months apart and updated within seconds of each
other. Bulk label and milestone operations produced those timestamps.
Housekeeping resets the clock on everything it touches, so an activity-based
rule defeats itself.

So the rule ages from `created_at`. Label-only and milestone-only edits do
not count as activity, and bot comments do not count either. Only a human
comment or a new signal rescues an issue.

#### Cleanup finds go to a ledger

A cleanup find (dead code, duplication, naming, comments) usually says "not
broken" in its own body. One issue per find grows the backlog faster than it
drains, and each issue competes for release slots that it cannot win: it
fails "what breaks if this never ships?" cleanly. A cleanup item is cheap
only when a change already has the file open. The ledger keeps these items in
one place, grouped by file, so `/start-issue` can offer them to a plan that
edits the file. A find with no named benefit is dropped, with a one-line
record, so that a later review does not raise it again.

#### The milestone is sealed

A sealed milestone is what makes a release predictable. A fix that "only
takes 30 minutes" is a self-assessed estimate made at the moment of maximum
enthusiasm, and it is the most common way a diff outgrows its plan. So fix
size is not part of the decision matrix. Only a genuine emergency (production
broken, data at risk, or a security exposure) interrupts a milestone, and it
ships as a patch release from `main`. The milestone keeps its theme and its
issue list. Everything else queues for the next planning session.

#### Gate-critical work bypasses the theme test

A gate that nobody trusts makes every other gate decorative. Repairs to a
standing gate are never "someday". The `gate-critical` label marks them. It
is applied by hand, because no signal implies it. At the time of the
proposal, two issues carried it: #1752 (decouple the integration suite from
`DB::getInstance()` so CI can run the integration gate) and #1843 (the review
gate false-failed on prose headings that begin with "Blocking"). Both are
closed. The label is the list, and the workflow doc does not name issues.

### Review rules: the reasoning

#### Rule 1: one round, all reviewers at once, before the push

Serial reviewers each see a different artifact. That guarantees that round
N+1 finds something round N never looked at. Parallel reviewers see the same
artifact once. So the workflow collects every finding into one list, triages
it once, fixes it in one commit, and then pushes.

#### Rule 2: the two-round ceiling

After the fix commit, re-checking only the fix is cheap. It is also where the
self-inflicted defects in #1841 and #1860 would have been caught. A third
round means the plan was wrong: three rounds of fixes on one PR is a planning
failure that looks like review. So the third round returns to the plan gate.

#### Rule 3: severity contract

Three buckets (Blocking, Advisory, Note) give every reviewer the same test.
PR #1860's final round was a CLAUDE.md wording correction. That is a Note. It
should not have produced a commit, and it should not have gated a merge.

#### Rule 4: the local gate must be the CI gate

CI catches things that locally green branches did not, because the two gates
are not the same gate. When the proposal was written, the asymmetries were:

| Gap | Local | CI |
| --- | --- | --- |
| Coding standards | Staged files only, in a temp dir | Whole repository |
| Unit tests | Only if a `.php`, `.json`, or `phpunit.xml` file is staged | Always |
| Unit test groups | `test:quick` runs everything | `test:quick:ci` excludes `known-broken`, `requires-upstream-install`, `regression` |
| Integration suite | Pre-push | Never runs |
| Playwright | Local only | Never runs |
| Markdown lint | Pre-commit | Never runs |
| CodeQL, Semgrep | No local equivalent | Every PR |

A change to a `.js`, `.html`, `.htaccess`, or spec file runs no unit tests
locally and the full suite in CI. `test:quick` and `test:quick:ci` cannot
agree by construction. Both are generators of green-locally, red-in-CI
results.

The proposed fix was one command, `composer ci:local`, that runs what CI runs
in CI's configuration, with pre-push running only that command. It also moved
the one-sided gates to both sides (markdown lint into CI, and the integration
and Playwright suites into CI). `composer ci:local` does not exist at the time
of this ADR. The how-to states the principle and not the command.

#### Rule 5: CI review is a backstop

The Claude review runs on every push, and a `Blocking` heading fails the
build. It is the one CI gate with no local equivalent and with
non-deterministic output: a new diff produces new findings, indefinitely. The
workflow's own comments record the failure. On #1688, the same unverified
hypothesis blocked the merge three rounds running, and the review itself said
that it could not check the library source.

Once Rule 1 puts every reviewer in front of the artifact before the push, the
CI reviewer gives a second opinion on code that was already reviewed. The
proposal was:

- **Issue PR to milestone:** advisory. It posts and does not fail the build.
- **Milestone to `main`:** blocking. This is one PR, with a release at stake,
  at the level of abstraction where the review earns its keep. `e920d29` is a
  bug that only the milestone review found.

A narrower alternative keeps the review blocking, but limits `Blocking` to an
enumerated list: security, data loss, and breaking API change. Under that
rule, #1860's wording nit never gates a merge, and #1838's autoloader fatal
still would. The adopted calibration lives in the comments at the top of
`.github/workflows/claude-code-review.yml`.

#### Rule 6: the test suite is production code

Of the twelve most recent non-dependabot PRs when the proposal was written,
eight were test-infrastructure repair or coverage: locator drift, fixture
drift, timeouts, a missing CSRF token, environment drift, and a Playwright
project that never existed and silently collected zero tests. The last class
is the dangerous one, because a suite that collects nothing passes. So a
spec that fails for a non-product reason is a defect in the suite and not a
flake, expected collection counts are asserted, and suite repair bypasses the
theme test.

### History: the build checklist and the backlog cull

The proposal ended with a checklist of mechanics to build: the twelve labels
(six `signal:*` labels, `status:ready`, `status:blocked`, `stale`,
`stale-no-demand`, `help-wanted`, `gate-critical`), signal fields in the issue
templates with the capture-time priority dropdown removed, a scheduled stale
action for the 180 plus 14 day rule, and the commands `/plan-milestone`,
`/start-issue`, and `/sprint-status`. The labels and the templates were done
on 2026-09-02, with the commands. The stale action was not built, so the
how-to tells the developer to apply the age-out rule during `/groom-backlog`.

The proposal also described a one-time backlog cull, because the age-out rule
is prospective: the oldest open issue was about 128 days old, so the first
age-outs were months away. The cull clustered symptoms into root causes (for
example, `DB::query()` not throwing (#1761) was the root cause of
issues #1719, #1720, and probably #1700), ran the three planning questions over the
clusters, kept defects that fail badly, security issues, and standing-gate
work, and closed the rest as `stale-no-demand`. The cull was finished work. The
live rules that it used are in the how-to: the three questions, the
exemptions, and the rescue rule.

## Consequences

- A developer has one how-to to follow. Design reasoning does not compete
  with instructions.
- The how-to depends on the command files for exact step behavior. When a
  command changes, update the how-to in the same PR.
- The numbered Review Rules keep their numbers in the how-to, so older
  references to "Rule 1" through "Rule 6" still resolve.
- The age-out rule has no automation. Backlog size depends on regular
  `/groom-backlog` sweeps.
- Rule 4 describes a principle that the repository does not yet meet in full.
  The gaps in the table above can still be reached.

## Alternatives Considered

- **A Projects board for sprint state.** A board is a second source of truth
  that one person maintains by hand, and it decays in the first busy week.
  GitHub already derives backlog, ready, in-progress, in-review, and done from
  the milestone, the branch, and the PR. Only `status:blocked` needs a manual
  label.
- **The stale action keyed on `updated_at`.** See "Age from creation, not
  from last activity" above.
- **Serial review after the push.** This is the observed behavior in the
  sampled PRs, and it produced the repair rounds.
- **A capture-time priority field.** Priority is decided at planning against a
  theme. A priority set at capture is noise that ages badly.

## Related

- [ISSUE_WORKFLOW.md](../ISSUE_WORKFLOW.md): the how-to
- PR #1936: the origin of the design
- `.claude/commands/`: the commands that run each step
