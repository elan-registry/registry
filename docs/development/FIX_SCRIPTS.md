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
  recorded in `fix_script_runs`, then archived to `_ARCHIVE/` when no longer
  needed. Sequentially numbered (`##-Descriptive-Name.php`).
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

```php
<?php
// app/admin/scripts/fix/##-Descriptive-Name.php
//   (or app/admin/scripts/maintenance/##-Descriptive-Name.php)

require_once '../../../../users/init.php';
require_once $abs_us_root.$us_url_root.'users/includes/template/prep.php';

// Security check
if (!securePage($php_self)) {
    die();
}

$db = DB::getInstance();
$userId = $user->data()->id;

// Script description
$scriptName = "Descriptive Name";
$scriptDescription = "What this script does...";

// Handle POST request
if (Input::exists()) {
    if (Token::check(Input::get('csrf'))) {
        try {
            $db->query("BEGIN");

            // Your maintenance logic here
            outputMessage("Processing...");

            // Log execution
            $db->insert('fix_script_runs', ['script_name' => $scriptName]);

            $db->query("COMMIT");
            outputMessage("✓ Complete!", 'success');

        } catch (Exception $e) {
            $db->query("ROLLBACK");
            logger($userId, 'DatabaseMaintenance', "Error in $scriptName: " . $e->getMessage());
            outputMessage("Error: " . $e->getMessage(), 'danger');
        }
    }
}

// Display UI
?>
<div class="container">
    <h2><?=$scriptName?></h2>
    <p><?=$scriptDescription?></p>

    <form method="post">
        <input type="hidden" name="csrf" value="<?=Token::generate()?>">
        <button type="submit" class="btn btn-primary">Start</button>
    </form>

    <div id="output"></div>
</div>
```

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

## Archiving Completed Fix Scripts

When a `fix/` script has been successfully run on production and will never
need to run again, move it to `app/admin/scripts/fix/_ARCHIVE/` and update the
archive README.

Maintenance scripts under `maintenance/` are not archived — they are intended
to be re-run, so they stay in place indefinitely.

**Do not delete scripts immediately** — move them to `_ARCHIVE/` first so the
git history and the README serve as an audit trail.

### When to archive

- The script has run successfully on production
- The underlying data issue is fully resolved
- There is no scenario where it would need to run again

### Archive process

1. Move the script:
   `git mv app/admin/scripts/fix/##-Name.php app/admin/scripts/fix/_ARCHIVE/##-Name.php`
2. Add an entry to `app/admin/scripts/fix/_ARCHIVE/README.md`:

```markdown
| `##-Name.php` | Brief description | What it did in one sentence |
```

1. Commit with message: `chore: archive completed fix script ##-Name`

### Bulk cleanup

When multiple archived scripts accumulate, they can be deleted in a single
commit to reduce repository size. Before deleting:

1. Ensure `app/admin/scripts/fix/_ARCHIVE/README.md` lists every script being
   removed with its purpose — this is the permanent record.
2. Include git recovery instructions in the README (see existing README for
   template).
3. Commit the deletions and README update together.

### Recovery

To restore a deleted script from git history:

```bash
git log --all --oneline -- app/admin/scripts/fix/_ARCHIVE/<filename>.php
git show <commit>:app/admin/scripts/fix/_ARCHIVE/<filename>.php > recovered-script.php
```

## See Also

- `/app/admin/scripts/fix/_TEMPLATE_Fix-Script.php` - The standardized template
- `/app/admin/scripts/fix/_ARCHIVE/README.md` - Record of all archived/deleted scripts
- `/app/admin/scripts/fix/README.md` - Fix scripts directory documentation
- [CODING_STANDARDS.md](CODING_STANDARDS.md) - Coding standards and conventions
