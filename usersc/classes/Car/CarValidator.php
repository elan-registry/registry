<?php

declare(strict_types=1);

namespace ElanRegistry\Car;

use ElanRegistry\Exceptions\CarValidationException;
use ElanRegistry\Reference\CarModel;
use DateTime;

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
    /**
     * Validate that required fields are present and not empty
     *
     * @param array<string, mixed> $fields Fields to validate
     * @param array<string> $requiredFields List of required field names
     * @return void
     * @throws CarValidationException If any required field is missing or empty
     */
    public function validateRequiredFields(array $fields, array $requiredFields): void
    {
        foreach ($requiredFields as $field) {
            if (!isset($fields[$field]) || empty(trim((string) $fields[$field]))) {
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
                        $validatedFields[$key] = $this->sanitizeString($value, 50);
                        if (strlen($validatedFields[$key]) < 3) {
                            throw new CarValidationException('Chassis number must be at least 3 characters long');
                        }
                    } elseif ($requireAll) {
                        throw new CarValidationException('Chassis number is required');
                    }
                    break;

                case 'model':
                    if (!empty($value)) {
                        // Sanitize input
                        $validatedFields[$key] = $this->sanitizeString($value, 100);

                        // Validate format: "series|variant|type"
                        $parts = explode('|', $validatedFields[$key]);
                        if (count($parts) !== 3) {
                            throw new CarValidationException(
                                'Invalid model format. Expected format: series|variant|type'
                            );
                        }

                        list($series, $variant, $type) = $parts;

                        // Trim whitespace
                        $series = trim($series);
                        $variant = trim($variant);
                        $type = trim($type);

                        // Validate model combination exists in car_models table
                        $carModelRef = new CarModel();
                        if (!$carModelRef->exists($series, $variant, $type)) {
                            throw new CarValidationException(
                                "Invalid model combination: {$series} {$variant} (Type {$type}) is not a valid Lotus Elan model"
                            );
                        }

                        // Store validated model
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
                        $validatedFields[$key] = $this->sanitizeString($value, 100);
                    }
                    break;

                case 'comments':
                    if (!empty($value)) {
                        $validatedFields[$key] = $this->sanitizeString($value, 5000);
                    }
                    break;

                case 'purchasedate':
                case 'solddate':
                    if (!empty($value)) {
                        $date = DateTime::createFromFormat('Y-m-d', $value);
                        if (!$date || $date->format('Y-m-d') !== $value) {
                            throw new CarValidationException("Invalid date format for {$key}. Use YYYY-MM-DD format");
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
                            throw new CarValidationException('Invalid website URL format');
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
                        $validatedFields[$key] = $this->sanitizeString($value, 100);
                    }
                    break;

                case 'lat':
                case 'lon':
                    if (!empty($value)) {
                        if (!is_numeric($value) || abs((float) $value) > 180) {
                            throw new CarValidationException("Invalid {$key} coordinate");
                        }
                        $validatedFields[$key] = (float) $value;
                    }
                    break;

                default:
                    $validatedFields[$key] = $value;
                    break;
            }
        }

        return $validatedFields;
    }

    /**
     * Sanitize string input
     *
     * @param string $input Input string to sanitize
     * @param int $maxLength Maximum allowed length
     * @return string Sanitized string
     */
    public function sanitizeString(string $input, int $maxLength): string
    {
        $sanitized = trim(strip_tags($input));

        if (strlen($sanitized) > $maxLength) {
            $sanitized = substr($sanitized, 0, $maxLength);
        }

        return $sanitized;
    }
}
