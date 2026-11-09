<?php

declare(strict_types=1);

use ElanRegistry\ChassisValidator;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Branch-coverage tests for ChassisValidator's private validation logic.
 * @see usersc/classes/ChassisValidator.php
 */
#[Group('fast')]
#[Group('unit')]
#[Group('chassis')]
final class ChassisValidatorTest extends TestCase
{
    private const RACE_MODEL  = '26|Race|Roadster';
    private const ELAN_MODEL  = 'S1|Standard|Roadster';
    private const PLUS2_MODEL = 'Elan+2|Standard|Coupe';

    /**
     * @return array{valid: bool, chassis: string, error_reason: string, format_type: string, override_used: bool}
     */
    private function validate(
        string $chassis,
        int    $year,
        string $model,
        bool   $allowOverride = false
    ): array {
        return (new ChassisValidator())->validate($chassis, $year, $model, $allowOverride);
    }

    // -------------------------------------------------------------------------
    // Race car detection — RACE_PATTERNS by year
    // -------------------------------------------------------------------------

    public function testRaceCar1963AcceptsOnlyRFormat(): void
    {
        $pass = $this->validate('26-R-01', 1963, self::RACE_MODEL);
        $this->assertTrue($pass['valid']);

        $fail = $this->validate('26-S2-01', 1963, self::RACE_MODEL);
        $this->assertFalse($fail['valid']);
        $this->assertStringContainsString('1963 race cars must use format 26-R-xx', $fail['error_reason']);
    }

    public function testRaceCar1964AcceptsBothFormats(): void
    {
        $rFormat = $this->validate('26-R-01', 1964, self::RACE_MODEL);
        $this->assertTrue($rFormat['valid']);

        $s2Format = $this->validate('26-S2-01', 1964, self::RACE_MODEL);
        $this->assertTrue($s2Format['valid']);

        $fail = $this->validate('26-X-01', 1964, self::RACE_MODEL);
        $this->assertFalse($fail['valid']);
        $this->assertStringContainsString(
            '1964 race cars must use format 26-R-xx or 26-S2-xx',
            $fail['error_reason']
        );
    }

    public function testRaceCar1965And1966AcceptOnlyS2Format(): void
    {
        foreach ([1965, 1966] as $year) {
            $pass = $this->validate('26-S2-01', $year, self::RACE_MODEL);
            $this->assertTrue($pass['valid']);

            $fail = $this->validate('26-R-01', $year, self::RACE_MODEL);
            $this->assertFalse($fail['valid']);
            $this->assertStringContainsString('race cars must use format 26-S2-xx', $fail['error_reason']);
        }
    }

    public function testRaceCarOffListYearUsesDefaultPattern(): void
    {
        $pass = $this->validate('26-R-01', 1967, self::RACE_MODEL);
        $this->assertTrue($pass['valid']);

        $fail = $this->validate('26-S2-01', 1967, self::RACE_MODEL);
        $this->assertFalse($fail['valid']);
        $this->assertStringContainsString('race cars must use format 26-R-xx', $fail['error_reason']);
    }

    // -------------------------------------------------------------------------
    // Pre-1970 production cars
    // -------------------------------------------------------------------------

    public function testPre1970ValidFourDigitChassis(): void
    {
        $result = $this->validate('1234', 1966, self::ELAN_MODEL);

        $this->assertTrue($result['valid']);
        $this->assertSame('pre_1970', $result['format_type']);
    }

    public function testPre1970NonNumericChassisFails(): void
    {
        $result = $this->validate('12AB', 1966, self::ELAN_MODEL);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('must be numeric', $result['error_reason']);
    }

    public function testPre1970WrongLengthChassisFails(): void
    {
        $tooShort = $this->validate('123', 1966, self::ELAN_MODEL);
        $this->assertFalse($tooShort['valid']);
        $this->assertStringContainsString('must be exactly 4 digits', $tooShort['error_reason']);

        $tooLong = $this->validate('12345', 1966, self::ELAN_MODEL);
        $this->assertFalse($tooLong['valid']);
        $this->assertStringContainsString('must be exactly 4 digits', $tooLong['error_reason']);
    }

