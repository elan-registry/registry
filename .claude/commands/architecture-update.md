---
description: Update ElanRegistry architecture documentation in the local wiki repo with codebase audit and Mermaid diagrams
model: claude-fable-5-1
---

# Architecture Documentation Update

Think through the codebase audit before writing documentation — verify
what the code actually does rather than what existing docs claim.

Keep output brief — terse status lines, no preamble, no restating of steps.

**Documentation split — the repo is authoritative.** Anything a code change
can falsify (schemas, column names, class names, method signatures, file
paths, page/route inventories, config keys) belongs in `docs/development/*.md`
in the Registry repo, not the wiki. The wiki holds only what a reader wants
before they have the repo open: onboarding narrative, domain concepts,
"why this design" context, and pointers to the repo docs that own the
falsifiable detail. This is the wiki's own policy
(`<wiki repo path>/CLAUDE.md`) — this command follows it, it does not
override it. When the codebase audit turns up a gap in falsifiable detail,
the fix is a repo doc change (flag it, or make it if in scope), never a new
wiki section that duplicates it.

## Step 0: Initialize TaskList

Before any other action, create one tracking task per major step below using
TaskCreate (pull wiki repo, codebase audit, doc split decision, parallel agent
launches, synthesis, diagram embedding, markdownlint, commit, publish).

Update the ElanRegistry architecture documentation in the local wiki repo.
Reads the current wiki pages from disk, audits them against the codebase,
updates onboarding/conceptual content and cross-references (not falsifiable
reference detail — see above), evaluates whether to split into multiple
documents, adds Mermaid diagrams throughout, ensures all files pass lint, and
commits + publishes via the wiki's two-branch workflow. All file reads and
writes target the wiki repo path directly.

