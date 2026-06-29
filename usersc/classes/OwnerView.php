<?php

declare(strict_types=1);

namespace ElanRegistry;

/**
 * OwnerView - Static utility class for owner display and view functions
 *
 * This class provides static methods for owner display and HTML generation
 * operations used across multiple pages. All methods are static and return
 * HTML strings. Separated from data operations following proper MVC patterns.
 */
class OwnerView
{
    private const QUALITY_SCORE_SUCCESS = 80;
    private const QUALITY_SCORE_WARNING = 60;

    /**
     * Build an escaped display name from the owner's first and last name
     *
     * @param object $owner Owner object with fname and lname properties
     * @return string Escaped display name, or empty string if no name
     */
    public static function displayName(object $owner): string
    {
        $name = trim(($owner->fname ?? '') . ' ' . ($owner->lname ?? ''));

        if ($name === '') {
            return '';
        }

        return htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Map a quality score to a Bootstrap contextual class
     *
     * @param float $score Quality score (0-100)
     * @return string Bootstrap contextual class (success, warning, or danger)
     */
    public static function qualityBadgeClass(float $score): string
    {
        if ($score >= self::QUALITY_SCORE_SUCCESS) {
            return 'success';
        }

        if ($score >= self::QUALITY_SCORE_WARNING) {
            return 'warning';
        }

        return 'danger';
    }

    /**
     * Display a quality score as a Bootstrap badge
     *
     * @param float $score Quality score (0-100)
     * @return string HTML string for the badge
     */
    public static function displayQualityBadge(float $score): string
    {
        $class = self::qualityBadgeClass($score);

        return '<span class="badge text-bg-' . $class . ' badge-sm">Quality: ' . (int) $score . '%</span>';
    }

    /**
     * Display a quality score as a Bootstrap progress bar
     *
     * @param float $score Quality score (0-100)
     * @param string $height CSS height value for the progress bar
     * @return string HTML string for the progress bar
     */
    public static function displayQualityProgressBar(float $score, string $height = '8px'): string
    {
        // Validate height against a safe CSS length pattern to prevent style injection.
        if (!preg_match('/^\d+(\.\d+)?(px|em|rem|%)$/', $height)) {
            $height = '8px';
        }

        $class = self::qualityBadgeClass($score);
        $value = (int) $score;

        $html = '<!--Start displayQualityProgressBar-->';
        $html .= '<div class="progress" style="height: ' . $height . '">';
        $html .= '<div class="progress-bar bg-' . $class . '" role="progressbar"';
        $html .= ' style="width: ' . $value . '%"';
        $html .= ' aria-valuenow="' . $value . '" aria-valuemin="0" aria-valuemax="100">';
        $html .= '</div>';
        $html .= '</div>';
        $html .= '<!--End displayQualityProgressBar-->';

        return $html;
    }

    /**
     * Build an escaped, comma-separated location string from the owner's
     * city, state, and country
     *
     * @param object $owner Owner object with city, state, and country properties
     * @return string Escaped location string, or empty string if no parts
     */
    public static function displayLocation(object $owner): string
    {
        $parts = array_filter([
            $owner->city ?? '',
            $owner->state ?? '',
            $owner->country ?? '',
        ], fn($v) => $v !== '');

        if (empty($parts)) {
            return '';
        }

        return htmlspecialchars(implode(', ', $parts), ENT_QUOTES, 'UTF-8');
    }

    /**
     * Display the owner's email and optional website as links
     *
     * @param object $owner Owner object with email and website properties
     * @return string HTML string for the contact info, or empty string if no data
     */
    public static function displayContactInfo(object $owner): string
    {
        $emailHtml = '';
        $email = $owner->email ?? '';

        if ($email !== '') {
            $escapedEmail = htmlspecialchars($email, ENT_QUOTES, 'UTF-8');
            $emailHtml = '<a href="mailto:' . $escapedEmail . '">' . $escapedEmail . '</a>';
        }

        $websiteHtml = '';
        $website = $owner->website ?? '';

        if ($website !== '') {
            $scheme = strtolower((string) (parse_url((string) $website, PHP_URL_SCHEME) ?? ''));

            if (in_array($scheme, ['http', 'https'], true)) {
                $escapedUrl = htmlspecialchars($website, ENT_QUOTES, 'UTF-8');
                $websiteHtml = '<a href="' . $escapedUrl . '" target="_blank" rel="noopener noreferrer">' . $escapedUrl . '</a>';
            }
        }

        if ($emailHtml === '' && $websiteHtml === '') {
            return '';
        }

        return $emailHtml . ($websiteHtml ? '<br>' . $websiteHtml : '');
    }

    /**
     * Display a list of missing profile fields with warning icons
     *
     * @param array $fields List of missing field labels
     * @return string HTML string for the list, or empty string if no fields
     */
    public static function displayMissingFields(array $fields): string
    {
        if (empty($fields)) {
            return '';
        }

        $html = '<!--Start displayMissingFields-->';
        $html .= '<ul class="list-unstyled mb-0">';

        foreach ($fields as $field) {
            $escapedField = htmlspecialchars($field, ENT_QUOTES, 'UTF-8');
            $html .= '<li><i class="fas fa-exclamation-triangle text-warning"></i> ' . $escapedField . '</li>';
        }

        $html .= '</ul>';
        $html .= '<!--End displayMissingFields-->';

        return $html;
    }
}
