<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The Verification tab reads its batch size and Eligible flags from one place.
 *
 * This test scans the source of app/admin/includes/tab-verification.php. It
 * fails when:
 *
 * - `$vsBatchSize` does not come from `$vsStatus->batchSize()`. The status
 *   probe gives null after a failed read (#2457). A value with a default
 *   shows 5 as fact, and a Save then writes 5 over the real setting.
 * - The Next Queue view builds its flags from the queue row. The batch
 *   preview row has no profile columns, so fromQueueRow() throws for it.
 *
 * Every checker has a negative control: an in-memory string that holds the
 * defect. The control asserts that the checker flags it.
 *
 * @issue 2457
 * @link https://github.com/elan-registry/registry/issues/2457
 */
#[Group('regression')]
final class VerificationTabSourceRegressionTest extends TestCase
{
    private const TAB = 'app/admin/includes/tab-verification.php';

    private function code(): string
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/' . self::TAB);
        $this->assertNotSame('', $source, 'The tab must exist.');

        $code = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }
                $code .= $token[1];
            } else {
                $code .= $token;
            }
        }

        return $code;
    }

    /**
     * A second assignment after the status probe would override it, so the
     * probe must be the only assignment, not just the first one.
     */
    private static function batchSizeComesFromStatus(string $code): bool
    {
        $assignments = preg_match_all('/\$vsBatchSize\s*(?:\?\?|[-+.*\/])?=(?![=>])/', $code);

        return $assignments === 1
            && preg_match('/\$vsBatchSize\s*=\s*\$vsStatus->batchSize\(\);/', $code) === 1;
    }

    /**
     * The flags are built with the rows, before <tbody>: Next Queue rows get
     * eligible(), every other view gets fromQueueRow() of its row.
     */
    private static function nextQueueViewUsesEligibleFlags(string $code): bool
    {
        return preg_match(
            '/if\s*\(\$vsIsNextQueueView\)\s*\{.*?\$vsQueueFlags\[\$vsQueueRowKey\]\s*=\s*EffectiveEmailFlags::eligible\(\);'
            . '.*?\}\s*else\s*\{.*?\$vsQueueFlags\[\$vsQueueRowKey\]\s*=\s*EffectiveEmailFlags::fromQueueRow\(\$vsQueueRow\);/s',
            $code
        ) === 1;
    }

    private static function sendFormPostsToTheNextQueueView(string $code): bool
    {
        return preg_match('/<form action="<\?=\s*vsEsc\(\$vsTabUrl\(\[\'status\'\s*=>\s*\'next_queue\'\]\)\)\s*\?>" method="POST">/', $code) === 1;
    }

    public function testBatchSizeComesFromTheStatusProbe(): void
    {
        $this->assertTrue(
            self::batchSizeComesFromStatus($this->code()),
            'The tab must assign $vsBatchSize from $vsStatus->batchSize().'
        );
    }

    public function testNextQueueViewUsesEligibleFlagsNotTheQueueRow(): void
    {
        $this->assertTrue(
            self::nextQueueViewUsesEligibleFlags($this->code()),
            'The Next Queue view must use EffectiveEmailFlags::eligible(). Its rows have no profile columns.'
        );
    }

    public function testSendFormActionIsTheNextQueueView(): void
    {
        $this->assertTrue(
            self::sendFormPostsToTheNextQueueView($this->code()),
            'The send form must post to status=next_queue, the view that previews the rows it sends.'
        );
    }

    // --- Negative controls ------------------------------------------------

    public function testNegativeControlBatchSizeFromSettingsIsFound(): void
    {
        $code = '$vsSettings = new VerificationSettings(dbi()); $vsBatchSize = $vsSettings->batchSizeOrNull() ?? 5;';

        $this->assertFalse(self::batchSizeComesFromStatus($code));
    }

    public function testNegativeControlSecondBatchSizeAssignmentIsFound(): void
    {
        $code = '$vsBatchSize = $vsStatus->batchSize(); if ($x) { $vsBatchSize ??= 5; }';

        $this->assertFalse(self::batchSizeComesFromStatus($code));
    }

    public function testControlComparisonIsNotCountedAsAnAssignment(): void
    {
        $code = '$vsBatchSize = $vsStatus->batchSize(); if ($vsBatchSize === null || $vsBatchSize == 0) {}';

        $this->assertTrue(self::batchSizeComesFromStatus($code));
    }

    public function testNegativeControlQueueRowFlagsForEveryViewIsFound(): void
    {
        $this->assertFalse(self::nextQueueViewUsesEligibleFlags(
            'if ($vsIsNextQueueView) { $vsQueueFlags[$vsQueueRowKey] = EffectiveEmailFlags::fromQueueRow($vsQueueRow); }'
            . ' else { $vsQueueFlags[$vsQueueRowKey] = EffectiveEmailFlags::fromQueueRow($vsQueueRow); }'
        ));
    }

    public function testNegativeControlSendFormToTheEligibleViewIsFound(): void
    {
        $this->assertFalse(self::sendFormPostsToTheNextQueueView(
            '<form action="<?= vsEsc($vsTabUrl([\'status\' => \'eligible\'])) ?>" method="POST">'
        ));
    }
}
