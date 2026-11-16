<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

use PHPUnit\Framework\Attributes\Group;

/**
 * #1599: the real global dbInt() must run. Without this, a dropped
 * `use ElanRegistry\TypeHelpers;` in custom_functions.php would fatal at
 * call time while every other test passed.
 */
#[Group('integration')]
final class DbIntTest extends IntegrationTestCase
{
    public function test_dbInt_withObjectProperty_returnsInt(): void
    {
        $obj = (object) ['id' => '42'];
        $this->assertSame(42, dbInt($obj));
    }
}
