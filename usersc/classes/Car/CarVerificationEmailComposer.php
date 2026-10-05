<?php

declare(strict_types=1);

namespace ElanRegistry\Car;

use ElanRegistry\EmailTemplate;
use ElanRegistry\LogCategories;

/**
 * CarVerificationEmailComposer - Builds the periodic owner verification email
 *
 * Composes the subject line and full branded HTML body for the verification
 * email an owner receives at most twice per twelve months, asking them to
 * confirm their car record is still accurate. The rendered layout matches the
 * approved mockup (docs/plans/features/car-owner-verification/images/exhibit-a-email.png):
 * greeting, Verify/Sold buttons, Owner Information, Car Information (with the
 * conditional chassis-override badge and highlighted rows for any blank
 * optional field — Color, Variant, Purchase Date, Photos, Website), the conditional
 * "About the Chassis Number" alert, the edit CTA, and a footer carrying the
 * one-click opt-out link.
 *
 * This class performs NO database access of its own. Everything it renders
 * comes from the `$carData` and `$owner` objects supplied by the caller — the
 * same shapes app/verify/verify_car.php already builds — which keeps it
 * unit-testable with no framework bootstrap beyond getBaseUrl(), logger(),
 * EMAIL_SUBJECT_PREFIX and ELAN_IMAGE_DIR. The one exception is logger(),
 * which writes to the logs table when a car's photos cannot be shown. The
 * class also reads the filesystem (is_file() only) so the email never links
 * an image that would show as broken. See photosRow() for the three Photos
 * states.
 *
 * Escaping: EmailTemplate::createMessageBox()'s `$content` and
 * createRawDetailRow()'s `$trustedHtml` are raw HTML by design, so every
 * free-form value rendered here is routed through createDetailRow(),
 * createMessageContent(), or this class's own esc() helper before it reaches
 * one of them.
 *
 * @package ElanRegistry\Car
 * @since v2.30.3
 * @see https://github.com/elan-registry/registry/issues/1882
 * @see https://github.com/elan-registry/registry/issues/1883
 */
final class CarVerificationEmailComposer
{
    /**
     * Lifetime of the verify/sold/opt-out links, in days.
     *
     * Must stay equal to VERIFY_LINK_TTL_DAYS in app/verify/verify_car.php — the
     * expiry sentence this class renders would otherwise promise a window the
     * landing page does not honour. A drift-guard unit test asserts the two match.
     */
    public const LINK_TTL_DAYS = 60;

    /** Display text used for any car/owner field that is null or blank. */
    private const NOT_SPECIFIED = 'Not specified';

    /** Display text used for a blank website or missing photo, which get the highlighted row treatment. */
    private const NOT_YET_PROVIDED = 'Not yet provided';

    private EmailTemplate $template;

    private string $imageRoot;

    /**
     * @param EmailTemplate|null $template  Renderer to compose with; a default
     *                                      instance is created when null, so
     *                                      tests can inject their own.
     * @param string|null        $imageRoot Directory holding the per-car image
     *                                      folders (`{root}/{carId}/`). Defaults
     *                                      to the site's ELAN_IMAGE_DIR, so the
     *                                      class reads no globals. Tests inject
     *                                      a temporary one.
     */
    public function __construct(?EmailTemplate $template = null, ?string $imageRoot = null)
    {
        $this->template  = $template ?? new EmailTemplate();
        $this->imageRoot = rtrim($imageRoot ?? dirname(__DIR__, 3) . '/' . ELAN_IMAGE_DIR, '/');
    }