    // -------------------------------------------------------------------------
    // Post-1970 routing
    // -------------------------------------------------------------------------

    public function testPost1970Year1970RoutesFiveCharToFiveCharFormat(): void
    {
        $result = $this->validate('1234A', 1970, self::ELAN_MODEL);

        $this->assertSame('post_1970', $result['format_type']);
        $this->assertTrue($result['valid']);
    }

    public function testPost1970Year1970RoutesElevenCharToElevenCharFormat(): void
    {
        $result = $this->validate('7001019999A', 1970, self::ELAN_MODEL);

        $this->assertSame('post_1970', $result['format_type']);
        $this->assertTrue($result['valid']);
    }

    public function testPost1970Year1970WrongLengthFails(): void
    {
        $result = $this->validate('1234567', 1970, self::ELAN_MODEL);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('must be 5 characters', $result['error_reason']);
        $this->assertStringContainsString('or 11 characters', $result['error_reason']);
    }

    public function testPost1970NonTransitionYearWrongLengthFails(): void
    {
        $result = $this->validate('1234567', 1973, self::ELAN_MODEL);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('must be 11 characters', $result['error_reason']);
        $this->assertStringContainsString('or 9 characters', $result['error_reason']);
    }

    public function testPost1970Year1971NineCharChassisFailsWithElevenCharError(): void
    {
        $result = $this->validate('72110581E', 1971, self::ELAN_MODEL);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('must be 11 characters', $result['error_reason']);
        $this->assertStringNotContainsString('or 9 characters', $result['error_reason']);
    }

    public function testPost1970NonTransitionYearFiveCharLengthFails(): void
    {
        $result = $this->validate('1234A', 1971, self::ELAN_MODEL);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('must be 11 characters', $result['error_reason']);
        $this->assertStringNotContainsString('5 characters', $result['error_reason']);
    }

    // -------------------------------------------------------------------------
    // Eleven-char format
    // -------------------------------------------------------------------------

    public function testElevenCharFormatValidElanSuffixPasses(): void
    {
        $result = $this->validate('7101019999A', 1973, self::ELAN_MODEL);

        $this->assertTrue($result['valid']);
    }

    public function testElevenCharFormatValidPlus2SuffixPasses(): void
    {
        $result = $this->validate('7101019999L', 1973, self::PLUS2_MODEL);

        $this->assertTrue($result['valid']);
    }

    public function testElevenCharFormatNonNumericBaseFails(): void
    {
        $result = $this->validate('71010199ABA', 1973, self::ELAN_MODEL);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('must be numeric', $result['error_reason']);
    }

    public function testElevenCharFormatInvalidSuffixFails(): void
    {
        $iSuffix = $this->validate('7101019999I', 1973, self::ELAN_MODEL);
        $this->assertFalse($iSuffix['valid']);
        $this->assertStringContainsString('require letter codes', $iSuffix['error_reason']);

        $zSuffix = $this->validate('7101019999Z', 1973, self::ELAN_MODEL);
        $this->assertFalse($zSuffix['valid']);
        $this->assertStringContainsString('require letter codes', $zSuffix['error_reason']);
    }

    // -------------------------------------------------------------------------
    // Five-char format (1970 transition)
    // -------------------------------------------------------------------------

    public function testFiveCharFormatValidSuffixPasses(): void
    {
        $result = $this->validate('1234A', 1970, self::ELAN_MODEL);

        $this->assertTrue($result['valid']);
    }

    public function testFiveCharFormatNonNumericBaseFails(): void
    {
        $result = $this->validate('12ABA', 1970, self::ELAN_MODEL);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('must be numeric', $result['error_reason']);
    }

    public function testFiveCharFormatInvalidSuffixFails(): void
    {
        $result = $this->validate('1234I', 1970, self::ELAN_MODEL);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('require letter codes', $result['error_reason']);
    }

    /**
     * Exponent forms that is_numeric() accepts as numbers.
     *
     * @return array<string, array{string, int}>
     */
    public static function exponentFormProvider(): array
    {
        return [
            '11 characters, 1972' => ['70e1229060G', 1972],
            '11 characters, 1971' => ['7e11229060G', 1971],
            '5 characters, 1970'  => ['1e23A', 1970],
            '4 digits, 1965'      => ['1e23', 1965],
        ];
    }

