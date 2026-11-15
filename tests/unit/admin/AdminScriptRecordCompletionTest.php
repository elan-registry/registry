<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../app/admin/includes/fix-script-core.php';

use ElanRegistry\LogCategories;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeDatabase;

use PHPUnit\Framework\Attributes\Group;

/**
 * DatabaseInterface double whose insert() always throws, standing in for a
 * fix_script_runs write failure (e.g. a schema mismatch or connection fault).
 */
final class ThrowingInsertDatabase extends FakeDatabase
{
    public function insert(string $table, array $fields = [], bool $update = false): bool
    {
        throw new \RuntimeException('simulated insert failure');
    }
}

/**
 * Unit tests for admin_script_record_completion() (app/admin/includes/fix-script-core.php),
 * covering the branches the integration test FixPagePermissionsAnalyzeRunTest does not
 * reach: the function's own failure path, and the caller-side "gate a success message
 * on whether the callback fired" pattern used at 21-Fix-Page-Permissions.php's Step-3
 * site (#1776).
 *
 * @see app/admin/includes/fix-script-core.php
 */
#[Group('fast')]
#[Group('unit')]
#[Group('admin')]
final class AdminScriptRecordCompletionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        global $mockLogEntries;
        $mockLogEntries = [];
    }

    #[Group('fast')]
    public function testSuccessfulInsertDoesNotLogOrInvokeOnFailure(): void
    {
        global $db, $mockLogEntries;
        $db = new FakeDatabase();

        $onFailureCalled = false;
        admin_script_record_completion(__FILE__, 42, function (string $msg) use (&$onFailureCalled) {
            $onFailureCalled = true;
        });

        $this->assertFalse($onFailureCalled);
        $this->assertEmpty($mockLogEntries);
    }

    #[Group('fast')]
    public function testSuccessfulInsertWithNoCallbackDoesNotError(): void
    {
        global $db, $mockLogEntries;
        $db = new FakeDatabase();

        // No $onFailure passed — must not throw, and must not log anything
        // (nothing failed, so there's nothing to record).
        admin_script_record_completion(__FILE__, 42);
        $this->assertEmpty($mockLogEntries);
    }

    #[Group('fast')]
    public function testFailedInsertLogsErrorWithScriptBasenameAndMessage(): void
    {
        global $db, $mockLogEntries;
        $db = new ThrowingInsertDatabase();

        admin_script_record_completion(__FILE__, 99);

        $this->assertCount(1, $mockLogEntries);
        $this->assertSame(99, $mockLogEntries[0]['user_id']);
        $this->assertSame(LogCategories::LOG_CATEGORY_FIX_SCRIPT_ERROR, $mockLogEntries[0]['category']);
        $this->assertStringContainsString(basename(__FILE__), $mockLogEntries[0]['message']);
        $this->assertStringContainsString('simulated insert failure', $mockLogEntries[0]['message']);
    }

    #[Group('fast')]
    public function testFailedInsertInvokesOnFailureWithWarningMessage(): void
    {
        global $db;
        $db = new ThrowingInsertDatabase();

        $receivedMessage = null;
        admin_script_record_completion(__FILE__, 1, function (string $msg) use (&$receivedMessage) {
            $receivedMessage = $msg;
        });

        $this->assertNotNull($receivedMessage);
        $this->assertStringContainsString('Could not record script completion', $receivedMessage);
    }

    #[Group('fast')]
    public function testFailedInsertWithNoCallbackDoesNotThrow(): void
    {
        global $db, $mockLogEntries;
        $db = new ThrowingInsertDatabase();

        // No $onFailure passed on a failing insert — must not throw, matching
        // the documented "never throws" contract. Still logs the failure.
        admin_script_record_completion(__FILE__, 1);
        $this->assertCount(1, $mockLogEntries);
    }

    #[Group('fast')]
    public function testOnFailureCallbackItselfThrowingIsSwallowed(): void
    {
        global $db, $mockLogEntries;
        $db = new ThrowingInsertDatabase();

        // A buggy caller-supplied callback must not escape the helper — the
        // "never throws" contract must hold even when $onFailure itself is broken.
        admin_script_record_completion(__FILE__, 7, function (string $msg): void {
            throw new \LogicException('callback bug');
        });

        // Two log entries: one for the original insert failure, one for the
        // callback's own throw.
        $this->assertCount(2, $mockLogEntries);
        $this->assertStringContainsString('simulated insert failure', $mockLogEntries[0]['message']);
        $this->assertStringContainsString('onFailure callback itself threw', $mockLogEntries[1]['message']);
        $this->assertStringContainsString('callback bug', $mockLogEntries[1]['message']);
    }

    /**
     * Reproduces 21-Fix-Page-Permissions.php's Step-3 "$recordingFailed" gating
     * pattern directly — the exact logic a /simplify pass fixed after it
     * initially shipped a double-message bug (both the failure warning and a
     * misleading "recorded" success line could print together). This locks in
     * that fix independent of the full script's securePage()/POST/CSRF gating.
     */
    #[Group('fast')]
    public function testRecordingFailedFlagPatternGatesSuccessMessageOnSuccess(): void
    {
        global $db;
        $db = new FakeDatabase();

        $messages = [];
        $recordingFailed = false;
        admin_script_record_completion(__FILE__, 1, function (string $msg) use (&$recordingFailed, &$messages) {
            $recordingFailed = true;
            $messages[] = $msg;
        });
        if (!$recordingFailed) {
            $messages[] = '✅ Script completion recorded';
        }

        $this->assertFalse($recordingFailed);
        $this->assertSame(['✅ Script completion recorded'], $messages);
    }

    #[Group('fast')]
    public function testRecordingFailedFlagPatternGatesSuccessMessageOnFailure(): void
    {
        global $db;
        $db = new ThrowingInsertDatabase();

        $messages = [];
        $recordingFailed = false;
        admin_script_record_completion(__FILE__, 1, function (string $msg) use (&$recordingFailed, &$messages) {
            $recordingFailed = true;
            $messages[] = $msg;
        });
        if (!$recordingFailed) {
            $messages[] = '✅ Script completion recorded';
        }

        $this->assertTrue($recordingFailed);
        // Exactly one message — the failure warning — never both.
        $this->assertCount(1, $messages);
        $this->assertStringContainsString('Could not record script completion', $messages[0]);
    }
}
