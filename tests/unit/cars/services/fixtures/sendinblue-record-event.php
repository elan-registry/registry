<?php

declare(strict_types=1);

// Stands in for the sendinblue plugin's sendinblueRecordEvent() (#2355), for
// BrevoWebhookEventProcessorTest. Each call goes into
// $GLOBALS['mockRecordEventCalls']. $GLOBALS['mockRecordEventMode'] picks the
// result: 'ok' returns true, 'false' returns false, 'throw' throws an
// exception whose message holds an address. Load it only in a separate
// process, because a global function cannot be removed once defined.
function sendinblueRecordEvent(mixed $messageId, mixed $event, mixed $occurredAt = null, mixed $reason = ''): bool
{
    $GLOBALS['mockRecordEventCalls'][] = [$messageId, $event, $occurredAt, $reason];

    return match ($GLOBALS['mockRecordEventMode'] ?? 'ok') {
        'false' => false,
        'throw' => throw new RuntimeException('plugin failed for leak@example.com', 42),
        default => true,
    };
}
