<?php

declare(strict_types=1);

// Stands in for the sendinblue plugin's sendinblueLastError() after a failed
// send, for CarVerificationSendServiceTest. Load it only in a separate process,
// because a global function cannot be removed once defined.
function sendinblueLastError(): string
{
    return 'Brevo API error 401: Key not found';
}
