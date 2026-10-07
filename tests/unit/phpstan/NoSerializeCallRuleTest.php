<?php

declare(strict_types=1);

use ElanRegistry\PHPStan\Rules\NoSerializeCallRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * @extends RuleTestCase<NoSerializeCallRule>
 */
#[Group('phpstan-rules')]
final class NoSerializeCallRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new NoSerializeCallRule();
    }

    public function testReportsSerializeAndUnserializeFunctionCalls(): void
    {
        $this->analyse([__DIR__ . '/fixtures/serialize/serialize-calls.php'], [
            ['Do not call serialize(). It can cause PHP object injection. Use json_encode() and json_decode().', 3],
            ['Do not call unserialize(). It can cause PHP object injection. Use json_encode() and json_decode().', 4],
        ]);
    }
}
