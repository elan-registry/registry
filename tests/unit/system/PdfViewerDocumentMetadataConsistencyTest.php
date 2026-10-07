<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Consistency gate for Issue #1538.
 *
 * docs/pdf-viewer.php's inline `$document_metadata` map (title/description
 * per reference PDF, used for SEO — see the comment above that array in
 * that file) and usersc/classes/SitemapService.php's STATIC_PAGES PDF
 * entries are two hand-maintained lists describing the same set of files,
 * edited independently. Nothing else enforces they stay in sync with each
 * other or with the files actually on disk — missing a map entry silently
 * regresses a page back to the generic soft-404-prone title, and a wrong
 * hand-encoded sitemap path publishes a 404 URL in sitemap.xml (the exact
 * class of problem this issue exists to fix).
 *
 * Pure static-text scan (file_get_contents() + regex): no database, no
 * bootstrapped UserSpice environment, so it can't accidentally pass by
 * executing the page. The PageMetadataBeforeInitRule PHPStan rule checks
 * that every page sets its metadata.
 */
#[Group('system')]
#[Group('fast')]
final class PdfViewerDocumentMetadataConsistencyTest extends TestCase
{
    private const PDF_VIEWER_PATH = 'docs/pdf-viewer.php';
    private const SITEMAP_SERVICE_PATH = 'usersc/classes/SitemapService.php';
    private const REFERENCE_ASSETS_DIR = 'docs/reference/assets/';

    /** @var array<string, array{title: string, description: string}>|null Cached across the test methods in this run. */
    private static ?array $documentMetadataCache = null;

    public function testDocumentMetadataMapIsNotEmpty(): void
    {
        $metadata = self::documentMetadata();
        $this->assertNotEmpty($metadata, 'docs/pdf-viewer.php must define at least one $document_metadata entry (Issue #1538)');
    }

    public function testDocumentMetadataKeysMatchSitemapPdfEntries(): void
    {
        $metadataKeys = array_keys(self::documentMetadata());
        sort($metadataKeys);

        $sitemapFilenames = self::sitemapPdfFilenames();
        sort($sitemapFilenames);

        $this->assertSame(
            $metadataKeys,
            $sitemapFilenames,
            'docs/pdf-viewer.php $document_metadata keys and the PDF entries in '
            . 'SitemapService::STATIC_PAGES must describe exactly the same set of files (Issue #1538). '
            . 'If you added/removed a PDF in one, add/remove the matching entry in the other.'
        );
    }

    public function testEveryDocumentMetadataKeyIsAnExistingFile(): void
    {
        $rootDir = self::projectRootDir();

        foreach (array_keys(self::documentMetadata()) as $filename) {
            $this->assertFileExists(
                $rootDir . '/' . self::REFERENCE_ASSETS_DIR . $filename,
                "\$document_metadata key '$filename' in docs/pdf-viewer.php must exist under "
                . self::REFERENCE_ASSETS_DIR . ' (Issue #1538) — a renamed/deleted file left stale metadata behind'
            );
        }
    }

    public function testEverySitemapPdfEntryIsAnExistingFile(): void
    {
        $rootDir = self::projectRootDir();

        foreach (self::sitemapPdfFilenames() as $filename) {
            $this->assertFileExists(
                $rootDir . '/' . self::REFERENCE_ASSETS_DIR . $filename,
                "SitemapService::STATIC_PAGES references '$filename', which does not exist under "
                . self::REFERENCE_ASSETS_DIR . ' (Issue #1538) — this would publish a 404 URL in sitemap.xml'
            );
        }
    }

