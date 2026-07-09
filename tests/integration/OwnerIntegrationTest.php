<?php
declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

use ElanRegistry\Owner;

/**
 * Integration tests for Owner class
 *
 * These tests require the full application bootstrap and real database connection.
 * They test Owner functionality with actual database data and global functions.
 */
class OwnerIntegrationTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();
    }

    /**
     * Test owner loading with valid ID
     */
    public function testFindWithValidUser(): void
    {
        // Use user ID 1 for testing
        $userId = 1;
        $owner = new Owner();
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
        $owner = new Owner((int)$userId);

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
        $owner = new Owner();
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

    public function testLatNullPassesThrough(): void
    {
        $result = $this->callValidateAndSanitize(['lat' => null]);
        $this->assertArrayNotHasKey('lat', $result);
    }

    public function testLatEmptyStringPassesThrough(): void
    {
        $result = $this->callValidateAndSanitize(['lat' => '']);
        $this->assertArrayNotHasKey('lat', $result);
    }

    public function testLatZeroIsAccepted(): void
    {
        // Zero is a valid equator coordinate — !empty() would incorrectly reject it
        $result = $this->callValidateAndSanitize(['lat' => '0']);
        $this->assertSame(0.0, $result['lat']);
    }

    public function testLatBoundaryAccepted(): void
    {
        $result = $this->callValidateAndSanitize(['lat' => '90']);
        $this->assertSame(90.0, $result['lat']);
    }

    public function testLatOutOfRangeRejected(): void
    {
        $this->expectException(\ElanRegistry\Exceptions\OwnerValidationException::class);
        $this->callValidateAndSanitize(['lat' => '91']);
    }

    public function testLatNonNumericRejected(): void
    {
        $this->expectException(\ElanRegistry\Exceptions\OwnerValidationException::class);
        $this->callValidateAndSanitize(['lat' => 'north']);
    }

    public function testLatIsCastToFloat(): void
    {
        $result = $this->callValidateAndSanitize(['lat' => '51.5']);
        $this->assertSame(51.5, $result['lat']);
    }

    public function testLonBoundaryAccepted(): void
    {
        $result = $this->callValidateAndSanitize(['lon' => '180']);
        $this->assertSame(180.0, $result['lon']);
    }

    public function testLonOutOfRangeRejected(): void
    {
        $this->expectException(\ElanRegistry\Exceptions\OwnerValidationException::class);
        $this->callValidateAndSanitize(['lon' => '181']);
    }

    public function testLonZeroIsAccepted(): void
    {
        // Zero is a valid prime-meridian coordinate — !empty() would incorrectly reject it
        $result = $this->callValidateAndSanitize(['lon' => '0']);
        $this->assertSame(0.0, $result['lon']);
    }

    public function testQualityScoreCountsZeroCoordinates(): void
    {
        // qualityScoreFromRow() must not treat 0.0 as "missing" — regression guard for !empty() fix
        $row = (object)[
            'fname' => 'Alice', 'lname' => 'Smith', 'email' => 'a@b.com',
            'city' => 'X', 'state' => 'Y', 'country' => 'Z',
            'lat' => '0', 'lon' => '0',
        ];
        $this->assertSame(100.0, Owner::qualityScoreFromRow($row));
    }

    public function testCompletenessAcceptsZeroCoordinates(): void
    {
        $userId = $this->createTestUser();
        $insertResult = $this->db->insert('profiles', [
            'user_id' => $userId,
            'city' => 'Equator', 'state' => 'Meridian', 'country' => 'Ocean',
            'lat' => 0, 'lon' => 0,
        ]);
        $this->assertTrue((bool) $insertResult, 'Test fixture: profiles insert must succeed');
        try {
            $owner = new Owner($userId);
            $missing = $owner->validateProfileCompleteness();
            $this->assertNotContains('Location Coordinates', $missing,
                'lat=0 and lon=0 are valid coordinates and must not be flagged as missing');
        } finally {
            $this->db->query("DELETE FROM profiles WHERE user_id = ?", [$userId]);
        }
    }

    public function testLatLonRoundTripThroughUpdate(): void
    {
        $userId = $this->createTestUser();
        $insertResult = $this->db->insert('profiles', [
            'user_id' => $userId,
            'city' => 'London', 'state' => 'England', 'country' => 'UK',
            'lat' => 1.0, 'lon' => 1.0,
        ]);
        $this->assertTrue((bool) $insertResult, 'Test fixture: profiles insert must succeed');
        try {
            $csrf = Token::generate();
            $owner = new Owner($userId);
            $owner->update([
                'id' => $userId,
                'csrf' => $csrf,
                'lat' => '0',
                'lon' => '0',
            ]);
            // update() calls find() internally — data is already reloaded from DB
            $this->assertSame(0.0, (float) $owner->data()->lat,
                'lat=0 must survive a MySQL write and read-back');
            $this->assertSame(0.0, (float) $owner->data()->lon,
                'lon=0 must survive a MySQL write and read-back');
        } finally {
            $this->db->query("DELETE FROM profiles WHERE user_id = ?", [$userId]);
        }
    }

    // -------------------------------------------------------------------------
    // city/state/country length cap raised from 50 → 100 (issue #1233 fix 1)
    // -------------------------------------------------------------------------

    public function testCityAccepts75CharValue(): void
    {
        // 75-char value is well within the 100-char cap — must pass through unchanged
        $city = str_repeat('x', 75);
        $result = $this->callValidateAndSanitize(['city' => $city]);
        $this->assertSame($city, $result['city']);
    }

    public function testCityAccepts100CharValue(): void
    {
        // Exactly at the new cap of 100 chars — must pass through unchanged
        $city = str_repeat('x', 100);
        $result = $this->callValidateAndSanitize(['city' => $city]);
        $this->assertSame(100, strlen($result['city']));
    }

    public function testCityTruncatesAt100Chars(): void
    {
        // 101-char value exceeds the cap and must be truncated to exactly 100 chars
        $city = str_repeat('x', 101);
        $result = $this->callValidateAndSanitize(['city' => $city]);
        $this->assertSame(100, strlen($result['city']));
    }

    // -------------------------------------------------------------------------
    // validateRequiredFields() '0' acceptance (issue #1233 fix 3)
    // -------------------------------------------------------------------------

    /**
     * @param array<string, string> $fields
     * @param list<string>          $required
     */
    private function callValidateRequiredFields(array $fields, array $required): void
    {
        $owner = new Owner();
        $method = new \ReflectionMethod($owner, 'validateRequiredFields');
        $method->invoke($owner, $fields, $required);
    }

    public function testRequiredFieldAcceptsZeroString(): void
    {
        // '0' is a legitimate value — trim((string)'0') === '' is false, so no exception
        $this->callValidateRequiredFields(
            ['fname' => '0', 'lname' => '0', 'email' => 'test@example.com'],
            ['fname', 'lname', 'email']
        );
        // If we reach here, no exception was thrown — that is the assertion
        $this->assertTrue(true);
    }

    public function testRequiredFieldRejectsEmptyString(): void
    {
        $this->expectException(\ElanRegistry\Exceptions\OwnerValidationException::class);
        $this->callValidateRequiredFields(
            ['fname' => '', 'lname' => 'Smith', 'email' => 'test@example.com'],
            ['fname', 'lname', 'email']
        );
    }

    public function testRequiredFieldRejectsWhitespaceOnly(): void
    {
        $this->expectException(\ElanRegistry\Exceptions\OwnerValidationException::class);
        $this->callValidateRequiredFields(
            ['fname' => '   ', 'lname' => 'Smith', 'email' => 'test@example.com'],
            ['fname', 'lname', 'email']
        );
    }
}