    /**
     * Compose the verification email's subject line and HTML body.
     *
     * A car with no safe photo listed gets a highlighted "Not yet provided" Photos
     * row and is named in the blank-field callout, the same as a blank Website.
     * A car with photos listed but no displayable thumbnail gets a plain
     * "N photos on file" row and is not named in the callout.
     *
     * @param object $carData Car row: id, year, type, chassis, chassis_override,
     *                        series, variant, color, purchasedate, solddate,
     *                        image, website
     * @param object $owner   Owner row: id, fname, lname, email, city, state,
     *                        country, join_date
     * @param string $vericode Plaintext verification code for this car's links
     * @return array{subject: string, html: string} SMTP subject line and full HTML body
     */
    public function compose(object $carData, object $owner, string $vericode): array
    {
        $chassisOverride = (int) ($carData->chassis_override ?? 0) === 1;
        $website         = $this->fieldOrDefault($carData->website ?? null, '');
        $photo           = $this->primaryPhoto($carData);

        // Fields checked for the highlight/callout treatment: genuinely
        // optional/supplementary details an owner might not have filled in
        // yet. Deliberately excludes id/year/type/chassis/series — near-
        // mandatory structural identifiers a car record shouldn't exist
        // without — and solddate, whose blankness for an unsold car is the
        // expected state, not missing data.
        $blankFields = array_keys(array_filter([
            'Color'         => $this->fieldOrDefault($carData->color ?? null, '') === '',
            'Variant'       => $this->fieldOrDefault($carData->variant ?? null, '') === '',
            'Purchase Date' => $this->fieldOrDefault($carData->purchasedate ?? null, '') === '',
            'Photos'        => $photo === null,
            'Website'       => $website === '',
        ]));

        $subject = EMAIL_SUBJECT_PREFIX . ' Please verify your Lotus Elan registry record';

        // 1. Greeting
        $content = '<p>Hello <strong>' . $this->esc((string) ($owner->fname ?? '')) . '</strong>,</p>';

        // 2. Why you're getting this, plus the sign-off (static copy, matches the mockup)
        $content .= '<p>It\'s been a while since you created or updated the information in the'
            . ' Lotus Elan Registry. Please review the information below and let me know if it\'s'
            . ' still current, if you\'ve sold the car, or click through to update it.</p>'
            . '<p>Thank you,<br>Jim, aka The Registrar</p>';

        // 3. Verify + Sold, side by side
        $content .= $this->template->createButtonRow([
            ['label' => 'Verify', 'url' => $this->verifyUrl($vericode), 'style' => 'primary'],
            ['label' => 'Sold',   'url' => $this->soldUrl($vericode),   'style' => 'danger'],
        ]);

        // 4. Owner Information
        $content .= $this->template->createMessageBox(
            'Owner Information',
            $this->ownerRows($owner),
            'default'
        );

        // 5. Car Information
        $content .= $this->template->createMessageBox(
            'Car Information',
            $this->carRows($carData, $chassisOverride, $website, $blankFields, $photo),
            'default'
        );

        // 6. Conditional chassis explainer — only when the chassis was overridden
        if ($chassisOverride) {
            $content .= $this->template->createMessageBox(
                'About the Chassis Number',
                $this->template->createMessageContent(
                    "This doesn't quite match the usual pattern we'd expect — but that's common."
                    . " Lotus's own factory records from this era are incomplete and often disagree"
                    . " with the cars themselves. If you get a chance to check it against a logbook,"
                    . " title, or the plate itself, we'd love to know!"
                ),
                'alert'
            );
        }

        // 7. Conditional blank-field callout — names every highlighted field above
        if ($blankFields !== []) {
            $content .= $this->template->createMessageContent(
                $this->blankFieldCallout($blankFields)
            );
        }

        // 8. Single CTA. Requires logging in (unlike Verify/Sold, which work
        // directly from the link) — the label says so up front rather than
        // letting the owner discover a login wall after clicking.
        $content .= $this->template->createButton(
            'Login and Review or Update Your Car Record',
            $this->editUrl((int) ($carData->id ?? 0)),
            'primary'
        );

        // 9. Footer block. It lives in $content rather than the render()
        // `footer_text` option because that option is htmlspecialchars()'d by
        // getBaseTemplate(), which would render the anchor below as literal text.
        $content .= '<hr style="border: none; border-top: 1px solid #dee2e6; margin: 25px 0;">'
            . '<p style="font-size: 13px; color: #6b7280;">You are receiving this message because'
            . ' this car is registered to you in the Lotus Elan Registry. We send it no more than'
            . ' twice in any twelve-month period.</p>'
            . '<p style="font-size: 13px; color: #6b7280;"><a href="'
            . $this->esc($this->optOutUrl($vericode))
            . '">Stop sending me these</a></p>';

        // 10. Expiry notice
        $content .= '<p style="font-size: 13px; color: #6b7280;">These links are valid for '
            . self::LINK_TTL_DAYS . ' days from the date this email was sent, and can be used'
            . ' more than once during that window.</p>';

        return [
            'subject' => $subject,
            'html'    => $this->template->render(
                'Please Verify Your Registry Record',
                'Request for Information Verification',
                $content,
                ['footer_text' => 'You received this email because this car is registered to you'
                    . ' in the Lotus Elan Registry. Use the "Stop sending me these" link above to'
                    . ' opt out of future verification requests.']
            ),
        ];
    }

