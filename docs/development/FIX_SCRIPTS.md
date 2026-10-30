# Admin Script Creation Guidelines

This document provides comprehensive guidelines for creating database
maintenance and one-time fix scripts for the Lotus Elan Registry.

> **Schema migrations now use Phinx.** One-time schema changes (DDL, FK
> constraints, column changes) belong in `database/migrations/` — not as FIX
> scripts. See [`database/migrations/README.md`](../../database/migrations/README.md)
> for how to create a Phinx migration. FIX scripts remain appropriate for admin
> utility tasks that require human judgment to run.

## Overview

Admin scripts are standardized PHP utilities used for database maintenance and
data correction. They follow a consistent pattern for UI, error handling, and
logging. As of the v2.20.0 restructuring, admin scripts live under
`app/admin/scripts/` and are split into two categories by purpose:

- **`app/admin/scripts/fix/`** — One-time migration / fix scripts. Run once,
  recorded in `fix_script_runs`, then deleted when no longer needed.
  Sequentially numbered (`##-Descriptive-Name.php`).
- **`app/admin/scripts/maintenance/`** — Repeatable system maintenance scripts
  that are safe to run multiple times (e.g., permission audits, thumbnail
  regeneration, orphan cleanup). Sequentially numbered for consistent ordering
  in the admin UI.

If the script runs once and is then done forever, it belongs in `fix/`. If the
script can usefully be re-run as part of routine maintenance, it belongs in
`maintenance/`.

## When creating admin scripts, ALWAYS use the standardized template

1. **Use Template**: Start with
   `app/admin/scripts/fix/_TEMPLATE_Fix-Script.php`
2. **Sequential Naming**: Use format `##-Descriptive-Name.php` (e.g.,
   `13-Fix-Something.php`)
3. **UI Standards**: Maintain two-step process (description → start button →
   progress tracking)
4. **Progress Tracking**: Use `outputMessage()` / `logProgress()` for progress
   updates and step indicators
5. **Logging**: Use simple `INSERT INTO fix_script_runs (script_name) VALUES
   (?)` format
6. **Database**: Always use proper transactions and error handling

## Template Features

The standardized template provides:

- Professional UI with progress bars and status updates
- Standardized completion summaries with statistics
- Proper error handling and rollback capabilities
- Consistent return navigation and logging

## Example Structure

**Copy `app/admin/scripts/fix/_TEMPLATE_Fix-Script.php`** rather than writing a
script from scratch. It is the source of truth for the current pattern; an
example reproduced here would drift from it, as an earlier version of this
section did.

The template wires up the shared infrastructure in
`app/admin/includes/fix-script-core.php`:

| Helper | Role |
| --- | --- |
| `admin_script_exec_requested()` | The gate. Returns true only for a POST with a valid CSRF token from a user passing `isAdmin()`. **Every destructive path must sit behind it** — it is what stops an editor, or a GET request, from executing a script. |
| `admin_script_start_form()` | Renders the confirm-and-execute form that produces that POST. |
| `logProgress($msg, $type)` | Writes to the two-phase progress UI and the run log. |
| `admin_script_close_button()` | Standard return navigation. |

Runs are recorded in `fix_script_runs`, which the admin health and maintenance
tabs read.

> **Schema changes do not belong here.** DDL goes in a Phinx migration, which
> runs at deploy time from the CLI rather than through a web-accessible page.
> See [ADR-009](adr/ADR-009-use-phinx-for-database-schema-migrations.md).

## Key Requirements

1. **Always use transactions** for database operations
2. **Always log errors** using the logger() function
3. **Always validate CSRF tokens** for form submissions
4. **Always provide clear progress updates** to users
5. **Always include return navigation** to the admin maintenance page
6. **Always log script execution** to fix_script_runs table

## Best Practices

- Test on development/staging environment first
- Include detailed progress messages
- Provide meaningful error messages
- Log all significant operations
- Include statistics in completion message
- Document what the script does in comments
- Use descriptive variable names
- Follow established coding standards

## Removing Completed Fix Scripts

When a `fix/` script has been successfully run on production and will never
need to run again, delete it. Git history is the permanent record — the script,
its comments, and the commit that removed it are all recoverable.

Maintenance scripts under `maintenance/` are never removed this way — they are
intended to be re-run, so they stay in place indefinitely.

Scripts were formerly moved to an `app/admin/scripts/fix/_ARCHIVE/` directory
first. That directory was deleted in v2.29.1: keeping executed scripts on disk
made them a copy-as-template trap (several had rotted to the point of fataling
if run) and polluted repo-wide searches, while adding nothing git history did
not already provide.

### When to remove

- The script has run successfully on production
- The underlying data issue is fully resolved
- There is no scenario where it would need to run again

### Removal process

1. Delete the script: `git rm app/admin/scripts/fix/##-Name.php`
2. Commit with message: `chore: remove completed fix script ##-Name`

Removing several at once as part of a milestone is normal — see
`.claude/commands/start-milestone.md`, which prompts for this cleanup when a
new milestone branch is created.

### Recovery

To restore a deleted script from git history, find the commit that **deleted**
it, then read the file from that commit's parent:

```bash
# 1. Find the deleting commit (the leading * matches whichever directory the
#    script lived in, and prints the path to use in step 2)
git log --all --full-history --diff-filter=D --format='%h %s' --name-only \
  -- '*<filename>.php'

# 2. Recover it — note the `^`: the file does not exist *at* the deleting
#    commit, only in its parent
git show <commit>^:<path-from-step-1> > recovered-script.php
```

