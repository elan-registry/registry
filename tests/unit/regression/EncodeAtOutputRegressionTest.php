<?php

declare(strict_types=1);

use ElanRegistry\Input;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Issue #844: the text-field updaters once used a truthy guard that dropped a literal "0".
 *
 * @issue 844
 * @link https://github.com/unibrain1/elanregistry/issues/844
 */
#[Group('regression')]
final class EncodeAtOutputRegressionTest extends RegressionTestCase
{
    private const TESTED_FIELDS = ['comments', 'engine', 'color', 'chassis', 'website', 'fname', 'lname', 'city', 'state', 'country'];

    #[DataProvider('zeroValueFieldProvider')]
    public function testLiteralZeroIsNotDiscarded(string $field): void
    {
        $_POST[$field] = '0';

        $result = Input::raw($field);

        $this->assertSame('0', $result, "Input::raw('{$field}') must return \"0\" unchanged");
    }

    /**
     * @return array<string, array{string}>
     */
    public static function zeroValueFieldProvider(): array
    {
        $cases = [];
        foreach (self::TESTED_FIELDS as $field) {
            $cases[$field] = [$field];
        }
        return $cases;
    }
}
