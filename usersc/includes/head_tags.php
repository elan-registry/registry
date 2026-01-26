<?php
/**
 * Enhanced Head Tags with Modern SEO and Social Media Support
 * Lotus Elan Registry - Optimized for search engines and social sharing
 */
require_once $abs_us_root . $us_url_root . 'app/version.php';

// Server global $current_url already available from server_globals.php
// loaded in Phase 1.11.12 (usersc/includes/loader.php)
// Uses validated Server::getScheme() and Server::get('HTTP_HOST') with proper sanitization
$site_title = $settings->site_name ?? 'Lotus Elan Registry';
$site_description = 'Registry for the Lotus Elan (1963-1973) and Elan Plus 2 (1967-1974). Document your classic British sports car, connect with owners, and preserve automotive history.';
$og_image = $us_url_root . 'usersc/templates/ElanRegistry/assets/images/og-lotus-elan.jpg';
?>

<!-- Basic Meta Tags -->
<meta charset="utf-8">
<meta http-equiv="X-UA-Compatible" content="IE=edge">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="description" content="<?= htmlspecialchars($site_description, ENT_QUOTES, 'UTF-8') ?>">
<meta name="keywords" content="Lotus Elan, Elan Plus 2, classic cars, British sports cars, automotive registry, car documentation, vintage automobiles">
<meta name="author" content="Jim Boone">
<meta name="version" content="<?= ApplicationVersion::get(); ?>">
<meta name="robots" content="index, follow">
<?php if (!empty($host)): ?>
<link rel="canonical" href="<?= htmlspecialchars($current_url, ENT_QUOTES, 'UTF-8') ?>">

<!-- Open Graph / Facebook -->
<meta property="og:type" content="website">
<meta property="og:url" content="<?= htmlspecialchars($current_url, ENT_QUOTES, 'UTF-8') ?>">
<meta property="og:title" content="<?= htmlspecialchars($site_title, ENT_QUOTES, 'UTF-8') ?>">
<meta property="og:description" content="<?= htmlspecialchars($site_description, ENT_QUOTES, 'UTF-8') ?>">
<meta property="og:image" content="<?= htmlspecialchars($og_image, ENT_QUOTES, 'UTF-8') ?>">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta property="og:image:alt" content="Lotus Elan Registry - Classic British Sports Car Documentation">
<meta property="og:site_name" content="<?= htmlspecialchars($site_title, ENT_QUOTES, 'UTF-8') ?>">

<!-- Twitter Card -->
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:url" content="<?= htmlspecialchars($current_url, ENT_QUOTES, 'UTF-8') ?>">
<meta name="twitter:title" content="<?= htmlspecialchars($site_title, ENT_QUOTES, 'UTF-8') ?>">
<meta name="twitter:description" content="<?= htmlspecialchars($site_description, ENT_QUOTES, 'UTF-8') ?>">
<meta name="twitter:image" content="<?= htmlspecialchars($og_image, ENT_QUOTES, 'UTF-8') ?>">
<meta name="twitter:image:alt" content="Lotus Elan Registry - Classic British Sports Car Documentation">
<?php endif; ?>

<!-- Favicon and Icons -->
<link rel="shortcut icon" href="<?= $us_url_root ?>usersc/templates/ElanRegistry/assets/images/favicon.ico">
<link rel="icon" type="image/png" sizes="32x32" href="<?= $us_url_root ?>usersc/templates/ElanRegistry/assets/images/favicon-32x32.png">
<link rel="icon" type="image/png" sizes="16x16" href="<?= $us_url_root ?>usersc/templates/ElanRegistry/assets/images/favicon-16x16.png">
<link rel="apple-touch-icon" sizes="180x180" href="<?= $us_url_root ?>usersc/templates/ElanRegistry/assets/images/apple-touch-icon.png">

<!-- DNS Prefetch for Performance -->
<link rel="dns-prefetch" href="//fonts.googleapis.com">
<link rel="dns-prefetch" href="//cdnjs.cloudflare.com">

<!-- Security and Policy -->
<meta name="referrer" content="strict-origin-when-cross-origin">