**Wiki repo path:** developer-specific, not committed here — read it from
`.claude.local.md` (gitignored; see `CLAUDE.md`'s GitHub Wiki section). If
that file doesn't exist or doesn't list a wiki clone path, stop and ask the
user for the local path to the wiki repo before proceeding. Every
`<wiki repo path>` placeholder below refers to this resolved path.
**Main repo path:** the current repository checkout (read-only for codebase
audit, except where Step 3 identifies an in-scope `docs/development/*.md` fix).

## Available Agents

Use the Task tool to launch these agents. Launch multiple agents in parallel
when they don't depend on each other.

| Agent Type | `subagent_type` | Model | Use When |
| --- | --- | --- | --- |
| Explore | `Explore` | sonnet | Codebase exploration and auditing |
| Technical Documentation Writer | `technical-documentation-writer` | — | Writing and updating wiki documents |
| Software Developer | `software-developer` | — | Diagram creation and file assembly |
| Senior Architect | `senior-architect` | — | Architecture review and validation |

## Workflow

### Step 0: Resolve the wiki repo path

Read `.claude.local.md` in the main repo root for the wiki clone path. If it
doesn't exist or doesn't list one, stop and ask the user. Substitute the
resolved path for every `<wiki repo path>` placeholder in the steps below.

Read `<wiki repo path>/CLAUDE.md` before anything else — it is the
authoritative statement of what belongs on the wiki, the two-branch publish
workflow, and page conventions (metadata footer, `Content-Review-Status`).
This command must not contradict it; if anything below appears to, that page
wins.

### Step 0.5: Pull the wiki repo and check out the working branch

Work happens on `master-upload`, never directly on `master` (published,
read by github.com) or `main` (dead).

```bash
git -C <wiki repo path> checkout master-upload
git -C <wiki repo path> pull
```

List the existing wiki pages to understand what is already there:

```bash
ls <wiki repo path>/*.md
```

All file reads and writes in subsequent steps use absolute paths under
`<wiki repo path>/`.

### Step 1: Read the current wiki documents

- Read every existing `<Topic>.md` page in the wiki repo using the Read tool
  (the `ls` from Step 0.5 gives the full list — this typically includes the
  architecture overview, transfer/user-flow pages, UserSpice integration
  pages, and any others present).
- These are the authoritative baselines for wiki content — do not rewrite
  what is already accurate. Note which pages already carry falsifiable detail
  that has since moved to the repo (schema tables, class inventories, code
  samples) — these are drift to fix in Step 3, not a pattern to extend.

### Step 2: Audit the codebase and repo docs against the existing wiki pages

- Walk the main repo codebase and `docs/development/*.md` and compare what you
  find against what the wiki claims.
- For each wiki section determine if it is: accurate, outdated, incomplete,
  drifted-into-repo-territory (contains falsifiable detail that duplicates or
  contradicts a repo doc), or missing entirely.
- Use Explore agents in parallel to cover different areas of the codebase
  (e.g., database schema, PHP classes, page inventory, file storage, external
  integrations) and to confirm which `docs/development/*.md` file currently
  owns each falsifiable topic.

### Step 3: Update the document content

For the wiki, ensure the following are true. For each:

- If a section already exists and is accurate, preserve it as-is.
- If a section exists but is outdated or incomplete **as onboarding/concept
  content**, update it.
- If a section contains falsifiable reference detail (schema, class/method
  detail, page inventory, config keys, code samples meant to be copied),
  **do not expand it.** Trim it to a short pointer and link to the owning
  `docs/development/*.md` file (or wiki architecture-overview page, if that's
  where the pointer already lives). If no repo doc currently owns that detail
  and it's genuinely missing, flag it in the final report as a repo-doc gap
  rather than writing it into the wiki.
- If a genuinely onboarding/conceptual section is missing entirely, add it.

**Wiki-appropriate topics** (update/add as onboarding-narrative and
cross-references, not reference tables):

1. **Application Overview** — what the registry is, why UserSpice, the
   `/users/` vs `/usersc/` split as a concept, top-level directory purpose
   (link to repo docs for the authoritative directory listing).
2. **UserSpice Integration — concepts** — what `securePage()`/`hasPerm()`
   mean conceptually and why the permission model is non-hierarchical; link
   to the repo for the current permission-level table and code patterns.
3. **PHP Architecture — concepts** — the entity-vs-repository convention,
   namespace philosophy; link to `CLASSES.md` for the actual class list.
4. **File Storage — concepts** — the upload → validate → store → serve shape
   at a narrative level; link to repo docs for constants, class names, and
   `.htaccess` specifics.
5. **External Integrations — concepts** — which services exist and why
   (Cloudflare, A2 Hosting, Brevo, maps/location), left at the "what and why"
   level; link to repo docs for API details, CSP allowlist entries, and
   config keys.
6. **Key User Flows** — narrative walkthroughs (registering a vehicle,
   requesting a transfer, contacting an owner, searching); these are
   legitimately wiki content since they describe user-facing behavior, not
   an implementation contract — but sequence diagrams should name pages and
   services at a conceptual level, not embed method signatures that will
   drift.

**Explicitly not wiki content** (verify these are absent or already reduced
to pointers; do not add them): a full page/route inventory with routing
rules, a full database schema with column types/indexes, per-class method
signatures, `.htaccess` rewrite rule listings, PHP code samples presented as
patterns to copy. These belong in `docs/development/DATABASE.md`,
`CLASSES.md`, `SYSTEM_OVERVIEW.md`, `CODING_STANDARDS.md`, etc. If auditing
finds one of these repo docs is itself stale relative to the code, note it in
the final report — fixing it is a separate, repo-side change and outside this
command's write scope unless the user asks for it.

### Step 4: Evaluate whether to split the document

Use the following criteria:

- If any single onboarding/concept topic is large enough to stand alone,
  split it out.
- Each resulting document should be cohesive and self-contained.
- Add cross-references between wiki documents, and to the owning repo doc,
  where a reader would naturally navigate.
- Target: no single document exceeding 4-6 printed pages.
- The wiki is typically already split along these lines (check `ls` output
  from Step 0.5 before assuming a new page is needed) — prefer updating an
  existing page over creating a new one.

Produce a split plan showing: filenames, titles, and which sections go into
each document. **Pause and ask for confirmation before executing any split
that creates or renames more than one document.**

### Step 5: Assign documents to parallel task groups

- Divide documents so each subagent owns one or more complete documents.
- Do not split a single document across multiple subagents.
- Base grouping on document size and complexity so work is roughly balanced.
- The orchestrating agent determines grouping before spawning any subagents.

### Step 6: Spawn subagents to produce diagrams in parallel

Launch assigned task groups concurrently. Each subagent should:

- Receive its assigned document(s) and diagram opportunities.
- Read the assigned document(s) and relevant source files for each section.
- Produce all diagrams as fenced mermaid code blocks (` ```mermaid `).
- Keep diagrams conceptual — component/flow/sequence shape is fine even
  though it names real files and classes, but do not encode column lists or
  method signatures that duplicate repo-owned reference tables (an ER diagram
  of all tables/columns belongs in `DATABASE.md`, not the wiki).
- For each diagram return: target filename, exact section heading, and
  diagram content.
- **Not write anything to disk** — return results only.

Good candidates, scoped to what a reader needs before opening the repo:

- **Application Architecture** — high-level component diagram of major PHP
  modules and how they relate; directory-purpose diagram (conceptual, not a
  full tree).
- **UserSpice & Access Control** — flowchart of authentication flow from
  login through permission gate to page access; a role-vs-capability diagram
  at the concept level (not a full page-by-page matrix — that's the repo's).
- **Key User Flows** — sequence diagrams for: registering a vehicle,
  requesting a transfer, contacting an owner, searching the registry; a
  flowchart for the admin approval/moderation workflow.
- **External Integrations** — Cloudflare caching flow, A2 Hosting request
  topology, UserSpice customization points.

Do not produce, as wiki diagrams: a full database ER diagram with every
column, a full page/route inventory diagram, or a sequence diagram whose
labels are exact method signatures likely to drift — those belong with the
repo docs that own that detail.

### Step 7: Collect and validate all subagent results

- Wait for all task groups to complete before proceeding.
- Review each returned diagram for accuracy and consistency.
- Ensure node names are consistent across all diagrams and documents.
- If any diagram is incomplete or inconsistent, re-run that task only before
  continuing.

### Step 8: Embed all diagrams into their target documents

- This step is performed by the orchestrating agent only.
- Write files to their absolute paths under
  `<wiki repo path>/`.
- Place each diagram directly below the section heading it relates to.
- Do not modify any existing prose — only insert diagram blocks, and the
  trims/pointer-links called for in Step 3.
- If a section already has a diagram, add new ones alongside rather than
  replacing.
- Make a single clean write of each file.

### Step 9: Update each document's metadata footer

Per the wiki's page conventions (`<wiki repo path>/CLAUDE.md`), each page ends
with a metadata footer. For every page touched:

- Update **Last Updated** to today's date.
- Set **Content-Review-Status: Reviewed** only for pages actually checked
  against the code this run — leave `Needs Review` on anything not verified.
- In the body (not the footer), note what changed: diagrams added and where,
  and any falsifiable content trimmed with a pointer to its new repo-doc home.

### Step 10: Lint all files

- Run `markdownlint` against all modified and newly created files in the wiki
  repo:

  ```bash
  markdownlint <wiki repo path>/*.md
  ```

- Fix any lint errors before proceeding — do not skip or suppress warnings.
- Re-run lint after fixes to confirm all files pass cleanly.
- If a lint error cannot be auto-resolved, report it and pause for guidance
  before continuing to the commit step.

### Step 11: Commit and publish via the two-branch workflow

- Confirm all modified files pass lint before staging.
- Stage content files explicitly — do not `git add -A`:

  ```bash
  git -C <wiki repo path> add <file1>.md <file2>.md ...
  ```

- Write a commit message in the format:
  `docs: update architecture documents with diagrams [YYYY-MM-DD]`
- Commit on `master-upload`:

  ```bash
  git -C <wiki repo path> commit -m "docs: update architecture documents with diagrams $(date +%Y-%m-%d)"
  ```

- Publish using the `/publish-wiki` command
  (`<wiki repo path>/.claude/commands/publish-wiki.md`) rather than driving
  git by hand — it pushes `master-upload`, fast-forwards `master`, pushes
  that, and verifies. If `/publish-wiki` is unavailable, follow its steps
  manually: never edit `master` directly, the merge must be
  `git merge master-upload --ff-only` (stop and ask the user if that fails —
  a non-fast-forward means `master` diverged), and verify afterward with
  `git log origin/master -1`.

### Step 12: Report what was done

- List every wiki document created or modified (full path in the wiki repo).
- For each document: list every diagram added and which section it was
  placed in, and note any falsifiable content trimmed with the repo doc it
  now points to.
- List any repo-doc staleness found during the audit that this command did
  not fix (out of scope — wiki-repo-only), so the user can decide whether to
  address it separately.
- Note which task groups completed and if any required a re-run.
- Note any lint errors that were found and how they were resolved.
- Note the commit hash on `master-upload` and confirm `master` fast-forwarded
  to it.
- Confirm the changes are live at `https://github.com/elan-registry/registry/wiki`.
- If the document was split, provide the exact wiki page titles created so
  they are consistent with the filenames used.
