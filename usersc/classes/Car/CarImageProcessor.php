<?php

declare(strict_types=1);

namespace ElanRegistry\Car;

use CarErrorMessages;
use ElanRegistry\Exceptions\CarDatabaseException;
use ElanRegistry\Exceptions\ImageProcessingException;
use LogCategories;
use DB;

/**
 * CarImageProcessor - Image encoding, decoding, and management for cars
 *
 * Extracted from Car.php to provide focused, testable image processing logic.
 * Handles JSON encoding of image lists, decoding and filesystem validation,
 * and image removal operations.
 *
 * @package ElanRegistry\Car
 * @since v2.15.0
 * @see https://github.com/unibrain1/elanregistry/issues/463
 */
class CarImageProcessor
{
    /**
     * Encode an array of images to JSON for database storage
     *
     * @param array<mixed> $images Array of image data
     * @return string JSON-encoded image string
     * @throws ImageProcessingException If encoding fails
     */
    public function encodeImages(array $images): string
    {
        $encoded = json_encode($images);
        if ($encoded === false) {
            throw new ImageProcessingException('Failed to encode images as JSON');
        }
        return $encoded;
    }

    /**
     * Decode and process image data from the database
     *
     * Handles both JSON and CSV legacy formats. Validates file existence,
     * determines image types, and builds image metadata arrays.
     *
     * @param string|null $imageData Raw image data from database
     * @param string $imageDir Relative image directory path (e.g., '/userimages/123/')
     * @param string $urlRoot URL root for building paths
     * @param string $absRoot Absolute filesystem root for file checks
     * @return array<int, array<string, mixed>> Array of image metadata
     */
    public function decodeAndProcessImages(
        ?string $imageData,
        string $imageDir,
        string $urlRoot,
        string $absRoot
    ): array {
        $carImages = null;

        if (!is_null($imageData) && !empty($imageData)) {
            $carImages = json_decode($imageData);

            if (is_null($carImages)) {
                $carImages = explode(',', $imageData);
            }
        } else {
            $carImages = [];
        }

        $images = [];
        foreach ($carImages as $key => $carimage) {
            $temp = pathinfo($absRoot . $urlRoot . $imageDir . $carImages[$key]);
            $file = $temp['dirname'] . "/" . $temp['basename'];
            if (is_file($file)) {
                $images[$key] = $temp;
                $images[$key]['path'] = $urlRoot . $imageDir . $images[$key]['basename'];
                $images[$key]['size'] = filesize($file);

                try {
                    $imageType = @exif_imagetype($file);
                    if ($imageType !== false) {
                        $images[$key]['type'] = image_type_to_extension($imageType, false);
                    } else {
                        $images[$key]['type'] = 'unknown';
                        logger(0, LogCategories::LOG_CATEGORY_FILE_ERROR, "CarImageProcessor: Unable to determine image type for file: {$file}");
                    }
                } catch (\Exception $e) {
                    $images[$key]['type'] = 'unknown';
                    logger(0, LogCategories::LOG_CATEGORY_FILE_ERROR, "CarImageProcessor: Exception getting image type for {$file}: " . $e->getMessage());
                }

                try {
                    $mimeType = @mime_content_type($file);
                    if ($mimeType !== false) {
                        $images[$key]['mime'] = $mimeType;
                    } else {
                        $images[$key]['mime'] = 'application/octet-stream';
                        logger(0, LogCategories::LOG_CATEGORY_FILE_ERROR, "CarImageProcessor: Unable to determine MIME type for file: {$file}");
                    }
                } catch (\Exception $e) {
                    $images[$key]['mime'] = 'application/octet-stream';
                    logger(0, LogCategories::LOG_CATEGORY_FILE_ERROR, "CarImageProcessor: Exception getting MIME type for {$file}: " . $e->getMessage());
                }
            }
        }

        return array_values($images);
    }

    /**
     * Remove an image from a car's image list
     *
     * @param object $carData Car data object (must have ->image and ->id properties)
     * @param string $filename Image filename to remove
     * @param DB $db Database instance
     * @return bool True if image was removed successfully, false if not found
     * @throws ImageProcessingException If filename is empty or encoding fails
     * @throws CarDatabaseException If database update fails
     */
    public function removeImage(object $carData, string $filename, DB $db): bool
    {
        if (empty($filename)) {
            throw new ImageProcessingException(CarErrorMessages::getMessage('image_filename_empty'));
        }

        $currentImages = [];
        if (!is_null($carData->image) && !empty($carData->image)) {
            $decoded = json_decode($carData->image);
            if ($decoded !== null) {
                $currentImages = is_array($decoded) ? $decoded : [$decoded];
            } else {
                $currentImages = explode(',', $carData->image);
            }
        }

        $imageIndex = array_search($filename, $currentImages, true);
        if ($imageIndex === false) {
            return false;
        }

        unset($currentImages[$imageIndex]);
        $currentImages = array_values($currentImages);

        $imageJson = empty($currentImages) ? '' : json_encode($currentImages);
        if ($imageJson === false && !empty($currentImages)) {
            throw new ImageProcessingException(CarErrorMessages::getMessage('image_encoding_failed'));
        }

        try {
            $updateSuccess = $db->update('cars', $carData->id, ['image' => $imageJson]);

            if ($updateSuccess) {
                $carData->image = $imageJson;
                return true;
            } else {
                throw new CarDatabaseException(CarErrorMessages::getAdminMessage('database_update_failed'));
            }
        } catch (CarDatabaseException $e) {
            throw $e;
        } catch (\Exception $e) {
            $technicalMsg = CarErrorMessages::getTechnicalMessage('image_remove_failed', ['error' => $e->getMessage()]);
            logger(0, LogCategories::LOG_CATEGORY_CAR_ACTIONS, $technicalMsg);
            throw new CarDatabaseException(CarErrorMessages::getMessage('image_remove_failed'));
        }
    }
}
