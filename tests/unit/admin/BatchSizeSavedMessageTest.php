<?php

declare(strict_types=1);

namespace Tests\Unit\Admin;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Source-inspection guard for the `verification_set_batch_size` handler in
 * app/admin/index.php.
 *
 * index.php needs the full UserSpice bootstrap, so a unit test cannot render
 * it (see ClearBlockOutcomeMessageTest for the same constraint). After a
 * successful save, the handler reads the value back for the success message.
 * batchSize() gives the default 5 after a failed read, so the message would
 * show 5 as fact. The handler must use batchSizeOrNull() and show a warning
 * text on null.
 */
#[Group('fast')]
final class BatchSizeSavedMessageTest extends TestCase
{
    /**
     * Return the body of the `case "verification_set_batch_size":` block.
     */
    private function readHandlerBlock(): string
    {
        $filePath = dirname(__DIR__, 3) . '/app/admin/index.php';
        $this->assertFileExists($filePath);
        $source = (string) file_get_contents($filePath);

        $found = preg_match('/case "verification_set_batch_size":(.*?)\n\s*case "/s', $source, $matches);
        $this->assertSame(1, $found, 'index.php must have a verification_set_batch_size case');

        return $matches[1];
    }

    public function testReadBackUsesBatchSizeOrNull(): void
    {
        $block = $this->readHandlerBlock();

        $this->assertStringContainsString('->batchSizeOrNull()', $block);
        $this->assertDoesNotMatchRegularExpression(
            '/->batchSize\(\)/',
            $block,
            'batchSize() shows the default 5 as fact after a failed read'
        );
    }

    public function testNullReadBackShowsTheSavedButUnreadableText(): void
    {
        $block = $this->readHandlerBlock();

        $this->assertMatchesRegularExpression(
            '/if \(\$savedBatchSize === null\) \{\s*\$errors\[\] = \'The batch size was saved, but the new value could not be read back\. \'\s*'
            . '\. \'Check the system log for VerificationConfigWarning entries\.\';\s*break;/',
            $block,
            'A null read-back must show the saved-but-unreadable text and stop before the success text'
        );
        $this->assertStringContainsString("sprintf('Batch size updated to %d.', \$savedBatchSize)", $block);
    }
}
