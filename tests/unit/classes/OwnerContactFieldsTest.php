<?php

declare(strict_types=1);

use ElanRegistry\DatabaseInterface;
use ElanRegistry\Owner;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Owner::ownerContactFields().
 *
 * This method is the single definition of the owner-contact column set,
 * consumed by both Owner::syncOwnerFieldsToCars() and
 * app/api/cars/save.php::buildCarDetails(). These tests exist to make any
 * future change to that set deliberate and visible rather than a silent
 * drift between the two consumers.
 *
 * @see https://github.com/elan-registry/registry/issues/1962
 */
#[Group('fast')]
#[Group('unit')]
final class OwnerContactFieldsTest extends TestCase
{
    private Owner $owner;
    private \ReflectionProperty $dataProp;

    protected function setUp(): void
    {
        // A DatabaseInterface double is required: Owner's constructor otherwise
        // falls back to dbi(), which is undefined in the unit tier
        // (custom_functions.php — where dbi() lives — is never loaded there).
        // A stub, not a mock — this test exercises pure data-shaping logic over
        // reflection-injected data and never reaches the database.
        $this->owner    = new Owner(null, $this->createStub(DatabaseInterface::class));
        $ref            = new \ReflectionClass(Owner::class);
        $this->dataProp = $ref->getProperty('_data');
    }

    public function testOwnerContactFields_PopulatedData_ReturnsExactlyExpectedNineKeys(): void
    {
        $this->dataProp->setValue($this->owner, (object) [
            'fname'   => 'Jim',
            'lname'   => 'Boone',
            'email'   => 'jim@example.com',
            'city'    => 'Portland',
            'state'   => 'Oregon',
            'country' => 'United States',
            'lat'     => 45.5,
            'lon'     => -122.6,
            'website' => 'https://example.com',
        ]);

        $fields = $this->owner->ownerContactFields();

        // Assert the full key set (not just that expected keys are present) so
        // both an addition and a removal to the set fail this test.
        $this->assertSame(
            ['fname', 'lname', 'email', 'city', 'state', 'country', 'lat', 'lon', 'website'],
            array_keys($fields)
        );
    }

    /**
     * `mtime` and `owner_last_updated` must never appear in this set.
     *
     * owner_last_updated is the verification-freshness clock; if it leaked
     * into this shared field list, every consumer of ownerContactFields()
     * (car edit save, owner-initiated car sync) would silently reset
     * verification freshness on every save. mtime is deliberately excluded
     * because the caller (Owner::syncOwnerFieldsToCars()) sets it explicitly
     * itself — see the `$ownerFields['mtime'] = $syncTime;` line immediately
     * after the call to this method.
     */
    public function testOwnerContactFields_NeverIncludesMtimeOrOwnerLastUpdated(): void
    {
        $this->dataProp->setValue($this->owner, (object) [
            'fname'              => 'Jim',
            'lname'              => 'Boone',
            'email'              => 'jim@example.com',
            'city'               => 'Portland',
            'state'              => 'Oregon',
            'country'            => 'United States',
            'lat'                => 45.5,
            'lon'                => -122.6,
            'website'            => 'https://example.com',
            'mtime'              => '2026-09-05 00:00:00',
            'owner_last_updated' => '2026-09-05 00:00:00',
        ]);

        $fields = $this->owner->ownerContactFields();

        $this->assertArrayNotHasKey('mtime', $fields);
        $this->assertArrayNotHasKey('owner_last_updated', $fields);
    }

    public function testOwnerContactFields_NullData_ReturnsNineNullValues(): void
    {
        // _data defaults to null when the owner failed to load (e.g. find()
        // returned false). save.php relies on exactly this contract — nine
        // null values, not an exception — to detect a failed owner load.
        $fields = $this->owner->ownerContactFields();

        $this->assertSame(
            ['fname', 'lname', 'email', 'city', 'state', 'country', 'lat', 'lon', 'website'],
            array_keys($fields)
        );
        $this->assertSame(array_fill(0, 9, null), array_values($fields));
    }
}
