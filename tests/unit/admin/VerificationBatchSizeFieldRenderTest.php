<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Support\PartialRenderHarness;

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
    use PartialRenderHarness;

    private const PARTIAL = __DIR__ . '/../../../app/admin/includes/verification-batch-size-field.php';

    private const CSRF_TOKEN = 'test-csrf-<token>';

    /** Include the partial the way the tab does and return its HTML. */
    private static function renderHtml(?int $vsBatchSize, bool $vsCanToggle): string
    {
        return self::renderPartial(self::PARTIAL, 'VERIFICATION_BATCH_SIZE_FIELD_PATH', [
            'vsBatchSize' => $vsBatchSize,
            'vsCanToggle' => $vsCanToggle,
            'csrfToken'   => self::CSRF_TOKEN,
        ]);
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
        self::assertStringContainsString(
            'The batch size could not be read. Check the system log for VerificationConfigWarning entries.',
            self::text($xp)
        );
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
