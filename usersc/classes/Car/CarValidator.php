<?php

declare(strict_types=1);

namespace ElanRegistry\Car;

use DateTime;
use ElanRegistry\Exceptions\CarValidationException;
use ElanRegistry\InputSanitizer;
use ElanRegistry\Reference\CarModel;

/**
 * CarValidator - Validation and sanitization for car data
 *
 * Extracted from Car.php to provide focused, testable validation logic.
 * Handles field validation, required field checks, and input sanitization.
 *
 * @package ElanRegistry\Car
 * @since v2.15.0
 * @see https://github.com/unibrain1/elanregistry/issues/463
 */
class CarValidator
{
    public const MIN_CAR_DATE = '1957-01-01';

    /**
     * Parse a pipe-delimited model string into its three components.
     *
     * @param string $model A string in "series|variant|type" format.
     * @return array{0: string, 1: string, 2: string} [$series, $variant, $type]
     * @throws CarValidationException If the string does not contain exactly two pipe separators,
     *                                or if any of the three resulting components is empty or whitespace-only.
     */
    public static function parseModel(string $model): array
    {
        $parts = explode('|', $model);
        if (count($parts) !== 3) {
            throw new CarValidationException('Invalid model format. Expected format: series|variant|type');
        }
        $trimmed = [trim($parts[0]), trim($parts[1]), trim($parts[2])];
        if ($trimmed[0] === '' || $trimmed[1] === '' || $trimmed[2] === '') {
            throw new CarValidationException('Invalid model format. Expected format: series|variant|type');
        }
        return $trimmed;
    }

    /**
     * Validate that required fields are present and not empty
     *
     * @param array<string, mixed> $fields Fields to validate
     * @param array<string> $requiredFields List of required field names
     * @return void
     * @throws CarValidationException If any required field is absent, empty, or whitespace-only
     */
    public function validateRequiredFields(array $fields, array $requiredFields): void
    {
        foreach ($requiredFields as $field) {
            if (!isset($fields[$field]) || trim((string)$fields[$field]) === '') {
                throw new CarValidationException("Required field '{$field}' is missing or empty");
            }
        }
    }

