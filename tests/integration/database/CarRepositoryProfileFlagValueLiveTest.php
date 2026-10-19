<?php

declare(strict_types=1);

require_once __DIR__ . '/../IntegrationTestCase.php';

use ElanRegistry\Car\CarRepository;
use ElanRegistry\Exceptions\ProfileFlagValueException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * #2393 gap 1: the strict profile flag reader against a real `profiles` row.
 *
 * The app connection sets sql_mode '' (users/classes/DB.php), so a manual
 * edit can store a flag value of 2. The unit tests feed the reader typed
 * values. This file proves that the value which MySQL returns for a stored 0,
 * 1 and 2 reaches the reader in a form it handles.
 */
#[Group('integration')]
final class CarRepositoryProfileFlagValueLiveTest extends IntegrationTestCase
{
    private CarRepository $repo;
    private int $userId;
    private string $email;
    private ?string $originalSqlMode = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();

        $this->repo = new CarRepository($this->db);
        $this->email = 'flagvalue-' . uniqid() . '@example.com';
        $this->userId = $this->createTestUser(['email' => $this->email], true);
    }

    protected function tearDown(): void
    {
        $this->restoreSqlMode();
        parent::tearDown();
    }

    /** Let the test connection store a flag value outside 0 and 1. */
    private function relaxSqlMode(): void
    {
        $row = $this->db->query('SELECT @@SESSION.sql_mode AS mode')->first();
        $this->originalSqlMode = (string) $row->mode;
        $this->db->query("SET SESSION sql_mode = ''");
        $this->assertFalse($this->db->error(), 'Test setup: SET SESSION sql_mode failed: ' . $this->db->errorString());
    }

    private function restoreSqlMode(): void
    {
        if ($this->originalSqlMode !== null && $this->databaseConnected) {
            $this->db->query('SET SESSION sql_mode = ?', [$this->originalSqlMode]);
            $this->originalSqlMode = null;
        }
    }

    private function storeFlag(string $column, int $value): void
    {
        // $column comes from the data provider below, never from input.
        $this->db->query("UPDATE profiles SET {$column} = ? WHERE user_id = ?", [$value, $this->userId]);
        $this->assertFalse($this->db->error(), 'Test setup: profile update failed: ' . $this->db->errorString());
        $stored = $this->db->query("SELECT {$column} AS v FROM profiles WHERE user_id = ?", [$this->userId])->first();
        $this->assertSame($value, (int) $stored->v, "Test setup: {$column} must hold {$value}");
    }

    /**
     * @return array<string, array{0: string, 1: string}> Column, and the reader that checks it
     */
    public static function readerProvider(): array
    {
        return [
            'email_opted_out via effectiveEmailFlags'  => ['email_opted_out', 'effectiveEmailFlags'],
            'email_blocked via effectiveEmailFlags'    => ['email_blocked', 'effectiveEmailFlags'],
            'email_bounced via effectiveEmailFlags'    => ['email_bounced', 'effectiveEmailFlags'],
            'email_opted_out via findProfileOptedOut'  => ['email_opted_out', 'findProfileOptedOut'],
            'email_opted_out via setProfileOptedOut'   => ['email_opted_out', 'setProfileOptedOut'],
            'email_blocked via clearProfileBlocked'    => ['email_blocked', 'clearProfileBlocked'],
            'email_bounced via clearProfileBounced'    => ['email_bounced', 'clearProfileBounced'],
        ];
    }

    private function callReader(string $reader): mixed
    {
        return match ($reader) {
            'effectiveEmailFlags' => $this->repo->effectiveEmailFlags($this->userId),
            'findProfileOptedOut' => $this->repo->findProfileOptedOut($this->userId),
            'setProfileOptedOut'  => $this->repo->setProfileOptedOut($this->userId, false),
            'clearProfileBlocked' => $this->repo->clearProfileBlocked($this->userId),
            'clearProfileBounced' => $this->repo->clearProfileBounced($this->userId),
            default               => throw new \LogicException("Unknown reader {$reader}"),
        };
    }

    public function testStoredZeroAndOneAreReadAsFlags(): void
    {
        $flags = $this->repo->effectiveEmailFlags($this->userId);
        $this->assertFalse($flags->optedOut);
        $this->assertFalse($flags->blocked);
        $this->assertFalse($flags->bounced);
        $this->assertSame(0, $this->repo->findProfileOptedOut($this->userId));

        $this->setOwnerOptedOut($this->userId);
        $this->setOwnerBlocked($this->userId, $this->email);
        $this->setOwnerBounced($this->userId, $this->email);

        $flags = $this->repo->effectiveEmailFlags($this->userId);
        $this->assertTrue($flags->optedOut);
        $this->assertTrue($flags->blocked);
        $this->assertTrue($flags->bounced);
        $this->assertSame(1, $this->repo->findProfileOptedOut($this->userId));
        $this->assertSame(1, $this->repo->clearProfileBlocked($this->userId), 'The writer returns the old value, 1');
        $this->assertSame(1, $this->repo->clearProfileBounced($this->userId));
        $this->assertSame(1, $this->repo->setProfileOptedOut($this->userId, false));
        $this->assertSame(0, $this->repo->findProfileOptedOut($this->userId));
    }

    #[DataProvider('readerProvider')]
    public function testStoredTwoMakesTheReaderThrowProfileFlagValueException(string $column, string $reader): void
    {
        $this->relaxSqlMode();
        try {
            $this->storeFlag($column, 2);

            try {
                $this->callReader($reader);
                $this->fail("{$reader} must refuse a stored {$column} of 2");
            } catch (ProfileFlagValueException $e) {
                $this->assertStringContainsString($column, $e->getMessage());
                $this->assertStringContainsString((string) $this->userId, $e->getMessage());
                $this->assertStringNotContainsString($this->email, $e->getMessage(), 'The message must not hold an address');
            }
        } finally {
            $this->storeFlag($column, 0);
            $this->restoreSqlMode();
        }
    }
}