Match the script's **final** state this way rather than searching for its
current path directly. Scripts deleted in v2.29.1 had been moved to
`app/admin/scripts/fix/_ARCHIVE/` first (and, before the v2.20.0
restructuring, `FIX/_ARCHIVE/`), and several were edited *after* that move — so
a `git log` on the pre-archive path stops at the move commit and recovers a
stale version.

## Rerun the Deletion Privacy Step (`30-Rerun-Deletion-Privacy-Step.php`)

Script `app/admin/scripts/maintenance/30-Rerun-Deletion-Privacy-Step.php`
runs the account-deletion privacy step again for one deleted user ID (#2419).
It is a repeatable maintenance script. An admin runs it. An editor cannot.

### When to run it

Run it when the `UserDeletion` log has this CRITICAL line for a user:

```text
CRITICAL: privacy step failed for user N at stage S, rolled back, personal data remains: ExceptionClass (code C[, SQLSTATE X]) at File.php:L
```

`N` is the user ID. `S` is the stage that failed. The part after the colon
gives the exception class, code, SQLSTATE (when known) and throw site, never
the message. If the rollback also failed, the line says
`rollback failed: <same summary>` in place of `rolled back`.

When `S` is `commit`, the line says `transaction state unknown` and
`personal data may remain`. The server may have committed the deletes. If the
rollback also failed, the line says
`transaction state unknown, rollback failed: <same summary>`. The separate
summary line that follows, `Privacy step for user N: …`, still says
`rolled back`.

Run the script in all these cases. When the step rolled back, the personal
data of that user remains: the plugin mail rows, the email events, the profile
and the holding row in `er_deleted_user_addresses`. When the commit landed, the
rerun finds no rows, commits 0 deletes, and shows that no holding row was
found. The log line holds no address.

Also run it for each user ID that the daily retention run names in the
`RetentionPurge` log line "Account-deletion privacy step not complete". The
run fails while an `er_deleted_user_addresses` row is older than 24 hours. It
passes again after the rerun.

For a "privacy step refused" line, read the reason in the line first:

- `the ID is below 1` or `a users row still exists`: do not run the script.
  The step deleted nothing, because the ID is not a deleted user.
- `the users row check failed`: the step could not read `users`. Make sure
  that no `users` row has ID `N`, then run the script for `N`.

After a refusal from the deletion hook, the log also has the line
`CRITICAL: car cleanup skipped for user N`. The script does not move cars.
If no `users` row has ID `N`, reassign the cars and expire their pending
transfer requests by hand.

### How to find the user ID

1. Open the `logs` table or the admin log view. Filter the category to `UserDeletion`.
2. Find the CRITICAL line.
3. Read the number after `for user`.

The log row also stores the same ID as its user ID.

### Steps

1. Open the Maintenance page in the admin console.
2. Open `30-Rerun-Deletion-Privacy-Step`.
3. Enter the user ID in the field `Deleted user ID`.
4. Click `Run Privacy Step`.

The script refuses an ID that still has a `users` row. This protects a live
owner's profile. It shows the user ID and row counts only, never an address.

### What success looks like

The page shows a green "Committed" message and a table of deleted row counts:

| Row | Count |
| --- | --- |
| `plg_sendinblue_messages` | Plugin mail rows deleted. |
| `er_email_events` | Email events deleted. |
| `er_deleted_user_addresses` | Holding rows deleted. A first rerun shows 1. |

If the page shows a note that `plg_sendinblue_messages` does not exist, the
site has no plugin table. Zero plugin rows is then correct.

A second run for the same ID is safe. It finds no holding row, profile or
event rows. It also logs a summary line to the `UserDeletion` log.

If the page warns that it found no holding row for the ID, check the ID
against the CRITICAL log line. A mistyped ID, a second run, or a missing
trigger gives this warning.

### Limits

The rerun cannot find every address. The car cleanup runs after a failed
privacy step. It takes the user's cars away from the user ID, so the rerun
cannot read the addresses on those cars. The holding row stores only
`users.email` and `users.email_new`. A `plg_sendinblue_messages` row sent to
an address that was only on the user's cars, with no `er_email_events` row for
the user, therefore remains. The page shows a note about this after each
committed run. The daily retention purge deletes those rows once they are
older than 90 days.

### If the run fails again

1. Read the page. A "Failed at stage" message names the stage. A "Refused"
   message means the ID has a `users` row or the check failed.
2. Read the `UserDeletion` log for the exception class and code.
3. Fix the cause. Examples are a database lock timeout or a missing table.
4. Run the script again. The holding row stays after a failure, so the
   addresses are still available.
5. If the failure repeats, open a GitHub issue. Put the user ID, the stage,
   the exception class and the code in it. Do not put an address in it.

Nothing retries a failed step by itself. See
[#2421](https://github.com/elan-registry/registry/issues/2421).

### After a deploy

The script is a new page. UserSpice creates its `pages` row when an admin
first opens it, and `21-Fix-Page-Permissions.php` changes only pages that have
a row. So on test, then on production:

1. Open `30-Rerun-Deletion-Privacy-Step.php` once as an admin.
2. Run `21-Fix-Page-Permissions.php` from the Maintenance page. This adds the
   permission rows for the new page.

## See Also

- `/app/admin/scripts/fix/_TEMPLATE_Fix-Script.php` - The standardized template
- `/app/admin/scripts/fix/README.md` - Fix scripts directory documentation
- [CODING_STANDARDS.md](CODING_STANDARDS.md) - Coding standards and conventions
