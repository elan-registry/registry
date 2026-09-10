<?php if (count(get_included_files()) == 1) {
	die();
} //Direct Access Not Permitted Leave this line in place
?>

<?php
/*
This will display the users Profile information

*/
global $userId, $us_url_root;

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

// Verification & email state for this owner's cars (#1924). Two queries total,
// regardless of car count: one for the per-car state, one aggregate for the
// latest delivery event across every car id.
//
// This hook is included mid-render by includeHook() inside users/admin.php's
// user view, so an uncaught exception here would take down the whole page —
// profile table, car buttons, permission management and all. Both repository
// calls throw CarDatabaseException on query failure, and DB::query() prepares
// outside its own try/catch, so a failing prepare can escape as a raw
// \PDOException instead; both are caught. \Throwable deliberately is not — a
// missing dbi() or autoload failure is fatal and must propagate.
$verificationLoadError  = false;
$verificationState      = [];
$latestEvents           = [];

try {
	$verificationRepo  = new \ElanRegistry\Car\CarRepository(dbi());
	$verificationState = $verificationRepo->findVerificationStateByOwner($user_id);
	$latestEvents      = $verificationRepo->findLatestEmailEventsByCarIds(
		array_map(static fn($car) => (int) $car->id, $verificationState)
	);
} catch (\ElanRegistry\Exceptions\CarDatabaseException | \PDOException $e) {
	$verificationLoadError = true;
	// logger() itself writes to the DB (DB::query()'s prepare() is not guarded
	// by its own try/catch — see the file-header comment on why \Throwable is
	// not caught above). If the failure that landed us in this catch block is
	// connection-level rather than query-level, logger() can throw the same
	// \PDOException it is meant to record, which would otherwise escape this
	// "safe" catch and take down the whole admin user-view page. Best-effort:
	// swallow a logging failure rather than let it defeat the degradation this
	// catch exists to provide.
	try {
		logger(
			$user_id,
			\ElanRegistry\LogCategories::LOG_CATEGORY_DATABASE_ERROR,
			'user_form_hook: verification/email panel failed to load for user_id=' . $user_id
				. ': ' . $e->getMessage()
		);
	} catch (\Throwable $loggerException) {
		error_log(
			'user_form_hook: verification/email panel failed to load for user_id=' . $user_id
				. ': ' . $e->getMessage() . ' (logger() itself also failed: ' . $loggerException->getMessage() . ')'
		);
	}
}

$bouncedCount    = 0;
$suppressedCount = 0;
foreach ($verificationState as $car) {
	if ((int) $car->email_bounced) {
		$bouncedCount++;
	}
	if ((int) $car->email_suppressed) {
		$suppressedCount++;
	}
}

// Freshness is the one-year rule defined by CarRepository::freshnessSql() —
// see that method's docblock for the authoritative statement of the rule and
// its clock-consistency caveat. isFresh() is the PHP-side form of that same
// rule; this hook is its first production caller.
//
// It throws CarValidationException on a corrupt timestamp rather than guessing.
// That is caught per row, not around the whole panel: a single car with a
// zero-date owner_last_updated must not blank out the other cars' rows, which
// is the diagnosis an admin opened this panel for. The row renders "Unknown"
// instead, so the corruption is visible rather than reported as a confident
// Verified/Unverified, and the message is logged for follow-up.
$isFreshCar = static function ($car) use ($user_id): ?bool {
	// owner_last_updated is NOT NULL by schema, so this branch should be
	// unreachable. Handle it explicitly anyway rather than casting a null into
	// isFresh(): the table below renders a null as "Never", and a silent
	// (string) cast would instead surface it as an "Unknown" corruption badge
	// for a row that is merely never-updated. Not fresh either way.
	if ($car->owner_last_updated === null) {
		return false;
	}

	try {
		return \ElanRegistry\Car\CarRepository::isFresh(
			$car->last_verified === null ? null : (string) $car->last_verified,
			(string) $car->owner_last_updated
		);
	} catch (\ElanRegistry\Exceptions\CarValidationException $e) {
		// Same logger()-can-itself-throw hazard as the outer catch above:
		// best-effort logging so a DB hiccup here can't escape this per-row
		// handler and blank the whole table instead of just this row.
		try {
			logger(
				$user_id,
				\ElanRegistry\LogCategories::LOG_CATEGORY_VALIDATION_ERROR,
				'user_form_hook: unusable verification timestamps for car_id=' . (int) $car->id
					. ': ' . $e->getMessage()
			);
		} catch (\Throwable $loggerException) {
			error_log(
				'user_form_hook: unusable verification timestamps for car_id=' . (int) $car->id
					. ': ' . $e->getMessage() . ' (logger() itself also failed: ' . $loggerException->getMessage() . ')'
			);
		}
		return null;
	}
};