    #[DataProvider('exponentFormProvider')]
    public function testExponentFormInDigitPartFails(string $chassis, int $year): void
    {
        $result = $this->validate($chassis, $year, self::ELAN_MODEL);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('must be numeric', $result['error_reason']);
    }

    // -------------------------------------------------------------------------
    // getValidSuffixes distinction (Elan vs +2)
    // -------------------------------------------------------------------------

    public function testGetValidSuffixesElanRange(): void
    {
        $aSuffix = $this->validate('7101019999A', 1973, self::ELAN_MODEL);
        $this->assertTrue($aSuffix['valid']);

        $jSuffix = $this->validate('7101019999J', 1973, self::ELAN_MODEL);
        $this->assertTrue($jSuffix['valid']);

        $kSuffix = $this->validate('7101019999K', 1973, self::ELAN_MODEL);
        $this->assertTrue($kSuffix['valid']);

        $lSuffix = $this->validate('7101019999L', 1973, self::ELAN_MODEL);
        $this->assertFalse($lSuffix['valid']);
        $this->assertStringContainsString('A-K (excluding I)', $lSuffix['error_reason']);
    }

    public function testGetValidSuffixesAcceptsLowercaseSuffix(): void
    {
        $result = $this->validate('7101019999a', 1973, self::ELAN_MODEL);

        $this->assertTrue($result['valid']);
    }

    public function testGetValidSuffixesPlus2Range(): void
    {
        foreach (['L', 'M', 'N'] as $suffix) {
            $result = $this->validate('7101019999' . $suffix, 1973, self::PLUS2_MODEL);
            $this->assertTrue($result['valid']);
        }

        $aSuffix = $this->validate('7101019999A', 1973, self::PLUS2_MODEL);
        $this->assertFalse($aSuffix['valid']);
        $this->assertStringContainsString('L, M, N', $aSuffix['error_reason']);
    }

    // -------------------------------------------------------------------------
    // Empty/required chassis
    // -------------------------------------------------------------------------

    public function testOverrideIsNoOpWhenAlreadyValid(): void
    {
        $result = $this->validate('1234', 1966, self::ELAN_MODEL, true);

        $this->assertTrue($result['valid']);
        $this->assertFalse($result['override_used']);
    }

    public function testEmptyChassisFails(): void
    {
        $result = $this->validate('', 1973, self::ELAN_MODEL);

        $this->assertFalse($result['valid']);
        $this->assertSame('Chassis number is required', $result['error_reason']);
    }

    public function testWhitespaceOnlyChassisFails(): void
    {
        $result = $this->validate('   ', 1973, self::ELAN_MODEL);

        $this->assertFalse($result['valid']);
        $this->assertSame('Chassis number is required', $result['error_reason']);
    }

    // -------------------------------------------------------------------------
    // Issue #2613: 1972-1974 chassis formats (9-char YYMMXXXXC, 11-char YYMMBBXXXXC)
    // -------------------------------------------------------------------------

    /**
     * @return array<string, array{string, int, string}>
     */
    public static function nineCharValidProvider(): array
    {
        $cases = [];
        foreach ([1972, 1973, 1974] as $year) {
            $cases["Elan 72110581E in $year"] = ['72110581E', $year, self::ELAN_MODEL];
            $cases["Plus 2 73081602L in $year"] = ['73081602L', $year, self::PLUS2_MODEL];
        }
        $cases['lowercase suffix'] = ['73081602e', 1973, self::ELAN_MODEL];

        return $cases;
    }

    #[DataProvider('nineCharValidProvider')]
    public function testNineCharFormatPassesFor1972To1974(string $chassis, int $year, string $model): void
    {
        $result = $this->validate($chassis, $year, $model);

        $this->assertTrue($result['valid']);
        $this->assertSame('', $result['error_reason']);
        $this->assertFalse($result['override_used']);
        $this->assertSame('post_1970', $result['format_type']);
    }

