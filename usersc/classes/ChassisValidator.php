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
            [$series, $variant, $type] = CarValidator::parseModel($model);
        } catch (CarValidationException $e) {
            $this->result['error_reason'] = 'Invalid model format';
            return $this->result;
        }

        // Validate based on variant (Race vs Production)
        if (stripos($variant, 'Race') !== false) {
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
        if ($year < 1970) {
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
        
        if (is_numeric($chassis) && $chassisLength === 4) {
            $this->result['valid'] = true;
        } else {
            if (!is_numeric($chassis)) {
                $this->result['error_reason'] = 'Pre-1970 chassis must be numeric (4 digits only, e.g., 1234)';
            } else {
                $this->result['error_reason'] = 'Pre-1970 chassis must be exactly 4 digits (e.g., 1234)';
            }
        }
    }

    /**
     * Validate post-1970 chassis (YYMMBBXXXXC format)
     * 
     * @param string $chassis
     * @param int $year
     * @param string $series
     * @param int $chassisLength
     */
    private function validatePost1970(string $chassis, int $year, string $series, int $chassisLength): void 
    {
        $this->result['format_type'] = 'post_1970';
        
        // Standard 11-character format: YYMMBBXXXXC
        if ($chassisLength === 11) {
            $this->validateElevenCharFormat($chassis, $series);
        } 
        // 1970 transition year also allows 5-character legacy format
        elseif ($year === 1970 && $chassisLength === 5) {
            $this->validateFiveCharFormat($chassis, $series);
        } 
        else {
            if ($year === 1970) {
                $this->result['error_reason'] = '1970 chassis must be 5 characters (legacy format) or 11 characters (new YYMMBBXXXXC format)';
            } else {
                $this->result['error_reason'] = 'Post-1970 chassis must be 11 characters in YYMMBBXXXXC format (e.g., 7301019999B)';
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
        
        if (!is_numeric($base)) {
            $this->result['error_reason'] = 'First 10 characters must be numeric in YYMMBBXXXXC format (e.g., 7301019999B)';
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
     * Validate 5-character format (1970 transition)
     * 
     * @param string $chassis
     * @param string $series
     */
    private function validateFiveCharFormat(string $chassis, string $series): void 
    {
        $base = substr($chassis, 0, 4);
        $suffix = strtoupper(substr($chassis, 4, 1));
        
        if (!is_numeric($base)) {
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

    /**
     * Get validation rules summary for display
     * 
     * @return array<string, array<int|string, string>>
     */
    public static function getValidationRules(): array 
    {
        return [
            'race_cars' => [
                '1963' => '26-R-xx format only',
                '1964' => '26-R-xx or 26-S2-xx formats',
                '1965-1966' => '26-S2-xx format only',
                'other_years' => '26-R-xx format'
            ],
            'production_cars' => [
                'pre_1970' => '4 digits numeric only',
                '1970' => '5 characters (legacy) or 11 characters (YYMMBBXXXXC)',
                'post_1970' => '11 characters YYMMBBXXXXC format'
            ],
            'letter_codes' => [
                'elan' => 'A, B, C, D, E, F, G, H, J, K (excluding I)',
                'plus2' => 'L, M, N only'
            ]
        ];
    }
}