<?php

declare(strict_types=1);

use ElanRegistry\Car\Car;
use ElanRegistry\Car\CarRepository;
use ElanRegistry\DatabaseInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the $isOwnerInitiated flag on Car::update()
 *
 * Owner-facing edits (app/api/cars/save.php::updateCar()) refresh the car's
 * owner_last_updated freshness timestamp; every other caller keeps the
 * pre-existing behavior via the default false. The refresh is folded into
 * the same updateCar() call as the rest of the edit — not a second UPDATE —
 * so a genuine owner edit produces exactly one cars_hist audit row.
 *
 * @see https://github.com/elan-registry/registry/issues/1155
 */
#[Group('fast')]
final class CarUpdateOwnerInitiatedTest extends TestCase
{
    /**
     * Declared type stays CarRepository so reflection injection below is accepted
     * without a cast — the intersection with MockObject (needed for
     * ->expects()/->method()) is expressed via @var.
     *
     * @var CarRepository&\PHPUnit\Framework\MockObject\MockObject
     */
    private CarRepository $mockRepo;
    private Car $car;

    protected function setUp(): void
    {
        $this->mockRepo = $this->createMock(CarRepository::class);

        // Car builds its own CarRepository lazily from the injected database
        // handle, so the mock is placed into the private property directly.
        $this->car = new Car(null, $this->createStub(DatabaseInterface::class));
        (new ReflectionProperty(Car::class, 'repository'))->setValue($this->car, $this->mockRepo);
    }

    public function testOwnerInitiatedUpdateIncludesOwnerLastUpdatedInSingleCall(): void
    {
        $this->mockRepo->expects($this->once())
            ->method('updateCar')
            ->with(42, $this->callback(
                fn(array $fields) => array_key_exists('owner_last_updated', $fields)
                    && is_string($fields['owner_last_updated'])
                    && $fields['owner_last_updated'] !== ''
            ))
            ->willReturn(true);
        $this->mockRepo->method('findById')->willReturn(null);

        $this->assertTrue($this->car->update(['id' => 42, 'color' => 'Carnival Red'], true));
    }

    public function testDefaultUpdateOmitsOwnerLastUpdated(): void
    {
        $this->mockRepo->expects($this->once())
            ->method('updateCar')
            ->with(42, $this->callback(
                fn(array $fields) => !array_key_exists('owner_last_updated', $fields)
            ))
            ->willReturn(true);
        $this->mockRepo->method('findById')->willReturn(null);

        $this->assertTrue($this->car->update(['id' => 42, 'color' => 'Carnival Red']));
    }

    /**
     * A single UPDATE means there is no separate best-effort write to fail —
     * the freshness timestamp either commits with the rest of the edit or the
     * whole update fails, same as any other field.
     */
    public function testOwnerInitiatedUpdateFailurePropagatesLikeAnyOtherField(): void
    {
        $this->mockRepo->expects($this->once())->method('updateCar')->willReturn(false);

        $this->expectException(\ElanRegistry\Exceptions\CarDatabaseException::class);

        $this->car->update(['id' => 42, 'color' => 'Carnival Red'], true);
    }
}