    /**
     * URL confirming the owner still owns the car.
     *
     * @param string $vericode Plaintext verification code
     * @return string Absolute URL
     */
    public function verifyUrl(string $vericode): string
    {
        return $this->actionUrl($vericode, 'verify');
    }

    /**
     * URL reporting the car has been sold.
     *
     * @param string $vericode Plaintext verification code
     * @return string Absolute URL
     */
    public function soldUrl(string $vericode): string
    {
        return $this->actionUrl($vericode, 'sold');
    }

    /**
     * URL opting the owner out of all future verification emails (#1883).
     *
     * @param string $vericode Plaintext verification code
     * @return string Absolute URL
     */
    public function optOutUrl(string $vericode): string
    {
        return $this->actionUrl($vericode, 'optout');
    }

    /**
     * URL of the full car edit form.
     *
     * @param int $carId Car id
     * @return string Absolute URL
     */
    public function editUrl(int $carId): string
    {
        return getBaseUrl() . '/app/owner/cars/edit.php?car_id=' . $carId;
    }

    /**
     * Build a verify_car.php action URL, matching that page's own link shape.
     *
     * @param string $vericode Plaintext verification code
     * @param string $action   'verify', 'sold', or 'optout'
     * @return string Absolute URL
     */
    private function actionUrl(string $vericode, string $action): string
    {
        return getBaseUrl() . '/app/verify/verify_car.php?vericode=' . rawurlencode($vericode)
            . '&action=' . $action;
    }

    /**
     * Build the escaped detail rows for the Owner Information box.
     *
     * @param object $owner Owner row
     * @return string HTML rows (every value escaped by createDetailRow())
     */
    private function ownerRows(object $owner): string
    {
        return $this->template->createDetailRow('User ID', $this->fieldOrDefault($owner->id ?? null))
            . $this->template->createDetailRow('First Name', $this->fieldOrDefault($owner->fname ?? null))
            . $this->template->createDetailRow('Last Name', $this->fieldOrDefault($owner->lname ?? null))
            . $this->template->createDetailRow('Email', $this->fieldOrDefault($owner->email ?? null))
            . $this->template->createDetailRow('City', $this->fieldOrDefault($owner->city ?? null))
            . $this->template->createDetailRow('State', $this->fieldOrDefault($owner->state ?? null))
            . $this->template->createDetailRow('Country', $this->fieldOrDefault($owner->country ?? null))
            . $this->template->createDetailRow('Join Date', $this->fieldOrDefault($owner->join_date ?? null));
    }

