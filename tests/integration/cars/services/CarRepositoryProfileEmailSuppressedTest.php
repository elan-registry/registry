<?php

declare(strict_types=1);

require_once __DIR__ . '/../../IntegrationTestCase.php';

use ElanRegistry\Car\CarRepository;
use PHPUnit\Framework\Attributes\Group;

/**
 * Real-DB integration tests for CarRepository::findProfileEmailSuppressed()
 * and CarRepository::updateProfileEmailSuppressed() (#1883).
 *
 * CarVerificationManager::suppressOwnerProfile()'s entire read-then-skip
 * design rests on a specific MySQL/PDO behavior: an UPDATE that sets a
 * column to the value it already holds reports 0 affected rows, which is
 * otherwise indistinguishable from "no matching row" (no profiles row for
 * this user). That behavior is asserted about at length in both classes'
 * docblocks but, before this file, was exercised only through mocks
 * (CarVerificationManagerTest.php) or a private test-local re-implementation
 * of the query (CarVerificationManagerSuppressForOwnerTest.php's
 * profileEmailSuppressed() helper) — never through the repository methods
 * themselves against a real connection. If PDO were ever configured with
 * MYSQL_ATTR_FOUND_ROWS (flipping rowCount() to matched-rows semantics), the
 * entire suppressOwnerProfile() read-then-skip guard would silently stop
 * doing anything useful, and nothing here or in the mocked tests would catch
 * it. These tests pin the actual runtime behavior directly.
 *
 * @see usersc/classes/Car/CarRepository.php
 * @see usersc/classes/Car/CarVerificationManager.php::suppressOwnerProfile()
 * @see https://github.com/elan-registry/registry/issues/1883
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
     * The load-bearing assertion this whole file exists for: a same-value
     * UPDATE against an EXISTING row must report false (0 affected rows) —
     * this is the exact MySQL/PDO behavior suppressOwnerProfile()'s
     * read-then-skip guard is built around. If this assertion ever starts
     * failing (e.g. because PDO gained MYSQL_ATTR_FOUND_ROWS), the guard in
     * CarVerificationManager::suppressOwnerProfile() becomes dead code and
     * every comment describing it becomes incorrect.
     */
    #[Group('fast')]
    public function testUpdateToSameValueOnExistingRowReturnsFalse(): void
    {
        $userId = $this->createTestUser([], true);

        // Confirm the starting value first — the row exists with the flag
        // already at 0 (createTestUser($withProfile: true)'s default).
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
}
