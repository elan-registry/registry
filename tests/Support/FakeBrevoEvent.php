<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * FakeBrevoEvent - Stub of \Brevo\Client\Model\GetEmailEventReportEvents
 *
 * The vendored Brevo SDK lives under usersc/plugins/sendinblue/vendor/ and is
 * not wired into the project's Composer autoloader (see
 * BrevoEventReconciliationClient's SDK_AUTOLOAD_RELATIVE_PATH), so the unit
 * suite cannot instantiate or mock the real model class. This reproduces the
 * getters BrevoEventReconciliationJob actually reads, with the same return
 * types the generated model declares (all `string`, nullable in practice
 * because the SDK leaves unset container keys as null).
 *
 * Deliberately a *named* class rather than an anonymous one, per the
 * `impureMethod.pure` rationale in CronJobGuardFakeDatabase's docblock.
 *
 * @package Tests\Support
 * @since v2.30.2
 * @see https://github.com/elan-registry/registry/issues/1889
 */
class FakeBrevoEvent
{
    public function __construct(
        private readonly mixed $email = 'owner@example.com',
        private readonly mixed $event = 'delivered',
        private readonly mixed $messageId = 'brevo-msg-1',
        private readonly mixed $tag = 'car_verification',
        private readonly mixed $date = '2026-09-08T12:00:00Z',
        private readonly mixed $reason = null,
    ) {
    }

    public function getEmail(): mixed
    {
        return $this->email;
    }

    public function getEvent(): mixed
    {
        return $this->event;
    }

    public function getMessageId(): mixed
    {
        return $this->messageId;
    }

    public function getTag(): mixed
    {
        return $this->tag;
    }

    public function getDate(): mixed
    {
        return $this->date;
    }

    public function getReason(): mixed
    {
        return $this->reason;
    }
}