    /**
     * Validate and sanitize car fields
     *
     * @param array<string, mixed> $fields Fields to validate and sanitize
     * @param bool $requireAll Whether all validations are required (create) or optional (update)
     * @return array<string, mixed> Validated and sanitized fields
     * @throws CarValidationException If validation fails
     */
    public function validateAndSanitizeFields(array $fields, bool $requireAll = true): array
    {
        $validatedFields = [];

        foreach ($fields as $key => $value) {
            switch ($key) {
                case 'chassis':
                    if (!empty($value)) {
                        $validatedFields[$key] = InputSanitizer::normalize($value, 50);
                        if (strlen($validatedFields[$key]) < 3) {
                            throw new CarValidationException('Chassis number must be at least 3 characters long');
                        }
                    } elseif ($requireAll) {
                        throw new CarValidationException('Chassis number is required');
                    }
                    break;

                case 'model':
                    if (!empty($value)) {
                        $validatedFields[$key] = InputSanitizer::normalize($value, 100);

                        [$series, $variant, $type] = self::parseModel($validatedFields[$key]);

                        $carModelRef = new CarModel();
                        if (!$carModelRef->exists($series, $variant, $type)) {
                            throw new CarValidationException(
                                "Invalid model combination: {$series} {$variant} (Type {$type}) is not a valid Lotus Elan model"
                            );
                        }

                        $validatedFields[$key] = "{$series}|{$variant}|{$type}";

                    } elseif ($requireAll) {
                        throw new CarValidationException('Model is required');
                    }
                    break;

                case 'year':
                    if (!empty($value)) {
                        if (!is_numeric($value) || $value < 1963 || $value > 1974) {
                            throw new CarValidationException('Year must be between 1963 and 1974 (Lotus Elan production years)');
                        }
                        $validatedFields[$key] = (int) $value;
                    } elseif ($requireAll) {
                        throw new CarValidationException('Year is required');
                    }
                    break;

                case 'series':
                case 'variant':
                case 'type':
                case 'color':
                case 'engine':
                    if (!empty($value)) {
                        $validatedFields[$key] = InputSanitizer::normalize($value, 100);
                    }
                    break;

                case 'comments':
                    if (!empty($value)) {
                        $validatedFields[$key] = InputSanitizer::normalize($value, 5000);
                    }
                    break;

                case 'purchasedate':
                case 'solddate':
                    if (!empty($value)) {
                        $date = DateTime::createFromFormat('Y-m-d', $value);
                        if (!$date || $date->format('Y-m-d') !== $value) {
                            throw new CarValidationException("Invalid date format for {$key}. Use YYYY-MM-DD format");
                        }
                        $date->setTime(0, 0, 0);
                        $min = new DateTime(self::MIN_CAR_DATE);
                        $max = new DateTime('today');
                        if ($date < $min || $date > $max) {
                            $label = ($key === 'purchasedate') ? 'Purchase date' : 'Sold date';
                            throw new CarValidationException(
                                "{$label} must be between " . self::MIN_CAR_DATE
                                . " and " . $max->format('Y-m-d')
                            );
                        }
                        $validatedFields[$key] = $value;
                    }
                    break;

                case 'email':
                    if (!empty($value)) {
                        if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                            throw new CarValidationException('Invalid email address format');
                        }
                        $validatedFields[$key] = filter_var($value, FILTER_SANITIZE_EMAIL);
                    }
                    break;

                case 'website':
                    if (!empty($value)) {
                        if (!filter_var($value, FILTER_VALIDATE_URL)) {
                            throw new CarValidationException(
                                'Website URL must start with http:// or https:// (e.g. https://example.com)'
                            );
                        }
                        $urlScheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));
                        if (!in_array($urlScheme, ['http', 'https'], true)) {
                            throw new CarValidationException(
                                'Website URL must use http:// or https:// — other protocols are not allowed'
                            );
                        }
                        $validatedFields[$key] = filter_var($value, FILTER_SANITIZE_URL);
                    }
                    break;

                case 'user_id':
                    if (!empty($value)) {
                        if (!is_numeric($value) || $value <= 0) {
                            throw new CarValidationException('Invalid user ID');
                        }
                        $validatedFields[$key] = (int) $value;
                    }
                    break;

                case 'city':
                case 'state':
                case 'country':
                    if (!empty($value)) {
                        $validatedFields[$key] = InputSanitizer::normalize($value, 100);
                    }
                    break;

                case 'lat':
                    // Explicit check — !empty() treats 0.0 as empty, silently dropping equator coordinates
                    if ($value !== null && $value !== '') {
                        if (!is_numeric($value) || abs((float) $value) > 90) {
                            throw new CarValidationException("Invalid lat coordinate");
                        }
                        $validatedFields[$key] = (float) $value;
                    }
                    break;

                case 'lon':
                    // Explicit check — !empty() treats 0.0 as empty, silently dropping prime-meridian coordinates
                    if ($value !== null && $value !== '') {
                        if (!is_numeric($value) || abs((float) $value) > 180) {
                            throw new CarValidationException("Invalid lon coordinate");
                        }
                        $validatedFields[$key] = (float) $value;
                    }
                    break;

                case 'chassis_override':
                    $validatedFields[$key] = ((int) $value === 1) ? 1 : 0;
                    break;

                default:
                    // Explicit check — !empty() would drop legitimate falsy values like '0' or 0
                    if ($value !== null && $value !== '') {
                        $validatedFields[$key] = $value;
                    }
                    break;
            }
        }

        if (isset($validatedFields['purchasedate'], $validatedFields['solddate'])) {
            $purchase = new DateTime($validatedFields['purchasedate']);
            $sold     = new DateTime($validatedFields['solddate']);
            if ($sold < $purchase) {
                throw new CarValidationException('Sold date cannot be before purchase date');
            }
        }

        return $validatedFields;
    }
}
