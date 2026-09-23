<?php

declare(strict_types=1);

use ElanRegistry\Cron\AbstractCronJob;
use ElanRegistry\Cron\CronJobGuard;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Locks CronJobGuard::ALLOWED_JOB_NAMES against every concrete AbstractCronJob
 * subclass's JOB_NAME, so a typo in either place fails a test instead of
 * silently making claim() always return false in production.
 *
 * The job list is DISCOVERED, not maintained by hand: discoverJobClasses()
 * globs usersc/classes/Cron/*.php, maps each basename to its PSR-4
 * fully-qualified name under ElanRegistry\Cron\, and keeps only the entries
 * that are real, loadable, concrete AbstractCronJob subclasses. That is
 * deliberately a filesystem scan rather than a class-discovery package —
 * one directory with a flat PSR-4 mapping needs no dependency to enumerate,
 * and this codebase has none to reuse (see composer.json).
 *
 * This matters because a hand-maintained list could not catch the failure
 * mode the test exists for. A developer who adds a fourth job class and
 * updates neither this list nor ALLOWED_JOB_NAMES leaves the two in perfect
 * agreement with each other and in total disagreement with the codebase —
 * green tests, and a job that can never claim a run. Discovery removes the
 * developer from that loop: the new file is found whether or not anyone
 * remembers it exists, so the only remaining way to go green is to actually
 * add the allowlist entry.
 *
 * Both directions are covered: a discovered job missing from the allowlist,
 * and an allowlist entry with no job class behind it.
 */
#[Group('fast')]
final class CronJobGuardAllowedJobNamesTest extends TestCase
{
    private const JOB_CLASS_DIR = __DIR__ . '/../../../usersc/classes/Cron';
    private const JOB_NAMESPACE = 'ElanRegistry\\Cron\\';

    public function testEveryDiscoveredJobNameIsInTheGuardAllowlist(): void
    {
        $allowed = $this->allowedJobNames();
        $jobClasses = $this->discoverJobClasses();

        $this->assertNotEmpty(
            $jobClasses,
            'Discovered no concrete AbstractCronJob subclasses in ' . self::JOB_CLASS_DIR
                . ' — the discovery scan itself is broken, so this test proves nothing.'
        );

        foreach ($jobClasses as $class) {
            $jobName = $this->jobNameOf($class);
            $this->assertContains(
                $jobName,
                $allowed,
                "{$class}::JOB_NAME ('{$jobName}') is missing from CronJobGuard::ALLOWED_JOB_NAMES — "
                    . 'this job could never claim a guarded run.'
            );
        }
    }

    public function testDiscoveredJobClassesCoverTheEntireAllowlist(): void
    {
        $jobNames = array_map(fn (string $class) => $this->jobNameOf($class), $this->discoverJobClasses());

        $this->assertSame(
            [],
            array_diff($this->allowedJobNames(), $jobNames),
            'CronJobGuard::ALLOWED_JOB_NAMES contains a name not produced by any concrete '
                . 'AbstractCronJob subclass in ' . self::JOB_CLASS_DIR . ' — either the job class was '
                . 'removed and its allowlist entry left behind, or the name is a typo.'
        );
    }

    /**
     * Every concrete AbstractCronJob subclass that exists in the codebase right now.
     *
     * @return list<class-string<AbstractCronJob>>
     */
    private function discoverJobClasses(): array
    {
        $classes = [];

        foreach (glob(self::JOB_CLASS_DIR . '/*.php') ?: [] as $file) {
            $class = self::JOB_NAMESPACE . basename($file, '.php');

            if (!class_exists($class) || !is_subclass_of($class, AbstractCronJob::class)) {
                continue;
            }

            if ((new \ReflectionClass($class))->isAbstract()) {
                continue;
            }

            /** @var class-string<AbstractCronJob> $class */
            $classes[] = $class;
        }

        return $classes;
    }

    /** @return list<string> */
    private function allowedJobNames(): array
    {
        $ref = new \ReflectionClass(CronJobGuard::class);
        /** @var list<string> */
        return $ref->getConstant('ALLOWED_JOB_NAMES');
    }

    /** @param class-string<AbstractCronJob> $class */
    private function jobNameOf(string $class): string
    {
        $ref = new \ReflectionClass($class);
        return (string) $ref->getConstant('JOB_NAME');
    }
}
