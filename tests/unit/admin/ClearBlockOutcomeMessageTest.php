<?php

declare(strict_types=1);

namespace Tests\Unit\Admin;

use ElanRegistry\Car\ClearBlockKind;
use PHPUnit\Framework\TestCase;

/**
 * Source-inspection guard for the Clear Block messages in app/admin/index.php.
 *
 * index.php needs the full UserSpice bootstrap, so a unit test cannot render
 * it (see VerificationBannerReasonTest for the same constraint). This test
 * reads the `match` on the outcome kind. It proves that a plugin fault gets
 * the error text that sends the admin to the logs, not the retry text, and
 * that each outcome kind has its own arm.
 */
final class ClearBlockOutcomeMessageTest extends TestCase
{
    /**
     * Return the body of the `match ($requestOutcome->kind)` block.
     */
    private function readOutcomeMatchBlock(): string
    {
        $filePath = dirname(__DIR__, 3) . '/app/admin/index.php';
        $this->assertFileExists($filePath);
        $source = (string) file_get_contents($filePath);

        $found = preg_match('/match \(\$requestOutcome->kind\) \{(.*?)\n\s*\};/s', $source, $matches);
        $this->assertSame(1, $found, 'index.php must map the Clear Block outcome kind with a match');

        return $matches[1];
    }

    public function testPluginFaultArmIsAnErrorThatSendsTheAdminToTheLogs(): void
    {
        $found = preg_match(
            '/ClearBlockKind::BrevoPluginFault => \[(true|false), \'([^\']*)\'\],/',
            $this->readOutcomeMatchBlock(),
            $arm
        );

        $this->assertSame(1, $found, 'The match must have a BrevoPluginFault arm');
        $this->assertSame('true', $arm[1], 'A plugin fault must show as an error');
        $this->assertStringContainsString('Check the logs', $arm[2]);
        $this->assertStringNotContainsStringIgnoringCase('try again', $arm[2], 'A retry cannot fix a plugin fault');
    }

    public function testEveryOutcomeKindHasAnArm(): void
    {
        $block = $this->readOutcomeMatchBlock();
        foreach (ClearBlockKind::cases() as $kind) {
            $this->assertMatchesRegularExpression(
                '/ClearBlockKind::' . preg_quote($kind->name, '/') . ' => \[/',
                $block,
                "The Clear Block match has no arm for ClearBlockKind::{$kind->name}"
            );
        }
    }
}
