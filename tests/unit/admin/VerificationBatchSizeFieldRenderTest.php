<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Render tests for the Batch size field partial of the Verification tab (#2457).
 *
 * The partial renders from $vsBatchSize (null when the read failed),
 * $vsCanToggle, and $csrfToken. These tests include the real partial and
 * read the HTML as a DOM. No test reads the source text.
 *
 * @issue 2457
 */
#[Group('fast')]
final class VerificationBatchSizeFieldRenderTest extends TestCase
{
    private const PARTIAL = __DIR__ . '/../../../app/admin/includes/verification-batch-size-field.php';

    private const CSRF_TOKEN = 'test-csrf-<token>';

    /** Include the partial the way the tab does and return its HTML. */
    private static function renderHtml(?int $vsBatchSize, bool $vsCanToggle): string
    {
        $csrfToken = self::CSRF_TOKEN;

        // Lets a reviewer prove a test can fail: point the test at a copy of an older partial.
        $override = getenv('VERIFICATION_BATCH_SIZE_FIELD_PATH');
        $file     = is_string($override) && $override !== '' ? $override : self::PARTIAL;

        // A PHP warning or deprecation inside the view is a defect, not noise.
        set_error_handler(static function (int $severity, string $message, string $errFile, int $line): never {
            throw new ErrorException($message, 0, $severity, $errFile, $line);
        });
        ob_start();
        try {
            include $file;
        } finally {
            $html = (string) ob_get_clean();
            restore_error_handler();
        }

        return $html;
    }

    private static function dom(string $html): DOMXPath
    {
        $doc = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8"><body>' . $html . '</body>');
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new DOMXPath($doc);
    }

    private static function nodeCount(DOMXPath $xp, string $query): int
    {
        $nodes = $xp->query($query);
        self::assertNotFalse($nodes);

        return $nodes->length;
    }

    private static function text(DOMXPath $xp): string
    {
        return preg_replace('/\s+/', ' ', trim((string) $xp->document->textContent)) ?? '';
    }

    /** @return array<string, array{bool}> */
    public static function viewerProvider(): array
    {
        return [
            'admin'  => [true],
            'editor' => [false],
        ];
    }

    #[DataProvider('viewerProvider')]
    public function testRenderWhenBatchSizeIsNullShowsUnavailableAndNoForm(bool $vsCanToggle): void
    {
        $xp = self::dom(self::renderHtml(null, $vsCanToggle));

        self::assertSame(1, self::nodeCount($xp, '//span[contains(@class, "badge") and contains(@class, "text-bg-danger")'
            . ' and normalize-space(.)="Unavailable"]'));
        self::assertStringContainsString('The batch size could not be read. Check the system log.', self::text($xp));
        self::assertSame(0, self::nodeCount($xp, '//form'), 'No viewer may get a Save form for an unread batch size');
        self::assertSame(0, self::nodeCount($xp, '//*[@id="verificationBatchSize"]'));
        self::assertStringNotContainsString('cars per run', self::text($xp));
    }

    public function testRenderWhenAdminAndBatchSizeIsReadShowsSaveForm(): void
    {
        $xp = self::dom(self::renderHtml(7, true));

        self::assertSame(1, self::nodeCount($xp, '//form[@method="POST" and @action="index.php?tab=verification"]'));
        self::assertSame(1, self::nodeCount(
            $xp,
            '//form//input[@type="hidden" and @name="csrf" and @value="' . self::CSRF_TOKEN . '"]'
        ));
        self::assertSame(1, self::nodeCount(
            $xp,
            '//form//input[@type="hidden" and @name="command" and @value="verification_set_batch_size"]'
        ));
        self::assertSame(1, self::nodeCount(
            $xp,
            '//form//input[@id="verificationBatchSize" and @name="batch_size" and @value="7"]'
        ));
        self::assertSame(0, self::nodeCount($xp, '//span[normalize-space(.)="Unavailable"]'));
    }

    public function testRenderWhenEditorAndBatchSizeIsReadShowsTextOnly(): void
    {
        $xp = self::dom(self::renderHtml(7, false));

        self::assertStringContainsString('7 cars per run', self::text($xp));
        self::assertSame(0, self::nodeCount($xp, '//form'));
        self::assertSame(0, self::nodeCount($xp, '//*[@id="verificationBatchSize"]'));
        self::assertSame(0, self::nodeCount($xp, '//span[normalize-space(.)="Unavailable"]'));
    }
}
