<?php

declare(strict_types=1);

namespace Tests\Unit\Views;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Render-level test for the `#resume-emails` section of
 * usersc/user_settings.php (#1895 — owner self-service "Resume verification
 * emails"), covering AC 1: the control's presence/absence in the DOM.
 *
 * usersc/user_settings.php as a whole cannot be require()'d from PHPUnit (it
 * executes on load and depends on live UserSpice globals/DB — see
 * UserSettingsWiringTest's class docblock for the established precedent of
 * source-inspection tests for this same file). The control's own markup
 * block is a self-contained "if ($emailSuppressed) ... endif" fragment,
 * though, with no dependency beyond $emailSuppressed, $pausedCarCount and
 * Token::generate() (stubbed by tests/bootstrap-unit.php). This test
 * extracts that exact fragment from the live file by tag balancing (never
 * retyped by hand, so it cannot drift from the real markup) and actually
 * renders it with PHP's own template engine — a true render check, not a
 * source-text substring match.
 *
 * @see usersc/user_settings.php
 * @see https://github.com/elan-registry/registry/issues/1895
 */
#[Group('fast')]
#[Group('unit')]
#[Group('owner-account')]
final class UserSettingsResumeSectionTest extends TestCase
{
    private const START_MARKER = "<?php if (\$emailSuppressed): ?>";
    private const END_MARKER = "<?php endif; ?>";

    /**
     * Extract the "if ($emailSuppressed) ... endif" fragment (the first
     * endif after the start marker — the section has no nested if/endif of
     * the same shape other than the inner $pausedCarCount check, which is
     * INSIDE this fragment and must stay there).
     */
    private function extractResumeSectionFragment(): string
    {
        $path = __DIR__ . '/../../../usersc/user_settings.php';
        $this->assertFileExists($path, 'usersc/user_settings.php must exist');

        $content = file_get_contents($path);
        $this->assertIsString($content, 'usersc/user_settings.php must be readable');

        $start = strpos($content, self::START_MARKER);
        $this->assertIsInt($start, 'Could not locate the resume-emails if ($emailSuppressed) marker');

        // The fragment contains one nested closing-endif tag (closing the
        // inner $pausedCarCount !== null check) before its own closing one,
        // so the SECOND occurrence after $start is the real end of this
        // fragment, not the first.
        $innerEndif = strpos($content, self::END_MARKER, $start + strlen(self::START_MARKER));
        $this->assertIsInt($innerEndif, 'Could not locate the inner endif (the $pausedCarCount check)');
        $outerEndif = strpos($content, self::END_MARKER, $innerEndif + strlen(self::END_MARKER));
        $this->assertIsInt($outerEndif, 'Could not locate the outer endif closing the resume-emails section');

        $fragment = substr($content, $start, $outerEndif + strlen(self::END_MARKER) - $start);

        $this->assertStringContainsString(
            'resume-emails',
            $fragment,
            'The extracted fragment must contain the #resume-emails section — '
            . 'extraction boundaries may have drifted from the real markup'
        );
        $this->assertStringContainsString(
            'resume_verification_emails',
            $fragment,
            'The extracted fragment must contain the submit button — '
            . 'extraction boundaries may have drifted from the real markup'
        );

        return $fragment;
    }

    /**
     * Render the extracted fragment as a real PHP template, with only the
     * two variables the fragment itself reads.
     */
    private function renderFragment(bool $emailSuppressed, ?int $pausedCarCount): string
    {
        $fragment = $this->extractResumeSectionFragment();

        $tmpFile = tempnam(sys_get_temp_dir(), 'resume-fragment-') . '.php';
        file_put_contents($tmpFile, $fragment);

        try {
            $emailSuppressedVar = $emailSuppressed; // extracted into scope under its real name below
            ob_start();
            (function () use ($tmpFile, $emailSuppressedVar, $pausedCarCount): void {
                $emailSuppressed = $emailSuppressedVar;
                include $tmpFile;
            })();
            $output = ob_get_clean();
            $this->assertIsString($output, 'Rendering the fragment must produce output (even if empty)');
            return $output;
        } finally {
            unlink($tmpFile);
        }
    }

    public function testControlIsAbsentWhenEmailIsNotSuppressed(): void
    {
        $html = $this->renderFragment(emailSuppressed: false, pausedCarCount: null);

        $this->assertSame('', trim($html), 'No markup may be rendered when $emailSuppressed is false');
    }

    public function testControlIsPresentWithSubmitButtonAndCsrfFieldWhenEmailIsSuppressed(): void
    {
        $html = $this->renderFragment(emailSuppressed: true, pausedCarCount: 3);

        $this->assertStringContainsString('id="resume-emails"', $html, 'The section wrapper must be rendered');
        $this->assertStringContainsString(
            'name="resume_verification_emails"',
            $html,
            'The submit button must be rendered'
        );
        $this->assertStringContainsString(
            'name="csrf"',
            $html,
            'The hidden CSRF field must be rendered'
        );
        $this->assertStringContainsString(
            'Verification emails are currently paused for 3 of your cars.',
            $html,
            'The paused-car-count copy must be rendered when the count is known'
        );
    }

    public function testControlFallsBackToGenericCopyWhenCarCountIsUnavailable(): void
    {
        $html = $this->renderFragment(emailSuppressed: true, pausedCarCount: null);

        $this->assertStringContainsString('id="resume-emails"', $html, 'The section must still render without a count');
        $this->assertStringContainsString(
            'Verification emails are currently paused for your cars.',
            $html,
            'The generic copy must be used when the count could not be computed'
        );
    }
}
