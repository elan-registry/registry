<?php
if (count(get_included_files()) == 1) die(); //Direct Access Not Permitted Leave this line in place
global $validation;
if (!verifyTurnstile()) {
    $validation->addError([lang('ERR_CAP'), 'cf-turnstile-response']);
}
