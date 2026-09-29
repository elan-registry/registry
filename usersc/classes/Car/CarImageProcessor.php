<?php

declare(strict_types=1);

namespace ElanRegistry\Car;

use ElanRegistry\AppConstants;
use ElanRegistry\Exceptions\CarConcurrentModificationException;
use ElanRegistry\Exceptions\CarDatabaseException;
use ElanRegistry\Exceptions\ImageProcessingException;
use ElanRegistry\LogCategories;

/**
 * CarImageProcessor - Image encoding, decoding, and management for cars
 *
 * Extracted from Car.php to provide focused, testable image processing logic.
 * Handles JSON encoding of image lists, decoding and filesystem validation,
 * image removal operations, and upload-time MIME/size validation.
 *
 * @package ElanRegistry\Car
 * @since v2.15.0
 * @see https://github.com/unibrain1/elanregistry/issues/463
 */
class CarImageProcessor
{
    public function __construct(private CarRepository $repo) {}

    /**
     * Allowed image file extensions. Shared by generateSecureFilename() (what
     * it may produce) and isValidFilename() (what it will accept).
     *
     * isSafeFilename() derives its list from this constant (plus 'jpeg' for legacy DB rows)
     * so it stays in sync automatically.
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
     * Allowed upload MIME types mapped to their file extension. Also serves
     * as the MIME allowlist (its keys) used by getMimeType(). Extensions
     * must match ALLOWED_EXTENSIONS.
     *
     * Shared by getMimeType() (validates a detected MIME type is allowed)
     * and getExtension() (converts an allowed MIME type to its extension),
     * so the two stay in sync automatically.
     *
     * @var array<string, string>
     */
    private const MIME_TO_EXTENSION = [
        'image/jpeg' => 'jpg',
        // 'image/jpg' isn't a registered MIME type — finfo/mime_content_type always emit
        // 'image/jpeg' for JPEGs, so this key is unreachable via getMimeType()'s output on
        // this codebase's supported platforms. Kept as a defensive allowance in case a
        // future direct caller of getExtension() (or a libmagic build on an untested
        // platform) ever produces the non-standard form.
        'image/jpg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    ];

    /**
     * Get MIME type of uploaded file with security validation
     *
     * @param string $file File path to analyze
     * @return string MIME type
     * @throws ImageProcessingException If unable to determine type or type is invalid
     */
    public static function getMimeType(string $file): string
    {
        // Secure MIME type detection with multiple validation layers
        // Primary method: Use finfo (most reliable)
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo === false) {
                throw new ImageProcessingException("Unable to initialize file info extension");
            }
            // @-suppressed: a missing/unreadable file throws a PHP warning here (matches the
            // @exif_imagetype()/@mime_content_type() suppression pattern below in
            // decodeAndProcessImages()) — the false return is already handled explicitly.
            $mimeType = @finfo_file($finfo, $file);
            // No finfo_close() — finfo objects are freed automatically (deprecated call as of PHP 8.5).
            if ($mimeType === false) {
                throw new ImageProcessingException("Unable to read file for MIME type detection (file may be unreadable or missing)");
            }
        } elseif (function_exists('mime_content_type')) {
            $mimeType = mime_content_type($file);
            if ($mimeType === false) {
                throw new ImageProcessingException("Unable to read file for MIME type detection (file may be unreadable or missing)");
            }
        } else {
            throw new ImageProcessingException("Unable to determine file MIME type");
        }

        // Additional validation: Check if detected MIME type is in our allowlist
        if (!array_key_exists($mimeType, self::MIME_TO_EXTENSION)) {
            throw new ImageProcessingException("Invalid file type detected: " . $mimeType);
        }

