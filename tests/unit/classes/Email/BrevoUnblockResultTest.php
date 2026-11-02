<?php

declare(strict_types=1);

use ElanRegistry\Email\BrevoUnblockOutcome;
use ElanRegistry\Email\BrevoUnblockReason;
use ElanRegistry\Email\BrevoUnblockResult;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the BrevoUnblockResult invariants (#2357): only an HTTP
 * failure holds an HTTP status, and a success holds no reason and no status.
 */
#[Group('fast')]
final class BrevoUnblockResultTest extends TestCase
{
    public function testFailedHttpKeepsTheStatus(): void
    {
        $result = BrevoUnblockResult::failed(BrevoUnblockReason::Http, 503, 'summary');

        $this->assertSame(BrevoUnblockOutcome::Failed, $result->outcome);
        $this->assertFalse($result->isSuccess());
        $this->assertSame(BrevoUnblockReason::Http, $result->reason);
        $this->assertSame(503, $result->httpStatus);
        $this->assertSame('summary', $result->detail);
    }

    public function testFailedHttpWithNoStatusThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('(reason http, HTTP status none)');

        BrevoUnblockResult::failed(BrevoUnblockReason::Http);
    }

    /**
     * @return array<string, array{BrevoUnblockReason}>
     */
    public static function nonHttpReasonProvider(): array
    {
        $rows = [];
        foreach (BrevoUnblockReason::cases() as $reason) {
            if ($reason !== BrevoUnblockReason::Http) {
                $rows[$reason->name] = [$reason];
            }
        }

        return $rows;
    }

    #[DataProvider('nonHttpReasonProvider')]
    public function testFailedWithAStatusForAnotherReasonThrows(BrevoUnblockReason $reason): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("(reason {$reason->value}, HTTP status 500)");

        BrevoUnblockResult::failed($reason, 500);
    }

    #[DataProvider('nonHttpReasonProvider')]
    public function testFailedWithNoStatusForAnotherReasonHasNoStatus(BrevoUnblockReason $reason): void
    {
        $result = BrevoUnblockResult::failed($reason);

        $this->assertSame($reason, $result->reason);
        $this->assertNull($result->httpStatus);
    }

    public function testNotBlockedIsASuccessWithNoStatusAndNoReason(): void
    {
        $result = BrevoUnblockResult::notBlocked();

        $this->assertSame(BrevoUnblockOutcome::NotBlocked, $result->outcome);
        $this->assertTrue($result->isSuccess());
        $this->assertNull($result->reason);
        $this->assertNull($result->httpStatus);
        $this->assertNull($result->detail);
    }

    public function testRemovedIsASuccessWithNoStatusAndNoReason(): void
    {
        $result = BrevoUnblockResult::removed();

        $this->assertSame(BrevoUnblockOutcome::Removed, $result->outcome);
        $this->assertTrue($result->isSuccess());
        $this->assertNull($result->reason);
        $this->assertNull($result->httpStatus);
    }
}
