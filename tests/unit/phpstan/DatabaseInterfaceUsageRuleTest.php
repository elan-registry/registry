<?php

declare(strict_types=1);

use ElanRegistry\PHPStan\Rules\DatabaseInterfaceUsageRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * @extends RuleTestCase<DatabaseInterfaceUsageRule>
 */
#[Group('phpstan-rules')]
final class DatabaseInterfaceUsageRuleTest extends RuleTestCase
{
    private const FIXTURE_ROOT = __DIR__ . '/fixtures/database-interface';
    private const CONCRETE_TYPE_ERROR = 'Do not type a parameter or property as the concrete \DB class. Use ElanRegistry\DatabaseInterface (#1585).';

    protected function getRule(): Rule
    {
        return new DatabaseInterfaceUsageRule(self::FIXTURE_ROOT);
    }

    public function testReportsConcreteDbTypesInProductionCode(): void
    {
        $this->analyse([self::FIXTURE_ROOT . '/usersc/classes/ConcreteDbService.php'], [
            [self::CONCRETE_TYPE_ERROR, 7],
            [self::CONCRETE_TYPE_ERROR, 9],
            [self::CONCRETE_TYPE_ERROR, 13],
            [self::CONCRETE_TYPE_ERROR, 22],
        ]);
    }

    public function testAllowsConcreteDbInTheAdapter(): void
    {
        $this->analyse([self::FIXTURE_ROOT . '/usersc/classes/Database/DbAdapter.php'], []);
    }

    public function testReportsGlobalDbDoublesButNotTypesOutsideProductionCode(): void
    {
        $this->analyse([self::FIXTURE_ROOT . '/tests/GlobalDbDouble.php'], [
            ['Do not declare a global DB class. Build a small ElanRegistry\DatabaseInterface test double (#1585).', 3],
            ['Do not declare a global QueryResult class. Build a small ElanRegistry\DatabaseInterface test double (#1585).', 7],
        ]);
    }

    public function testAllowsNamespacedDbClass(): void
    {
        $this->analyse([self::FIXTURE_ROOT . '/tests/NamespacedDouble.php'], []);
    }
}
