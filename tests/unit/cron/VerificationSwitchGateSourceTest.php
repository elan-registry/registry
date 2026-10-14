<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Source test: the list of files in usersc/classes/Cron that contain the text
 * `isEnabled(` must be SendVerificationBatchJob.php only (#2358).
 *
 * The prune, the Brevo reconciliation and the suppression import must run
 * while sends are off. A second `isEnabled(` call in the Cron folder could
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
     * @return list<string> Names of the files that contain `isEnabled(`
     */
    private static function filesReadingTheSwitch(array $files): array
    {
        $names = [];
        foreach ($files as $name => $source) {
            if (str_contains($source, 'isEnabled(')) {
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
            ['A.php', 'B.php'],
            self::filesReadingTheSwitch([
                'A.php' => '<?php $x = $settings->isEnabled();',
                'B.php' => "<?php\n// calls isEnabled() later\n",
                'C.php' => '<?php $y = 1;',
            ])
        );
    }
}
