<?php

declare(strict_types=1);

require_once __DIR__ . '/../../IntegrationTestCase.php';

use ElanRegistry\Car\CarRepository;
use PHPUnit\Framework\Attributes\Group;

/**
 * #1883, #1895: CarRepository profile email-suppressed reads and writes
 * against a real connection. suppressOwnerProfile()'s read-then-skip guard
 * depends on a same-value UPDATE reporting 0 affected rows; the mocks
 * cannot show that.
 */
#[Group('integration')]
#[Group('car-verification')]
final class CarRepositoryProfileEmailSuppressedTest extends IntegrationTestCase
{
    private CarRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();

        $this->repo = new CarRepository($this->db);
    }

    #[Group('fast')]
    public function testFindReturnsNullForUserWithNoProfilesRow(): void
    {
        $userId = $this->createTestUser();

        $this->assertNull(
            $this->repo->findProfileEmailSuppressed($userId),
            'A user with no profiles row must read as null, not 0 — the two are semantically '
            . 'different (no opt-out decision recorded at all, vs. an explicit "not suppressed")'
        );
    }

    #[Group('fast')]
    public function testFindReturnsZeroForProfilesRowWithFlagClear(): void
    {
        $userId = $this->createTestUser([], true);

        $this->assertSame(
            0,
            $this->repo->findProfileEmailSuppressed($userId),
            'A profiles row with the flag clear must read as 0, distinguishable from '
            . 'null (no row at all) — this is the exact distinction findProfileEmailSuppressed() exists to draw'
        );
    }

    #[Group('fast')]
    public function testFindReturnsOneAfterUpdateSetsFlag(): void
    {
        $userId = $this->createTestUser([], true);

        $this->assertTrue($this->repo->updateProfileEmailSuppressed($userId, true));

        $this->assertSame(1, $this->repo->findProfileEmailSuppressed($userId));
    }

    #[Group('fast')]
    public function testUpdateOnUserWithNoProfilesRowReturnsFalseAndInsertsNothing(): void
    {
        $userId = $this->createTestUser();

        $result = $this->repo->updateProfileEmailSuppressed($userId, true);

        $this->assertFalse(
            $result,
            'Updating a nonexistent profiles row must report false, not throw and not insert a row — '
            . 'the row-insertion decision belongs to the caller (suppressOwnerProfile() throws on this),'
            . ' not to this repository method'
        );
        $this->assertNull(
            $this->repo->findProfileEmailSuppressed($userId),
            'No profiles row may have been created as a side effect of the failed update'
        );
    }

    /**
     * A same-value UPDATE on an existing row must report false (0 rows). If
     * PDO gains MYSQL_ATTR_FOUND_ROWS, suppressOwnerProfile()'s guard breaks.
     */
    #[Group('fast')]
    public function testUpdateToSameValueOnExistingRowReturnsFalse(): void
    {
        $userId = $this->createTestUser([], true);

        // The row exists with the flag already at 0.
        $this->assertSame(0, $this->repo->findProfileEmailSuppressed($userId));

        $result = $this->repo->updateProfileEmailSuppressed($userId, false);

        $this->assertFalse(
            $result,
            'An UPDATE that writes the value a row already holds must report false (0 affected rows) — '
            . 'this is the MySQL/PDO behavior (no MYSQL_ATTR_FOUND_ROWS configured) that '
            . 'CarVerificationManager::suppressOwnerProfile()\'s read-then-skip idempotency guard depends on'
        );
        $this->assertSame(
            0,
            $this->repo->findProfileEmailSuppressed($userId),
            'The value itself must remain unchanged (still 0), not merely report false'
        );
    }

    #[Group('fast')]
    public function testUpdateToDifferentValueOnExistingRowReturnsTrueAndPersists(): void
    {
        $userId = $this->createTestUser([], true);

        $result = $this->repo->updateProfileEmailSuppressed($userId, true);

        $this->assertTrue(
            $result,
            'An UPDATE that actually changes a value on an existing row must report true'
        );
        $this->assertSame(1, $this->repo->findProfileEmailSuppressed($userId));
    }

    /**
     * The locking read returns the same null/0/1 values as the plain read.
     * CarVerificationManagerSuppressForOwnerByOwnerTest covers its
     * concurrency behavior.
     */
    #[Group('fast')]
    public function testFindForUpdateReturnsNullZeroAndOneInsideTransaction(): void
    {
        $noProfileUserId = $this->createTestUser();
        $userId = $this->createTestUser([], true);

        $this->repo->beginTransaction();
        try {
            $this->assertNull($this->repo->findProfileEmailSuppressedForUpdate($noProfileUserId));
            $this->assertSame(0, $this->repo->findProfileEmailSuppressedForUpdate($userId));
            $this->assertTrue($this->repo->updateProfileEmailSuppressed($userId, true));
            $this->assertSame(1, $this->repo->findProfileEmailSuppressedForUpdate($userId));
        } finally {
            $this->repo->rollback();
        }
    }

    /**
     * profiles.user_id is not UNIQUE. With two rows, the first at 0 and a
     * second at 1, the locking read must report 1, like the MAX() in the
     * eligibility queries. A first()-based read reported 0, so a resume
     * reported success while the second row still blocked every send.
     */
    #[Group('fast')]
    #[Group('regression')]
    public function testFindForUpdateUsesMaxAcrossDuplicateProfilesRows(): void
    {
        $userId = $this->createTestUser([], true);
        $this->db->query(
            'INSERT INTO profiles (user_id, bio, city, state, country, email_suppressed)
             SELECT user_id, bio, city, state, country, 1 FROM profiles WHERE user_id = ?',
            [$userId]
        );
        $this->assertFalse($this->db->error(), 'Test setup: the duplicate profiles row must insert');

        $this->repo->beginTransaction();
        try {
            $this->assertSame(1, $this->repo->findProfileEmailSuppressedForUpdate($userId));
        } finally {
            $this->repo->rollback();
        }
    }
}
