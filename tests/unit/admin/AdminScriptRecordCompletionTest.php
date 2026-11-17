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
 * against a fake database. The helper must never throw, because a failed
 * fix_script_runs write must not hide the result of the calling script. The tests
 * cover a good insert, a failed insert with and without onFailure, and an
 * onFailure callback that throws.
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
}
