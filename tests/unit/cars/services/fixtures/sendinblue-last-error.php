<?php

declare(strict_types=1);

// Stands in for the sendinblue plugin's sendinblueLastError() after a failed
// send, for CarVerificationSendServiceTest. Load it only in a separate process,
// because a global function cannot be removed once defined. A test sets
// $GLOBALS['mockSendinblueLastError'] to choose the error text.
function sendinblueLastError(): string
{
    return $GLOBALS['mockSendinblueLastError'] ?? '';
}
