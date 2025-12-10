<?php

declare(strict_types=1);

/**
 * Markdown Parser Utility
 *
 * A lightweight markdown to HTML converter for documentation rendering.
 * Supports headers, bold/italic text, links, code blocks, and lists.
 *
 * @package ElanRegistry\Documentation
 * @version 2.9.0
 * @author Jim Boone
 */

namespace ElanRegistry\Documentation;

class MarkdownParser
{
    /**
     * Convert markdown content to HTML
     *
     * @param string $markdown The markdown content to convert
     * @return string The converted HTML content
     */
    public static function toHtml(string $markdown): string
    {
        // Headers
        $markdown = preg_replace('/^# (.*)$/m', '<h1>$1</h1>', $markdown);
        $markdown = preg_replace('/^## (.*)$/m', '<h2>$1</h2>', $markdown);
        $markdown = preg_replace('/^### (.*)$/m', '<h3>$1</h3>', $markdown);
        $markdown = preg_replace('/^#### (.*)$/m', '<h4>$1</h4>', $markdown);
        $markdown = preg_replace('/^##### (.*)$/m', '<h5>$1</h5>', $markdown);

        // Bold and italic
        $markdown = preg_replace('/\*\*(.*?)\*\*/', '<strong>$1</strong>', $markdown);
        $markdown = preg_replace('/\*(.*?)\*/', '<em>$1</em>', $markdown);

        // Links with XSS protection
        $markdown = preg_replace_callback(
            '/\[([^\]]+)\]\(([^)]+)\)/',
            function ($matches) {
                $text = htmlspecialchars($matches[1], ENT_QUOTES, 'UTF-8');
                $url = htmlspecialchars($matches[2], ENT_QUOTES, 'UTF-8');
                // Only allow safe URL schemes
                if (self::isSafeUrl($url)) {
                    return '<a href="' . $url . '" target="_blank">' . $text . '</a>';
                }
                return $text; // Return just the text if URL is unsafe
            },
            $markdown
        );

        // Code blocks
        $markdown = preg_replace('/```(.*?)```/s', '<pre><code>$1</code></pre>', $markdown);
        $markdown = preg_replace('/`([^`]+)`/', '<code>$1</code>', $markdown);

        // Process lists
        $markdown = self::processLists($markdown);

        // Line breaks and paragraphs
        $markdown = preg_replace('/\n\n+/', '</p><p>', $markdown);
        $markdown = '<p>' . $markdown . '</p>';

        // Clean up extra p tags around headers and lists
        $cleanupPatterns = [
            '/<p><h([1-6])/' => '<h$1',
            '/<\/h([1-6])><\/p>/' => '</h$1>',
            '/<p><ul>/' => '<ul>',
            '/<\/ul><\/p>/' => '</ul>',
            '/<p><ol>/' => '<ol>',
            '/<\/ol><\/p>/' => '</ol>',
            '/<p><pre>/' => '<pre>',
            '/<\/pre><\/p>/' => '</pre>',
            '/<p><\/p>/' => '',
            '/<p>\s*<\/p>/' => ''
        ];

        foreach ($cleanupPatterns as $pattern => $replacement) {
            $markdown = preg_replace($pattern, $replacement, $markdown);
        }

        return $markdown;
    }

    /**
     * Process markdown lists (both bullet and numbered)
     *
     * @param string $markdown The markdown content
     * @return string Processed content with HTML lists
     */
    private static function processLists(string $markdown): string
    {
        $lines = explode("\n", $markdown);
        $inList = false;
        $listType = null; // 'ul' or 'ol'

        for ($i = 0; $i < count($lines); $i++) {
            $line = $lines[$i];

            // Bullet lists
            if (preg_match('/^(\s*)- (.*)$/', $line, $matches)) {
                $content = $matches[2]; // Don't escape - HTML tags already created from markdown

                if (!$inList || $listType !== 'ul') {
                    if ($inList) {
                        $lines[$i-1] .= '</' . $listType . '>';
                    }
                    $lines[$i] = '<ul><li>' . $content . '</li>';
                    $inList = true;
                    $listType = 'ul';
                } else {
                    $lines[$i] = '<li>' . $content . '</li>';
                }
            }
            // Numbered lists
            elseif (preg_match('/^(\s*)\d+\. (.*)$/', $line, $matches)) {
                $content = $matches[2]; // Don't escape - HTML tags already created from markdown

                if (!$inList || $listType !== 'ol') {
                    if ($inList) {
                        $lines[$i-1] .= '</' . $listType . '>';
                    }
                    $lines[$i] = '<ol><li>' . $content . '</li>';
                    $inList = true;
                    $listType = 'ol';
                } else {
                    $lines[$i] = '<li>' . $content . '</li>';
                }
            } else {
                if ($inList && trim($line) === '') {
                    // Keep list open for empty lines
                    continue;
                } elseif ($inList) {
                    // Close the list
                    $lines[$i-1] .= '</' . $listType . '>';
                    $inList = false;
                    $listType = null;
                }
            }
        }

        // Close any remaining open list
        if ($inList) {
            $lines[count($lines)-1] .= '</' . $listType . '>';
        }

        return implode("\n", $lines);
    }

    /**
     * Check if a URL is safe to include in links
     *
     * @param string $url The URL to check
     * @return bool True if the URL is safe
     */
    private static function isSafeUrl(string $url): bool
    {
        // Allow relative URLs and safe schemes
        if (strpos($url, '/') === 0) {
            return true; // Relative URL
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);
        $safeSchemes = ['http', 'https', 'mailto'];

        return in_array(strtolower($scheme), $safeSchemes, true);
    }

    /**
     * Sanitize HTML output to prevent XSS
     *
     * @param string $html The HTML to sanitize
     * @return string Sanitized HTML
     */
    public static function sanitizeHtml(string $html): string
    {
        // Allow basic HTML tags but strip dangerous ones
        $allowedTags = '<h1><h2><h3><h4><h5><h6><p><br><strong><em><a><ul><ol><li><pre><code><blockquote><table><thead><tbody><tr><th><td>';

        return strip_tags($html, $allowedTags);
    }
}