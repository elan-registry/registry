<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Issue #915: the chassis_override flag must reach Car::update() from save.php.
 *
 * @issue 915
 * @link https://github.com/unibrain1/elanregistry/issues/915
 */
#[Group('regression')]
final class Issue915RegressionTest extends TestCase
{
    /**
     * If the POST key is renamed or the assignment is removed, the flag stops
     * persisting even though Car.php and CarValidator.php stay correct.
     */
    public function testEditPhpWiresChassisOverrideThroughToCardetails(): void
    {
        $editSource = file_get_contents(dirname(__DIR__, 3) . '/app/api/cars/save.php');

        $this->assertNotFalse($editSource, 'save.php must be readable');
        $this->assertStringContainsString(
            "Input::raw('chassis_override')",
            $editSource,
            "save.php must read chassis_override from POST via Input::raw()"
        );
        $this->assertStringContainsString(
            "\$cardetails['chassis_override']",
            $editSource,
            "save.php must assign chassis_override into \$cardetails so it reaches Car::update()"
        );
    }
}
