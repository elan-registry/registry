<?php
if (count(get_included_files()) == 1) die(); //Direct Access Not Permitted Leave this line in place
global $validation;
if (!verifyTurnstile()) {
    $validation->addError(['Verification could not be completed. Please try again or contact the site administrator if the problem persists.', 'cf-turnstile-response']);
}
