<?php if (count(get_included_files()) == 1) {
	die();
} //Direct Access Not Permitted Leave this line in place
?>

<?php
/*
This will display the users Profile information

*/
global $userId;

$user_id = $userId;

$thatUser = null;
$userQ = $db->query("SELECT * FROM profiles WHERE user_id = ?", array($user_id));
if ($userQ->count() > 0) {
	$thatUser = $userQ->results();
}

$thatCar = null;
$carQ = $db->query("SELECT c.* FROM cars c WHERE c.user_id = ? ORDER BY c.model, c.year", array($user_id));
if ($carQ->count() > 0) {
	$thatCar = $carQ->results();
}

$esc = fn($value) => htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');

?>
<table id="accounttable" class="table table-striped table-bordered table-sm">
	<?php if (isset($thatUser)) { ?>
		<tr>
			<td><strong>City : </strong></td>
			<td><?= $esc($thatUser[0]->city) ?></td>
		</tr>
		<tr>
			<td><strong>State : </strong></td>
			<td><?= $esc($thatUser[0]->state) ?></td>
		</tr>
		<tr>
			<td><strong>Country : </strong></td>
			<td><?= $esc($thatUser[0]->country) ?></td>
		</tr>
		<tr>
			<td><strong>LAT : </strong></td>
			<td><?= $esc($thatUser[0]->lat) ?></td>
		</tr>
		<tr>
			<td><strong>LON : </strong></td>
			<td><?= $esc($thatUser[0]->lon) ?></td>
		</tr>
	<?php } else { ?>
		<tr>
			<td colspan="2"><em class="text-muted">No profile data</em></td>
		</tr>
	<?php } ?>
	<?php if (isset($thatCar)) { ?>
		<tr>
			<td><strong>CAR ID : </strong></td>
			<td><?= $esc($thatCar[0]->id) ?></td>
		</tr>
		<tr>
			<td><strong>YEAR : </strong></td>
			<td><?= $esc($thatCar[0]->year) ?></td>
		</tr>
		<tr>
			<td><strong>TYPE : </strong></td>
			<td><?= $esc($thatCar[0]->type) ?></td>
		</tr>
		<tr>
			<td><strong>CHASSIS : </strong></td>
			<td><?= $esc($thatCar[0]->chassis) ?></td>
		</tr>
	<?php } else { ?>
		<tr>
			<td colspan="2"><em class="text-muted">No cars registered</em></td>
		</tr>
	<?php } ?>
</table>
