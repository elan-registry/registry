<?php
/**
 * Enhanced Head Tags with Modern SEO and Social Media Support
 * Lotus Elan Registry - Optimized for search engines and social sharing
 */
require_once $abs_us_root . $us_url_root . 'app/version.php';

// $current_origin and $php_self come from server_globals.php, loaded in
// Phase 1.11.12 (usersc/includes/loader.php). $current_origin uses the
// validated scheme and host. $php_self is Server::get('PHP_SELF'), which
// drops PATH_INFO, so a crafted path suffix does not reach the canonical.
// The canonical, og:url, and twitter:url do not use $current_url, because it
// includes the raw query string (#1960). A page that a query parameter
// identifies sets $pageCanonicalUrl from validated values before
// elanregistry_prep.php.
$site_title = $settings->site_name ?? 'Lotus Elan Registry';
$og_title = !empty($pageTitle) ? $pageTitle : $site_title;
$site_description = !empty($pageDescription)
    ? $pageDescription
    : 'Registry for the Lotus Elan (1963-1973) and Elan Plus 2 (1967-1974). Document your classic British sports car, connect with owners, and preserve automotive history.';
$pageRobots = !empty($pageRobots) ? $pageRobots : 'index, follow';
$og_image = $us_url_root . 'usersc/images/og-lotus-elan.jpg';
?>

<!-- Basic Meta Tags -->
<meta charset="utf-8">
<meta http-equiv="X-UA-Compatible" content="IE=edge">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="description" content="<?= htmlspecialchars($site_description, ENT_QUOTES, 'UTF-8') ?>">
<meta name="keywords" content="Lotus Elan, Elan Plus 2, classic cars, British sports cars, automotive registry, car documentation, vintage automobiles">
<meta name="author" content="Jim Boone">
<meta name="version" content="<?= ApplicationVersion::get(); ?>">
<meta name="robots" content="<?= htmlspecialchars($pageRobots, ENT_QUOTES, 'UTF-8') ?>">
<?php if (!empty($host)):
    $canonical_url = !empty($pageCanonicalUrl)
        ? $pageCanonicalUrl
        : \ElanRegistry\CanonicalUrl::build($current_origin, $php_self);
?>
<link rel="canonical" href="<?= htmlspecialchars($canonical_url, ENT_QUOTES, 'UTF-8') ?>">

<!-- Open Graph / Facebook -->
<meta property="og:type" content="website">
<meta property="og:url" content="<?= htmlspecialchars($canonical_url, ENT_QUOTES, 'UTF-8') ?>">
<meta property="og:title" content="<?= htmlspecialchars($og_title, ENT_QUOTES, 'UTF-8') ?>">
<meta property="og:description" content="<?= htmlspecialchars($site_description, ENT_QUOTES, 'UTF-8') ?>">
<meta property="og:image" content="<?= htmlspecialchars($og_image, ENT_QUOTES, 'UTF-8') ?>">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta property="og:image:alt" content="Lotus Elan Registry - Classic British Sports Car Documentation">
<meta property="og:site_name" content="<?= htmlspecialchars($site_title, ENT_QUOTES, 'UTF-8') ?>">

<!-- Twitter Card -->
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:url" content="<?= htmlspecialchars($canonical_url, ENT_QUOTES, 'UTF-8') ?>">
<meta name="twitter:title" content="<?= htmlspecialchars($og_title, ENT_QUOTES, 'UTF-8') ?>">
<meta name="twitter:description" content="<?= htmlspecialchars($site_description, ENT_QUOTES, 'UTF-8') ?>">
<meta name="twitter:image" content="<?= htmlspecialchars($og_image, ENT_QUOTES, 'UTF-8') ?>">
<meta name="twitter:image:alt" content="Lotus Elan Registry - Classic British Sports Car Documentation">
<?php endif; ?>

<!-- Favicon and Icons -->
<link rel="shortcut icon" href="<?= $us_url_root ?>usersc/images/favicon.ico">
<link rel="icon" type="image/png" sizes="32x32" href="<?= $us_url_root ?>usersc/images/favicon-32x32.png">
<link rel="icon" type="image/png" sizes="16x16" href="<?= $us_url_root ?>usersc/images/favicon-16x16.png">
<link rel="apple-touch-icon" sizes="180x180" href="<?= $us_url_root ?>usersc/images/apple-touch-icon.png">

<!-- Security and Policy -->
<meta name="referrer" content="strict-origin-when-cross-origin">
