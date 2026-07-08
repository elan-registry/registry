<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Regression test for Issue #915: chassis_override flag
 *
 * Ensures that the chassis_override boolean flag cannot be accidentally dropped
 * from the allowlist or the validator switch statement in future refactors.
 *
 * @issue 915
 * @link https://github.com/unibrain1/elanregistry/issues/915
 * @category regression
 *
 * Root cause: chassis_override was needed to allow administrators to override
 * chassis-number validation rules for documented edge cases. The flag must be
 * present in Car::$validCarFields so that it passes the field-allowlist check,
 * and it must have a dedicated case in CarValidator::validateAndSanitizeFields()
 * so that any submitted value is coerced to a strict 0/1 integer before storage.
 *
 * Fix: Added 'chassis_override' to $validCarFields in Car.php and added
 * case 'chassis_override': to the switch statement in CarValidator.php.
 */
final class Issue915RegressionTest extends TestCase
{
    /** @var string Absolute path to the project root */
    private string $projectRoot;

    protected function setUp(): void
    {
        // tests/regression/ is two levels below the project root
        $this->projectRoot = dirname(__DIR__, 2);
    }

    /**
     * 'chassis_override' must remain in Car::$validCarFields.
     *
     * If it is ever removed the field will be silently discarded on every save,
     * causing the override to be lost without any error surfacing to the user.
     */
    public function testChassisOverrideIsInCarValidCarFields(): void
    {
        $carSource = file_get_contents($this->projectRoot . '/usersc/classes/Car/Car.php');

        $this->assertNotFalse($carSource, 'Car.php must be readable');
        $this->assertStringContainsString(
            "'chassis_override'",
            $carSource,
            "Car::\$validCarFields must contain 'chassis_override' — removing it silently discards the override flag on save"
        );
    }

    /**
     * case 'chassis_override': must remain in CarValidator::validateAndSanitizeFields().
     *
     * Without this case the value falls through to the default branch, which
     * stores whatever string the user submitted instead of a coerced 0/1 integer.
     */
    public function testChassisOverrideValidatorCaseExists(): void
    {
        $validatorSource = file_get_contents(
            $this->projectRoot . '/usersc/classes/Car/CarValidator.php'
        );

        $this->assertNotFalse($validatorSource, 'CarValidator.php must be readable');
        $this->assertStringContainsString(
            "case 'chassis_override':",
            $validatorSource,
            "CarValidator::validateAndSanitizeFields() must have a dedicated case for 'chassis_override' to enforce 0/1 coercion"
        );
    }

    /**
     * edit.php must read chassis_override from POST and write it to $cardetails.
     *
     * Guards the wiring between the form submission and Car::update() — if the
     * POST key is renamed or the assignment is removed, the flag silently stops
     * persisting even though Car.php and CarValidator.php remain correct.
     */
    public function testEditPhpWiresChassisOverrideThroughToCardetails(): void
    {
        $editSource = file_get_contents($this->projectRoot . '/app/api/cars/save.php');

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
