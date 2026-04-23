<?php

declare(strict_types=1);

/**
 * Documentation Portal Template
 *
 * Static rendering utilities for documentation portal pages.
 * Eliminates duplicated Bootstrap card/grid HTML across portal index pages.
 *
 * @package ElanRegistry\Documentation
 */

namespace ElanRegistry\Documentation;

class DocumentPortalTemplate
{
    /** @var list<string> */
    private const REQUIRED_HEADER_KEYS = ['title', 'description'];

    /** @var list<string> */
    private const REQUIRED_CARD_KEYS = ['title', 'icon', 'url', 'buttonText'];

    /** @var list<string> */
    private const REQUIRED_LINK_KEYS = ['label', 'url'];

    private const DEFAULT_CARD_CLASS   = 'registry-card h-100';
    private const DEFAULT_HEADER_CLASS = 'bg-primary text-white';
    private const DEFAULT_BUTTON_CLASS = 'btn-primary btn-sm';
    private const DEFAULT_BTN_CLASS    = 'btn-outline-primary';
    private const DEFAULT_COL_CLASS    = 'col-lg-4';

    /**
     * Render the portal heading card (page title + description).
     *
     * @param array{
     *     title: string,
     *     description: string,
     *     titleIcon?: string,
     *     headerClass?: string,
     *     leadText?: string,
     *     isAdmin?: bool
     * } $config
     * @throws \InvalidArgumentException if required keys are missing
     */
    public static function renderPortalHeader(array $config): string
    {
        self::requireKeys($config, self::REQUIRED_HEADER_KEYS, 'renderPortalHeader');

        $title       = htmlspecialchars($config['title'], ENT_QUOTES, 'UTF-8');
        $description = htmlspecialchars($config['description'], ENT_QUOTES, 'UTF-8');
        $headerClass = isset($config['headerClass'])
            ? ' ' . htmlspecialchars($config['headerClass'], ENT_QUOTES, 'UTF-8')
            : '';

        $html  = "<div class='row'>";
        $html .= "<div class='col-12'>";
        $html .= "<div class='card registry-card'>";
        $html .= "<div class='card-header{$headerClass}'>";
        $html .= "<h1 class='mb-0'>" . self::renderIcon($config['titleIcon'] ?? '') . "{$title}</h1>";
        $html .= "<p class='text-muted mb-0'>{$description}</p>";
        $html .= "</div>";
        $html .= "<div class='card-body'>";

        if (isset($config['leadText']) && $config['leadText'] !== '') {
            $leadText = htmlspecialchars($config['leadText'], ENT_QUOTES, 'UTF-8');
            $html    .= "<p class='lead'>{$leadText}</p>";
        }

        if (!empty($config['isAdmin'])) {
            $html .= "<div class='alert alert-warning'>";
            $html .= "<i class='fas fa-exclamation-triangle'></i> <strong>Administrator Access Required</strong><br>";
            $html .= "This documentation is intended for registry administrators and technical staff only.";
            $html .= "</div>";
        }

        $html .= "</div>";
        $html .= "</div>";
        $html .= "</div>";
        $html .= "</div>";

        return $html;
    }

    /**
     * Render a single document registry-card.
     *
     * @param array{
     *     title: string,
     *     icon: string,
     *     url: string,
     *     buttonText: string,
     *     headerClass?: string,
     *     buttonClass?: string,
     *     cardClass?: string,
     *     description?: string,
     *     metadata?: string,
     *     listItems?: list<array{icon: string, text: string}>,
     *     buttonIcon?: string,
     *     headerStyle?: string,
     *     buttonStyle?: string,
     *     colClass?: string
     * } $card
     * @throws \InvalidArgumentException if required keys are missing
     */
    public static function renderDocumentCard(array $card): string
    {
        self::requireKeys($card, self::REQUIRED_CARD_KEYS, 'renderDocumentCard');

        $title       = htmlspecialchars($card['title'], ENT_QUOTES, 'UTF-8');
        $icon        = htmlspecialchars($card['icon'], ENT_QUOTES, 'UTF-8');
        $url         = htmlspecialchars($card['url'], ENT_QUOTES, 'UTF-8');
        $buttonText  = htmlspecialchars($card['buttonText'], ENT_QUOTES, 'UTF-8');
        $cardClass   = isset($card['cardClass'])   ? htmlspecialchars($card['cardClass'],   ENT_QUOTES, 'UTF-8') : self::DEFAULT_CARD_CLASS;
        $headerClass = isset($card['headerClass']) ? htmlspecialchars($card['headerClass'], ENT_QUOTES, 'UTF-8') : self::DEFAULT_HEADER_CLASS;
        $buttonClass = isset($card['buttonClass']) ? htmlspecialchars($card['buttonClass'], ENT_QUOTES, 'UTF-8') : self::DEFAULT_BUTTON_CLASS;

        $headerStyleAttr = self::renderStyleAttr($card['headerStyle'] ?? '');
        $buttonStyleAttr = self::renderStyleAttr($card['buttonStyle'] ?? '');

        $html  = "<div class='card {$cardClass}'>";
        $html .= "<div class='card-header {$headerClass}'{$headerStyleAttr}>";
        $html .= "<h5 class='mb-0'><i class='fas {$icon}'></i> {$title}</h5>";
        $html .= "</div>";
        $html .= "<div class='card-body d-flex flex-column'>";

        if (isset($card['description']) && $card['description'] !== '') {
            $html .= "<p class='card-text'>" . htmlspecialchars($card['description'], ENT_QUOTES, 'UTF-8') . "</p>";
        }

        if (isset($card['metadata']) && $card['metadata'] !== '') {
            $html .= "<p class='small text-muted'>" . htmlspecialchars($card['metadata'], ENT_QUOTES, 'UTF-8') . "</p>";
        }

        if (!empty($card['listItems'])) {
            $html .= "<ul class='list-unstyled'>";
            foreach ($card['listItems'] as $index => $item) {
                self::requireKeys($item, ['icon', 'text'], "renderDocumentCard listItems[{$index}]");
                $itemText  = htmlspecialchars($item['text'], ENT_QUOTES, 'UTF-8');
                $html     .= "<li>" . self::renderIcon($item['icon']) . "{$itemText}</li>";
            }
            $html .= "</ul>";
        }

        $html .= "<div class='mt-auto'>";
        $html .= "<a href='{$url}' class='btn {$buttonClass}'{$buttonStyleAttr}>" . self::renderIcon($card['buttonIcon'] ?? '') . "{$buttonText}</a>";
        $html .= "</div>";
        $html .= "</div>";
        $html .= "</div>";

        return $html;
    }