    /**
     * Build the escaped detail rows for the Car Information box.
     *
     * @param object $carData        Car row
     * @param bool   $chassisOverride Whether the chassis number was explicitly overridden
     * @param string $website        Trimmed website value ('' when blank)
     * @param array<int, string> $blankFields Labels (from the same set this
     *                                        method renders — 'Color',
     *                                        'Variant', 'Purchase Date',
     *                                        'Website') currently blank, plus
     *                                        'Photos' only when no safe photo
     *                                        is listed; each gets the highlighted
     *                                        row treatment
     * @param array{thumb: string|null, listed: int, onDisk: int}|null $photo
     *        primaryPhoto() result. The Photos row follows it: thumbnail,
     *        plain "N photos on file", or highlighted when null
     * @return string HTML rows
     */
    private function carRows(
        object $carData,
        bool $chassisOverride,
        string $website,
        array $blankFields,
        ?array $photo
    ): string {
        $websiteBlank = in_array('Website', $blankFields, true);

        $rows = $this->template->createDetailRow('Car ID', $this->fieldOrDefault($carData->id ?? null))
            . $this->template->createDetailRow('Year', $this->fieldOrDefault($carData->year ?? null))
            . $this->template->createDetailRow('Type', $this->fieldOrDefault($carData->type ?? null));

        $chassis = $this->fieldOrDefault($carData->chassis ?? null);
        if ($chassisOverride) {
            // Composed by this method: the only interpolated value is $chassis,
            // escaped here before it reaches the raw-HTML row; the badge markup
            // and its text are static.
            $rows .= $this->template->createRawDetailRow(
                'Chassis',
                $this->esc($chassis)
                . ' <span style="display: inline-block; background-color: #FFF9E0; color: #8a6d3b;'
                . ' border: 1px solid #f0d78c; border-radius: 10px; padding: 2px 10px;'
                . ' font-size: 12px; font-weight: bold;">Please double-check</span>'
            );
        } else {
            $rows .= $this->template->createDetailRow('Chassis', $chassis);
        }

        $rows .= $this->template->createDetailRow('Series', $this->fieldOrDefault($carData->series ?? null))
            . $this->template->createDetailRow(
                'Variant',
                $this->fieldOrDefault($carData->variant ?? null),
                in_array('Variant', $blankFields, true)
            )
            . $this->template->createDetailRow(
                'Color',
                $this->fieldOrDefault($carData->color ?? null),
                in_array('Color', $blankFields, true)
            )
            . $this->template->createDetailRow(
                'Purchase Date',
                $this->fieldOrDefault($carData->purchasedate ?? null),
                in_array('Purchase Date', $blankFields, true)
            )
            . $this->template->createDetailRow('Sold Date', $this->fieldOrDefault($carData->solddate ?? null))
            . $this->photosRow($photo, $carData)
            . $this->template->createDetailRow(
                'Website',
                $websiteBlank ? self::NOT_YET_PROVIDED : $website,
                $websiteBlank
            );

        return $rows;
    }

    /**
     * Build the "still blank" callout naming every highlighted field.
     *
     * @param array<int, string> $blankFields Non-empty list of blank field labels
     * @return string Plain-text sentence (escaped by createMessageContent())
     */
    private function blankFieldCallout(array $blankFields): string
    {
        $count = count($blankFields);
        $noun  = $count === 1 ? 'field' : 'fields';
        $verb  = $count === 1 ? 'is' : 'are';

        $list = $count <= 1
            ? implode('', $blankFields)
            : implode(', ', array_slice($blankFields, 0, -1)) . ' and ' . end($blankFields);

        return "{$count} {$noun} above {$verb} still blank — {$list}, highlighted above. If you"
            . " have that information handy, we'd love to add it, along with anything else"
            . " that's missing or outdated.";
    }

    /**
     * Build the Photos row for whichever of the three states applies.
     *
     * - Thumbnail: the primary photo and its -resized-300 file are on disk. A
     *   linked image plus "View all N photos", N being the photos on disk.
     * - Plain "N photos on file": photos are listed but no thumbnail can be
     *   shown. Not highlighted, not in the callout. primaryPhoto() logs it.
     * - Highlighted "Not yet provided": no safe photo is listed. compose()
     *   names Photos in the blank-field callout.
     *
     * @param array{thumb: string|null, listed: int, onDisk: int}|null $photo primaryPhoto() result
     * @param object $carData Car row
     * @return string HTML row
     */
    private function photosRow(?array $photo, object $carData): string
    {
        if ($photo === null) {
            return $this->template->createDetailRow('Photos', self::NOT_YET_PROVIDED, true);
        }

        if ($photo['thumb'] === null) {
            return $this->template->createDetailRow(
                'Photos',
                $photo['listed'] === 1 ? '1 photo on file' : $photo['listed'] . ' photos on file'
            );
        }

        return $this->template->createRawDetailRow(
            'Photos',
            $this->thumbnailHtml($photo['thumb'], $photo['onDisk'], $carData)
        );
    }

