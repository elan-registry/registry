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
 * - The Eligible view builds its flags from the queue row. The Eligible
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

    private static function batchSizeComesFromStatus(string $code): bool
    {
        return preg_match('/\$vsBatchSize\s*=\s*\$vsStatus->batchSize\(\);/', $code) === 1;
    }

    private static function eligibleViewUsesEligibleFlags(string $code): bool
    {
        return preg_match(
            '/\$queueFlags\s*=\s*\$vsIsEligibleView\s*\?\s*EffectiveEmailFlags::eligible\(\)\s*:\s*EffectiveEmailFlags::fromQueueRow\(\$queueCar\);/',
            $code
        ) === 1;
    }

    public function testBatchSizeComesFromTheStatusProbe(): void
    {
        $this->assertTrue(
            self::batchSizeComesFromStatus($this->code()),
            'The tab must assign $vsBatchSize from $vsStatus->batchSize().'
        );
    }

    public function testEligibleViewUsesEligibleFlagsNotTheQueueRow(): void
    {
        $this->assertTrue(
            self::eligibleViewUsesEligibleFlags($this->code()),
            'The Eligible view must use EffectiveEmailFlags::eligible(). Its rows have no profile columns.'
        );
    }

    // --- Negative controls ------------------------------------------------

    public function testNegativeControlBatchSizeFromSettingsIsFound(): void
    {
        $code = '$vsSettings = new VerificationSettings(dbi()); $vsBatchSize = $vsSettings->batchSizeOrNull() ?? 5;';

        $this->assertFalse(self::batchSizeComesFromStatus($code));
    }

    public function testNegativeControlQueueRowFlagsForEveryViewIsFound(): void
    {
        $this->assertFalse(self::eligibleViewUsesEligibleFlags('$queueFlags = EffectiveEmailFlags::fromQueueRow($queueCar);'));
    }
}
