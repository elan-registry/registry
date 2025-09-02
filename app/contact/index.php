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
require_once $abs_us_root.$us_url_root.'users/includes/template/prep.php';

if (!securePage($_SERVER['PHP_SELF'])) {
    die();
} ?>

<div class="page-wrapper">
	<div class="container">
	<br>
		<div class="row">
			<div class="col-md-8 offset-md-2">
				<form name="contactform" method="post" action="send-feedback.php">
					<fieldset>
						<legend><i class='fas fa-comment'></i> Feedback</legend>
						<div class="mb-3 row">
							<label for="id" class="col-sm-2 col-form-label">ID</label>
							<div class="col-sm-10">
								<input type="text" readonly class="form-control-plaintext" id="id" name="id" value=<?= $user->data()->id ?>>
							</div>
						</div>		
						<div class="mb-3 row">
							<label for="name" class="col-sm-2 col-form-label">Name</label>
							<div class="col-sm-10">
								<input type="text" readonly class="form-control-plaintext" id="name" name="name" value="<?php echo $user->data()->fname . ' ' . $user->data()->lname;?>">
							</div>
						</div>
						<div class="mb-3 row">
							<label for="email" class="col-sm-2 col-form-label">Email</label>
							<div class="col-sm-10">
								<input type="text" readonly class="form-control-plaintext" id="email" name="email" value=<?= $user->data()->email ?>>
							</div>
						</div>
						<div class="mb-3 row">
							<label for="comments" class="col-sm-2 col-form-label">Comments</label>
							<div class="col-sm-10">
								<textarea required class="form-control" name="comments" maxlength="1000" cols="60" rows="10"></textarea>
							</div>
						</div>
						
						<input type="hidden" name="csrf" value="<?= htmlspecialchars(Token::generate(), ENT_QUOTES, 'UTF-8'); ?>" />
					</fieldset>
					<input class='btn btn-primary btn-sm' type='submit' value='Submit' />
				</form>
			</div> <!-- /.col -->
		</div> <!-- /.row -->
	</div> <!-- /.container -->
</div> <!-- /.wrapper -->


<!-- footers -->
<?php
require_once $abs_us_root . $us_url_root . 'users/includes/html_footer.php'; //custom template footer
?>
