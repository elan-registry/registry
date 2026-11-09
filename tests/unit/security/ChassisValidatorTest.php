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
    // Race car detection — RACE_PATTERNS by year (1963-1966 only)
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

    /**
     * @return array<string, array{int}>
     */
    public static function raceYearOutOfRangeProvider(): array
    {
        return ['year 1962' => [1962], 'year 1967' => [1967], 'year 1970' => [1970]];
    }

    #[DataProvider('raceYearOutOfRangeProvider')]
    public function testRaceCarYearOutside1963To1966Fails(int $year): void
    {
        foreach (['26-R-01', '26-S2-01'] as $chassis) {
            $result = $this->validate($chassis, $year, self::RACE_MODEL);

            $this->assertFalse($result['valid']);
            $this->assertSame('Race car year must be 1963-1966', $result['error_reason']);
            $this->assertSame('race', $result['format_type']);
        }
    }

    // -------------------------------------------------------------------------
    // Production model year range (1963-1974)
    // -------------------------------------------------------------------------

    /**
     * @return array<string, array{string, int}>
     */
    public static function productionYearOutOfRangeProvider(): array
    {
        return [
            'year 1962, 4 digits'       => ['1234', 1962],
            'year 1975, 9 characters'   => ['75081602E', 1975],
            'year 1975, 11 characters'  => ['7501019999E', 1975],
            'year 0, 4 digits'          => ['1234', 0],
        ];
    }

    #[DataProvider('productionYearOutOfRangeProvider')]
    public function testProductionYearOutside1963To1974Fails(string $chassis, int $year): void
    {
        foreach ([self::ELAN_MODEL, self::PLUS2_MODEL] as $model) {
            $result = $this->validate($chassis, $year, $model);

            $this->assertFalse($result['valid']);
            $this->assertSame('Year must be 1963-1974', $result['error_reason']);
        }
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

    public function testPost1970Year1971WrongLengthFails(): void
    {
        $result = $this->validate('1234567', 1971, self::ELAN_MODEL);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('must be 11 characters', $result['error_reason']);
        $this->assertStringNotContainsString('9 characters', $result['error_reason']);
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
        $result = $this->validate('7101019999A', 1971, self::ELAN_MODEL);

        $this->assertTrue($result['valid']);
    }

    public function testElevenCharFormatValidPlus2SuffixPasses(): void
    {
        $result = $this->validate('7101019999L', 1971, self::PLUS2_MODEL);

        $this->assertTrue($result['valid']);
    }

    public function testElevenCharFormatNonNumericBaseFails(): void
    {
        $result = $this->validate('71010199ABA', 1971, self::ELAN_MODEL);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('must be numeric', $result['error_reason']);
    }

    public function testElevenCharFormatInvalidSuffixFails(): void
    {
        $iSuffix = $this->validate('7101019999I', 1971, self::ELAN_MODEL);
        $this->assertFalse($iSuffix['valid']);
        $this->assertStringContainsString('require letter codes', $iSuffix['error_reason']);

        $zSuffix = $this->validate('7101019999Z', 1971, self::ELAN_MODEL);
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
            '11 characters, 1970' => ['70e1229060G', 1970],
            '9 characters, 1972'  => ['7e110581E', 1972],
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
        $aSuffix = $this->validate('7101019999A', 1971, self::ELAN_MODEL);
        $this->assertTrue($aSuffix['valid']);

        $jSuffix = $this->validate('7101019999J', 1971, self::ELAN_MODEL);
        $this->assertTrue($jSuffix['valid']);

        $kSuffix = $this->validate('7101019999K', 1971, self::ELAN_MODEL);
        $this->assertTrue($kSuffix['valid']);

        $lSuffix = $this->validate('7101019999L', 1971, self::ELAN_MODEL);
        $this->assertFalse($lSuffix['valid']);
        $this->assertStringContainsString('A-K (excluding I)', $lSuffix['error_reason']);
    }

    public function testGetValidSuffixesAcceptsLowercaseSuffix(): void
    {
        $result = $this->validate('7101019999a', 1971, self::ELAN_MODEL);

        $this->assertTrue($result['valid']);
    }

    public function testGetValidSuffixesPlus2Range(): void
    {
        foreach (['L', 'M', 'N'] as $suffix) {
            $result = $this->validate('7101019999' . $suffix, 1971, self::PLUS2_MODEL);
            $this->assertTrue($result['valid']);
        }

        $aSuffix = $this->validate('7101019999A', 1971, self::PLUS2_MODEL);
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
    // Issue #2613: 1972-1974 chassis format (9-char YYMMXXXXC only)
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
        $cases['Elan letter G'] = ['72110581G', 1972, self::ELAN_MODEL];
        $cases['Elan letter H'] = ['73081602H', 1973, self::ELAN_MODEL];
        $cases['Elan letter K'] = ['74010001K', 1974, self::ELAN_MODEL];
        $cases['Plus 2 letter N'] = ['74010001N', 1974, self::PLUS2_MODEL];
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
    public static function elevenCharFor1972To1974Provider(): array
    {
        return [
            'Plus 2, YY 71, 1972' => ['7100000468L', 1972, self::PLUS2_MODEL],
            'Elan, YY 71, 1972'   => ['7112290609G', 1972, self::ELAN_MODEL],
            'Elan, YY 71, 1973'   => ['7112290609G', 1973, self::ELAN_MODEL],
            'Elan, YY 70, 1974'   => ['7012290609G', 1974, self::ELAN_MODEL],
            'Elan, YY 73, 1973'   => ['7301019999B', 1973, self::ELAN_MODEL],
            'Elan, YY 73, 1974'   => ['7301019999B', 1974, self::ELAN_MODEL],
        ];
    }

    #[DataProvider('elevenCharFor1972To1974Provider')]
    public function testElevenCharChassisFailsFor1972To1974(string $chassis, int $year, string $model): void
    {
        $result = $this->validate($chassis, $year, $model);

        $this->assertFalse($result['valid']);
        $this->assertSame('1972-1974 chassis must be 9 characters YYMMXXXXC (e.g., 73081602L)', $result['error_reason']);
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
            '11 characters' => ['7112290609G'],
            '12 characters' => ['711229060912'],
        ];
    }

    #[DataProvider('otherLengthProvider')]
    public function testOtherLengthsFailFor1972To1974WithErrorNamingOnlyNineCharFormat(string $chassis): void
    {
        foreach ([1972, 1973, 1974] as $year) {
            $result = $this->validate($chassis, $year, self::ELAN_MODEL);

            $this->assertFalse($result['valid']);
            $this->assertStringContainsString('9 characters YYMMXXXXC', $result['error_reason']);
            $this->assertStringNotContainsString('YYMMBBXXXXC', $result['error_reason']);
            $this->assertStringNotContainsString('11 characters', $result['error_reason']);
        }
    }

    public function testOverrideMakesFailing1973ChassisValid(): void
    {
        $result = $this->validate('7301019999B', 1973, self::ELAN_MODEL, true);

        $this->assertTrue($result['valid']);
        $this->assertTrue($result['override_used']);
        $this->assertStringContainsString('must be 9 characters', $result['error_reason']);
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
            '1963 production'     => [1963, self::ELAN_MODEL, 'pre_1970'],
            '1962 production'     => [1962, self::ELAN_MODEL, 'year_out_of_range'],
            '1975 production'     => [1975, self::ELAN_MODEL, 'year_out_of_range'],
            '1972 plus 2'         => [1972, self::PLUS2_MODEL, 'years_1972_1974'],
            '1975 plus 2'         => [1975, self::PLUS2_MODEL, 'year_out_of_range'],
            'race model 1966'     => [1966, self::RACE_MODEL, 'race'],
            'race model 1972'     => [1972, self::RACE_MODEL, 'race'],
            'race model null year' => [null, self::RACE_MODEL, 'race'],
            'race model zero year' => [0, self::RACE_MODEL, 'race'],
            'null year'           => [null, self::ELAN_MODEL, 'year_out_of_range'],
            'zero year'           => [0, self::ELAN_MODEL, 'year_out_of_range'],
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
        foreach ([0, 1962, 1963, 1964, 1965, 1966, 1967, 1969, 1970, 1971, 1972, 1974, 1975, 1980] as $year) {
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
            // validate() rejects the year before it applies a format rule.
            'year_out_of_range' => '',
        ];

        // A chassis that fails every rule, so validate() reaches the year routing.
        $result = $this->validate('X', $year, $model);
        $group = ChassisValidator::ruleGroup($year, $model);

        $this->assertFalse($result['valid']);
        $this->assertArrayHasKey($group, $formatTypeByGroup);
        $this->assertSame($formatTypeByGroup[$group], $result['format_type']);
    }
}
