<?php

declare(strict_types=1);

use ElanRegistry\Car\EmailEventApplier;
use ElanRegistry\Car\EmailEventApplyResult;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Support\SpyEmailEventApplier;

/**
 * Keeps SpyEmailEventApplier in step with EmailEventApplier
 *
 * The spy skips the parent constructor. An inherited public method would
 * then run real code on uninitialized collaborators and fail with an error
 * far from its cause. This test fails first and names the method.
 */
#[Group('fast')]
final class SpyEmailEventApplierContractTest extends TestCase
{
    public function testSpyOverridesEveryPublicMethodOfTheApplier(): void
    {
        $notOverridden = [];
        foreach ((new ReflectionClass(EmailEventApplier::class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isConstructor()) {
                continue;
            }
            $spyMethod = new ReflectionMethod(SpyEmailEventApplier::class, $method->getName());
            if ($spyMethod->getDeclaringClass()->getName() !== SpyEmailEventApplier::class) {
                $notOverridden[] = $method->getName();
            }
        }

        $this->assertSame(
            [],
            $notOverridden,
            'SpyEmailEventApplier must override each public method of EmailEventApplier'
        );
    }

    public function testSpyApplyReturnTypeMatchesTheApplier(): void
    {
        $applierType = (new ReflectionMethod(EmailEventApplier::class, 'apply'))->getReturnType();
        $spyType = (new ReflectionMethod(SpyEmailEventApplier::class, 'apply'))->getReturnType();

        $this->assertNotNull($applierType);
        $this->assertNotNull($spyType);
        $this->assertSame((string) $applierType, (string) $spyType);
        $this->assertSame(EmailEventApplyResult::class, (string) $spyType);
    }
}
