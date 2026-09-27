# ADR-018: Build Frontend Assets at Deploy Time Instead of Committing Build Output

## Status

Accepted

**Supersedes:** [ADR-017](ADR-017-automate-frontend-vendoring-via-npm-build-pipeline.md)

## Date

2026-08-27

## Implementation status

Implemented on 2026-08-27 in #1806. The post-receive hook runs
`npm ci --omit=dev` and `npm run build` before it removes development files
from the deployed work tree. Build-time packages are listed in `dependencies`.
The generated `usersc/js/` and `usersc/css/` files are ignored by Git. The
`vendor-drift` CI job was removed. These implementation details supersede the
follow-up items in the original decision text below.

## Context

ADR-017 automated frontend vendoring by having `scripts/build.js` copy/build
vendored libraries from `node_modules` into `usersc/js/`/`usersc/css/`, with
the **built output committed to git** and CI's `vendor-drift` job catching
any commit where the output no longer matches what a fresh build produces.
It explicitly reaffirmed ADR-015's earlier rejection of build-at-deploy-time,
on the stated premise that "the deploy hook ... will continue to never
invoke `npm install` or `npm run build`" — a premise ADR-015 originally
justified as "the shared-hosting deployment model does not natively support"
a build step at deploy time.

That premise has now been tested directly against the production hosting
environment, rather than assumed. This ADR records what was found and the
resulting decision.

### The recurring cost of build-then-commit

ADR-017's approach works correctly, but has produced the exact category of
friction it was designed around, just shifted one step downstream: a
Dependabot version bump to `package.json` alone (with no rebuilt/committed
output in the same commit) correctly fails `vendor-drift`, since nothing
regenerated `usersc/js`/`usersc/css` from the new dependency version. This
is not a false positive — it's the check doing its job — but it means
**every** routine dependency bump requires a human to locally run
`npm run build`, review the diff, and push a fix commit before the PR can
merge. This has now happened three times (#1741, #1742, #1743) and is
currently blocking two more open Dependabot PRs: **#1739** (`maplibre-gl`
6.4.1 → 6.5.0) and **#1740** (`@versatiles/style`). Confirmed live on #1739:
`Vendored Asset Drift Check` fails with a one-line diff (a license-URL
version string inside `usersc/js/maplibre-gl-shared.js`) that a human must
manually regenerate and push.

### Verifying the premise: is Node/npm actually usable on the deploy host?

Tested directly via SSH against `test.elanregistry.org` (A2 Hosting, the
same host class as production), in a genuinely non-interactive context —
not an interactive login shell, which sources RC files a git post-receive
hook's exec context does not:

```console
$ ssh a2hosting 'bash -c "which node; which npm; node --version; npm --version"'
/home/unibrain/.nvm/versions/node/v18.20.8/bin/node
/home/unibrain/.nvm/versions/node/v18.20.8/bin/npm
v18.20.8
10.8.2
```

**Node and npm resolve correctly in a non-interactive `bash -c` context,
with no nvm-sourcing workaround required.** This alone was the single
biggest open question — ADR-015/017 never verified it, and it could have
killed this option outright if node/npm required an interactively-sourced
shell profile a git hook doesn't get. It doesn't.

### Verifying the cost: how expensive is a real build?

The live deployed working tree at `/home/unibrain/test.elanregistry.org`
had already had `package.json`/`package-lock.json`/`scripts/` removed by
`.deployignore`'s post-checkout cleanup (confirmed: `npm ci` there failed
immediately with `EUSAGE`, since no lockfile was present — itself
confirming `.deployignore`'s current behavior operates exactly as
documented). To measure real build cost without disturbing the live
deployed site, a fresh clone was made into a scratch directory on the same
host (`~/scratch-build-test/`), which has an intact `package-lock.json`:

```console
$ time npm ci
... (17 EBADENGINE warnings — see below)
added 195 packages, and audited 196 packages in 16s
found 0 vulnerabilities
real    0m15.722s   user    0m3.539s   sys    0m0.862s

$ time npm run build
Built 25 files.
Copied 9 vendor files.
Copied MapLibre GL JS assets.
Copied Chart.js asset.
Vendored DataTables (core, fixedheader, responsive) assets.
Generated usersc/js/versatiles-colorful.json
real    0m0.657s   user    0m0.222s   sys    0m0.065s
```

**`npm ci`: ~16 seconds** (dominated by package download/extraction, not
CPU). **`npm run build`: under 1 second.** Combined, well under a minute —
not a concern for a shared-hosting resource ceiling, and a small, bounded
addition to a deploy window that already runs `composer install` and
`phinx migrate` synchronously today.

