<?php
if (count(get_included_files()) == 1) die();
global $validation;
if (!verifyTurnstile()) {
    $validation->addError([lang('ERR_CAP'), 'cf-turnstile-response']);
}
