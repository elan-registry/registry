---
description: Update ElanRegistry architecture documentation in wiki/ with codebase audit and Mermaid diagrams
model: claude-opus-4-7
---

# Architecture Documentation Update

## Step 0: Initialize TaskList

Before any other action, create one tracking task per major step below using
TaskCreate (branch + fetch wiki, codebase audit, doc split decision, parallel
agent launches, synthesis, diagram embedding, markdownlint, commit, summary).


Update the ElanRegistry architecture documentation in the `wiki/` directory.
Fetches the current wiki page, audits it against the codebase, updates all
content, evaluates whether to split into multiple documents, adds Mermaid
diagrams throughout, ensures all files pass lint, and prepares everything for
review on a branch. Does not update the live wiki.

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

### Step 0: Set up a working branch

- Confirm you are starting from the `main` branch. If not, switch to main first.
- Pull the latest changes from `origin/main`.
- Create a new branch called `docs/architecture-update`.
- All changes should be made on this branch.

### Step 1: Fetch the current wiki document

- Use WebFetch to retrieve the current content of the architecture document at:
  `https://raw.githubusercontent.com/wiki/unibrain1/elanregistry/Elan-Registry-Architecture-and-Database-Design.md`
- Save it to `wiki/Elan-Registry-Architecture-and-Database-Design.md`.
- This is the authoritative baseline — do not rewrite what is already accurate.

### Step 2: Audit the codebase against the existing doc

- Walk the full codebase and compare what you find against what is documented.
- For each section determine if it is: accurate, outdated, incomplete, or
  missing entirely.
- Use Explore agents in parallel to cover different areas of the codebase
  (e.g., database schema, PHP classes, page inventory, file storage, external
  integrations).

### Step 3: Update the document content

Ensure the document contains **all** of the following sections. For each:

- If a section already exists and is accurate, preserve it as-is.
- If a section exists but is outdated or incomplete, update it.
- If a section is missing entirely, add it.

**Required sections:**

1. **Application Overview** — Purpose, scope, frameworks/libraries (UserSpice,
   composer dependencies), directory structure.

2. **Page & Route Inventory** — Every public-facing and admin page. For each:
   purpose, data displayed, access level (public / authenticated / admin). URL
   routing patterns and `.htaccess` rewrite rules.

3. **Database Schema** — Every table: purpose, columns, data types, indexes.
   Foreign key relationships (even if not enforced at DB level).

4. **UserSpice Integration** — Authentication and access control. Which
   pages/functions are gated and by what permission level. Customizations to
   core UserSpice behavior.

5. **PHP Architecture & Data Flow** — Key PHP files: entry points, includes,
   helpers, config. Class structure and notable functions. Data flow from MySQL
   query to PHP processing to HTML output. API endpoints and AJAX calls.

6. **PDF & File Storage** — How PDFs and files are stored (database metadata +
   filesystem path). Upload handling logic and file validation. How files are
   served to authenticated vs public users.

7. **External Integrations** — External services (Cloudflare, A2 Hosting,
   email, etc.). Caching behavior and interaction with dynamic content.

8. **Key User Flows** — Registering a vehicle, uploading a document, searching
   the registry. Admin-only workflows (approvals, moderation, data management).

### Step 4: Evaluate whether to split the document

Use the following criteria:

- If any single topic section is large enough to stand alone as a reference
  document, split it out.
- Each resulting document should be cohesive and self-contained.
- Add cross-references between documents where a reader would naturally navigate.
- Target: no single document exceeding 4-6 printed pages.

Recommended split candidates (split only if content warrants it):

- Overview & Application Architecture
- Database Schema & Data Model
- UserSpice Integration & Access Control
- PHP Architecture & Data Flow
- PDF & File Storage
- Key User Flows
- External Integrations & Infrastructure

Produce a split plan showing: filenames, titles, and which sections go into
each document. **Pause and ask for confirmation before executing any split that
creates or renames more than one document.**

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
- For each diagram return: target filename, exact section heading, and diagram
  content.
- **Not write anything to disk** — return results only.

Ensure the following diagrams are produced and placed in the most relevant
document:

**Database Schema:**

- Full ER diagram of all tables, columns, and relationships.
- Separate ER diagram for any major subsystem if the schema warrants it.

**Application Architecture:**

- High-level component diagram showing major PHP modules and how they relate.
- Directory structure diagram showing key folders and their purpose.

**UserSpice & Access Control:**

- Flowchart showing authentication flow from login through permission gate to
  page access.
- Permission matrix diagram showing roles vs page/feature access levels.

**PHP & Data Flow:**

- Sequence diagram for a primary read path: user request -> PHP -> MySQL ->
  HTML response.
- Sequence diagram for a primary write path: form submit -> validation -> DB
  write -> response.

**PDF & File Storage:**

- Flowchart showing file upload: validation -> storage -> metadata write.
- Flowchart showing file retrieval: auth check -> metadata lookup -> file serve.

**Key User Flows:**

- Sequence diagram for: registering a vehicle.
- Sequence diagram for: uploading a document.
- Sequence diagram for: searching the registry.
- Flowchart for admin approval or moderation workflow.

**Additional diagrams:**

- Any diagrams that would meaningfully clarify undocumented complexity.
- Good candidates: Cloudflare caching flow, A2 Hosting deployment topology,
  UserSpice customization points.

### Step 7: Collect and validate all subagent results

- Wait for all task groups to complete before proceeding.
- Review each returned diagram for accuracy and consistency.
- Ensure node names are consistent across all diagrams and documents.
- If any diagram is incomplete or inconsistent, re-run that task only before
  continuing.

### Step 8: Embed all diagrams into their target documents

- This step is performed by the orchestrating agent only.
- Place each diagram directly below the section heading it relates to.
- Do not modify any existing prose — only insert diagram blocks.
- If a section already has a diagram, add new ones alongside rather than
  replacing.
- Make a single clean write of each file.

### Step 9: Add an update summary to each document

- Below each document's title, add or update a "Last Updated" block with
  today's date.
- Note: "Added Mermaid diagrams throughout."
- List the diagrams added and which sections they appear in.

### Step 10: Lint all files

- Run `markdownlint` against all modified and newly created files in `wiki/`.
- Fix any lint errors before proceeding — do not skip or suppress warnings.
- Re-run lint after fixes to confirm all files pass cleanly.
- If a lint error cannot be auto-resolved, report it and pause for guidance
  before continuing to the commit step.

### Step 11: Commit the changes

- Confirm all files in `wiki/` pass lint before staging.
- Stage all modified and newly created files under `wiki/`.
- Write a commit message in the format:
  `docs: update architecture documents with diagrams [date]`
- Commit to the `docs/architecture-update` branch.
- **Do not push** — leave that for manual review.

### Step 12: Report what was done

- List every document created or modified.
- For each document: list every diagram added and which section it was placed in.
- Note which task groups completed and if any required a re-run.
- Note any lint errors that were found and how they were resolved.
- Note the branch name and commit hash.
- Remind the user that once satisfied, each document will need to be manually
  created or updated in the GitHub wiki at:
  `https://github.com/unibrain1/elanregistry/wiki`
- If the document was split, provide the exact wiki page titles to create so
  they are consistent with the filenames used in `wiki/`.
