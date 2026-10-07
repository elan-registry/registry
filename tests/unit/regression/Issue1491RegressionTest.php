<?php

declare(strict_types=1);

use ElanRegistry\Car\CarValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Issue #1491: CarValidator did not trim fname/lname, unlike city/state/country.
 *
 * @issue 1491
 * @link https://github.com/elan-registry/registry/issues/1491
 */
#[Group('regression')]
final class Issue1491RegressionTest extends TestCase
{
    private CarValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new CarValidator();
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function nameFieldProvider(): array
    {
        return [
            'fname with leading/trailing whitespace' => ['fname', '  John  ', 'John'],
            'lname with leading/trailing whitespace' => ['lname', '  Smith  ', 'Smith'],
            'fname with tabs and newlines'           => ['fname', "\tJohn\n", 'John'],
            'lname with tabs and newlines'           => ['lname', "\tSmith\n", 'Smith'],
        ];
    }

    #[DataProvider('nameFieldProvider')]
    public function testValidateAndSanitizeFieldsTrimsNameField(string $field, string $input, string $expected): void
    {
        $result = $this->validator->validateAndSanitizeFields([$field => $input], false);

        $this->assertSame($expected, $result[$field]);
    }

    public function testValidateAndSanitizeFieldsDropsEmptyFname(): void
    {
        $result = $this->validator->validateAndSanitizeFields(['fname' => ''], false);

        $this->assertArrayNotHasKey('fname', $result);
    }

    public function testValidateAndSanitizeFieldsDropsEmptyLname(): void
    {
        $result = $this->validator->validateAndSanitizeFields(['lname' => ''], false);

        $this->assertArrayNotHasKey('lname', $result);
    }

    /**
     * The 100-char cap is inherited from city/state/country, although the
     * cars.fname/lname columns are varchar(155).
     */
    #[DataProvider('longNameFieldProvider')]
    public function testValidateAndSanitizeFieldsTruncatesLongNameField(string $field, string $input, int $expectedLength): void
    {
        $result = $this->validator->validateAndSanitizeFields([$field => $input], false);

        $this->assertSame($expectedLength, mb_strlen($result[$field]));
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: int}>
     */
    public static function longNameFieldProvider(): array
    {
        return [
            'fname longer than 100 chars' => ['fname', str_repeat('a', 150), 100],
            'lname longer than 100 chars' => ['lname', str_repeat('b', 150), 100],
        ];
    }
}
