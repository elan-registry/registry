<?php

declare(strict_types=1);

namespace Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;

/**
 * Regression test for eligibilitySkipReason()'s attempt-cap clause (#1884).
 *
 * app/admin/index.php cannot be require()'d directly in a unit test (needs
 * the full UserSpice framework bootstrap — securePage(), $abs_us_root, etc.),
 * matching the same constraint tests/unit/admin/AdminContactSanitizationTest.php
 * documents for process-admin-contact.php. This is a source-inspection guard,
 * not a behavioral test: it asserts the attempt-cap logic is present in the
 * real source, so a regression that silently removes it (e.g. someone
 * "simplifying" eligibilitySkipReason() back to its pre-#1884 six branches)
 * is caught even though the function itself cannot be executed here.
 *
 * The cap this guards: findVerificationEligible()'s SQL and this function's
 * PHP mirror must both enforce "at most 2 sends per rolling 12-month window"
 * (CarRepositoryTest::testFindVerificationEligibleQueryContainsExpectedConditions()
 * covers the SQL side of the same rule) — without it, a stale car re-enters
 * every batch with no limit, which is exactly the defect this cap exists to
 * close.
 */
final class VerificationEligibilitySkipReasonTest extends TestCase
{
    private const INDEX_FILE = 'app/admin/index.php';

    private function readIndexSource(): string
    {
        $filePath = dirname(__DIR__, 3) . '/' . self::INDEX_FILE;
        $this->assertFileExists($filePath, 'app/admin/index.php must exist (#1884)');

        return (string) file_get_contents($filePath);
    }

    public function testEligibilitySkipReasonEnforcesAttemptCap(): void
    {
        $source = $this->readIndexSource();

        $this->assertStringContainsString(
            'function eligibilitySkipReason',
            $source,
            'eligibilitySkipReason() must exist in app/admin/index.php'
        );

        $this->assertStringContainsString(
            "'verification_attempts_since'",
            $source,
            'eligibilitySkipReason() must read verification_attempts_since to mirror '
                . "findVerificationEligible()'s attempt-cap clause"
        );

        $this->assertStringContainsString(
            "(int) (\$carData->verification_attempts ?? 0) >= 2",
            $source,
            'eligibilitySkipReason() must reject a car that has reached the 2-send cap '
                . 'within its current rolling window, matching the FRD\'s Eligibility Criteria'
        );

        $this->assertStringContainsString(
            'Attempt cap reached for this year',
            $source,
            'A capped car must be reported with a specific reason, not a generic skip'
        );
    }

    public function testFindVerificationEligibleSourceAlsoEnforcesTheSameCap(): void
    {
        $repoFile = dirname(__DIR__, 3) . '/usersc/classes/Car/CarRepository.php';
        $this->assertFileExists($repoFile);
        $source = (string) file_get_contents($repoFile);

        // Pinning the SQL fragment here too (in addition to
        // CarRepositoryTest's mock-based assertion) keeps this test's own
        // "both sides agree" claim self-contained and independently
        // verifiable without cross-referencing another test file.
        $this->assertStringContainsString(
            'cars.verification_attempts < 2',
            $source,
            'findVerificationEligible() must enforce the same 2-send cap eligibilitySkipReason() checks'
        );
    }
}
