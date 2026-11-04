<?php

declare(strict_types=1);

use ElanRegistry\Cron\AbstractCronJob;
use ElanRegistry\Cron\BrevoEventReconciliationJob;
use ElanRegistry\Cron\BrevoSuppressionSyncJob;
use ElanRegistry\Cron\SendVerificationBatchJob;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Source test (#2358, #2359, #2572): in usersc/classes/Cron, only SendVerificationBatchJob.php
 * may contain a switch read, that is any `isEnabled*(` text (`isEnabled(`, `isEnabledOrNull(`).
 *
 * The prune, the Brevo reconciliation and the suppression import must run
 * while sends are off. A second switch read in the Cron folder could
 * stop one of them. The test checks text, not calls. The scan reads the raw
 * text, comments included, so a comment that names the call also fails.
 * Reword such a comment.
 */
#[Group('fast')]
final class VerificationSwitchGateSourceTest extends TestCase
{
    private const CRON_DIR = '/usersc/classes/Cron';

    /**
     * @param array<string, string> $files File name => source text
     * @return list<string> Names of the files that contain an `isEnabled*(` call
     */
    private static function filesReadingTheSwitch(array $files): array
    {
        $names = [];
        foreach ($files as $name => $source) {
            if (preg_match('/\bisEnabled\w*\(/', $source) === 1) {
                $names[] = $name;
            }
        }
        sort($names);

        return $names;
    }

    /**
     * @return array<string, string> File name => source text
     */
    private function cronSources(): array
    {
        $paths = glob(dirname(__DIR__, 3) . self::CRON_DIR . '/*.php');
        $this->assertIsArray($paths);
        $this->assertNotSame([], $paths, 'The glob must find the Cron classes, or the scan proves nothing.');

        $files = [];
        foreach ($paths as $path) {
            $source = file_get_contents($path);
            $this->assertIsString($source);
            $files[basename($path)] = $source;
        }

        return $files;
    }

    public function testOnlyTheSendBatchJobReadsTheVerificationSwitch(): void
    {
        $files = $this->cronSources();

        $this->assertArrayHasKey('SendVerificationBatchJob.php', $files);
        $this->assertArrayHasKey('AbstractCronJob.php', $files);
        $this->assertSame(['SendVerificationBatchJob.php'], self::filesReadingTheSwitch($files));
    }

    public function testNegativeControlScannerSeesAReadInAnotherFileAndInAComment(): void
    {
        $this->assertSame(
            ['A.php', 'B.php', 'D.php'],
            self::filesReadingTheSwitch([
                'A.php' => '<?php $x = $settings->isEnabled();',
                'B.php' => "<?php\n// calls isEnabled() later\n",
                'C.php' => '<?php $y = 1;',
                'D.php' => '<?php $z = $settings->isEnabledOrNull();',
            ])
        );
    }

    /** A Brevo job that overrides the gate would stop with the switch off, even with no `isEnabled(` text. */
    public function testOnlyTheSendBatchJobOverridesTheSwitchGate(): void
    {
        $declaring = static fn (string $class): string
            => (new ReflectionMethod($class, 'refusesWhileSwitchOff'))->getDeclaringClass()->getName();

        $this->assertSame(SendVerificationBatchJob::class, $declaring(SendVerificationBatchJob::class));
        $this->assertSame(AbstractCronJob::class, $declaring(BrevoEventReconciliationJob::class));
        $this->assertSame(AbstractCronJob::class, $declaring(BrevoSuppressionSyncJob::class));
    }
}
