# FIX Script Creation Guidelines

This document provides comprehensive guidelines for creating database
maintenance scripts (FIX scripts) for the Lotus Elan Registry.

## Overview

FIX scripts are standardized database maintenance and data correction scripts
that follow a consistent pattern for UI, error handling, and logging.

## When creating FIX scripts, ALWAYS use the standardized template

1. **Use Template**: Start with `FIX/_TEMPLATE_Fix-Script.php`
2. **Sequential Naming**: Use format `##-Descriptive-Name.php` (e.g.,
   `13-Fix-Something.php`)
3. **UI Standards**: Maintain two-step process (description → start button →
   progress tracking)
4. **Progress Tracking**: Use `outputMessage()` for progress updates and step
   indicators
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
// FIX/##-Descriptive-Name.php

require_once '../users/init.php';
require_once $abs_us_root.$us_url_root.'users/includes/template/prep.php';

// Security check
if (!securePage($_SERVER['PHP_SELF'])) {
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
5. **Always include return navigation** to FIX index
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

## See Also

- `/FIX/_TEMPLATE_Fix-Script.php` - The standardized template
- `/FIX/README.md` - FIX scripts directory documentation
- [PROJECT_CONVENTIONS.md](PROJECT_CONVENTIONS.md) - Project-specific coding standards
- [CODING_STANDARDS.md](CODING_STANDARDS.md) - Comprehensive coding standards
