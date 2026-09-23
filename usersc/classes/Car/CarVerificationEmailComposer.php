<?php

declare(strict_types=1);

namespace ElanRegistry\Car;

use ElanRegistry\EmailTemplate;

/**
 * CarVerificationEmailComposer - Builds the periodic owner verification email
 *
 * Composes the subject line and full branded HTML body for the verification
 * email an owner receives at most twice per twelve months, asking them to
 * confirm their car record is still accurate. The rendered layout matches the
 * approved mockup (docs/plans/car-owner-verification/images/exhibit-a-email.png):
 * greeting, Verify/Sold buttons, Owner Information, Car Information (with the
 * conditional chassis-override badge and highlighted rows for any blank
 * optional field — Color, Variant, Purchase Date, Website), the conditional
 * "About the Chassis Number" alert, the edit CTA, and a footer carrying the
 * one-click opt-out link.
 *
 * This class performs NO database access. Everything it renders comes from the
 * `$carData` and `$owner` objects supplied by the caller — the same shapes
 * app/verify/verify_car.php already builds — which keeps it unit-testable with
 * no framework bootstrap beyond getBaseUrl().
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
     * Was meant to mark links for Brevo click-tracking exclusion (#1883 AC9),
     * but Brevo has no per-link tracking-exclusion mechanism for transactional
     * email — no CSS class, tag, or API parameter accomplishes this.
     * Confirmed during #2147's investigation. This class is currently inert;
     * see #2147 for the decision on whether to remove it.
     */
    public const NO_TRACK_LINK_CLASS = 'er-no-track';

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

    /** Display text used for a blank website, which gets the highlighted row treatment. */
    private const WEBSITE_NOT_PROVIDED = 'Not yet provided';

    private EmailTemplate $template;

    /**
     * @param EmailTemplate|null $template Renderer to compose with; a default
     *                                     instance is created when null, so
     *                                     tests can inject their own.
     */
    public function __construct(?EmailTemplate $template = null)
    {
        $this->template = $template ?? new EmailTemplate();
    }

    /**
     * Compose the verification email's subject line and HTML body.
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
        $website         = trim((string) ($carData->website ?? ''));

        // Fields checked for the highlight/callout treatment: genuinely
        // optional/supplementary details an owner might not have filled in
        // yet. Deliberately excludes id/year/type/chassis/series — near-
        // mandatory structural identifiers a car record shouldn't exist
        // without — and solddate, whose blankness for an unsold car is the
        // expected state, not missing data.
        $blankFields = [];
        foreach ([
            'Color'         => trim((string) ($carData->color ?? '')),
            'Variant'       => trim((string) ($carData->variant ?? '')),
            'Purchase Date' => trim((string) ($carData->purchasedate ?? '')),
            'Website'       => $website,
        ] as $label => $value) {
            if ($value === '') {
                $blankFields[] = $label;
            }
        }

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
            $this->carRows($carData, $chassisOverride, $website, $blankFields),
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
            // #1883 AC9 — NOTE (#2147): this class name was meant to mark the
            // link for Brevo click-tracking exclusion, but no such per-link
            // exclusion mechanism exists in Brevo's API for transactional
            // email — confirmed during #2147's investigation. The class is
            // currently inert; Brevo tracks this link like any other.
            . '<p style="font-size: 13px; color: #6b7280;"><a href="'
            . $this->esc($this->optOutUrl($vericode))
            . '" class="' . self::NO_TRACK_LINK_CLASS . '">Stop sending me these</a></p>';

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
     *                                        'Website') currently blank;
     *                                        each gets the highlighted row
     *                                        treatment
     * @return string HTML rows
     */
    private function carRows(
        object $carData,
        bool $chassisOverride,
        string $website,
        array $blankFields
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
            . $this->template->createRawDetailRow('Photos', $this->photoRow($carData))
            . $this->template->createDetailRow(
                'Website',
                $websiteBlank ? self::WEBSITE_NOT_PROVIDED : $website,
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
     * Build the Photos row's trusted-HTML value: a thumbnail of the first
     * photo plus a "View all N photos" link, or a plain "None on file" text
     * when there are none.
     *
     * Reads `cars.image` (a JSON array of bare filenames) directly rather than
     * going through CarImageProcessor, which needs a repository and therefore a
     * database connection this class deliberately does without — so the
     * thumbnail path is built as `{basename}-resized-100.{ext}`
     * (CarImageProcessor's documented resize-variant naming) rather than
     * confirmed to exist on disk. A missing resized variant renders as a
     * broken image icon in the recipient's mail client, which is an accepted
     * tradeoff (the same risk any email image already carries, and most mail
     * clients block images by default until the viewer opts in) rather than
     * omitting the thumbnail the approved mockup shows.
     *
     * @param object $carData Car row (id, image)
     * @return string Trusted HTML for createRawDetailRow() — every
     *                interpolated value is escaped by this method itself
     */
    private function photoRow(object $carData): string
    {
        $raw = $carData->image ?? null;
        if (!is_string($raw) || trim($raw) === '') {
            return 'None on file';
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || $decoded === []) {
            return 'None on file';
        }

        $first = $decoded[0] ?? null;
        if (!is_string($first) || trim($first) === '') {
            return $this->photoSummary($carData);
        }

        $carId = (int) ($carData->id ?? 0);
        $dot   = strrpos($first, '.');
        $thumb = $dot === false
            ? $first . '-resized-100'
            : substr($first, 0, $dot) . '-resized-100' . substr($first, $dot);

        $thumbUrl  = $this->esc(getBaseUrl() . '/userimages/' . $carId . '/' . $thumb);
        $detailUrl = $this->esc(getBaseUrl() . '/app/owner/cars/details.php?car_id=' . $carId);
        $count     = count($decoded);
        $linkText  = $count === 1 ? 'View photo →' : "View all {$count} photos →";

        return '<img src="' . $thumbUrl . '" alt="Car photo" width="80" height="60"'
            . ' style="border-radius: 6px; object-fit: cover; vertical-align: middle;'
            . ' margin-right: 10px;">'
            . '<a href="' . $detailUrl . '" style="vertical-align: middle;">' . $linkText . '</a>';
    }

    /**
     * Describe how many photos the car has on file, as plain text.
     *
     * Used as photoRow()'s fallback when the first filename entry is
     * malformed (present but not a usable string) — still reports an
     * accurate count without attempting to build a thumbnail URL from data
     * that isn't a filename.
     *
     * @param object $carData Car row
     * @return string Human-readable photo count
     */
    private function photoSummary(object $carData): string
    {
        $raw = $carData->image ?? null;
        if (!is_string($raw) || trim($raw) === '') {
            return 'None on file';
        }

        $decoded = json_decode($raw, true);
        $count   = is_array($decoded) ? count($decoded) : 0;

        if ($count === 0) {
            return 'None on file';
        }

        return $count === 1 ? '1 photo on file' : $count . ' photos on file';
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