// Pluralizes a unit name if the count is not exactly 1.
$pluralize = static fn(int $count, string $singular): string =>
	$singular . ($count === 1 ? '' : 's');

// Renders a timestamp as "<value> (N days ago)" or "<value> (in N days)" for a
// future timestamp. UserSpice's own ago() helper lives in users/helpers/audit.php,
// which nothing requires, so it is not callable from this hook's scope — the
// relative part is formatted here.
$relativeTime = static function ($value) use ($esc, $pluralize): string {
	// Bare strtotime() is too permissive to trust here: it silently rolls a
	// zero-date ('0000-00-00 00:00:00') over to a huge negative timestamp
	// instead of failing, and this table's rows can carry exactly that value —
	// DB.php sets sql_mode = '', so MySQL both stores and returns zero-dates in
	// a DATETIME column (see CarRepository::parseTimestamp()'s own comment on
	// this same reachability). Rejecting ts <= 0 catches that corruption
	// instead of rendering a nonsense "740266 days ago" beside a row the
	// Verification column has already flagged "Unknown" for the same reason.
	$ts = $value !== null ? strtotime((string) $value) : false;
	if ($ts === false || $ts <= 0) {
		return $esc($value);
	}

	$now      = new DateTimeImmutable('now');
	$relative = (new DateTimeImmutable('@' . $ts))->diff($now);

	if ($relative->days >= 1) {
		$span = $relative->days . ' ' . $pluralize($relative->days, 'day');
	} elseif ($relative->h >= 1) {
		$span = $relative->h . ' ' . $pluralize($relative->h, 'hour');
	} else {
		$span = $relative->i . ' ' . $pluralize($relative->i, 'minute');
	}

	// occurred_at values originate from Brevo's payload, not this server's
	// clock, so a future timestamp (clock skew, a backdated event) is
	// possible. DateInterval::$invert is 1 when the diff() base (`$now`) is
	// EARLIER than the target — i.e. the target is in the future — and 0
	// otherwise; without checking it, a future timestamp renders the
	// misleading "in N days" as "N days ago".
	$suffix = $relative->invert === 1 ? 'from now' : 'ago';

	return $esc($value) . ' <span class="text-muted">(' . $esc($span) . ' ' . $suffix . ')</span>';
};

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
			<td colspan="2">
				<?php foreach ($thatCar as $car) { ?>
					<a href="<?= $esc($us_url_root) ?>app/owner/cars/details.php?car_id=<?= (int) $car->id ?>"
					   class="btn btn-sm btn-primary me-1 mb-1"
					   target="_blank">
						Car #<?= (int) $car->id ?>
					</a>
				<?php } ?>
			</td>
		</tr>
	<?php } else { ?>
		<tr>
			<td colspan="2"><em class="text-muted">No cars registered</em></td>
		</tr>
	<?php } ?>
</table>

