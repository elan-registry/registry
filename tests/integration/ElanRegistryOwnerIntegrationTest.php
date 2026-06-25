<?php
declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

/**
 * Integration tests for ElanRegistryOwner class
 *
 * These tests require the full application bootstrap and real database connection.
 * They test ElanRegistryOwner functionality with actual database data and global functions.
 */
class ElanRegistryOwnerIntegrationTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();
    }

    /**
     * Test static getOwnerProfile method with valid user
     */
    public function testGetOwnerProfileWithValidUser(): void
    {
        // Use user ID 1 for testing
        $userId = 1;
        $ownerData = ElanRegistryOwner::getOwnerProfile((int)$userId);

        $this->assertNotNull($ownerData);
        $this->assertEquals($userId, $ownerData->id);
        $this->assertObjectHasProperty('fname', $ownerData);
        $this->assertObjectHasProperty('lname', $ownerData);
        $this->assertObjectHasProperty('email', $ownerData);
    }

    /**
     * Test owner loading with valid ID
     */
    public function testFindWithValidUser(): void
    {
        // Use user ID 1 for testing
        $userId = 1;
        $owner = new ElanRegistryOwner();
        $result = $owner->find((int)$userId);

        $this->assertTrue($result);
        $this->assertNotNull($owner->data());
        $this->assertEquals($userId, $owner->data()->id);
    }

    /**
     * Test getting cars owned by owner
     */
    public function testGetCarsOwned(): void
    {
        // Use user ID 1 for testing
        $userId = 1;
        $owner = new ElanRegistryOwner((int)$userId);

        $ownedCars = $owner->getCarsOwned();
        $this->assertIsArray($ownedCars);
        $this->assertGreaterThan(0, count($ownedCars));

        // Check that all returned cars belong to this user
        foreach ($ownedCars as $carData) {
            $this->assertEquals($userId, $carData->user_id);
        }
    }

    /**
     * @param array<string, string> $fields
     * @return array<string, mixed>
     */
    private function callValidateAndSanitize(array $fields, bool $requireAll = false): array
    {
        $owner = new ElanRegistryOwner();
        $method = new \ReflectionMethod($owner, 'validateAndSanitizeFields');
        /** @var array<string, mixed> */
        return $method->invoke($owner, $fields, $requireAll);
    }

    public function testWebsiteHttpIsAccepted(): void
    {
        $result = $this->callValidateAndSanitize(['website' => 'http://example.com']);
        $this->assertEquals('http://example.com', $result['website']);
    }

    public function testWebsiteHttpsIsAccepted(): void
    {
        $result = $this->callValidateAndSanitize(['website' => 'https://example.com']);
        $this->assertEquals('https://example.com', $result['website']);
    }

    public function testWebsiteJavascriptSchemeIsRejected(): void
    {
        // javascript:alert(1) fails FILTER_VALIDATE_URL — caught by the URL format check
        // Regression for #927: getUserMessage() must return the specific URL message,
        // not the generic 'The owner information provided is invalid...' default.
        try {
            $this->callValidateAndSanitize(['website' => 'javascript:alert(1)']);
            $this->fail('Expected OwnerValidationException was not thrown');
        } catch (\ElanRegistry\Exceptions\OwnerValidationException $e) {
            $this->assertMatchesRegularExpression('/http.*https/i', $e->getMessage());
            $this->assertEquals(
                'Website URL must start with http:// or https:// (e.g. https://example.com)',
                $e->getUserMessage(),
                'getUserMessage() must return the specific URL message, not the generic default'
            );
            $this->assertNotEquals(
                'The owner information provided is invalid. Please check your input.',
                $e->getUserMessage()
            );
        }
    }

    public function testWebsiteJavascriptVoidSchemeIsRejected(): void
    {
        // javascript:void(0) passes FILTER_VALIDATE_URL on some PHP versions — blocked by scheme whitelist
        // Regression for #927: getUserMessage() must return the specific URL message.
        // The exact throw path (URL format vs scheme whitelist) may vary by PHP version,
        // so we assert on the shared pattern rather than the exact message string.
        try {
            $this->callValidateAndSanitize(['website' => 'javascript:void(0)']);
            $this->fail('Expected OwnerValidationException was not thrown');
        } catch (\ElanRegistry\Exceptions\OwnerValidationException $e) {
            $this->assertMatchesRegularExpression('/http.*https/i', $e->getMessage());
            $this->assertMatchesRegularExpression(
                '/http.*https/i',
                $e->getUserMessage(),
                'getUserMessage() must return a specific URL message, not the generic default'
            );
            $this->assertNotEquals(
                'The owner information provided is invalid. Please check your input.',
                $e->getUserMessage()
            );
        }
    }

    public function testWebsiteDataSchemeIsRejected(): void
    {
        // data: fails FILTER_VALIDATE_URL — caught by the URL format check
        // Regression for #927: getUserMessage() must return the specific URL message.
        try {
            $this->callValidateAndSanitize(['website' => 'data:text/html,test']);
            $this->fail('Expected OwnerValidationException was not thrown');
        } catch (\ElanRegistry\Exceptions\OwnerValidationException $e) {
            $this->assertMatchesRegularExpression('/http.*https/i', $e->getMessage());
            $this->assertEquals(
                'Website URL must start with http:// or https:// (e.g. https://example.com)',
                $e->getUserMessage(),
                'getUserMessage() must return the specific URL message, not the generic default'
            );
            $this->assertNotEquals(
                'The owner information provided is invalid. Please check your input.',
                $e->getUserMessage()
            );
        }
    }

    public function testWebsiteFtpSchemeIsRejected(): void
    {
        // ftp: passes FILTER_VALIDATE_URL but is blocked by the scheme whitelist
        // Regression for #927: getUserMessage() must return the specific URL message.
        try {
            $this->callValidateAndSanitize(['website' => 'ftp://example.com/file.txt']);
            $this->fail('Expected OwnerValidationException was not thrown');
        } catch (\ElanRegistry\Exceptions\OwnerValidationException $e) {
            $this->assertEquals(
                'Website URL must use http:// or https:// — other protocols are not allowed',
                $e->getMessage()
            );
            $this->assertEquals(
                'Website URL must use http:// or https:// — other protocols are not allowed',
                $e->getUserMessage(),
                'getUserMessage() must return the specific URL message, not the generic default'
            );
            $this->assertNotEquals(
                'The owner information provided is invalid. Please check your input.',
                $e->getUserMessage()
            );
        }
    }

    public function testWebsiteSchemelessDomainIsRejected(): void
    {
        // Regression for #927: getUserMessage() must not return the generic default.
        try {
            $this->callValidateAndSanitize(['website' => 'example.com']);
            $this->fail('Expected OwnerValidationException was not thrown');
        } catch (\ElanRegistry\Exceptions\OwnerValidationException $e) {
            $this->assertEquals(
                'Website URL must start with http:// or https:// (e.g. https://example.com)',
                $e->getUserMessage(),
                'getUserMessage() must return the specific URL message, not the generic default'
            );
        }
    }

    public function testWebsiteEmptyIsAccepted(): void
    {
        $result = $this->callValidateAndSanitize(['website' => '']);
        $this->assertArrayNotHasKey('website', $result);
    }
}
