<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

use PHPUnit\Framework\Attributes\Group;

/**
 * app/owner/cars/details.php loads the owner's email state from the profile
 * and shows it in the Email on file row, for registry admins only (#2101).
 *
 * The card partial has its own tests. These tests render the real page, so
 * they fail if the page stops loading the state for the card.
 *
 * Each request runs in its own `php -r` subprocess because the page can call exit.
 */
#[Group('integration')]
#[Group('car-verification')]
final class CarDetailsEmailOnFileRenderTest extends IntegrationTestCase
{
    private const PAGE = 'app/owner/cars/details.php';
    private const PERMISSION_MEMBER = 1;

    private const PERMISSION_ADMIN = 2;

    private int $carId = 0;
    private string $chassis = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();

        // A profile row, because the bounced flag lives on the profile.
        $ownerId = $this->createTestUser([], true);
        $this->chassis = 'T' . substr(uniqid(), -10);
        $this->carId = $this->createTestCar($ownerId, ['chassis' => $this->chassis]);
        $row = $this->db->query('SELECT email FROM users WHERE id = ?', [$ownerId])->first();
        $this->assertIsObject($row, 'Test setup: owner row must exist');
        $this->setOwnerBounced($ownerId, (string) $row->email);
    }

    /**
     * Render the page for a viewer and check that it rendered this car.
     * A crash or a redirect must not pass as "no row".
     */
    private function renderDetailsAs(int $viewerId): string
    {
        $result = $this->invokeEndpoint(self::PAGE, [], $viewerId, 'omit', ['car_id' => (string) $this->carId], 'GET');

        $this->assertSame(0, $result['exitCode'], 'Subprocess must exit cleanly: ' . $result['raw']);
        // Not assertNoPhpErrorText(): it also matches "TypeError", which a
        // JavaScript comment in the page footer contains.
        foreach (['Fatal error', 'Parse error', 'Uncaught', 'Warning:', 'Notice:', 'Deprecated:'] as $phpError) {
            $this->assertStringNotContainsString($phpError, $result['raw']);
        }
        $this->assertStringContainsString($this->chassis, $result['raw'], 'The page must render this car');

        return $result['raw'];
    }

    private function xpathFor(string $html): DOMXPath
    {
        $doc = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $doc->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $this->assertTrue($loaded, 'The page HTML must parse');

        return new DOMXPath($doc);
    }

    /**
     * @return string The text of the Email on file row, or '' when the row is absent
     */
    private function emailOnFileText(DOMXPath $xpath): string
    {
        $nodes = $xpath->query('//dt[starts-with(normalize-space(.),"Email on file")]/following-sibling::dd[1]');
        $this->assertNotFalse($nodes);

        return $nodes->length === 0 ? '' : trim((string) preg_replace('/\s+/', ' ', (string) $nodes->item(0)?->textContent));
    }

    public function testAdminSeesTheBouncedStateInTheEmailOnFileRow(): void
    {
        // permissions = 1, because securePage() treats 0 as banned.
        $adminId = $this->createTestUser(['permissions' => 1]);
        $this->grantTestPermission($adminId, self::PERMISSION_ADMIN);

        $text = $this->emailOnFileText($this->xpathFor($this->renderDetailsAs($adminId)));

        $this->assertStringContainsString('Bounced', $text);
    }

    public function testMemberSeesNoEmailOnFileRow(): void
    {
        $memberId = $this->createTestUser(['permissions' => 1]);
        $this->grantTestPermission($memberId, self::PERMISSION_MEMBER);

        $xpath = $this->xpathFor($this->renderDetailsAs($memberId));

        // Positive marker: the parse must find the card's rows that a member
        // sees. Without it, a parse failure or a renamed label would also give "no row".
        $chassisRows = $xpath->query('//dt[starts-with(normalize-space(.),"Chassis")]');
        $this->assertNotFalse($chassisRows);
        $this->assertGreaterThan(0, $chassisRows->length, 'The member must see the Chassis row of the vehicle info card');
        $this->assertSame('', $this->emailOnFileText($xpath));
    }
}
