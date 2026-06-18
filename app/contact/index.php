<?php
/**
 * contact.php
 * Contact form for user feedback and inquiries to the registry administrators.
 *
 * Provides a simple feedback form for registered users to submit comments,
 * questions, or suggestions. Includes CSRF protection and input validation.
 *
 * @author Elan Registry Admin
 * @copyright 2025
 */
require_once '../../users/init.php';
require_once $abs_us_root.$us_url_root.'usersc/includes/elanregistry_prep.php';

if (!securePage($php_self)) {
    die();
} ?>

<div class="page-wrapper">
	<div class="container-fluid">
		<div class="page-container">
		<br>
		<div class="card registry-card">
			<div class="card-header card-header-er-primary">
				<h2 class="mb-0 card-header-er-primary-text"><i class='fas fa-comment text-primary'></i> Feedback</h2>
			</div>
			<div class="card-body">
				<!-- User Information -->
				<div class="row mb-4">
					<div class="col-md-6">
						<h5 class="text-primary"><i class="fas fa-user-circle"></i> Your Information</h5>
						<div class="bg-light p-3 rounded">
							<div class="mb-2">
								<strong><?= $user->data()->fname . ' ' . $user->data()->lname ?></strong>
							</div>
							<div class="text-muted">
								<i class="fas fa-envelope"></i> <?= $user->data()->email ?>
							</div>
							<small class="text-muted">User ID: <?= $user->data()->id ?></small>
						</div>
					</div>
					<div class="col-md-6">
						<h5 class="text-primary"><i class="fas fa-info-circle"></i> About Feedback</h5>
						<div class="bg-light p-3 rounded">
							<p class="mb-2">Help us improve the Elan Registry!</p>
							<ul class="mb-0 text-muted small">
								<li>Report bugs or issues</li>
								<li>Suggest new features</li>
								<li>Share ideas for improvements</li>
								<li>General comments or questions</li>
							</ul>
						</div>
					</div>
				</div>

				<!-- Feedback Form -->
				<form name="contactform" method="post" action="send-feedback.php" class="needs-validation" novalidate>
					<div class="mb-4">
						<label for="comments" class="form-label h5">
							<i class="fas fa-comment text-primary"></i> Your Feedback
						</label>
						<textarea 
							required 
							class="form-control" 
							name="comments" 
							id="comments"
							maxlength="1000" 
							rows="8" 
							placeholder="Share your feedback, suggestions, or report issues..."
							style="resize: vertical;"
						></textarea>
						<div class="form-text">
							<i class="fas fa-info-circle"></i> Maximum 1000 characters. Your contact information will be included for follow-up if needed.
						</div>
						<div class="invalid-feedback">
							Please enter your feedback.
						</div>
					</div>

					<!-- Hidden Fields -->
					<input type="hidden" name="id" value="<?= $user->data()->id ?>" />
					<input type="hidden" name="name" value="<?= htmlspecialchars($user->data()->fname . ' ' . $user->data()->lname, ENT_QUOTES, 'UTF-8') ?>" />
					<input type="hidden" name="email" value="<?= htmlspecialchars($user->data()->email, ENT_QUOTES, 'UTF-8') ?>" />
					<input type="hidden" name="csrf" value="<?= htmlspecialchars(Token::generate(), ENT_QUOTES, 'UTF-8'); ?>" />
					<input type="hidden" name="referrer" value="<?= htmlspecialchars($referer ?: $us_url_root, ENT_QUOTES, 'UTF-8'); ?>" />

					<!-- Submit Button -->
					<div class="d-grid gap-2 d-md-flex justify-content-md-end">
						<a href="javascript:history.back()" class="btn btn-outline-secondary me-md-2">
							<i class="fas fa-arrow-left"></i> Cancel
						</a>
						<button type="submit" class="btn btn-primary btn-lg">
							<i class="fas fa-paper-plane"></i> Send Feedback
						</button>
					</div>
				</form>
			</div> <!-- card-body -->
		</div> <!-- card -->
		</div> <!-- page-container -->
	</div> <!-- container-fluid -->
</div> <!-- page-wrapper -->


<!-- footers -->
<?php
require_once $abs_us_root . $us_url_root . 'users/includes/html_footer.php'; //custom template footer
?>
