<?php

declare(strict_types=1);

use ElanRegistry\PHPStan\Rules\PageMetadataBeforeInitRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * @extends RuleTestCase<PageMetadataBeforeInitRule>
 */
#[Group('phpstan-rules')]
final class PageMetadataBeforeInitRuleTest extends RuleTestCase
{
    private const FIXTURE_ROOT = __DIR__ . '/fixtures/page-metadata';

    protected function getRule(): Rule
    {
        return new PageMetadataBeforeInitRule(self::FIXTURE_ROOT, ['app/exempt.php', 'app/stale-exemption.php']);
    }

    public function testAcceptsPageThatSetsMetadataBeforeInit(): void
    {
        $this->analyse([self::FIXTURE_ROOT . '/app/complete.php'], []);
    }

    public function testReportsAssignmentAfterInit(): void
    {
        $this->analyse([self::FIXTURE_ROOT . '/app/late.php'], [
            ['Assign $pageTitle before the require_once of users/init.php (line 4). '
                . 'The loader reads it at init time, so a later assignment has no effect.', 10],
        ]);
    }

    public function testReportsMissingDescription(): void
    {
        $this->analyse([self::FIXTURE_ROOT . '/app/missing-description.php'], [
            ['This page calls securePage() but does not set $pageDescription. Set $pageTitle and $pageDescription '
                . "before require_once 'users/init.php' (elanregistry_overrides section 6).", 1],
        ]);
    }

    public function testReportsPageWithoutInitRequire(): void
    {
        $this->analyse([self::FIXTURE_ROOT . '/app/no-init.php'], [
            ['This page sets $pageTitle but has no require_once of users/init.php, so the order cannot be checked.', 3],
            ['This page sets $pageDescription but has no require_once of users/init.php, so the order cannot be checked.', 4],
        ]);
    }

    public function testAcceptsExemptPageWithoutTitle(): void
    {
        $this->analyse([self::FIXTURE_ROOT . '/app/exempt.php'], []);
    }

    public function testReportsStaleExemption(): void
    {
        $this->analyse([self::FIXTURE_ROOT . '/app/stale-exemption.php'], [
            ["This page now sets \$pageTitle. Remove 'app/stale-exemption.php' from the PageMetadataBeforeInitRule "
                . 'exemption list in phpstan.neon.', 3],
        ]);
    }

    public function testIgnoresApiEndpointsAndFilesWithoutSecurePage(): void
    {
        $this->analyse([
            self::FIXTURE_ROOT . '/app/api/endpoint.php',
            self::FIXTURE_ROOT . '/app/not-a-page.php',
        ], []);
    }
}