    /**
     * Find the car's primary photo and count the photos listed and on disk.
     *
     * Reads `cars.image` directly rather than going through CarImageProcessor,
     * which needs a repository and therefore a database connection this class
     * deliberately does without. The value is decoded as
     * CarImageProcessor::decodeAndProcessImages() does (JSON, else legacy
     * comma-separated). Entries that are not strings or fail
     * CarImageProcessor::isSafeFilename() are skipped, which also keeps a
     * crafted value from steering is_file() outside the car's folder.
     *
     * The primary photo is the first safe entry whose base file exists. This is
     * the same rule verifyPrimaryPhotoUrl() in verify_car.php uses, so the
     * email and the landing page show the same photo. The email links the
     * -resized-300 variant, and only when that file exists, so a recipient
     * never sees a broken image.
     *
     * Logs once under FileError when photos are listed but no thumbnail can be
     * shown, and when the value holds entries but none is safe. An empty list
     * (`[]`, stored after the last photo is removed) is the normal no-photo
     * state and logs nothing. Raw values and filesystem paths stay out of
     * the message.
     *
     * @param object $carData Car row (id, image)
     * @return array{thumb: string|null, listed: int, onDisk: int}|null Null when
     *         no safe photo is listed. Otherwise `thumb` is the variant filename
     *         (null when no thumbnail can be shown), `listed` counts safe
     *         entries and `onDisk` counts those whose base file exists
     */
    private function primaryPhoto(object $carData): ?array
    {
        $carId = (int) ($carData->id ?? 0);
        $raw   = $carData->image ?? null;
        if ($carId <= 0 || !is_string($raw) || trim($raw) === '') {
            return null;
        }

        $entries = $this->decodeImageEntries($raw);
        if ($entries === []) {
            return null;
        }

        $safe = array_values(array_filter(
            $entries,
            static fn (mixed $entry): bool => is_string($entry) && CarImageProcessor::isSafeFilename($entry)
        ));
        $unsafe = count($entries) - count($safe);
        if ($safe === []) {
            $this->logPhotoProblem($carId, sprintf(
                'has %d image entries, %d unsafe skipped; no usable photo',
                count($entries),
                $unsafe
            ));
            return null;
        }

        $dir    = $this->imageRoot . '/' . $carId . '/';
        $onDisk = array_values(array_filter($safe, static fn (string $name): bool => is_file($dir . $name)));
        $photo  = ['thumb' => null, 'listed' => count($safe), 'onDisk' => count($onDisk)];

        $reason = 'no base file on disk';
        if ($onDisk !== []) {
            $parts = pathinfo($onDisk[0]);
            // isSafeFilename() guarantees an extension; the default only satisfies the optional key's type.
            $candidate = $parts['filename'] . '-resized-300.' . ($parts['extension'] ?? '');
            if (is_file($dir . $candidate)) {
                $photo['thumb'] = $candidate;
                return $photo;
            }
            $reason = 'primary -resized-300 missing';
        }

        $this->logPhotoProblem($carId, sprintf(
            'lists %d photo(s), %d on disk, %d unsafe skipped; no thumbnail (%s)',
            count($safe),
            count($onDisk),
            $unsafe,
            $reason
        ));

        return $photo;
    }

