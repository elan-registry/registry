<?php

require_once($abs_us_root . $us_url_root . 'users/includes/template/header1_must_include.php');

?>
<?php // Temporary: CDN URLs hardcoded here until #618 rewrites this template to Bootstrap 5 ?>
<!-- Bootstrap 4.5.3 CSS -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.5.3/dist/css/bootstrap.min.css" integrity="sha384-TX8t27EcRE3e/ihU7zmQxVncDAy5uIKz4rEkgIXeMed4M0jlfIDPvg6uqKI2xXr2" crossorigin="anonymous">
<!-- Bootswatch Simplex 4.6.0 -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootswatch/4.6.0/simplex/bootstrap.min.css" integrity="sha512-9hj+qhrmo7MUSzKG3nwkDWncL1x8e2d1wfJxufofoBMMLXlqlqvjpT0V0blusJ8CFx9fs9Ru7ICYkVrz62Q33w==" crossorigin="anonymous">
<!-- jQuery 3.7.1 -->
<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js" integrity="sha384-1H217gwSVyLSIfaLxHbE7dRb3v4mYCKbpQvzx0cegeju1MVsGrX5xXxAvs/HgeFs" crossorigin="anonymous"></script>
<!-- Bootstrap 4.5.3 JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.5.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-ho+j7jyWK8fNQe+A12Hb8AhRq26LrZ/JpcUGGOn+Y7RsweNrtN/tE3MoK7ZeZDyx" crossorigin="anonymous"></script>
<!-- Popper.js 1.16.1 -->
<script src="https://cdn.jsdelivr.net/npm/popper.js@1.16.1/dist/umd/popper.min.js" integrity="sha384-9/reFTGAW83EW2RDu2S0VKaIzap3H66lZH81PoYlFhbGU+6BZp6G7niu735Sk7lN" crossorigin="anonymous"></script>
<!-- Font Awesome: self-hosted via UserSpice -->
<link rel="stylesheet" href="<?=$us_url_root?>users/fonts/css/fontawesome.min.css">
<link rel="stylesheet" href="<?=$us_url_root?>users/fonts/css/solid.min.css">
<link rel="stylesheet" href="<?=$us_url_root?>users/fonts/css/brands.min.css">

<!-- https://jonsuh.com/hamburgers -->
<link href="<?= $us_url_root ?>usersc/templates/<?= $settings->template ?>/assets/css/hamburgers.min.css" rel="stylesheet">

<!-- Registry Application Styles - Consolidated CSS v2.12.0 -->
<link href="<?= $us_url_root ?>usersc/templates/<?= $settings->template ?>/assets/css/consolidated.min.css" rel="stylesheet">
<?php
require_once($abs_us_root . $us_url_root . 'usersc/templates/' . $settings->template . '/assets/functions/style.php');
?>
</head>
<?php require_once($abs_us_root . $us_url_root . 'users/includes/template/header3_must_include.php'); ?>