<div class="card">
	<div class="card-header">
		<h6 class="mb-0">Verification &amp; Email</h6>
	</div>
	<div class="card-body">
		<?php if ($verificationLoadError) { ?>
			<div class="alert alert-danger">
				Verification and email status could not be loaded. The rest of this page is
				unaffected &mdash; see the admin logs for details.
			</div>
		<?php } elseif ($bouncedCount > 0 || $suppressedCount > 0) { ?>
			<div class="alert alert-warning">
				<?= (int) $bouncedCount ?> <?= $pluralize($bouncedCount, 'car') ?> bounced,
				<?= (int) $suppressedCount ?> suppressed
			</div>
		<?php } else { ?>
			<div class="alert alert-primary">No delivery problems recorded</div>
		<?php } ?>

		<p class="mb-3">
			<a href="https://app.brevo.com/transactional/email/settings/blocked-contacts"
			   target="_blank"
			   rel="noopener noreferrer">
				Brevo blocked contacts
			</a>
			<span class="d-block text-muted small">
				Search by email address there to review or remove a block at Brevo.
			</span>
		</p>

		<?php if ($verificationLoadError) { ?>
			<?php /* No per-car table: an empty $verificationState here means "unknown", not "no cars". */ ?>
		<?php } elseif ($verificationState === []) { ?>
			<p class="text-muted mb-0">No cars registered</p>
		<?php } else { ?>
			<div class="table-responsive">
				<table class="table table-sm table-striped mb-0">
					<thead>
						<tr>
							<th scope="col">Car</th>
							<th scope="col">Verification</th>
							<th scope="col">Bounced</th>
							<th scope="col">Suppressed</th>
							<th scope="col">Owner last updated</th>
							<th scope="col">Last delivery event</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($verificationState as $car) {
							$carId       = (int) $car->id;
							$latestEvent = $latestEvents[$carId] ?? null;
						?>
							<tr>
								<td>
									<a href="<?= $esc($us_url_root) ?>app/owner/cars/details.php?car_id=<?= $carId ?>"
									   class="btn btn-sm btn-primary"
									   target="_blank">
										Car #<?= $carId ?>
									</a>
								</td>
								<td>
									<?php $isFresh = $isFreshCar($car); ?>
									<?php if ($isFresh === null) { ?>
										<span class="badge text-bg-danger">Unknown</span>
										<span class="d-block small">Unusable timestamps &mdash; see logs</span>
									<?php } elseif ($isFresh) { ?>
										<span class="badge text-bg-primary">Verified</span>
									<?php } else { ?>
										<span class="badge text-bg-warning">Unverified</span>
									<?php } ?>
								</td>
								<td>
									<?php if ((int) $car->email_bounced) { ?>
										<span class="badge text-bg-danger">Bounced</span>
										<?= $esc($car->email_bounced_address) ?>
										<?php
										// Only date this column from an event that is itself a bounce.
										// The latest event may be a later `delivered` or `unique_opened`,
										// and showing its timestamp beside the Bounced badge would date
										// the bounce to the moment mail last worked. Mirrors the
										// Suppressed column's own event-type check below.
										if (
											$latestEvent !== null
											&& in_array(
												$latestEvent->event,
												\ElanRegistry\Car\EmailEventApplier::HARD_BOUNCE_EVENTS,
												true
											)
										) { ?>
											<span class="d-block small"><?= $relativeTime($latestEvent->occurred_at) ?></span>
										<?php } ?>
									<?php } else { ?>
										&mdash;
									<?php } ?>
								</td>
								<td>
									<?php if ((int) $car->email_suppressed) { ?>
										<span class="badge text-bg-warning">Suppressed</span>
										<?php
										$suppressionReason =
											$latestEvent !== null
											&& in_array(
												$latestEvent->event,
												\ElanRegistry\Car\EmailEventApplier::SUPPRESSION_EVENTS,
												true
											)
												? $latestEvent->reason
												: null;
										?>
										<?php if ($suppressionReason !== null && $suppressionReason !== '') { ?>
											<span class="d-block small"><?= $esc($suppressionReason) ?></span>
										<?php } ?>
									<?php } else { ?>
										&mdash;
									<?php } ?>
								</td>
								<td>
									<?php if ($car->owner_last_updated === null) { ?>
										Never
									<?php } else { ?>
										<?= $relativeTime($car->owner_last_updated) ?>
									<?php } ?>
								</td>
								<td>
									<?php if ($latestEvent === null) { ?>
										<span class="text-muted">No events recorded</span>
									<?php } else { ?>
										<?= $esc($latestEvent->event) ?>
										<span class="d-block small"><?= $relativeTime($latestEvent->occurred_at) ?></span>
									<?php } ?>
								</td>
							</tr>
						<?php } ?>
					</tbody>
				</table>
			</div>
		<?php } ?>
	</div>
</div>
