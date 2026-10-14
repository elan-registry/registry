<?php

declare(strict_types=1);

// Stands in for the sendinblue plugin's sendinblueLastMessageId(), for
// CarVerificationSendServiceTest. Each call returns the next value of
// $GLOBALS['mockLastMessageIds']. Load it only in a separate process,
// because a global function cannot be removed once defined.
function sendinblueLastMessageId(): ?string
{
    return array_shift($GLOBALS['mockLastMessageIds']);
}
