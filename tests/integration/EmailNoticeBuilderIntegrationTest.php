<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

use ElanRegistry\Car\CarRepository;
use ElanRegistry\Car\EmailNoticeBuilder;
use PHPUnit\Framework\Attributes\Group;

/**
 * EmailNoticeBuilder::buildForOwner() end to end with real rows; the unit
 * tests mock CarRepository and never run its self-join queries.
 */
#[Group('integration')]
final class EmailNoticeBuilderIntegrationTest extends IntegrationTestCase
{
    private CarRepository $repo;
    private EmailNoticeBuilder $builder;
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();

        $this->repo = new CarRepository($this->db);
        $this->builder = new EmailNoticeBuilder($this->repo);
        $this->userId = $this->createTestUser();
    }

    private function insertHistRow(int $carId, string $operation, string $timestamp): void
    {
        $inserted = $this->db->insert('cars_hist', [
            'operation' => $operation,
            'car_id'    => $carId,
            'model'     => 'Elan',
            'series'    => 'S4',
            'variant'   => 'SE',
            'type'      => 'FHC',
            'chassis'   => 'ENBTEST',
            'timestamp' => $timestamp,
        ]);
        $this->assertTrue($inserted, 'Failed to seed cars_hist row: ' . $this->db->errorString());
    }

    private function insertEmailEvent(int $carId, string $email, string $event, string $occurredAt, string $messageId): void
    {
        $this->repo->insertEmailEvent($carId, $email, $event, null, $messageId, $occurredAt);
    }

    #[Group('fast')]
    public function testOwnerOptOutCauseEndToEnd(): void
    {
        // Owner clicked the opt-out link: cars.email_suppressed = 1, a
        // matching cars_hist 'EMAIL SUPPRESSED' row, and NO er_email_events
        // row (the opt-out handler never writes one).
        $email = 'optout-' . uniqid() . '@example.com';
        $carId = $this->createTestCar($this->userId, [
            'email' => $email,
            'email_suppressed' => 1,
        ]);
        $this->insertHistRow($carId, 'EMAIL SUPPRESSED', '2026-03-01 12:00:00');

        $result = $this->builder->buildForOwner($this->userId);

        $this->assertNotNull($result);
        $this->assertTrue($result['hasSuppressed']);
        $this->assertFalse($result['hasBounced']);
        $this->assertCount(1, $result['addresses']);
        $this->assertSame($email, $result['addresses'][0]['address']);
        $this->assertSame(EmailNoticeBuilder::CAUSE_OWNER_OPTOUT, $result['addresses'][0]['suppressed']['cause']);
        $this->assertSame('2026-03-01', $result['addresses'][0]['suppressed']['date']);
    }

    #[Group('fast')]
    public function testSpamCauseEndToEnd(): void
    {
        // Brevo reported a spam complaint: cars.email_suppressed = 1, a
        // matching er_email_events 'spam' row, and NO cars_hist
        // 'EMAIL SUPPRESSED' row (the webhook path never writes one).
        $email = 'spam-' . uniqid() . '@example.com';
        $carId = $this->createTestCar($this->userId, [
            'email' => $email,
            'email_suppressed' => 1,
        ]);
        $this->insertEmailEvent($carId, $email, 'spam', '2026-03-10 09:30:00', 'spam-msg-1');

        $result = $this->builder->buildForOwner($this->userId);

        $this->assertNotNull($result);
        $this->assertTrue($result['hasSuppressed']);
        $this->assertCount(1, $result['addresses']);
        $this->assertSame(EmailNoticeBuilder::CAUSE_BREVO_COMPLAINT, $result['addresses'][0]['suppressed']['cause']);
        $this->assertSame('2026-03-10', $result['addresses'][0]['suppressed']['date']);

        $this->db->query('DELETE FROM er_email_events WHERE car_id = ?', [$carId]);
    }

    #[Group('fast')]
    public function testFourAddressOverflowEndToEnd(): void
    {
        // Four distinct cars, each with its own suppressed address: all four
        // real rows must be found by findVerificationStateByOwner(), capped
        // to MAX_ADDRESSES (3), with overflowCount reporting the 4th.
        $carIds = [];
        for ($i = 1; $i <= 4; $i++) {
            $carIds[] = $this->createTestCar($this->userId, [
                'email' => "overflow-{$i}-" . uniqid() . '@example.com',
                'email_suppressed' => 1,
            ]);
        }

        $result = $this->builder->buildForOwner($this->userId);

        $this->assertNotNull($result);
        $this->assertCount(EmailNoticeBuilder::MAX_ADDRESSES, $result['addresses']);
        $this->assertSame(1, $result['overflowCount']);
        $this->assertTrue($result['hasSuppressed']);

        foreach ($carIds as $carId) {
            $this->deleteTestCar($carId);
        }
    }

    #[Group('fast')]
    public function testCleanOwnerWithNoFlaggedCarsReturnsNull(): void
    {
        $carId = $this->createTestCar($this->userId, [
            'email' => 'clean-' . uniqid() . '@example.com',
        ]);

        $result = $this->builder->buildForOwner($this->userId);

        $this->assertNull($result);

        $this->deleteTestCar($carId);
    }
}
