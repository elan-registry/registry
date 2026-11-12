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
    public const ALLOWED_EXTENSIONS = ['jpg', 'png', 'gif'];

    /**
     * Smallest upload size in bytes that can be a real photo. A smaller file
     * is empty or damaged. car-edit.js uses the same value for its client check.
     */
    public const MIN_FILE_BYTES = 100;

    /** Owner message for a file smaller than MIN_FILE_BYTES. */
    private const TOO_SMALL_MESSAGE = 'The photo file is empty or damaged. Choose a different photo.';

    /** Owner message for a file type that is not an allowed photo type. */
    private const TYPE_NOT_ALLOWED_MESSAGE = 'The file is not a JPEG, PNG or GIF photo. Choose a different photo.';

    /** Owner message when the server cannot detect the file type. */
    private const UNREADABLE_MESSAGE = 'The server could not read the photo. Upload the photo again.';

    /** Owner message when the server did not receive the photo. */
    private const NOT_RECEIVED_MESSAGE = 'The server could not receive the photo. Upload the photo again.';

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
                throw new ImageProcessingException("Unable to initialize file info extension", 0, null, self::UNREADABLE_MESSAGE);
            }
            // @-suppressed: a missing/unreadable file throws a PHP warning here (matches the
            // @exif_imagetype()/@mime_content_type() suppression pattern below in
            // decodeAndProcessImages()) — the false return is already handled explicitly.
            $mimeType = @finfo_file($finfo, $file);
            // No finfo_close() — finfo objects are freed automatically (deprecated call as of PHP 8.5).
        } elseif (function_exists('mime_content_type')) {
            $mimeType = mime_content_type($file);
        } else {
            throw new ImageProcessingException("Unable to determine file MIME type", 0, null, self::UNREADABLE_MESSAGE);
        }

        if ($mimeType === false) {
            throw new ImageProcessingException(
                "Unable to read file for MIME type detection (file may be unreadable or missing)",
                0,
                null,
                self::UNREADABLE_MESSAGE
            );
        }

        // Additional validation: Check if detected MIME type is in our allowlist
        if (!array_key_exists($mimeType, self::MIME_TO_EXTENSION)) {
            throw new ImageProcessingException("Invalid file type detected: " . $mimeType, 0, null, self::TYPE_NOT_ALLOWED_MESSAGE);
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
            throw new ImageProcessingException("Unsupported file type: " . $mimeType, 0, null, self::TYPE_NOT_ALLOWED_MESSAGE);
        }

        return self::MIME_TO_EXTENSION[$mimeType];
    }

    /**
     * Validate file upload security constraints
     *
     * An upload error code gets an owner message, because PHP leaves tmp_name
     * empty for a failed upload and the owner must know which photo to send
     * again (#2626).
     *
     * @param array $file File upload array from $_FILES
     * @param int|null $maxSize Maximum file size in bytes. Null uses serverMaxBytes().
     * @throws ImageProcessingException If validation fails
     */
    public static function validateFileUpload(array $file, ?int $maxSize = null): void
    {
        $maxSize ??= self::serverMaxBytes();

        if ($file['error'] !== UPLOAD_ERR_OK) {
            $userMessage = match ($file['error']) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => self::tooLargeMessage($maxSize),
                UPLOAD_ERR_PARTIAL =>
                    'Photo upload did not complete. Upload the photo again.',
                default => self::NOT_RECEIVED_MESSAGE,
            };
            throw new ImageProcessingException("File upload error: " . $file['error'], 0, null, $userMessage);
        }

        if ($file['size'] > $maxSize) {
            throw new ImageProcessingException(
                "File too large: {$file['size']} bytes. Maximum size: " . ($maxSize / 1024 / 1024) . "MB",
                0,
                null,
                self::tooLargeMessage($maxSize)
            );
        }

        if (!is_uploaded_file($file['tmp_name'])) {
            throw new ImageProcessingException("Invalid file upload", 0, null, self::NOT_RECEIVED_MESSAGE);
        }

        if ($file['size'] < self::MIN_FILE_BYTES) {
            throw new ImageProcessingException(
                "File too small - minimum " . self::MIN_FILE_BYTES . " bytes required",
                0,
                null,
                self::TOO_SMALL_MESSAGE
            );
        }
    }

    /**
     * Validate each file in a multi-file upload before any file is moved.
     *
     * The save stops when one file is bad, so all files are checked first.
     * Then the owner gets one message for each bad file, and a batch that
     * fails validation moves no file (#1844). An UPLOAD_ERR_NO_FILE
     * entry is an empty file input, not a photo, so it is skipped.
     *
     * @param array<mixed> $files The $_FILES['file'] shape: 'name', 'tmp_name',
     *                            'error' and 'size', each a list with the same keys.
     *                            An empty array is an empty batch.
     * @return array{mimeTypes: array<int|string, string>, errors: list<string>, rejected: list<array{name: string, reason: string}>}
     *         mimeTypes: the detected MIME type of each valid file, keyed by file index.
     *         errors: one owner message for each bad file.
     *         rejected: the raw client name and the technical reason of each bad file, for the log.
     * @throws ImageProcessingException If $files does not have the $_FILES shape
     */
    public static function validateUploadBatch(array $files): array
    {
        $result = ['mimeTypes' => [], 'errors' => [], 'rejected' => []];
        if ($files === []) {
            return $result;
        }

        $fields = ['name', 'tmp_name', 'error', 'size'];
        foreach ($fields as $field) {
            if (!isset($files[$field]) || !is_array($files[$field])) {
                throw new ImageProcessingException(
                    "Malformed upload batch: '{$field}' is missing or not a list",
                    0,
                    null,
                    self::NOT_RECEIVED_MESSAGE
                );
            }
        }

        $keys = array_keys($files['name']);
        foreach ($fields as $field) {
            if (array_keys($files[$field]) !== $keys) {
                throw new ImageProcessingException(
                    "Malformed upload batch: '{$field}' keys do not match 'name' keys",
                    0,
                    null,
                    self::NOT_RECEIVED_MESSAGE
                );
            }
        }

        foreach ($keys as $key) {
            $name    = $files['name'][$key];
            $tmpName = $files['tmp_name'][$key];
            $error   = $files['error'][$key];
            $size    = $files['size'][$key];
            // A NUL byte in tmp_name makes is_uploaded_file() throw ValueError.
            if (
                !is_string($name) || !is_string($tmpName) || !is_int($error) || !is_int($size)
                || str_contains($tmpName, "\0")
            ) {
                throw new ImageProcessingException(
                    "Malformed upload batch: an entry has a wrong-typed field or a NUL byte in 'tmp_name'",
                    0,
                    null,
                    self::NOT_RECEIVED_MESSAGE
                );
            }

            if ($error === UPLOAD_ERR_NO_FILE) {
                continue;
            }

            try {
                self::validateFileUpload(['name' => $name, 'tmp_name' => $tmpName, 'error' => $error, 'size' => $size]);
                $result['mimeTypes'][$key] = self::getMimeType($tmpName);
            } catch (ImageProcessingException $e) {
                $result['errors'][]   = 'File upload rejected for ' . self::cleanClientText($name, 100) . ': ' . $e->getUserMessage();
                $result['rejected'][] = ['name' => $name, 'reason' => $e->getMessage()];
            }
        }

        return $result;
    }

    /**
     * Get the server limit for each posted photo, in bytes.
     *
     * @return int ELAN_IMAGE_SERVER_MAX_SIZE (MB) in bytes, 1 MB = 1,048,576 bytes
     */
    public static function serverMaxBytes(): int
    {
        return (int) round(ELAN_IMAGE_SERVER_MAX_SIZE * 1048576);
    }

    /**
     * Build the owner message for a photo above the size limit.
     *
     * @param int $maxSize Maximum file size in bytes
     * @return string Message with the limit in MB (1 MB = 1,048,576 bytes), for example "5 MB", and what to do
     */
    private static function tooLargeMessage(int $maxSize): string
    {
        return 'Photo is too large. The maximum size is ' . round($maxSize / 1048576, 1)
            . ' MB. Make the photo smaller, then upload it again.';
    }

    /**
     * Check whether a request body is larger than the PHP post_max_size limit.
     *
     * When the body is larger than post_max_size, PHP discards $_POST and
     * $_FILES before the script runs (#2647). The caller uses this check to
     * tell that case apart from a request with no data.
     *
     * ini_parse_quantity() is the parser PHP uses for post_max_size, so a
     * malformed value gives the same limit that PHP enforces. A limit of 0 or
     * less means no limit, as in PHP.
     *
     * @param int    $contentLength Request body size in bytes (CONTENT_LENGTH)
     * @param string $postMaxSize   post_max_size value, for example "64M"
     * @return bool True when the body is larger than a set limit
     */
    public static function exceedsPostMaxSize(int $contentLength, string $postMaxSize): bool
    {
        $limit = ini_parse_quantity($postMaxSize);
        if ($limit <= 0) {
            return false;
        }

        return $contentLength > $limit;
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
     * Read the base filenames stored in a car's `image` column.
     *
     * The column normally holds a JSON array of bare filenames, but rows
     * predating that format store a comma-separated list — the same fallback
     * decodeAndProcessImages() applies. Values are returned verbatim. Each
     * caller validates a name before it touches the filesystem or keeps it.
     *
     * @param string|null $imageData Raw `cars.image` value.
     * @return list<string> Stored filenames in their stored order, possibly empty.
     */
    public static function storedImageFilenames(?string $imageData): array
    {
        if ($imageData === null || $imageData === '') {
            return [];
        }

        $decoded = json_decode($imageData, true);
        if (!is_array($decoded)) {
            $decoded = explode(',', $imageData);
        }

        $filenames = [];
        foreach ($decoded as $filename) {
            if (is_string($filename) && $filename !== '') {
                $filenames[] = $filename;
            }
        }

        return $filenames;
    }

    /**
     * Decide which names in a save's requested order go into cars.image.
     *
     * The allowlist comes from server state, so a client cannot add a name the
     * car never had. A name this request generated must match the secure format.
     * A name already stored for the car needs only isSafeFilename(), so legacy
     * names (timestamps, bare hashes, .jpeg) survive a save (#2609). A stored
     * name that fails isSafeFilename() goes to storedUnsafe, apart from
     * dropped, so that the caller can stop the save and not remove the photo
     * (#2626). Each other name, for example an upload name that did not get
     * replaced, is dropped. Order is kept and duplicates are not removed.
     *
     * @param list<string> $requestedOrder Client order, with upload names already replaced
     * @param list<string> $newNames       Names that this request generated
     * @param list<string> $storedNames    Names in cars.image before this save
     * @return array{kept: list<string>, dropped: list<string>, storedUnsafe: list<string>}
     */
    public static function resolveUploadOrder(array $requestedOrder, array $newNames, array $storedNames): array
    {
        $kept         = [];
        $dropped      = [];
        $storedUnsafe = [];
        foreach ($requestedOrder as $name) {
            if (in_array($name, $newNames, true) && self::isValidFilename($name)) {
                $kept[] = $name;
            } elseif (in_array($name, $storedNames, true)) {
                if (self::isSafeFilename($name)) {
                    $kept[] = $name;
                } else {
                    $storedUnsafe[] = $name;
                }
            } else {
                $dropped[] = $name;
            }
        }
        return ['kept' => $kept, 'dropped' => $dropped, 'storedUnsafe' => $storedUnsafe];
    }

    /**
     * Find the problems in a resolveUploadOrder() result that must stop the save.
     *
     * Each problem means that the save would lose a photo with no message, so
     * each one gets an owner message (#2626). A dropped name in $failedNames
     * already has a message from the upload loop, so it is not reported again.
     * The messages are plain text. The page escapes them when it shows them.
     *
     * @param array{kept: list<string>, dropped: list<string>, storedUnsafe: list<string>} $result
     *        Return value of resolveUploadOrder()
     * @param list<string> $newNames    Names that this request generated
     * @param list<string> $failedNames Client names of uploads that failed with a message
     * @return array{missing: list<string>, storedUnsafe: list<string>, unplaced: list<string>, errors: list<string>}
     *         missing: dropped names with no message yet. unplaced: generated
     *         names with no place in kept. errors: owner messages, empty when
     *         the save can continue.
     */
    public static function checkUploadOrder(array $result, array $newNames, array $failedNames): array
    {
        $missing      = array_values(array_diff($result['dropped'], $failedNames));
        $storedUnsafe = $result['storedUnsafe'];
        $unplaced     = array_values(array_diff($newNames, $result['kept']));

        $errors = [];
        if ($missing !== []) {
            $errors[] = count($missing) . ' photo(s) did not reach the server: '
                . self::messageNames($missing)
                . '. Reload the page, then add the photo(s) again.';
        }
        if ($storedUnsafe !== []) {
            $errors[] = 'A saved photo has a file name that the registry cannot keep: '
                . self::messageNames($storedUnsafe)
                . '. The save stopped so that the photo is not removed. Contact the registry.';
        }
        // A stored file with no entry in cars.image is a photo lost with no
        // message. This occurs when the browser file name in 'filenames' does
        // not match the $_FILES name: a comma breaks the explode(','), and PHP
        // changes names that hold '"' or '\'.
        if ($unplaced !== []) {
            $errors[] = 'Photo could not be added. Rename the file with plain characters (letters, numbers, - and _). Then upload it again.';
        }

        return [
            'missing'      => $missing,
            'storedUnsafe' => $storedUnsafe,
            'unplaced'     => $unplaced,
            'errors'       => $errors,
        ];
    }

    /**
     * Join file names for an owner message.
     *
     * The names can come from the client, so control characters are removed,
     * invalid UTF-8 is replaced (the JSON response must encode), and the count
     * and each length are capped.
     *
     * @param list<string> $names File names
     * @return string Comma-separated names, safe as plain text
     */
    private static function messageNames(array $names): string
    {
        $clean = [];
        foreach (array_slice($names, 0, 20) as $name) {
            $clean[] = self::cleanClientText($name, 100);
        }
        return implode(', ', $clean);
    }

    /**
     * Make client text safe to put in an owner message.
     *
     * Control characters could fake a log line or a message line, invalid UTF-8
     * would make the JSON response fail, and the cap limits what a client can add.
     * The text is not HTML-encoded: car-edit.js escapes the error text.
     *
     * @param string $text      Client text, for example a browser file name
     * @param int    $maxLength Maximum length in characters
     * @return string Cleaned text
     */
    public static function cleanClientText(string $text, int $maxLength): string
    {
        return mb_substr((string) preg_replace('/[\x00-\x1F\x7F]/', '', mb_scrub($text, 'UTF-8')), 0, $maxLength);
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
     * @param bool $isOwnerInitiated True when the caller decides this removal is an
     *                               owner action. The same write then resets
     *                               owner_last_updated. The caller owns that
     *                               decision (see app/api/cars/save.php).
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
