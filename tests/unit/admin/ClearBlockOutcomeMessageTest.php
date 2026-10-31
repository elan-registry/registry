<?php

declare(strict_types=1);

namespace Tests\Unit\Admin;

use ElanRegistry\Car\ClearBlockOutcome;
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
            '/ClearBlockOutcome::BREVO_PLUGIN_FAULT => \[(true|false), \'([^\']*)\'\],/',
            $this->readOutcomeMatchBlock(),
            $arm
        );

        $this->assertSame(1, $found, 'The match must have a BREVO_PLUGIN_FAULT arm');
        $this->assertSame('true', $arm[1], 'A plugin fault must show as an error');
        $this->assertStringContainsString('Check the logs', $arm[2]);
        $this->assertStringNotContainsStringIgnoringCase('try again', $arm[2], 'A retry cannot fix a plugin fault');
    }

    public function testEveryOutcomeKindHasAnArm(): void
    {
        $block = $this->readOutcomeMatchBlock();
        $constants = (new \ReflectionClass(ClearBlockOutcome::class))->getConstants();
        $this->assertNotEmpty($constants);

        foreach (array_keys($constants) as $name) {
            $this->assertMatchesRegularExpression(
                '/ClearBlockOutcome::' . preg_quote($name, '/') . ' => \[/',
                $block,
                "The Clear Block match has no arm for ClearBlockOutcome::{$name}"
            );
        }
    }
}
