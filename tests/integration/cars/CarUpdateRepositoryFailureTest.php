<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/IntegrationTestCase.php';

use ElanRegistry\Car\CarRepository;
use ElanRegistry\Exceptions\CarDatabaseException;
use PHPUnit\Framework\Attributes\Group;

/**
 * Integration regression test for Car::update() repository-failure path
 *
 * Verifies the #934 fix: when CarRepository::update() returns false, exactly
 * one log entry is written under LOG_CATEGORY_DATABASE_ERROR and
 * CarDatabaseException is thrown. The old code had two consecutive
 * if (!$updateResult) guards — the first logged under LOG_CATEGORY_CAR_UPDATE
 * ("may indicate no changes") before the second logged the actual failure and
 * threw. That duplicate guard is now removed.
 *
 * This test lives in the integration suite (not unit) because the unit
 * bootstrap substitutes a framework mock for the Car class that does not
 * exercise the real update() logic. Here we load the real Car class and
 * inject a PHPUnit mock via Reflection for the single repository property
 * we need to control.
 *
 * @issue 934
 * @link https://github.com/unibrain1/elanregistry/issues/934
 * @see usersc/classes/Car.php Car::update()
 */
#[Group('integration')]
#[Group('car-update')]
final class CarUpdateRepositoryFailureTest extends IntegrationTestCase
{
    private \ReflectionProperty $repositoryProp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();

        $this->repositoryProp = (new \ReflectionClass(\ElanRegistry\Car\Car::class))
            ->getProperty('repository');
    }

    /**
     * Helper: build a Car with a mock repository that always returns false
     * from update().
     */
    private function carWithFailingRepo(): \ElanRegistry\Car\Car
    {
        $car = new \ElanRegistry\Car\Car();

        $mockRepo = $this->createMock(CarRepository::class);
        $mockRepo->method('update')->willReturn(false);

        $this->repositoryProp->setValue($car, $mockRepo);

        return $car;
    }

    /**
     * Core assertion: CarDatabaseException is thrown on repo update failure.
     */
    public function testUpdateThrowsCarDatabaseExceptionOnRepositoryFailure(): void
    {
        $this->expectException(CarDatabaseException::class);
        $this->expectExceptionMessage('Database update failed');

        $this->carWithFailingRepo()->update([
            'id'    => 1,
            'token' => Token::generate(),
        ]);
    }

    /**
     * Regression guard: exactly one log entry under DATABASE_ERROR, none under
     * CAR_UPDATE. Previously two guards fired two log() calls; now only one.
     */
    public function testUpdateLogsExactlyOnceUnderDatabaseErrorOnFailure(): void
    {
        $dbErrBefore = $this->countMatchingLogs('DatabaseError', 'Car update failed%');
        $carUpdBefore = $this->countMatchingLogs('CarUpdate', '%');

        try {
            $this->carWithFailingRepo()->update([
                'id'    => 1,
                'token' => Token::generate(),
            ]);
        } catch (CarDatabaseException) {
            // expected
        }

        $dbErrAfter  = $this->countMatchingLogs('DatabaseError', 'Car update failed%');
        $carUpdAfter = $this->countMatchingLogs('CarUpdate', '%');

        $this->assertSame(
            $dbErrBefore + 1,
            $dbErrAfter,
            'Car::update() must log exactly once under DatabaseError when the repository returns false'
        );

        $this->assertSame(
            $carUpdBefore,
            $carUpdAfter,
            'LOG_CATEGORY_CAR_UPDATE must not fire on update failure after #934 fix'
        );
    }

    /**
     * Count rows in the logs table matching a logtype and lognote pattern.
     *
     * @param string $logtype  Exact logtype value (e.g. 'DatabaseError')
     * @param string $lognote  LIKE pattern for lognote (e.g. 'Car update failed%')
     */
    private function countMatchingLogs(string $logtype, string $lognote): int
    {
        $result = $this->db->query(
            'SELECT COUNT(*) AS cnt FROM logs WHERE logtype = ? AND lognote LIKE ?',
            [$logtype, $lognote]
        );

        return (int) ($result->first()->cnt ?? 0);
    }
}
