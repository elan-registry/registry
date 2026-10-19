<?php

declare(strict_types=1);

/**
 * Fixture for IntegrationTestCaseFlashHelperTest. invokeEndpoint() runs it
 * after the UserSpice bootstrap. It is not a web page and has no securePage()
 * call, so it must stay out of the web routes.
 *
 * POST 'mode': 'redirect' sets one error, one success and one warning flash
 * message and redirects. 'none' sets no flash message and prints plain text.
 */
if (($_POST['mode'] ?? '') === 'redirect') {
    usError('Fixture error one');
    usError('Fixture error two');
    usSuccess('Fixture success');
    usWarning('Fixture warning');
    Redirect::to('/index.php');
    exit;
}

echo 'fixture-plain-output';