`/usr/bin/time -v` (for peak-memory measurement) is not installed on this
host image — only bash's builtin `time` (wall-clock only) was available.
Memory was not measured directly; given the modest package count (195) and
sub-second build step, and that this is the same host class already
running the live PHP application (Composer install, migrations, and the
request-serving PHP process itself) without resource issues, this is not
treated as a blocking unknown — but is noted as an incomplete measurement
should evidence of memory pressure surface after implementation.

`npm ci` produced 17 `EBADENGINE` warnings — packages declaring a minimum
Node version above the host's v18.20.8 (`eslint@10.8.1`, `@playwright/
test@1.62.1`, `markdownlint-cli2@0.23.2`, and others, mostly requiring
Node ≥20 or ≥22). **All 17 are `devDependencies`** — test/lint/docs tooling
never needed to run `scripts/build.js` in production. The actual runtime
dependency of the build step, `esbuild@^0.28.2`, is itself a
`devDependency` today (not yet separated from test/lint tooling in
`package.json`), but its own engine requirement is compatible with Node 18
— the warnings are non-fatal noise from unrelated packages, not a build
blocker. `npm ci` still installs the full `devDependencies` list by
default; a production deploy step should avoid this entirely (see
Consequences → Negative, and the follow-up issue).

## Decision

**Supersede ADR-017.** Move frontend asset building from "build-then-commit"
to "build-at-deploy": `usersc/js/`/`usersc/css/`'s vendored contents become
gitignored build output, regenerated by `scripts/server-hooks/post-receive`
on every deploy via `npm ci && npm run build` (or a scoped equivalent — see
Consequences), rather than committed to the repository and checked for
drift by CI.

This directly closes the actual recurring pain (#1741-1743, and the
currently-blocked #1739/#1740): a Dependabot bump to `package.json` becomes
a normal, mergeable PR — the next deploy regenerates the correct output
automatically. There is no longer a "committed output out of sync with
declared version" failure mode to catch, because there is no committed
output to fall out of sync.

### Why this reverses ADR-015/017's rejection, on the merits

ADR-015's original objection — "the shared-hosting deployment model does
not natively support" a build step at deploy time — is now empirically
false for this host, as demonstrated above: Node/npm resolve
non-interactively, and a full build completes in well under a minute. The
premise that killed this option twice was never re-tested against the
actual host until now; it fails to hold.

### New considerations neither ADR-015 nor ADR-017 addressed

**Deploy atomicity.** `scripts/server-hooks/post-receive` does a blocking
`git checkout "$newrev" --force` directly into the live served document
root — confirmed via this session's research — with no maintenance-mode
gate and no release-directory/symlink-swap pattern. Composer install and
Phinx migrations already run synchronously in this exposed window today; a
build step adds to it. Unlike a single PHP-file swap, `scripts/build.js`
regenerates ~34 individual files (JS/CSS minification, vendor copies,
DataTables concatenation) — a request landing mid-build has a materially
higher chance of hitting a partially-regenerated asset than today's brief
PHP-swap inconsistency window.

**Mitigation, not full resolution:** the new build step must **halt the
deploy on failure**, exactly as Composer install and migrations already do
today (the hook already treats both as hard-stop conditions) — a failed
`npm run build` must not leave a half-regenerated `usersc/js`/`usersc/css`
serving to live traffic while reporting deploy success. True atomicity
(a maintenance-mode gate, or writing to a staging directory and swapping
it in) is out of scope for this ADR — it would be a larger, separate
change to the deploy model affecting Composer/migrations too, not
specific to frontend assets. This ADR accepts the same brief-inconsistency
risk profile the deploy hook already carries for every other deploy step,
scaled slightly by file count, not qualitatively new.

**Supply-chain exposure.** Build-at-deploy means `npm ci` fetches
directly from the npm registry, on the production host, under the same OS
user that owns the live site and `.env`, on every push — a materially
larger blast radius than CI-only fetch plus a reviewable committed
artifact (today's model: a compromised npm package can only affect what a
CI job produces, which a human then reviews in a diff before it's
committed; under this ADR, the same compromised package runs its install
scripts directly on the box serving `elanregistry.org`). This is a real,
accepted tradeoff, not a dismissed one: mitigated by `npm ci` (not `npm
install`) enforcing the committed lockfile exactly, and by the same
`package-lock.json` review process (Dependabot PR + CI checks) that
already gates every dependency change today before it reaches the deploy
branch. No new install-time review step is added by this ADR beyond what
already exists.

## Consequences

### Positive

- Closes the #1741-1743 recurring-friction pattern and unblocks the
  currently-open #1739/#1740 without any manual rebuild-and-push step.
- Removes an entire class of CI check (`vendor-drift`) and its failure
  mode — there is nothing left to drift, since the served files are
  regenerated fresh on every deploy rather than checked against a
  point-in-time commit.
- `usersc/js/`/`usersc/css/`'s vendored contents (FilePond, MapLibre GL,
  DataTables, Chart.js, `@versatiles/style` output) are removed from git
  history going forward — smaller diffs, no more "did someone hand-edit a
  vendored file" review burden.

### Negative

- Deploy time increases because the hook installs npm packages and builds
  frontend assets. The original test-host measurement was about 16 seconds
  for a full `npm ci` and less than one second for `npm run build`. The hook
  now uses `npm ci --omit=dev`, so the install time can differ. The deploy
  remains synchronous and non-atomic (see "New considerations" above).
- New supply-chain exposure: `npm ci` executes on the production host
  itself, under the site's OS user, on every deploy — a larger blast
  radius than CI-only npm execution. Accepted per the analysis above, not
  eliminated.
- **Resolved during implementation:** Build-time packages, including
  `esbuild` and `@versatiles/style`, are production dependencies. The deploy
  hook runs `npm ci --omit=dev`, then removes `package.json`,
  `package-lock.json`, and `scripts/` during cleanup. This avoids installing
  test and lint dependencies on the server.
- No maintenance-mode gate or atomic release-swap is introduced by this
  ADR — the existing brief-inconsistency window during deploy (already
  present for Composer/migrations) is accepted as-is, scaled slightly by
  the build step's file count. A future ADR could address deploy
  atomicity holistically; this ADR does not attempt it.

### Neutral / carried forward from ADR-017 unchanged

- The corrected library inventory ADR-017 established (framework-native
  vs. project-vendored vs. removed-entirely) is unaffected by *how* the
  project-vendored tier gets built — only *when*.
- DataTables' extension set (Core, FixedHeader, Responsive only),
  concatenation-not-bundling build mechanics, and jQuery-global runtime
  detection are all ADR-017 decisions about *what* gets built and *how* —
  unchanged by this ADR, which only moves *when* the existing
  `scripts/build.js` runs.
- Composer's role (PHP dependencies only) is unaffected.

## Alternatives Considered

### Reaffirm ADR-017, add a GitHub Actions rebuild-and-push-back workflow

Keep build-then-commit exactly as ADR-017 specifies, but add a CI workflow
that runs `npm run build` and pushes a commit back onto a Dependabot PR
branch automatically (requiring `contents: write` permission on that
specific workflow's `GITHUB_TOKEN`), closing the manual-rebuild friction
without touching the deploy path at all.

**Rejected because:** it was the fallback plan if Node/npm proved
unusable or too costly on the deploy host — verification showed neither is
true. Given the deploy-at-build path is viable on the merits, it directly
eliminates the `vendor-drift` check entirely rather than adding a second
automation layer (a bot commit + a drift-check + a build-then-commit
step) on top of the existing one. Kept in mind as a strictly simpler
fallback if the deploy-at-build implementation (follow-up issue) surfaces
a blocker not visible from this ADR's verification (e.g. a production-only
resource ceiling not present on the test host).

### Full deploy-atomicity redesign (maintenance-mode gate / release-directory swap)

Address the pre-existing non-atomic, direct-to-docroot deploy model
(present regardless of this ADR) at the same time as introducing the
build step, so the new failure window this ADR adds is eliminated rather
than merely bounded.

**Rejected for this ADR's scope because:** it's a strictly larger change
affecting every deploy step (Composer install, migrations, VERSION
writing), not something specific to frontend-asset building. Conflating
the two would make this decision's actual scope (move a fast, already-safe
build step earlier in the pipeline) much harder to review and revert
independently. Left as an explicit open item in Consequences → Negative
for a future ADR to address holistically, if the accepted risk here proves
insufficient in practice.

## References

- **Issue:** #1771
- **Superseded ADR:** [ADR-017](ADR-017-automate-frontend-vendoring-via-npm-build-pipeline.md)
- **Related ADR:** [ADR-015](ADR-015-self-host-frontend-libraries.md)
  (original build-at-deploy rejection, the premise this ADR re-tested)
- **Blocked PRs unblocked by this decision:** #1739, #1740
- **Prior recurring-friction instances:** #1741, #1742, #1743
- **Verification performed:** live SSH check against `test.elanregistry.org`
  (A2 Hosting), 2026-08-27 — non-interactive Node/npm resolution, and a
  fresh-clone `npm ci && npm run build` timing run
- **Build pipeline:** `scripts/build.js`
- **Deploy hook:** `scripts/server-hooks/post-receive`
- **Deploy cleanup list:** `.deployignore`
- **Implementation:** #1806 updated `scripts/server-hooks/post-receive`,
  `.deployignore`, `package.json`, and CI to apply this decision.
- **Nygard ADR Format:**
  [https://cognitect.com/blog/2011/11/15/documenting-architecture-decisions](https://cognitect.com/blog/2011/11/15/documenting-architecture-decisions)
