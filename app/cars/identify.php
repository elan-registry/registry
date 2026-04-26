<?php
declare(strict_types=1);
/**
 * Redirect to new documentation system
 * This page has been migrated to the unified documentation system
 *
 * @author Elan Registry Admin
 * @copyright 2025
 */

// Permanent redirect (301) to new location
header('HTTP/1.1 301 Moved Permanently');
header('Location: ../../docs/reference/identification-guide.php');
exit;
