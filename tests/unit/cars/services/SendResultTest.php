<?php

declare(strict_types=1);

use ElanRegistry\Car\SendResult;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for SendResult, in particular the sentUnrecorded()/isUnrecorded()
 * pair added for #1884's bookkeeping-failure handling.
 *
 * isUnrecorded() is defined as `status === STATUS_SENT && reason !== null`.
 * That two-part definition is the whole point of testing it directly: an
 * implementation that dropped the status conjunct and checked only
 * `reason !== null` would compile, pass every other test in the suite (since
 * failed() always carries a reason too), and silently misroute every genuine
 * send failure into the admin report's "sent, but not recorded" bucket —
 * telling the admin an email went out when it never did.
 */
#[Group('fast')]
final class SendResultTest extends TestCase
{
    public function testSentHasSentStatusNoReasonAndIsNotUnrecorded(): void
    {
        $result = SendResult::sent(100);

        $this->assertSame(SendResult::STATUS_SENT, $result->status);
        $this->assertNull($result->reason);
        $this->assertFalse($result->isUnrecorded());
    }

    public function testFailedHasFailedStatusAndIsNotUnrecordedDespiteHavingAReason(): void
    {
        $result = SendResult::failed(100, 'The email could not be sent.');

        $this->assertSame(SendResult::STATUS_FAILED, $result->status);
        $this->assertSame('The email could not be sent.', $result->reason);

        // The dangerous case: failed() also carries a non-null reason, so
        // isUnrecorded() must key on status, not merely on reason !== null,
        // or every failure would be misreported as a successful-but-unrecorded send.
        $this->assertFalse($result->isUnrecorded());
    }

    public function testSentUnrecordedHasSentStatusNonNullReasonAndIsUnrecorded(): void
    {
        $result = SendResult::sentUnrecorded(100, 'Email sent, but the send could not be recorded.');

        $this->assertSame(SendResult::STATUS_SENT, $result->status);
        $this->assertSame('Email sent, but the send could not be recorded.', $result->reason);
        $this->assertTrue($result->isUnrecorded());
    }

    public function testCarIdIsPreservedAcrossAllThreeConstructors(): void
    {
        $this->assertSame(42, SendResult::sent(42)->carId);
        $this->assertSame(42, SendResult::failed(42, 'x')->carId);
        $this->assertSame(42, SendResult::sentUnrecorded(42, 'x')->carId);
    }
}
