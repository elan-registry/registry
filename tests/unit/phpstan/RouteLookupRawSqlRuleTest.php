<?php

declare(strict_types=1);

use ElanRegistry\PHPStan\Rules\RouteLookupRawSqlRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * @extends RuleTestCase<RouteLookupRawSqlRule>
 */
#[Group('phpstan-rules')]
final class RouteLookupRawSqlRuleTest extends RuleTestCase
{
    private const FIXTURE_ROOT = __DIR__ . '/fixtures/route-lookup';
    private const ERROR = 'Raw SELECT FROM cars/users in an action file. Use new Car() or new Owner() for the lookup (#962).';

    protected function getRule(): Rule
    {
        return new RouteLookupRawSqlRule(self::FIXTURE_ROOT, ['app/api/listed.php']);
    }

    public function testReportsRawLookupsInListedFile(): void
    {
        $this->analyse([self::FIXTURE_ROOT . '/app/api/listed.php'], [
            [self::ERROR, 3],
            [self::ERROR, 7],
        ]);
    }

    public function testIgnoresUnlistedFiles(): void
    {
        $this->analyse([self::FIXTURE_ROOT . '/app/api/unlisted.php'], []);
    }
}
