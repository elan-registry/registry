<?php

declare(strict_types=1);

use ElanRegistry\Cron\CronRequestGate;
use ElanRegistry\LogCategories;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Support\VerificationSettingsFakeDatabase;

require_once __DIR__ . '/../../Support/FakeDatabase.php';
require_once __DIR__ . '/../../Support/VerificationSettingsFakeDatabase.php';

/**
 * Unit tests for CronRequestGate — the cron_ip allowlist check plus
 * last-cron-request bookkeeping extracted from users/cron/cron.php (#2086).
 *
 * These are behavioral tests of the gate itself: each case drives
 * admitAndRecord() and asserts on the three observable outcomes — the return
 * value, whether recordCronRequest()'s `UPDATE ... last_cron_request_at`
 * actually reached the database (via VerificationSettingsFakeDatabase's
 * existing wasCronRequestUpdateCalled() flag), and what was logged (via the
 * $mockLogEntries global installed by tests/bootstrap-unit.php).
 *
 * The denial path's "no write" assertion is the load-bearing one: a denied
 * cron hit must never be recorded, because lastCronRequestAt() reads that
 * column as evidence the transport is alive. Recording a denied hit would
 * make a misconfigured cron_ip look like a healthy cron.
 */
#[Group('fast')]
final class CronRequestGateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        global $mockLogEntries;
        $mockLogEntries = [];
    }

    public function testDeniesAndLogsWhenRequestIpDoesNotMatchConfiguredCronIp(): void
    {
        global $mockLogEntries;

        $db = new VerificationSettingsFakeDatabase();

        $this->assertFalse(
            (new CronRequestGate($db))->admitAndRecord('203.0.113.9', '198.51.100.7'),
            'A request from an IP other than the configured cron_ip must be denied'
        );
        $this->assertFalse(
            $db->wasCronRequestUpdateCalled(),
            'A denied request must never be recorded as a successful cron hit'
        );
        $this->assertCount(1, $mockLogEntries, 'A denial must be logged exactly once');
        $this->assertSame(LogCategories::LOG_CATEGORY_CRON_REQUEST, $mockLogEntries[0]['category']);
        $this->assertStringContainsString('DENIED', $mockLogEntries[0]['message']);
        $this->assertStringContainsString(
            '203.0.113.9',
            $mockLogEntries[0]['message'],
            'The denial log must name the rejected IP so a misconfiguration is diagnosable'
        );
    }

    public function testAdmitsAndRecordsWhenRequestIpMatchesConfiguredCronIp(): void
    {
        global $mockLogEntries;

        $db = new VerificationSettingsFakeDatabase();

        $this->assertTrue((new CronRequestGate($db))->admitAndRecord('198.51.100.7', '198.51.100.7'));
        $this->assertTrue(
            $db->wasCronRequestUpdateCalled(),
            'An admitted request must record last_cron_request_at'
        );
        $this->assertSame([], $mockLogEntries, 'An admitted request must not log a denial');
    }

    public function testAdmitsLocalhostEvenWhenItDoesNotMatchConfiguredCronIp(): void
    {
        global $mockLogEntries;

        $db = new VerificationSettingsFakeDatabase();

        $this->assertTrue(
            (new CronRequestGate($db))->admitAndRecord('127.0.0.1', '198.51.100.7'),
            'Localhost is always admitted, so a local run is never locked out by cron_ip'
        );
        $this->assertTrue($db->wasCronRequestUpdateCalled());
        $this->assertSame([], $mockLogEntries);
    }

    public function testAdmitsAnyIpWhenNoCronIpIsConfigured(): void
    {
        global $mockLogEntries;

        $db = new VerificationSettingsFakeDatabase();

        $this->assertTrue(
            (new CronRequestGate($db))->admitAndRecord('203.0.113.9', ''),
            'An empty cron_ip means no restriction — every caller is admitted'
        );
        $this->assertTrue($db->wasCronRequestUpdateCalled());
        $this->assertSame([], $mockLogEntries);
    }
}
