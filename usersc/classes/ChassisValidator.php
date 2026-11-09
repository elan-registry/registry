<?php

declare(strict_types=1);

namespace ElanRegistry;

use ElanRegistry\Car\CarValidator;
use ElanRegistry\Exceptions\CarValidationException;

/**
 * ChassisValidator.php
 * Centralized Lotus Elan chassis number validation system
 * 
 * Provides comprehensive validation for all Lotus Elan chassis numbering formats
 * including historical race car formats and production car evolution from 1963-1974.
 *
 * Sources for the rules: the Robinshaw and Ross book on the Lotus Elan, and the
 * lotuselansprint.com "Unit Numbers" and "Number Made" pages.
 *
 * Cars built from 1 January 1972 dropped the batch number (BB): YYMMBBXXXXC
 * became YYMMXXXXC. The format follows the build date (YY), not the model year,
 * so a 1972-1974 model year car built in 1970 or 1971 still has 11 characters.
 * 
 * @author Elan Registry Team
 */

class ChassisValidator 
{
    /**
     * Validation result structure
     * @var array{valid: bool, chassis: string, error_reason: string, format_type: string, override_used: bool}
     */
    private array $result = [
        'valid' => false,
        'chassis' => '',
        'error_reason' => '',
        'format_type' => '',
        'override_used' => false
    ];

