
<?php
/**
 * Userspice Root Redirector
 *
 * Determines the root URL for Userspice and redirects to it.
 * Used for path resolution and navigation consistency.
 */
$path = ['', 'users/', 'usersc/', 'app/', 'app/owner/', 'app/owner/cars/', 'app/owner/contact/', 'app/owner/reports/', 'app/api/contact/', 'app/admin/', 'app/admin/includes/', 'app/admin/includes/system/', 'app/admin/scripts/fix/', 'app/admin/scripts/maintenance/', 'app/admin/verify/', 'docs/', 'docs/guides/', 'docs/reference/', 'docs/stories/', 'docs/stories/brian_walton/', 'docs/stories/SGO_2F/'];
// Only add or remove values in the $path variable separated by commas above

$abs_us_root = Server::get('DOCUMENT_ROOT');

$self_path = explode("/", Server::get('PHP_SELF'));
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
