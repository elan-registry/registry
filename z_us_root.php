
<?php
/**
 * Userspice Root Redirector
 *
 * Determines the root URL for Userspice and redirects to it.
 * Used for path resolution and navigation consistency.
 */
$path = ['', 'users/', 'usersc/', 'app/', 'stories/', 'FIX/', 'scripts/', 'app/admin/verify/', 'app/cars/', 'app/contact/', 'app/reports/', 'app/reports/api/', 'app/cars/actions/', 'app/admin/', 'app/admin/includes/', 'stories/brian_walton/', 'stories/SGO_2F/', 'error/', 'docs/', 'docs/admin/', 'docs/faq/', 'docs/faq/admin/'];
// Only add or remove values in the $path variable separated by commas above

$abs_us_root = $_SERVER['DOCUMENT_ROOT'];

$self_path = explode("/", $_SERVER['PHP_SELF']);
$self_path_length = count($self_path);
$file_found = false;

for ($i = 1; $i < $self_path_length; $i++) {
	array_splice($self_path, $self_path_length - $i, $i);
	$us_url_root = implode("/", $self_path) . "/";
	if (file_exists($abs_us_root . $us_url_root . 'z_us_root.php')) {
		$file_found = true;
		break;
	} else {
		$file_found = false;
	}
}
// Redirect back to Userspice URL root (usually /)
header('Location: ' . $us_url_root);
exit;