    /** Valid letter codes for different model types */
    private const LETTER_CODES = [
        'elan' => ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'J', 'K'], // Excluding I
        'plus2' => ['L', 'M', 'N']
    ];

    /** Race car patterns by year */
    private const RACE_PATTERNS = [
        1963 => ['/^26-R-\d{2}$/'],
        1964 => ['/^26-R-\d{2}$/', '/^26-S2-\d{2}$/'],
        1965 => ['/^26-S2-\d{2}$/'],
        1966 => ['/^26-S2-\d{2}$/'],
        'default' => ['/^26-R-\d{2}$/']
    ];

    /** First model year of the post-1970 formats; also the 5-character transition year */
    private const FIRST_POST_1970_YEAR = 1970;

    /** First and last model years that allow the 9-character YYMMXXXXC format */
    private const FIRST_NINE_CHAR_YEAR = 1972;
    private const LAST_NINE_CHAR_YEAR = 1974;

    /**
     * Rule groups that ruleGroup() returns, in display order, with admin labels.
     * Admin reports group failing cars by these keys.
     */
    public const RULE_GROUPS = [
        'race' => 'Race cars: 26-R-xx or 26-S2-xx, by year',
        'pre_1970' => 'Model years before 1970: 4 digits',
        'year_1970' => 'Model year 1970: 5 or 11 characters',
        'year_1971' => 'Model year 1971: 11 characters YYMMBBXXXXC',
        'years_1972_1974' => 'Model years 1972-1974: 9 characters YYMMXXXXC',
        'from_1975' => 'Model years 1975 and later: 11 characters YYMMBBXXXXC',
        // A null or 0 year. validate() has no rule for it; callers report the car without validate().
        'year_empty' => 'Year empty',
        'model_unreadable' => 'Model not readable',
    ];

    /**
     * Validate chassis number with comprehensive rules
     * 
     * @param string $chassis The chassis number to validate
     * @param int $year The car year
     * @param string $model The model string (contains series, variant, type)
     * @param bool $allowOverride Whether to allow validation override
     * @return array{valid: bool, chassis: string, error_reason: string, format_type: string, override_used: bool} Validation result
     */
    public function validate(string $chassis, int $year, string $model, bool $allowOverride = false): array 
    {
        $this->result = [
            'valid' => false,
            'chassis' => trim($chassis),
            'error_reason' => '',
            'format_type' => '',
            'override_used' => false
        ];

        if (empty($this->result['chassis'])) {
            $this->result['error_reason'] = 'Chassis number is required';
            return $this->result;
        }

        // Allowlist: only digits, letters, forward-slash, and hyphen are valid in any known
        // Lotus Elan chassis format (e.g. "26-R-01", "26/0001", "11120R0001A").
        // This guard runs before the override branch so override cannot bypass char-safety.
        if (!preg_match('/^[0-9A-Za-z\/\-]+$/', $this->result['chassis'])) {
            $this->result['error_reason'] = 'Chassis number contains invalid characters';
            return $this->result;
        }

        $chassisLength = strlen($this->result['chassis']);
        
        // Parse model components
        try {
            [$series, $variant] = CarValidator::parseModel($model);
        } catch (CarValidationException $e) {
            $this->result['error_reason'] = 'Invalid model format';
            return $this->result;
        }

        // Validate based on variant (Race vs Production)
        if (self::isRaceVariant($variant)) {
            $this->validateRaceCar($this->result['chassis'], $year);
        } else {
            $this->validateProductionCar($this->result['chassis'], $year, $series, $chassisLength);
        }

        // Handle override if validation failed but override is allowed
        if (!$this->result['valid'] && $allowOverride) {
            $this->result['valid'] = true;
            $this->result['override_used'] = true;
        }

        return $this->result;
    }

    /**
     * Return the rule group that validate() applies to a car, as a key of RULE_GROUPS.
     *
     * The routing follows validate() after its chassis checks (empty chassis,
     * invalid characters): a model that does not parse comes first, then race
     * variants, then the year. One exception: a null or 0 year gives
     * 'year_empty', but validate() applies the pre-1970 rule to year 0. Do not
     * call validate() for a car with an empty year.
     *
     * @param int|null $year  The car year; null or 0 means the year is empty
     * @param string   $model The model string (series|variant|type)
     * @return key-of<self::RULE_GROUPS> Rule group key
     */
    public static function ruleGroup(?int $year, string $model): string
    {
        try {
            [, $variant] = CarValidator::parseModel($model);
        } catch (CarValidationException) {
            return 'model_unreadable';
        }

        if (self::isRaceVariant($variant)) {
            return 'race';
        }

        return match (true) {
            $year === null || $year === 0 => 'year_empty',
            $year < self::FIRST_POST_1970_YEAR => 'pre_1970',
            $year === self::FIRST_POST_1970_YEAR => 'year_1970',
            $year < self::FIRST_NINE_CHAR_YEAR => 'year_1971',
            $year <= self::LAST_NINE_CHAR_YEAR => 'years_1972_1974',
            default => 'from_1975',
        };
    }

    /**
     * Whether a model variant is a race car variant
     *
     * @param string $variant The variant part of the model string
     * @return bool True for a race variant
     */
    private static function isRaceVariant(string $variant): bool
    {
        return stripos($variant, 'Race') !== false;
    }

    /**
     * Validate race car chassis numbers
     *
     * @param string $chassis
     * @param int $year
     */
    private function validateRaceCar(string $chassis, int $year): void
    {
        $this->result['format_type'] = 'race';
        
        $patterns = self::RACE_PATTERNS[$year] ?? self::RACE_PATTERNS['default'];
        
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $chassis)) {
                $this->result['valid'] = true;
                return;
            }
        }

        $this->result['error_reason'] = match ($year) {
            1963       => '1963 race cars must use format 26-R-xx (e.g., 26-R-01)',
            1964       => '1964 race cars must use format 26-R-xx or 26-S2-xx (e.g., 26-R-01 or 26-S2-01)',
            1965, 1966 => "{$year} race cars must use format 26-S2-xx (e.g., 26-S2-01)",
            default    => "{$year} race cars must use format 26-R-xx (e.g., 26-R-01)",
        };
    }

    /**
     * Validate production car chassis numbers
     *
     * @param string $chassis
     * @param int $year
     * @param string $series
     * @param int $chassisLength
     */
    private function validateProductionCar(string $chassis, int $year, string $series, int $chassisLength): void
    {
        if ($year < self::FIRST_POST_1970_YEAR) {
            $this->validatePre1970($chassis, $chassisLength);
        } else {
            $this->validatePost1970($chassis, $year, $series, $chassisLength);
        }
    }

    /**
     * Validate pre-1970 chassis (4 digits only)
     * 
     * @param string $chassis
     * @param int $chassisLength
     */
    private function validatePre1970(string $chassis, int $chassisLength): void 
    {
        $this->result['format_type'] = 'pre_1970';
        
        if (ctype_digit($chassis) && $chassisLength === 4) {
            $this->result['valid'] = true;
        } else {
            if (!ctype_digit($chassis)) {
                $this->result['error_reason'] = 'Pre-1970 chassis must be numeric (4 digits only, e.g., 1234)';
            } else {
                $this->result['error_reason'] = 'Pre-1970 chassis must be exactly 4 digits (e.g., 1234)';
            }
        }
    }

    /**
     * Validate post-1970 chassis (YYMMBBXXXXC or YYMMXXXXC format)
     * 
     * @param string $chassis
     * @param int $year
     * @param string $series
     * @param int $chassisLength
     */
    private function validatePost1970(string $chassis, int $year, string $series, int $chassisLength): void 
    {
        $this->result['format_type'] = 'post_1970';

        // 1972-1974 model years: 9 characters if built from January 1972, else 11 characters
        if ($year >= self::FIRST_NINE_CHAR_YEAR && $year <= self::LAST_NINE_CHAR_YEAR) {
            if ($chassisLength === 9) {
                $this->validateNineCharFormat($chassis, $series);
            } elseif ($chassisLength === 11) {
                $this->validateElevenCharFormat($chassis, $series);
                if ($this->result['valid'] && !in_array(substr($chassis, 0, 2), ['70', '71'], true)) {
                    $this->result['valid'] = false;
                    $this->result['error_reason'] = 'An 11-character YYMMBBXXXXC chassis must start with 70 or 71 (built before 1972). ' .
                                                  'Cars built from January 1972 use 9 characters YYMMXXXXC (e.g., 73081602L)';
                }
            } else {
                $this->result['error_reason'] = '1972-1974 chassis must be 11 characters YYMMBBXXXXC (built before 1972, e.g., 7112290609G) ' .
                                              'or 9 characters YYMMXXXXC (built from January 1972, e.g., 73081602L)';
            }
            return;
        }

        // Standard 11-character format: YYMMBBXXXXC
        if ($chassisLength === 11) {
            $this->validateElevenCharFormat($chassis, $series);
        } 
        // 1970 transition year also allows 5-character legacy format
        elseif ($year === self::FIRST_POST_1970_YEAR && $chassisLength === 5) {
            $this->validateFiveCharFormat($chassis, $series);
        } 
        else {
            if ($year === self::FIRST_POST_1970_YEAR) {
                $this->result['error_reason'] = '1970 chassis must be 5 characters (legacy format) or 11 characters (new YYMMBBXXXXC format)';
            } else {
                $this->result['error_reason'] = 'Post-1970 chassis must be 11 characters in YYMMBBXXXXC format (e.g., 7112290609G)';
            }
        }
    }

    /**
     * Validate 11-character format (YYMMBBXXXXC)
     * 
     * @param string $chassis
     * @param string $series
     */
    private function validateElevenCharFormat(string $chassis, string $series): void 
    {
        $base = substr($chassis, 0, 10);
        $suffix = strtoupper(substr($chassis, 10, 1));
        
        if (!ctype_digit($base)) {
            $this->result['error_reason'] = 'First 10 characters must be numeric in YYMMBBXXXXC format (e.g., 7112290609G)';
            return;
        }

        $validSuffixes = $this->getValidSuffixes($series);
        if (!in_array($suffix, $validSuffixes['codes'])) {
            $this->result['error_reason'] = $validSuffixes['type'] . ' models require letter codes: ' . 
                                          $validSuffixes['description'] . ' (current: "' . $suffix . '")';
            return;
        }

        $this->result['valid'] = true;
    }

    /**
     * Validate 9-character format (YYMMXXXXC), used for cars built from January 1972
     *
     * @param string $chassis
     * @param string $series
     */
    private function validateNineCharFormat(string $chassis, string $series): void
    {
        $base = substr($chassis, 0, 8);
        $suffix = strtoupper(substr($chassis, 8, 1));

        if (!ctype_digit($base)) {
            $this->result['error_reason'] = 'First 8 characters must be numeric in YYMMXXXXC format (e.g., 73081602L)';
            return;
        }

        if ((int) substr($base, 0, 2) < 72) {
            $this->result['error_reason'] = 'A 9-character YYMMXXXXC chassis must start with 72 or higher (built from January 1972, e.g., 73081602L)';
            return;
        }

        $validSuffixes = $this->getValidSuffixes($series);
        if (!in_array($suffix, $validSuffixes['codes'], true)) {
            $this->result['error_reason'] = $validSuffixes['type'] . ' models require letter codes: ' .
                                          $validSuffixes['description'] . ' (current: "' . $suffix . '")';
            return;
        }

        $this->result['valid'] = true;
    }

    /**
     * Validate 5-character format (1970 transition)
     * 
     * @param string $chassis
     * @param string $series
     */
    private function validateFiveCharFormat(string $chassis, string $series): void 
    {
        $base = substr($chassis, 0, 4);
        $suffix = strtoupper(substr($chassis, 4, 1));
        
        if (!ctype_digit($base)) {
            $this->result['error_reason'] = '1970 transition format: First 4 characters must be numeric plus letter (e.g., 1234A)';
            return;
        }

        $validSuffixes = $this->getValidSuffixes($series);
        if (!in_array($suffix, $validSuffixes['codes'])) {
            $this->result['error_reason'] = '1970 ' . $validSuffixes['type'] . ' models require letter codes: ' . 
                                          $validSuffixes['description'] . ' (current: "' . $suffix . '")';
            return;
        }

        $this->result['valid'] = true;
    }

    /**
     * Get valid letter codes for model series
     * 
     * @param string $series
     * @return array{codes: list<string>, type: string, description: string}
     */
    private function getValidSuffixes(string $series): array 
    {
        if (str_contains($series, '+2')) {
            return [
                'codes' => self::LETTER_CODES['plus2'],
                'type' => '+2',
                'description' => 'L, M, N'
            ];
        }

        return [
            'codes' => self::LETTER_CODES['elan'],
            'type' => 'Elan',
            'description' => 'A-K (excluding I)'
        ];
    }
}