    /**
     * @return array<string, array{string, int, string}>
     */
    public static function elevenCharBefore1972Provider(): array
    {
        return [
            'Plus 2, YY 71, 1972' => ['7100000468L', 1972, self::PLUS2_MODEL],
            'Elan, YY 71, 1972'   => ['7112290609G', 1972, self::ELAN_MODEL],
            'Elan, YY 71, 1973'   => ['7112290609G', 1973, self::ELAN_MODEL],
            'Elan, YY 70, 1974'   => ['7012290609G', 1974, self::ELAN_MODEL],
        ];
    }

    #[DataProvider('elevenCharBefore1972Provider')]
    public function testElevenCharChassisBuiltBefore1972PassesFor1972To1974(string $chassis, int $year, string $model): void
    {
        $result = $this->validate($chassis, $year, $model);

        $this->assertTrue($result['valid']);
        $this->assertSame('', $result['error_reason']);
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function elevenCharFrom1972Provider(): array
    {
        return [
            '7301019999B in 1973' => ['7301019999B', 1973],
            '7301019999B in 1972' => ['7301019999B', 1972],
            '7301019999B in 1974' => ['7301019999B', 1974],
            'YY 72 in 1972'       => ['7201019999B', 1972],
        ];
    }

    #[DataProvider('elevenCharFrom1972Provider')]
    public function testElevenCharChassisWithYyFrom72FailsFor1972To1974(string $chassis, int $year): void
    {
        $result = $this->validate($chassis, $year, self::ELAN_MODEL);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('must start with 70 or 71', $result['error_reason']);
        $this->assertStringContainsString('73081602L', $result['error_reason']);
    }

    public function testNineCharChassisWithYyBelow72FailsFor1972(): void
    {
        $result = $this->validate('71110581E', 1972, self::ELAN_MODEL);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('must start with 72 or higher', $result['error_reason']);
    }

    /**
     * @return array<string, array{int}>
     */
    public static function yearsBefore1972Provider(): array
    {
        return ['year 1970' => [1970], 'year 1971' => [1971]];
    }

    #[DataProvider('yearsBefore1972Provider')]
    public function testNineCharChassisFailsForYearsBefore1972(int $year): void
    {
        $result = $this->validate('72110581E', $year, self::ELAN_MODEL);

        $this->assertFalse($result['valid']);
        $this->assertStringNotContainsString('YYMMXXXXC', $result['error_reason']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function elevenCharYy1971Provider(): array
    {
        return [
            'YY 71' => ['7112290609G'],
            'YY 73' => ['7301019999B'],
            'YY 65' => ['6501019999B'],
        ];
    }

    #[DataProvider('elevenCharYy1971Provider')]
    public function testElevenCharChassisPassesFor1971WithAnyYy(string $chassis): void
    {
        $result = $this->validate($chassis, 1971, self::ELAN_MODEL);

        $this->assertTrue($result['valid']);
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function nineCharInvalidProvider(): array
    {
        return [
            'letter in digits'        => ['7211058AE', self::ELAN_MODEL, 'First 8 characters must be numeric'],
            'letter I on Elan'        => ['73081602I', self::ELAN_MODEL, 'A-K (excluding I)'],
            'Plus 2 letter on Elan'   => ['73081602L', self::ELAN_MODEL, 'A-K (excluding I)'],
            'Elan letter on Plus 2'   => ['72110581E', self::PLUS2_MODEL, 'L, M, N'],
            'all letters as base'     => ['ABCDEFGHE', self::ELAN_MODEL, 'First 8 characters must be numeric'],
        ];
    }

    #[DataProvider('nineCharInvalidProvider')]
    public function testNineCharFormatInvalidChassisFails(string $chassis, string $model, string $expected): void
    {
        $result = $this->validate($chassis, 1973, $model);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString($expected, $result['error_reason']);
    }

    public function testNineCharChassisWithSpaceFailsAsInvalidCharacters(): void
    {
        $result = $this->validate('7208058 H', 1972, self::ELAN_MODEL);

        $this->assertFalse($result['valid']);
        $this->assertSame('Chassis number contains invalid characters', $result['error_reason']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function otherLengthProvider(): array
    {
        return [
            '7 characters'  => ['7211058'],
            '8 characters'  => ['7211058E'],
            '10 characters' => ['7211058101'],
            '12 characters' => ['711229060912'],
        ];
    }

    #[DataProvider('otherLengthProvider')]
    public function testOtherLengthsFailFor1972To1974WithErrorNamingBothFormats(string $chassis): void
    {
        foreach ([1972, 1973, 1974] as $year) {
            $result = $this->validate($chassis, $year, self::ELAN_MODEL);

            $this->assertFalse($result['valid']);
            $this->assertStringContainsString('YYMMBBXXXXC', $result['error_reason']);
            $this->assertStringContainsString('YYMMXXXXC', $result['error_reason']);
        }
    }

    public function testOverrideMakesFailing1973ChassisValid(): void
    {
        $result = $this->validate('7301019999B', 1973, self::ELAN_MODEL, true);

        $this->assertTrue($result['valid']);
        $this->assertTrue($result['override_used']);
        $this->assertStringContainsString('must start with 70 or 71', $result['error_reason']);
    }

    // -------------------------------------------------------------------------
    // ruleGroup() — the rule group that validate() applies to a car
    // -------------------------------------------------------------------------

    /**
     * @return array<string, array{int|null, string, string}>
     */
    public static function ruleGroupProvider(): array
    {
        return [
            '1969 production'     => [1969, self::ELAN_MODEL, 'pre_1970'],
            '1970 production'     => [1970, self::ELAN_MODEL, 'year_1970'],
            '1971 production'     => [1971, self::PLUS2_MODEL, 'year_1971'],
            '1972 production'     => [1972, self::ELAN_MODEL, 'years_1972_1974'],
            '1974 production'     => [1974, self::PLUS2_MODEL, 'years_1972_1974'],
            '1975 production'     => [1975, self::ELAN_MODEL, 'from_1975'],
            '1972 plus 2'         => [1972, self::PLUS2_MODEL, 'years_1972_1974'],
            '1975 plus 2'         => [1975, self::PLUS2_MODEL, 'from_1975'],
            'race model 1972'     => [1972, self::RACE_MODEL, 'race'],
            'race model null year' => [null, self::RACE_MODEL, 'race'],
            'race model zero year' => [0, self::RACE_MODEL, 'race'],
            'null year'           => [null, self::ELAN_MODEL, 'year_empty'],
            'zero year'           => [0, self::ELAN_MODEL, 'year_empty'],
            'unparseable model'   => [1972, 'S1|Standard', 'model_unreadable'],
        ];
    }

    #[DataProvider('ruleGroupProvider')]
    public function testRuleGroupReturnsExpectedKeyForYearAndModel(?int $year, string $model, string $expected): void
    {
        $group = ChassisValidator::ruleGroup($year, $model);

        $this->assertSame($expected, $group);
        $this->assertArrayHasKey($group, ChassisValidator::RULE_GROUPS);
    }

    /**
     * Cars with a non-empty year, for the ruleGroup()/validate() agreement test.
     *
     * @return array<string, array{int, string}>
     */
    public static function ruleGroupAgreementProvider(): array
    {
        $cases = [];
        foreach ([1963, 1964, 1965, 1969, 1970, 1971, 1972, 1974, 1975, 1980] as $year) {
            $cases["race $year"] = [$year, self::RACE_MODEL];
            $cases["elan $year"] = [$year, self::ELAN_MODEL];
            $cases["plus 2 $year"] = [$year, self::PLUS2_MODEL];
        }
        return $cases;
    }

    #[DataProvider('ruleGroupAgreementProvider')]
    public function testRuleGroupMatchesFormatTypeThatValidateApplies(int $year, string $model): void
    {
        $formatTypeByGroup = [
            'race' => 'race',
            'pre_1970' => 'pre_1970',
            'year_1970' => 'post_1970',
            'year_1971' => 'post_1970',
            'years_1972_1974' => 'post_1970',
            'from_1975' => 'post_1970',
        ];

        // A chassis that fails every rule, so validate() reaches the year routing.
        $result = $this->validate('X', $year, $model);
        $group = ChassisValidator::ruleGroup($year, $model);

        $this->assertFalse($result['valid']);
        $this->assertArrayHasKey($group, $formatTypeByGroup);
        $this->assertSame($formatTypeByGroup[$group], $result['format_type']);
    }
}