    /**
     * Log a car whose listed photos cannot be shown, once per compose() call.
     *
     * The message holds counts and a fixed reason only. Raw `cars.image`
     * values and filesystem paths stay out of the logs table.
     *
     * @param int    $carId  Car id
     * @param string $detail Counts and reason, appended after the car id
     */
    private function logPhotoProblem(int $carId, string $detail): void
    {
        logger(0, LogCategories::LOG_CATEGORY_FILE_ERROR, "CarVerificationEmailComposer: car {$carId} {$detail}");
    }

    /**
     * Decode a `cars.image` value into its raw entries.
     *
     * Same order as CarImageProcessor::decodeAndProcessImages(): JSON first,
     * then the legacy comma-separated format. A JSON scalar yields no
     * entries, which matches the landing page: its foreach over a scalar
     * finds no photo.
     *
     * @param string $raw Non-empty `cars.image` value
     * @return array<mixed> Entries of unknown type, empty when the value is not a list
     */
    private function decodeImageEntries(string $raw): array
    {
        $decoded = json_decode($raw, true) ?? explode(',', $raw);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Build descriptive alt text naming the car, for the photo thumbnail.
     *
     * Blank parts are left out so the text never has double spaces or a
     * dangling comma. Not escaped; the caller escapes it.
     *
     * @param object $carData Car row (year, series, variant, type, color)
     * @return string e.g. "1969 Lotus Elan S4 SE FHC, British Racing Green"
     */
    private function photoAltText(object $carData): string
    {
        $alt = implode(' ', array_filter([
            $this->fieldOrDefault($carData->year ?? null, ''),
            'Lotus Elan',
            $this->fieldOrDefault($carData->series ?? null, ''),
            $this->fieldOrDefault($carData->variant ?? null, ''),
            $this->fieldOrDefault($carData->type ?? null, ''),
        ], static fn (string $part): bool => $part !== ''));
        $color = $this->fieldOrDefault($carData->color ?? null, '');

        return $color === '' ? $alt : $alt . ', ' . $color;
    }

    /**
     * Build the thumbnail state's trusted-HTML value: a 300px image of the
     * primary photo plus a link to the car's details page.
     *
     * Pure markup builder — existence checks already happened in
     * primaryPhoto(). The image has no height attribute because the variant's
     * longest side is 300px, so its aspect ratio varies.
     *
     * @param string $thumb  Variant filename from primaryPhoto()
     * @param int    $onDisk Number of photos on disk, for the link text
     * @param object $carData Car row (id, year, series, variant, type, color)
     * @return string Trusted HTML for createRawDetailRow() — every
     *                interpolated value is escaped by this method itself
     */
    private function thumbnailHtml(string $thumb, int $onDisk, object $carData): string
    {
        $carId     = (int) ($carData->id ?? 0);
        $thumbUrl  = $this->esc(getBaseUrl() . '/' . ELAN_IMAGE_DIR . $carId . '/' . $thumb);
        $detailUrl = $this->esc(getBaseUrl() . '/app/owner/cars/details.php?car_id=' . $carId);
        $alt       = $this->esc($this->photoAltText($carData));
        $linkText  = $this->esc(
            $onDisk === 1 ? 'View photo →' : 'View all ' . $onDisk . ' photos →'
        );

        return '<img src="' . $thumbUrl . '" alt="' . $alt . '" width="300"'
            . ' style="max-width: 100%; height: auto; border-radius: 6px; display: block;'
            . ' margin-bottom: 8px;">'
            . '<a href="' . $detailUrl . '">' . $linkText . '</a>';
    }

    /**
     * Render a possibly-missing field as display text.
     *
     * @param mixed  $value   Raw field value
     * @param string $default Text used when the value is null or blank
     * @return string Display text (never "null", never a PHP notice)
     */
    private function fieldOrDefault(mixed $value, string $default = self::NOT_SPECIFIED): string
    {
        if ($value === null || is_array($value) || is_object($value)) {
            return $default;
        }

        $text = trim((string) $value);

        return $text === '' ? $default : $text;
    }

    /**
     * HTML-escape a value for interpolation into raw-HTML template parameters.
     *
     * @param string $value Raw value
     * @return string Escaped value
     */
    private function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