        return $mimeType;
    }

    /**
     * Get file extension from MIME type
     *
     * @param string $mimeType MIME type to convert
     * @return string File extension
     * @throws ImageProcessingException If MIME type is not supported
     */
    public static function getExtension(string $mimeType): string
    {
        if (!isset(self::MIME_TO_EXTENSION[$mimeType])) {
            throw new ImageProcessingException("Unsupported file type: " . $mimeType);
        }

        return self::MIME_TO_EXTENSION[$mimeType];
    }

    /**
     * Validate file upload security constraints
     *
     * @param array $file File upload array from $_FILES
     * @param int $maxSize Maximum file size in bytes (default 5MB)
     * @throws ImageProcessingException If validation fails
     */
    public static function validateFileUpload(array $file, int $maxSize = 5242880): void // Default 5MB
    {
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new ImageProcessingException("File upload error: " . $file['error']);
        }

        if ($file['size'] > $maxSize) {
            throw new ImageProcessingException("File too large. Maximum size: " . ($maxSize / 1024 / 1024) . "MB");
        }

        if (!is_uploaded_file($file['tmp_name'])) {
            throw new ImageProcessingException("Invalid file upload");
        }

        if ($file['size'] < 100) {
            throw new ImageProcessingException("File too small - minimum 100 bytes required");
        }
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
     * Works by allowlisting the character set [\w\-.] (ASCII word chars, hyphen,
     * dot) then requiring a known image extension. Any character outside
     * that set — including '/', '\', '*', space, null bytes, HTML-special chars,
     * or any non-ASCII byte — causes the match to fail. Path traversal is rejected
     * because '/' is not in the allowed set, not via explicit detection.
     *
     * The /u (UTF-8) flag is deliberately omitted so \w matches only ASCII
     * [a-zA-Z0-9_] and not Unicode word characters.
     *
     * Extension list is derived from ALLOWED_EXTENSIONS plus 'jpeg' for legacy DB rows,
     * so adding a new extension to ALLOWED_EXTENSIONS automatically permits it here too.
     *
     * Used by decodeAndProcessImages() and buildImageDetails() (reorder path).
     *
     * @param string $filename Filename to validate (directory components are
     *                         rejected because '/' is not in [\w\-.])
     * @return bool True if the filename is safe for filesystem use
     */
    public static function isSafeFilename(string $filename): bool
    {
        $exts = implode('|', array_merge(self::ALLOWED_EXTENSIONS, ['jpeg']));
        return (bool) preg_match('/^[\w\-.]+\.(' . $exts . ')\z/iD', $filename);
    }

    /**
     * Check whether a filename is a resized variant of a base image, as opposed
     * to the original upload itself.
     *
     * Resized variants are named `{basename}-resized-{size}.{ext}` (see
     * uploadImages() in save.php, and the srcset builder in CarView.php). Only
     * the base (non-resized) file's existence determines whether an image
     * displays at all — see decodeAndProcessImages() above, which checks
     * is_file() on the base filename only. Resized variants are purely
     * derived/optional, so callers deciding whether a base filename is
     * "unmoved"/"missing" must not conflate a variant's failure with the
     * base file's own failure.
     *
     * @param string $filename Filename to classify (basename only, no directory)
     * @return bool True if $filename matches the `-resized-{size}.` variant pattern
     */
    public static function isResizedVariant(string $filename): bool
    {
        return (bool) preg_match('/-resized-\d+\.[^.]+\z/', $filename);
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
        if (!empty($imageData)) {
            $carImages = json_decode($imageData) ?? explode(',', $imageData);
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
     * @param bool $isOwnerInitiated True when the car's owner removes the photo.
     *                               The same write then resets owner_last_updated.
     *                               An admin or editor on another owner's car
     *                               passes false, which leaves it unchanged.
     * @return bool True if image was removed successfully, false if not found
     * @throws ImageProcessingException If filename is empty or encoding fails
     * @throws CarDatabaseException If database update fails
     * @throws CarConcurrentModificationException If a concurrent request modified the image list
     */
    public function removeImage(object $carData, string $filename, bool $isOwnerInitiated = false): bool
    {
        if (empty($filename)) {
            throw new ImageProcessingException('No image was specified for removal.');
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
        if ($imageJson === false) {
            throw new ImageProcessingException('Unable to process car images. Please try again or contact support.');
        }

        $ownerLastUpdated = $isOwnerInitiated ? date(AppConstants::DATETIME_FORMAT) : null;
        $cas = $this->repo->updateImage((int) $carData->id, $imageJson, $carData->image, $ownerLastUpdated);
        if (!$cas) {
            throw new CarConcurrentModificationException(
                "Image list changed concurrently for car {$carData->id}"
            );
        }
        $carData->image = $imageJson;
        if ($ownerLastUpdated !== null) {
            $carData->owner_last_updated = $ownerLastUpdated;
        }
        return true;
    }

    /**
     * Remove multiple images from a car's image list in a single write.
     *
     * Unlike removeImage(), this does not throw on a CAS conflict — it is
     * used from compensating-cleanup paths (e.g. save.php's mvTmpImages())
     * that already have their own error-reporting mechanism and must not
     * have that flow interrupted by an exception.
     *
     * @param object $carData Car data object (must have ->image and ->id properties)
     * @param array<int, string> $filenames Image filenames to remove
     * @return array{updated: bool, casConflict: bool} Result of the removal attempt.
     *         updated=false, casConflict=false means none of $filenames were present
     *         in the car's current image list — callers should treat this as
     *         unexpected (e.g. log it) when they only pass filenames just decoded
     *         from this same row, since a genuine no-op then signals a
     *         data-consistency issue rather than routine "nothing to remove".
     * @throws ImageProcessingException If encoding fails
     * @throws CarDatabaseException If database update fails
     */
    public function removeImages(object $carData, array $filenames): array
    {
        if (empty($filenames)) {
            return ['updated' => false, 'casConflict' => false];
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

        $remainingImages = array_values(array_diff($currentImages, $filenames));

        if ($remainingImages === $currentImages) {
            return ['updated' => false, 'casConflict' => false];
        }

        $imageJson = empty($remainingImages) ? '' : json_encode($remainingImages);
        if ($imageJson === false) {
            throw new ImageProcessingException('Unable to process car images. Please try again or contact support.');
        }

        $cas = $this->repo->updateImage((int) $carData->id, $imageJson, $carData->image);
        if (!$cas) {
            return ['updated' => false, 'casConflict' => true];
        }

        $carData->image = $imageJson;
        return ['updated' => true, 'casConflict' => false];
    }
}
