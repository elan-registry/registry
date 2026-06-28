  <?php
/**
 * Lotus Elan Registry - Homepage
 *
 * This is the main landing page for the Lotus Elan Registry website.
 * It displays registry statistics, a random featured car, and important resources.
 *
 * @package ElanRegistry
 * @version 2.8.0
 * @author Jim Boone
 */

require_once 'users/init.php';
require_once $abs_us_root . $us_url_root . 'users/includes/template/prep.php';
require_once $abs_us_root . $us_url_root . 'usersc/classes/CarView.php';
use ElanRegistry\Car\CarShowcaseService;

if (!securePage($php_self)) {
	die();
}

$showcasePool = [];
try {
    $showcasePool = CarShowcaseService::buildShowcasePool($db);
} catch (\Throwable $e) {
    logger(0, LogCategories::LOG_CATEGORY_SYSTEM_ERROR, 'Homepage showcase pool failed: ' . $e->getMessage());
}

$seriesResults = $db->query("SELECT
    CASE
        WHEN series LIKE 's1%' THEN 's1'
        WHEN series LIKE 's2%' THEN 's2'
        WHEN series LIKE 's3%' THEN 's3'
        WHEN series LIKE 's4%' THEN 's4'
        WHEN series LIKE 'sprint%' THEN 'sprint'
        WHEN series LIKE '+2%' THEN '+2'
    END as series_group,
    COUNT(*) as count
FROM cars
WHERE series LIKE 's1%' OR series LIKE 's2%' OR series LIKE 's3%' OR series LIKE 's4%' OR series LIKE 'sprint%' OR series LIKE '+2%'
GROUP BY series_group")->results();

$count = ['s1' => 0, 's2' => 0, 's3' => 0, 's4' => 0, 'sprint' => 0, '+2' => 0];

foreach ($seriesResults as $result) {
    if ($result->series_group) {
        $count[$result->series_group] = (int) $result->count;
    }
}

$notes = [
    's1'     => "900",
    's2'     => "1250",
    's3'     => "2650",
    's4'     => "2976",
    'sprint' => "900",
    '+2'     => "4526",
];

$total = 0;
$totalN = 0;
foreach ($count as $key => $value) {
    $total  += (int) $value;
    $totalN += (int) $notes[$key];
}

$yearsSince = (int) (new DateTime())->diff(new DateTime('2003-01-01'))->y;

$timelineData = [];
try {
    $timelineData = (new StatisticsDataService($db))->getTimelineData();
} catch (\Throwable $e) {
    logger(0, LogCategories::LOG_CATEGORY_SYSTEM_ERROR, 'Homepage timeline query failed: ' . $e->getMessage());
}

$timelineJson = '[]';
try {
    $timelineJson = json_encode($timelineData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
} catch (\JsonException $e) {
    logger(0, LogCategories::LOG_CATEGORY_SYSTEM_ERROR, 'Homepage timeline json_encode failed: ' . $e->getMessage());
}

?>
<div class="page-wrapper">
	<div class='container'>
		<div class='row'>
			<div class='col-lg-5 order-last order-lg-first'>
				<div class='card registry-card'>
					<div class='card-header card-header-er-primary'>
						<h1 class='mb-0 card-header-er-primary-text'><i class='fas fa-car'></i> <?php echo htmlspecialchars($settings->site_name ?? 'Lotus Elan Registry', ENT_QUOTES, 'UTF-8'); ?></h1>
						<p class='card-header-er-primary-text'>A place to document Lotus Elan and Lotus Elan Plus 2</p>
					</div>
					<div class='card-body'>
						<p>This is the Registry for the 1963 thru 1973 Lotus
							Elan and the 1967 thru 1974 Lotus Elan Plus 2. The purpose of the registry is to keep a
							history of the cars, trace the evolution of the
							Lotus Elan and to facilitate owner communication.
						</p>
						<p>The Lotus Elan Registry started in January 2003. A thread on LotusElan.net asked the question,
							<a href='http://www.lotuselan.net/forums/elan-f14/lotus-elan-register-t349.html'>
								Does anybody know if there is a Lotus Elan register?</a>
							I bashed together a registry and <?= (int) $yearsSince ?> years later
							we have over <?= (int) $total ?> cars accounted for with more added every month.
						</p>
					</div>
				</div>

				<div class='card registry-card'>
					<div class="card-header card-header-er-primary">
						<h2 class='mb-0 card-header-er-primary-text'><i class='fas fa-chart-bar'></i> How are we doing?</h2>
					</div>
					<div class="card-body">
						<div class="row g-2">
							<?php foreach ($count as $key => $value):
								$key_display = htmlspecialchars(strtoupper((string) $key), ENT_QUOTES, 'UTF-8');
								$pct = (int) $notes[$key] > 0 ? (int) round((int) $value * 100 / (int) $notes[$key]) : 0;
							?>
							<div class="col-6">
								<div class="er-stat-tile">
									<div class="er-stat-label"><?= $key_display ?></div>
									<div class="er-stat-number"><?= (int) $value ?></div>
									<div class="er-stat-label">of <?= htmlspecialchars((string) $notes[$key], ENT_QUOTES, 'UTF-8') ?> &middot; <?= $pct ?>%</div>
								</div>
							</div>
							<?php endforeach; ?>
							<?php $totalPct = $totalN > 0 ? (int) round($total * 100 / $totalN) : 0; ?>
							<div class="col-12">
								<div class="er-stat-tile d-flex align-items-center gap-3">
									<div>
										<div class="er-stat-label">TOTAL</div>
										<div class="er-stat-number"><?= number_format((int) $total) ?></div>
									</div>
									<div class="er-stat-label">of <?= number_format((int) $totalN) ?> &middot; <?= $totalPct ?>%</div>
								</div>
							</div>
						</div>
						<p class="mt-3 mb-0"><small>* - Number produced is from
								<a href="https://www.amazon.com/Authentic-Lotus-1962-1974-Marques-Models/dp/0947981950">
									Authentic Lotus Elan &amp; Plus 2 1962 - 1974 by Robinshaw and Ross</a>, page 22 and page 138.
								In cases where there is a range of values, I took the lower.</small></p>
					</div>
				</div>

			</div>
			<div class='col-lg-7 order-first order-lg-last'>
				<div class='card registry-card'>
					<div class='card-header card-header-er-primary'>
						<h2 class='mb-0 card-header-er-primary-text'><i class='fas fa-clock'></i> Recent Additions</h2>
					</div>
					<div class='card-body p-0'>
						<?php if (!empty($showcasePool)) { ?>
						<div id="car-showcase">
							<?php foreach ($showcasePool as $index => $carData) {
								$showcaseCar = new Car((int) $carData->id);
								$d = $showcaseCar->data();
							?>
							<div class="<?= $index > 0 ? 'd-none ' : '' ?>px-3 pt-3"
								 data-showcase-slide="<?= (int) $index ?>"
								 data-car-id="<?= (int) $carData->id ?>"
								 data-is-new="<?= $carData->is_new ? '1' : '0' ?>">
								<?php if ($carData->is_new) { ?>
								<div class="text-end pb-1">
									<span class="badge text-bg-primary badge-sm">NEW</span>
								</div>
								<?php } ?>
								<?= CarView::displayCarousel($showcaseCar, (int) $carData->id) ?>
								<?php
								$ownerLocation = array_filter([
									$d->city ?? '',
									$d->state ?? '',
									$d->country ?? '',
								]);
								?>
								<dl class="row g-0 mt-2 mb-0 small">
									<dt class="col-4">Owner</dt>
									<dd class="col-8 mb-1"><?= !empty($d->fname) ? htmlspecialchars(ucfirst((string) $d->fname), ENT_QUOTES, 'UTF-8') : '<em class="text-muted">Not specified</em>' ?></dd>
									<?php if (!empty($ownerLocation)) { ?>
									<dt class="col-4">Location</dt>
									<dd class="col-8 mb-1"><?= htmlspecialchars(implode(', ', $ownerLocation), ENT_QUOTES, 'UTF-8') ?></dd>
									<?php } ?>
									<dt class="col-4">Year</dt>
									<dd class="col-8 mb-1"><?= htmlspecialchars((string) $d->year, ENT_QUOTES, 'UTF-8') ?></dd>
									<dt class="col-4">Series</dt>
									<dd class="col-8 mb-1"><?= htmlspecialchars((string) $d->series, ENT_QUOTES, 'UTF-8') ?></dd>
									<dt class="col-4">Variant</dt>
									<dd class="col-8 mb-1"><?= htmlspecialchars((string) $d->variant, ENT_QUOTES, 'UTF-8') ?></dd>
									<dt class="col-4">Type</dt>
									<dd class="col-8 mb-0"><?= htmlspecialchars((string) $d->type, ENT_QUOTES, 'UTF-8') ?></dd>
								</dl>
								<div class="mt-2 mb-3">
									<a class='btn btn-primary btn-sm'
									   href='<?= htmlspecialchars($us_url_root, ENT_QUOTES, 'UTF-8') ?>app/cars/details.php?car_id=<?= (int) $carData->id ?>'>
										<i class='fas fa-eye'></i> Details
									</a>
								</div>
							</div>
							<?php } ?>
							<?php if (count($showcasePool) > 1) { ?>
							<div class="d-flex align-items-center justify-content-between px-3 pb-3">
								<button id="showcase-prev" class="btn btn-sm btn-outline-secondary" aria-label="Previous car">
									<i class="fas fa-chevron-left" aria-hidden="true"></i>
								</button>
								<span id="showcase-counter" class="small text-muted" aria-live="polite" aria-atomic="true"></span>
								<button id="showcase-next" class="btn btn-sm btn-outline-secondary" aria-label="Next car">
									<i class="fas fa-chevron-right" aria-hidden="true"></i>
								</button>
							</div>
							<?php } ?>
						</div>
						<?php } else { ?>
						<div class="text-center py-4">
							<i class="fas fa-car text-muted" style="font-size: 3rem;"></i>
							<h5 class="text-muted mt-3 mb-2">No Featured Cars Available</h5>
							<p class="text-muted">Cars are being added to the registry regularly. Check back soon!</p>
						</div>
						<?php } ?>
					</div>
				</div>

				<div class='card registry-card mt-4'>
					<div class='card-header card-header-er-primary'>
						<h2 class='mb-0 card-header-er-primary-text'><i class='fas fa-chart-line'></i> Registry Growth</h2>
					</div>
					<div class='card-body' style='height: 220px;'>
						<canvas id='homepageTimelineChart'></canvas>
					</div>
				</div>
			</div>

		</div>
		<div class="row mt-4">
			<div class="col-12">
				<div class='card registry-card'>
					<div class='card-header card-header-er-primary'>
						<h2 class='mb-0 card-header-er-primary-text'><i class='fas fa-heart'></i> Thanks</h2>
					</div>
					<div class='card-body'>
						<p class='card-text'>Thank you to the many people on the Elan mailing list and the
							Elan forums who have helped with the registry.
							The group has helped with testing, providing pictures, provided feedback on what
							should be included, and kept me motivated to improve the site.
							This is their work. I am just the one who assembled the pieces.</p>

						<p class='card-text'>Special thanks to Ross, Tim, Gary, Ed, Terry, Peter, Jeff, Nicholas,
							Alan, Christian, Michael, Stan,
							Jason and everyone else who has contributed and will continue to make the registry
							what it is, a place
							for us to obsess over little British cars.</p>
					</div>
				</div>
			</div>
		</div>
	</div>
</div>
<script>
var homepageTimelineData = <?= $timelineJson ?>;
</script>
<script src="<?= htmlspecialchars($us_url_root, ENT_QUOTES, 'UTF-8') ?>usersc/js/chart.umd.min.js"></script>
<script>
(function () {
    try {
        var canvasEl = document.getElementById('homepageTimelineChart');
        if (!canvasEl) { return; }

        var data = homepageTimelineData || [];
        var monthlyCounts = {};
        data.forEach(function (item) {
            var date = new Date(item.ctime);
            if (isNaN(date.getTime())) { return; }
            var key = date.getFullYear() + '-' + String(date.getMonth() + 1).padStart(2, '0');
            monthlyCounts[key] = (monthlyCounts[key] || 0) + 1;
        });
        var sortedMonths = Object.keys(monthlyCounts).sort();
        var cumulative = 0;
        var chartData = sortedMonths.map(function (month) {
            cumulative += monthlyCounts[month];
            return { x: month, y: cumulative };
        });
        new Chart(canvasEl.getContext('2d'), {
            type: 'line',
            data: {
                datasets: [{
                    data: chartData,
                    borderColor: '#00563F',
                    backgroundColor: '#00563F20',
                    fill: true,
                    tension: 0.4,
                    pointRadius: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    x: { type: 'category', ticks: { maxTicksLimit: 8 } },
                    y: { beginAtZero: false }
                },
                plugins: { legend: { display: false } }
            }
        });
    } catch (err) {
        console.error('[ElanRegistry] Homepage growth chart failed:', err);
    }
}());
</script>
<style>[data-showcase-slide]{transition:opacity .3s ease-in-out}</style>
<script src="<?= htmlspecialchars($us_url_root, ENT_QUOTES, 'UTF-8') ?>app/assets/js/car-showcase.min.js"></script>
<?php require_once $abs_us_root . $us_url_root . 'users/includes/html_footer.php'; ?>
