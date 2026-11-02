<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Issue #2476: the transfer request endpoint must treat a send policy skip
 * of the owner notice as "owner not notified".
 *
 * sendRequest() returns a TransferNoticeOutcome. The endpoint must log a
 * line for Skipped and for Failed, and must pass the outcome to
 * sendAdminAlert(), so that the admin alert can say the owner got no notice.
 * The test reads the endpoint source with comments and whitespace removed.
 *
 * @issue 2476
 * @link https://github.com/elan-registry/registry/issues/2476
 */
#[Group('regression')]
#[Group('fast')]
final class Issue2476TransferOwnerNoticeRegressionTest extends TestCase
{
    private const ENDPOINT = __DIR__ . '/../../../app/api/cars/transfer-request.php';

    private static ?string $code = null;

    /** Code with comments and whitespace removed. String literals keep their spaces. */
    private function code(): string
    {
        if (self::$code !== null) {
            return self::$code;
        }

        $source = file_get_contents(self::ENDPOINT);
        $this->assertIsString($source);

        $code = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_WHITESPACE], true)) {
                    continue;
                }
                $code .= $token[1];
            } else {
                $code .= $token;
            }
        }
        return self::$code = $code;
    }

    /**
     * Assert a pattern against the code. A plain preg_match keeps the
     * failure output short: assertMatchesRegularExpression prints the whole file.
     */
    private function assertCodeMatches(string $pattern, string $message): void
    {
        $this->assertSame(1, preg_match($pattern, $this->code()), $message);
    }

    /** Fails without the change: the endpoint tests a bool and a skip is not logged. */
    public function testEndpointLogsOwnerMayNotBeAwareForSkippedAndFailed(): void
    {
        $this->assertCodeMatches(
            '/TransferNoticeOutcome::Skipped=>logger\([^;]*?skipped by the send policy[^;]*?owner may not be aware of this request/',
            'The Skipped arm must log that the owner may not be aware'
        );
        $this->assertCodeMatches(
            '/TransferNoticeOutcome::Failed=>logger\([^;]*?Owner notification failed[^;]*?owner may not be aware of this request/',
            'The Failed arm must log that the owner may not be aware'
        );
    }

    /** Fails without the change: sendAdminAlert() gets only the request ID. */
    public function testEndpointPassesOwnerNoticeToSendAdminAlert(): void
    {
        $this->assertCodeMatches(
            '/\$ownerNotice=\$emailService->sendRequest\(\$transferRequestId\);match\(\$ownerNotice\)/',
            'The endpoint must match on the sendRequest() outcome'
        );
        $this->assertCodeMatches(
            '/->sendAdminAlert\(\$transferRequestId,\$ownerNotice\)/',
            'The outcome must go to sendAdminAlert()'
        );
    }

    /** The requester must not learn the current owner's email settings from the response. */
    public function testEndpointResponseDoesNotRevealOwnerNotice(): void
    {
        $code = $this->code();
        $this->assertSame(
            1,
            preg_match("/ApiResponse::success\\('Transfer request submitted successfully\\.'\\).*?->send\\(\\);/", $code, $match),
            'The success response must be in the endpoint'
        );
        foreach (['ownerNotice', 'TransferNoticeOutcome', 'ownerWasNotified'] as $name) {
            $this->assertStringNotContainsString($name, $match[0], "The success response must not use $name");
        }
    }
}