    /**
     * The reverse direction of testEveryDocumentMetadataKeyIsAnExistingFile():
     * every PDF actually present under docs/reference/assets/ must have a
     * $document_metadata entry, not just every entry must have a file. Without
     * this, a future PDF dropped into that directory (and linked from
     * workshop.php/technical-articles.php/paint-colors.php) without a matching
     * map entry would silently render the generic soft-404-prone title —
     * exactly the regression Issue #1538 exists to fix — with no other test
     * catching it (the two "keys match" / "entry is an existing file" tests
     * above only compare the hand-maintained lists to each other and to disk
     * in the other direction).
     */
    public function testEveryReferenceAssetPdfHasMetadata(): void
    {
        $onDisk = array_map(
            'basename',
            glob(self::projectRootDir() . '/' . self::REFERENCE_ASSETS_DIR . '*.pdf') ?: []
        );
        sort($onDisk);

        $metadataKeys = array_keys(self::documentMetadata());
        sort($metadataKeys);

        $this->assertSame(
            $onDisk,
            $metadataKeys,
            'Every PDF under ' . self::REFERENCE_ASSETS_DIR . ' must have a $document_metadata entry in '
            . 'docs/pdf-viewer.php (Issue #1538) — an unmapped PDF renders the generic, soft-404-prone '
            . 'title/description this issue exists to eliminate.'
        );
    }

    public function testDocumentMetadataTitlesAreUnique(): void
    {
        $titles = array_map(static fn (array $entry): string => $entry['title'], self::documentMetadata());
        $this->assertSame(
            count($titles),
            count(array_unique($titles)),
            '$document_metadata titles in docs/pdf-viewer.php must be unique (Issue #1538) — '
            . 'duplicate titles reintroduce the "every page looks the same" soft-404 signal'
        );
    }

    public function testDocumentMetadataDescriptionsAreUnique(): void
    {
        $descriptions = array_map(static fn (array $entry): string => $entry['description'], self::documentMetadata());
        $this->assertSame(
            count($descriptions),
            count(array_unique($descriptions)),
            '$document_metadata descriptions in docs/pdf-viewer.php must be unique (Issue #1538)'
        );
    }

    public function testDocumentMetadataDescriptionsAreNotTooLongForAMetaDescription(): void
    {
        foreach (self::documentMetadata() as $filename => $entry) {
            $this->assertLessThanOrEqual(
                160,
                mb_strlen($entry['description']),
                "\$document_metadata description for '$filename' exceeds 160 characters — "
                . 'search engines typically truncate meta descriptions around this length (Issue #1538)'
            );
        }
    }

    /**
     * Extracts the $document_metadata array from docs/pdf-viewer.php via
     * regex over the raw source — deliberately does not require/execute
     * the file (it calls securePage()/init.php and isn't safe to bootstrap
     * from a fast unit test).
     *
     * @return array<string, array{title: string, description: string}>
     */
    private static function documentMetadata(): array
    {
        if (self::$documentMetadataCache !== null) {
            return self::$documentMetadataCache;
        }

        $content = (string)file_get_contents(self::projectRootDir() . '/' . self::PDF_VIEWER_PATH);

        // Matches each top-level entry: 'filename.pdf' => [ 'title' => '...', 'description' => '...' ],
        $pattern = '/\'([^\']+\.pdf)\'\s*=>\s*\[\s*'
            . '\'title\'\s*=>\s*\'([^\']*)\'\s*,\s*'
            . '\'description\'\s*=>\s*\'([^\']*)\'\s*,?\s*'
            . '\]/s';

        $matches = [];
        preg_match_all($pattern, $content, $matches, PREG_SET_ORDER);

        $metadata = [];
        foreach ($matches as $match) {
            $metadata[$match[1]] = ['title' => $match[2], 'description' => $match[3]];
        }

        self::$documentMetadataCache = $metadata;
        return $metadata;
    }

    /**
     * Extracts every *.pdf filename referenced in SitemapService's
     * STATIC_PAGES const, decoded back from its hand-written percent-encoded
     * literal to the real on-disk filename.
     *
     * @return list<string>
     */
    private static function sitemapPdfFilenames(): array
    {
        $content = (string)file_get_contents(self::projectRootDir() . '/' . self::SITEMAP_SERVICE_PATH);

        $matches = [];
        preg_match_all(
            "/'path'\s*=>\s*'\/docs\/reference\/assets\/([^']+\.pdf)'/",
            $content,
            $matches
        );

        return array_map('rawurldecode', $matches[1]);
    }

    private static function projectRootDir(): string
    {
        return dirname(__DIR__, 3);
    }
}
