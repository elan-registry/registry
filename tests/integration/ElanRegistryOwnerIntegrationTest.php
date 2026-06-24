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
        $this->expectException(\ElanRegistry\Exceptions\OwnerValidationException::class);
        $this->expectExceptionMessageMatches('/http.*https/');
        $this->callValidateAndSanitize(['website' => 'javascript:alert(1)']);
    }

    public function testWebsiteJavascriptVoidSchemeIsRejected(): void
    {
        // javascript:void(0) passes FILTER_VALIDATE_URL on some PHP versions — blocked by scheme whitelist
        $this->expectException(\ElanRegistry\Exceptions\OwnerValidationException::class);
        $this->expectExceptionMessageMatches('/http.*https/');
        $this->callValidateAndSanitize(['website' => 'javascript:void(0)']);
    }

    public function testWebsiteDataSchemeIsRejected(): void
    {
        // data: fails FILTER_VALIDATE_URL — caught by the URL format check
        $this->expectException(\ElanRegistry\Exceptions\OwnerValidationException::class);
        $this->expectExceptionMessageMatches('/http.*https/');
        $this->callValidateAndSanitize(['website' => 'data:text/html,test']);
    }

    public function testWebsiteFtpSchemeIsRejected(): void
    {
        // ftp: passes FILTER_VALIDATE_URL but is blocked by the scheme whitelist
        $this->expectException(\ElanRegistry\Exceptions\OwnerValidationException::class);
        $this->expectExceptionMessageMatches('/http.*https/');
        $this->callValidateAndSanitize(['website' => 'ftp://example.com/file.txt']);
    }

    public function testWebsiteSchemelessDomainIsRejected(): void
    {
        $this->expectException(\ElanRegistry\Exceptions\OwnerValidationException::class);
        $this->callValidateAndSanitize(['website' => 'example.com']);
    }

    public function testWebsiteEmptyIsAccepted(): void
    {
        $result = $this->callValidateAndSanitize(['website' => '']);
        $this->assertArrayNotHasKey('website', $result);
    }
}
