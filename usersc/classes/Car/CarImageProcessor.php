<?php

declare(strict_types=1);

namespace ElanRegistry\Car;

use DB;
use ElanRegistry\CarErrorMessages;
use ElanRegistry\Exceptions\CarDatabaseException;
use ElanRegistry\Exceptions\ImageProcessingException;
use ElanRegistry\LogCategories;

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
     * Allowed image file extensions. Shared by generateSecureFilename() (what
     * it may produce) and isValidFilename() (what it will accept).
     *
     * Two other extension lists diverge from this one and must be kept in sync manually:
     *   - isSafeFilename() below — hardcoded inline, also includes 'jpeg' for legacy DB rows
     *   - getExtension() in save.php — MIME-to-extension map
     *
     * @var list<string>
     */
    public const ALLOWED_EXTENSIONS = ['jpg', 'png', 'gif', 'webp'];

    /**
     * Derive the allowlist regex from ALLOWED_EXTENSIONS.
     *
     * \z is the primary end-of-string anchor (unlike $, it never matches
     * before a trailing newline). The D modifier is redundant when \z is
     * used but makes the intent explicit.
     */
    private static function buildPattern(): string
    {
        return '/^img_[0-9a-f]{32}\.(' . implode('|', self::ALLOWED_EXTENSIONS) . ')\z/D';
    }

    /**
     * Generate a cryptographically secure filename for a car image.
     *
     * @param string $extension File extension — must be in ALLOWED_EXTENSIONS
     * @return string Secure filename in the format img_[32 hex chars].[ext]
     * @throws ImageProcessingException If the extension is not allowed
     */
    public static function generateSecureFilename(string $extension): string
    {
        $ext = strtolower($extension);
        if (!in_array($ext, self::ALLOWED_EXTENSIONS, true)) {
            throw new ImageProcessingException("Unsupported image extension: {$ext}");
        }
        return 'img_' . bin2hex(random_bytes(16)) . '.' . $ext;
    }

    /**
     * Check whether a filename matches the secure-name format.
     *
     * The pattern is anchored to the full string (^img_…\z), so path traversal
     * sequences (../, /, glob chars) cause an immediate mismatch without needing
     * basename() normalisation. The raw value must match exactly.
     *
     * @param string $filename Filename to validate
     * @return bool True if the filename matches the allowlist
     */
    public static function isValidFilename(string $filename): bool
    {
        return (bool) preg_match(self::buildPattern(), $filename);
    }

    /**
     * Check whether a filename is safe for filesystem operations on the read path.
     *
     * Unlike isValidFilename(), accepts legacy filenames that predate the
     * img_[hex32] naming scheme (timestamps, bare hashes, old uniqid format).
     *
     * Works by allowlisting the character set [\w\-. ] (ASCII word chars, hyphen,
     * dot, space) then requiring a known image extension. Any character outside
     * that set — including '/', '\', '*', null bytes, HTML-special chars, or any
     * non-ASCII byte — causes the match to fail. Path traversal is rejected because
     * '/' is not in the allowed set, not via explicit detection.
     *
     * The /u (UTF-8) flag is deliberately omitted so \w matches only ASCII
     * [a-zA-Z0-9_] and not Unicode word characters.
     *
     * Has its own extension list (jpg, jpeg, png, gif, webp) including 'jpeg'
     * for historical DB rows — diverges from ALLOWED_EXTENSIONS.
     *
     * Used by decodeAndProcessImages() and buildImageDetails() (reorder path).
     *
     * @param string $filename Filename to validate (directory components are
     *                         rejected because '/' is not in [\w\-. ])
     * @return bool True if the filename is safe for filesystem use
     */
    public static function isSafeFilename(string $filename): bool
    {
        return (bool) preg_match('/^[\w\-. ]+\.(jpg|jpeg|png|gif|webp)\z/iD', $filename);
    }

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

        if (!empty($imageData)) {
            $carImages = json_decode($imageData);

            if (is_null($carImages)) {
                $carImages = explode(',', $imageData);
            }
        } else {
            $carImages = [];
        }

        $images = [];
        foreach ($carImages as $key => $carimage) {
            if (!self::isSafeFilename((string) $carimage)) {
                logger(0, LogCategories::LOG_CATEGORY_FILE_ERROR,
                    'decodeAndProcessImages: skipping unsafe filename: '
                    . htmlspecialchars((string) $carimage, ENT_QUOTES, 'UTF-8'));
                continue;
            }
            $safeFilename = basename((string) $carimage);
            $temp = pathinfo($absRoot . $urlRoot . $imageDir . $safeFilename);
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
        if (!empty($carData->image)) {
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
            $updateSuccess = (new CarRepository($db))->updateImage((int) $carData->id, $imageJson);
        } catch (\Exception $e) {
            $technicalMsg = CarErrorMessages::getTechnicalMessage('image_remove_failed', ['error' => $e->getMessage()]);
            logger(0, LogCategories::LOG_CATEGORY_CAR_ACTIONS, $technicalMsg);
            throw new CarDatabaseException(CarErrorMessages::getMessage('image_remove_failed'));
        }

        if ($updateSuccess) {
            $carData->image = $imageJson;
            return true;
        }

        $technicalMsg = CarErrorMessages::getTechnicalMessage('database_update_failed', ['error' => 'Database update returned false: ' . $db->errorString()]);
        logger(0, LogCategories::LOG_CATEGORY_CAR_ACTIONS, $technicalMsg);
        throw new CarDatabaseException(CarErrorMessages::getMessage('database_update_failed'));
    }
}
