<?php
/**
 * Custom override of users/includes/system_messages_header.php
 *
 * Changes from upstream:
 * - Default justify changed from 'left' to 'right' (Issue #536)
 * - Added z-index: 1090 to ensure toasts render above all content
 * - Uses inline styles for positioning (Bootstrap 4 compatible)
 *
 * Toast CSS (color bars, body styles) is in system_messages_footer.php.
 * Only positioning/z-index overrides belong here.
 *
 * @todo Issue #234 (BS5 migration): Remove this override. The upstream
 *       users/includes/system_messages_header.php uses BS5 utility classes
 *       (position-fixed, top-0, end-0) which will work once the template
 *       loads Bootstrap 5. After migration:
 *       1. Delete this file
 *       2. Set $system_messages_justify = 'right' in usersc/includes/loader.php
 *          (or upstream default if changed to 'right')
 *       3. Add z-index: 1090 to upstream CSS or via custom stylesheet
 */

// Set default justify if not already set
if (!isset($system_messages_justify)) { $system_messages_justify = 'right'; } // left|center|right

$justify = in_array($system_messages_justify, ['left','center','right'], true) ? $system_messages_justify : 'right';

// Map justify to CSS positioning (BS4-compatible inline styles)
$positionStyle = [
    'left'   => 'top: 0; left: 0;',
    'center' => 'top: 0; left: 50%; transform: translateX(-50%);',
    'right'  => 'top: 0; right: 0;',
][$justify];
?>

<style>
#us-toast-container {
    position: fixed;
    <?php echo $positionStyle; ?>
    z-index: 1090;
    padding-top: 4.6rem;
    padding-left: .5rem;
    padding-right: 1rem;
    max-width: 90vw;
}
</style>

<!-- Toast Container for UserSpice Messages -->
<div id="us-toast-container"
     data-justify="<?php echo htmlspecialchars($justify, ENT_QUOTES, 'UTF-8'); ?>">
</div>