    /**
     * Render a Bootstrap row of document cards.
     *
     * The default column class is col-lg-4. Individual cards may override via
     * $card['colClass'].
     *
     * @param list<array<string, mixed>> $cards
     */
    public static function renderDocumentCardGrid(array $cards, string $colClass = self::DEFAULT_COL_CLASS): string
    {
        if ($cards === []) {
            return '';
        }

        $escapedDefaultCol = htmlspecialchars($colClass, ENT_QUOTES, 'UTF-8');
        $html              = "<div class='row mt-4'>";

        foreach ($cards as $card) {
            $col   = isset($card['colClass'])
                ? htmlspecialchars((string) $card['colClass'], ENT_QUOTES, 'UTF-8')
                : $escapedDefaultCol;
            $html .= "<div class='{$col} mb-4'>";
            $html .= self::renderDocumentCard($card);
            $html .= "</div>";
        }

        $html .= "</div>";

        return $html;
    }

    /**
     * Render an h2 section heading row.
     *
     * @param string $icon       Font Awesome icon class without 'fas ' prefix (e.g. 'fa-code')
     * @param string $title      Section heading text
     * @param string $colorClass Optional Bootstrap text color class applied to the icon only,
     *                           not the heading text (e.g. 'text-danger' tints the icon).
     */
    public static function renderSectionHeading(string $icon, string $title, string $colorClass = ''): string
    {
        $escapedIcon  = htmlspecialchars($icon, ENT_QUOTES, 'UTF-8');
        $escapedTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
        $iconClasses  = 'fas ' . $escapedIcon . ($colorClass !== '' ? ' ' . htmlspecialchars($colorClass, ENT_QUOTES, 'UTF-8') : '');

        $html  = "<div class='row mt-4'>";
        $html .= "<div class='col-12'>";
        $html .= "<h2><i class='{$iconClasses}'></i> {$escapedTitle}</h2>";
        $html .= "</div>";
        $html .= "</div>";

        return $html;
    }

    /**
     * Render the navigation footer row.
     *
     * @param list<array{label: string, url: string, icon?: string, btnClass?: string}> $links
     * @throws \InvalidArgumentException if any link is missing required keys
     */
    public static function renderNavFooter(array $links): string
    {
        if ($links === []) {
            return '';
        }

        $lastIndex = count($links) - 1;

        $html  = "<div class='row mt-4 mb-4'>";
        $html .= "<div class='col-12 text-center'>";

        foreach ($links as $index => $link) {
            self::requireKeys($link, self::REQUIRED_LINK_KEYS, 'renderNavFooter');

            $label    = htmlspecialchars($link['label'], ENT_QUOTES, 'UTF-8');
            $url      = htmlspecialchars($link['url'], ENT_QUOTES, 'UTF-8');
            $btnClass = isset($link['btnClass'])
                ? htmlspecialchars($link['btnClass'], ENT_QUOTES, 'UTF-8')
                : self::DEFAULT_BTN_CLASS;
            $classes  = 'btn ' . $btnClass . ($index < $lastIndex ? ' mr-2' : '');

            $html .= "<a href='{$url}' class='{$classes}'>" . self::renderIcon($link['icon'] ?? '') . "{$label}</a>";
        }

        $html .= "</div>";
        $html .= "</div>";

        return $html;
    }

    /**
     * Render a Font Awesome icon element, or empty string if no icon class given.
     *
     * Returns "<i class='fas {escapedClass}'></i> " when $iconClass is non-empty,
     * so callers can concatenate directly without an extra space check.
     */
    private static function renderIcon(string $iconClass): string
    {
        if ($iconClass === '') {
            return '';
        }

        return "<i class='fas " . htmlspecialchars($iconClass, ENT_QUOTES, 'UTF-8') . "'></i> ";
    }

    /**
     * Render an inline style attribute string, or empty string if no value given.
     */
    private static function renderStyleAttr(string $style): string
    {
        if ($style === '') {
            return '';
        }

        return " style='" . htmlspecialchars($style, ENT_QUOTES, 'UTF-8') . "'";
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string> $keys
     * @throws \InvalidArgumentException
     */
    private static function requireKeys(array $data, array $keys, string $context): void
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $data)) {
                throw new \InvalidArgumentException(
                    "DocumentPortalTemplate::{$context}: missing required key '{$key}'"
                );
            }
        }
    }
}
