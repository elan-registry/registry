<?php

declare(strict_types=1);

/**
 * Markdown Renderer
 *
 * Converts Markdown to HTML using league/commonmark 2.x with GFM support,
 * heading permalinks, and external-link safety attributes. Replaces the
 * bespoke MarkdownParser with a fully spec-compliant implementation.
 *
 * Security model:
 * - html_input: 'strip' removes raw HTML embedded in Markdown (XSS via inline HTML)
 * - allow_unsafe_links: false blocks javascript:, data:, and vbscript: URIs in href/src
 * - ExternalLinkExtension adds rel="noopener noreferrer" to all external links
 * - Post-processing regex strips data: URIs from img src attributes
 *   (belt-and-suspenders; case-insensitive with leading-whitespace guard)
 *
 * @package ElanRegistry\Documentation
 * @since v2.24.0
 * @see Issue #815 — Replace MarkdownParser with league/commonmark
 */

namespace ElanRegistry\Documentation;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\ExternalLink\ExternalLinkExtension;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\Extension\HeadingPermalink\HeadingPermalinkExtension;
use League\CommonMark\MarkdownConverter as CommonMarkConverter;

class MarkdownRenderer
{
    /**
     * Convert Markdown to safe HTML.
     *
     * Runs the full league/commonmark pipeline (CommonMark + GFM + heading
     * permalinks + external-link attributes), then applies post-processing:
     *
     * 1. All <img> tags receive the Bootstrap `img-fluid` class.
     * 2. data: URIs in image src attributes are stripped (see security model above).
     * 3. When $baseUrl is supplied, relative URLs in src/href attributes are
     *    resolved:
     *    - Root-relative paths (starting with `/`) are prefixed with $baseUrl.
     *    - All other relative paths are prefixed with `{$baseUrl}/docs/`.
     *    - Absolute URLs (http/https/mailto) and anchor fragments (#) are left
     *      unchanged.
     *
     * @param string $markdown Raw Markdown content to convert. Must be valid UTF-8.
     * @param string $baseUrl  Optional site root URL without trailing slash
     *                         (e.g. `$us_url_root`); trailing slash is accepted
     *                         and stripped internally.
     * @return string          Converted, post-processed HTML.
     * @throws \League\CommonMark\Exception\UnexpectedEncodingException if $markdown
     *         is not valid UTF-8 or ASCII.
     */
    public static function convert(string $markdown, string $baseUrl = ''): string
    {
        $environment = new Environment([
            'html_input'         => 'strip',
            'allow_unsafe_links' => false,
            'external_link'      => [
                'open_in_new_window' => true,
                'noopener'           => 'external',
                'noreferrer'         => 'external',
            ],
            'heading_permalink'  => [
                'symbol'          => '',
                'html_class'      => '',
                'id_prefix'       => '',
                'fragment_prefix' => '',
            ],
        ]);

        $environment->addExtension(new CommonMarkCoreExtension());
        $environment->addExtension(new GithubFlavoredMarkdownExtension());
        $environment->addExtension(new HeadingPermalinkExtension());
        $environment->addExtension(new ExternalLinkExtension());

        $converter = new CommonMarkConverter($environment);
        $html      = $converter->convert($markdown)->getContent();

        if (str_contains($html, '<img ')) {
            $html = preg_replace('/<img /', '<img class="img-fluid" ', $html) ?? $html;
        }

        // Strip data: URIs from image src attributes. allow_unsafe_links covers
        // href only; this extends the same protection to src.
        // Case-insensitive + leading-whitespace guard to match obfuscated variants.
        if (str_contains(strtolower($html), 'src="') && str_contains(strtolower($html), 'data:')) {
            $html = preg_replace('/src="\s*data:[^"]*"/i', 'src=""', $html) ?? $html;
        }

        if ($baseUrl !== '') {
            $base = rtrim($baseUrl, '/');
            $html = preg_replace_callback(
                '/(src|href)="([^"]*)"/',
                static function (array $matches) use ($base): string {
                    $attr = $matches[1];
                    $url  = $matches[2];

                    // Leave empty, fragment, absolute URLs (any scheme), and mailto untouched.
                    // 'http' covers both http:// and https://.
                    if (
                        $url === ''
                        || str_starts_with($url, '#')
                        || str_starts_with($url, 'http')
                        || str_starts_with($url, 'mailto:')
                        || str_contains($url, '://')
                    ) {
                        return $attr . '="' . $url . '"';
                    }

                    // Root-relative: prepend base only.
                    if (str_starts_with($url, '/')) {
                        return $attr . '="' . $base . $url . '"';
                    }

                    // All other relative URLs: prepend base + /docs/.
                    return $attr . '="' . $base . '/docs/' . $url . '"';
                },
                $html
            ) ?? $html;
        }

        return $html;
    }
}
