<?php

require_once($abs_us_root . $us_url_root . 'users/includes/template/header1_must_include.php');

// Bootstrap Core
echo html_entity_decode($settings->elan_bootstrap_css_cdn);

// Theme - https://bootswatch.com/simplex/ 
echo html_entity_decode($settings->elan_bootswatch_cdn);
// jQuery
echo html_entity_decode($settings->elan_jquery_cdn);

// Bootstrap Core CSS
echo html_entity_decode($settings->elan_bootstrap_js_cdn);

// Popper
echo html_entity_decode($settings->elan_popper_cdn);

// Custom Fonts/Animation/Styling from FontAwsome 
echo html_entity_decode($settings->elan_fontawesome_cdn);

?>

<!-- https://jonsuh.com/hamburgers -->
<link href="<?= $us_url_root ?>usersc/templates/<?= $settings->template ?>/assets/css/hamburgers.min.css" rel="stylesheet">

<!-- Registry Application Styles - Consolidated CSS v2.12.0 -->
<link href="<?= $us_url_root ?>usersc/templates/<?= $settings->template ?>/assets/css/consolidated.min.css" rel="stylesheet">
<?php
require_once($abs_us_root . $us_url_root . 'usersc/templates/' . $settings->template . '/assets/functions/style.php');
?>
</head>
<?php require_once($abs_us_root . $us_url_root . 'users/includes/template/header3_must_include.php'); ?>