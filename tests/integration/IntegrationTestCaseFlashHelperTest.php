<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

use PHPUnit\Framework\Attributes\Group;

/**
 * invokeEndpoint() returns the UserSpice session flash messages in its `flash`
 * key, so a test can check what an endpoint said before it redirected.
 */
#[Group('integration')]
final class IntegrationTestCaseFlashHelperTest extends IntegrationTestCase
{
    private const FIXTURE = 'tests/integration/fixtures/flash-endpoint.php';

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();
    }

    public function test_invokeEndpoint_flashHoldsMessagesSetBeforeRedirect(): void
    {
        $result = $this->invokeEndpoint(self::FIXTURE, ['mode' => 'redirect'], null);

        $this->assertSame(['Fixture error one', 'Fixture error two'], $result['flash']['error']);
        $this->assertSame(['Fixture success'], $result['flash']['success']);
        $this->assertSame(['Fixture warning'], $result['flash']['message']);
    }

    public function test_invokeEndpoint_flashPayloadIsStrippedFromRaw(): void
    {
        $result = $this->invokeEndpoint(self::FIXTURE, ['mode' => 'redirect'], null);

        $this->assertStringNotContainsString('___FLASH:', $result['raw']);
        $this->assertStringNotContainsString('___HTTP:', $result['raw']);
    }

    public function test_invokeEndpoint_flashIsEmptyWhenEndpointSetsNone(): void
    {
        $result = $this->invokeEndpoint(self::FIXTURE, ['mode' => 'none'], null);

        $this->assertSame(['error' => [], 'success' => [], 'message' => []], $result['flash']);
        $this->assertSame('fixture-plain-output', $result['raw']);
    }
}
