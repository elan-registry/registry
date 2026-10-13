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
 * though, with no dependency beyond $emailSuppressed, $pausedCarCount,
 * $emailBlocked, $ownerClearable and
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

    /** A template-syntax open (`<?php if (...): ?>`) or close (`<?php endif; ?>`) tag. */
    private const IF_TAG_PATTERN = '/<\?php\s+(?:(if)\s*\((?:(?!\?>).)*\)\s*:|(endif)\s*;?)\s*\?>/s';

    /**
     * Extract the "if ($emailSuppressed) ... endif" fragment by tag
     * balancing: each template-syntax `if` adds one level and each `endif`
     * removes one. `else:` and `elseif (...):` do not change the depth. The
     * fragment ends at the `endif` that brings the depth back to 0.
     */
    private function extractResumeSectionFragment(): string
    {
        $path = __DIR__ . '/../../../usersc/user_settings.php';
        $this->assertFileExists($path, 'usersc/user_settings.php must exist');

        $content = file_get_contents($path);
        $this->assertIsString($content, 'usersc/user_settings.php must be readable');

        $start = strpos($content, self::START_MARKER);
        $this->assertIsInt($start, 'Could not locate the resume-emails if ($emailSuppressed) marker');

        preg_match_all(self::IF_TAG_PATTERN, $content, $tags, PREG_SET_ORDER | PREG_OFFSET_CAPTURE, $start);

        $depth = 0;
        $end = null;
        foreach ($tags as $tag) {
            $depth += ($tag[1][0] ?? '') === 'if' ? 1 : -1;
            if ($depth === 0) {
                $end = $tag[0][1] + strlen($tag[0][0]);
                break;
            }
        }
        $this->assertIsInt($end, 'Could not locate the endif closing the resume-emails section');

        $fragment = substr($content, $start, $end - $start);

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
     * variables the fragment itself reads.
     */
    private function renderFragment(
        bool $emailSuppressed,
        ?int $pausedCarCount,
        bool $emailBlocked = false,
        bool $ownerClearable = true
    ): string
    {
        $fragment = $this->extractResumeSectionFragment();

        $tmpFile = tempnam(sys_get_temp_dir(), 'resume-fragment-') . '.php';
        file_put_contents($tmpFile, $fragment);

        try {
            $emailSuppressedVar = $emailSuppressed; // extracted into scope under its real name below
            ob_start();
            (function () use ($tmpFile, $emailSuppressedVar, $pausedCarCount, $emailBlocked, $ownerClearable): void {
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

    /**
     * The button clears only the owner's opt-out, not a block (#2101), so an
     * owner who has both must be told that one click does not fix the block.
     */
    #[Group('regression')]
    public function testBlockNoteTellsOwnerToContactTheRegistryAndKeepsTheButton(): void
    {
        $html = $this->renderFragment(emailSuppressed: true, pausedCarCount: 3, emailBlocked: true);

        $this->assertStringContainsString('Our email provider flagged your address.', $html);
        $this->assertStringContainsString('This button does not turn verification emails back on.', $html);
        $this->assertStringContainsString('contact the registry', $html);
        $this->assertStringContainsString('name="resume_verification_emails"', $html, 'The opt-out can still be cleared');
    }

    /**
     * With a block and no opt-out, the button would clear nothing, so it must
     * not render. The block note must still render, without a button mention.
     */
    #[Group('regression')]
    public function testButtonIsAbsentWhenTheOwnerIsOnlyBlocked(): void
    {
        $html = $this->renderFragment(
            emailSuppressed: true,
            pausedCarCount: 2,
            emailBlocked: true,
            ownerClearable: false
        );

        $this->assertStringContainsString('id="resume-emails"', $html);
        $this->assertStringNotContainsString('name="resume_verification_emails"', $html);
        $this->assertStringContainsString('Our email provider flagged your address.', $html);
        $this->assertStringNotContainsString('This button', $html, 'The note must not refer to a button that is not there');
        $this->assertStringContainsString('contact the registry', $html);
    }

    public function testBlockNoteIsAbsentWhenTheOwnerIsNotBlocked(): void
    {
        $html = $this->renderFragment(emailSuppressed: true, pausedCarCount: 3);

        $this->assertStringNotContainsString('flagged', $html);
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
