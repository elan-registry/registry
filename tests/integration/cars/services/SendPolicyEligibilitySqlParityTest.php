<?php

declare(strict_types=1);

require_once __DIR__ . '/../../IntegrationTestCase.php';

use ElanRegistry\Car\CarRepository;
use ElanRegistry\Car\MailKind;
use ElanRegistry\Car\SendPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * #2360: the verification eligibility SQL and SendPolicy must agree.
 *
 * The batch selects owners with SQL (findVerificationEligible()). The admin
 * tool and the cron job then ask SendPolicy per owner. Two rule sets can
 * drift apart, so for each flag that SendPolicy says blocks verification mail
 * this test checks that the SQL also excludes a car whose owner has the
 * flag. The flag list comes from SendPolicy::blockingFlags() and not from a
 * copy.
 */
#[Group('integration')]
#[Group('car-verification')]
final class SendPolicyEligibilitySqlParityTest extends IntegrationTestCase
{
    private const OWNER_EMAIL = 'parity-owner@example.com';

    private CarRepository $repo;
    private SendPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();
        $this->repo   = new CarRepository($this->db);
        $this->policy = new SendPolicy($this->repo);
    }

    /** @return array<string, array{0: string}> */
    public static function verificationFlagProvider(): array
    {
        // The provider runs before setUp(), and blockingFlags() never reads the repository.
        $repo  = (new \ReflectionClass(CarRepository::class))->newInstanceWithoutConstructor();
        $flags = (new SendPolicy($repo))->blockingFlags(MailKind::Verification);

        return array_combine($flags, array_map(static fn (string $f): array => [$f], $flags));
    }

    /** @return array<string, array{0: string}> flags that name an address */
    public static function addressBoundFlagProvider(): array
    {
        return [
            'email_blocked' => ['email_blocked'],
            'email_bounced' => ['email_bounced'],
        ];
    }

    /**
     * A stale, unsold car with an email and no recent send. With a clean
     * owner it is eligible.
     */
    private function createEligibleCar(int $ownerId, string $email): int
    {
        $stale = date('Y-m-d H:i:s', strtotime('-3 years'));

        return $this->createTestCar($ownerId, [
            'email'              => $email,
            'last_verified'      => null,
            'owner_last_updated' => $stale,
            'mtime'              => $stale,
            'solddate'           => null,
        ]);
    }

    /**
     * Put an owner in the state that the flag names. no_profile is a user
     * with no profiles row.
     */
    private function createOwnerWithFlag(string $flag, string $email): int
    {
        $ownerId = $this->createTestUser(['email' => $email], $flag !== 'no_profile');
        match ($flag) {
            'no_profile'      => null,
            'email_opted_out' => $this->setOwnerOptedOut($ownerId),
            'email_blocked'   => $this->setOwnerBlocked($ownerId, $email),
            'email_bounced'   => $this->setOwnerBounced($ownerId, $email),
            default           => $this->fail("Test setup: no fixture for flag {$flag}"),
        };

        return $ownerId;
    }

    public function test_blockingFlags_verification_isNotEmpty(): void
    {
        // Without this the data provider would give no cases and the parity tests would pass with zero runs.
        $this->assertNotSame([], $this->policy->blockingFlags(MailKind::Verification));
    }

    public function test_blockingFlags_verification_coversEveryOwnerFlag(): void
    {
        // A flag dropped from the table would drop out of the provider and out of the parity checks.
        // This pins the set, so that the loss is a failure and not a silent gap.
        $this->assertSame(
            ['no_profile', 'email_bounced', 'email_opted_out', 'email_blocked'],
            $this->policy->blockingFlags(MailKind::Verification)
        );
    }

    public function test_cleanOwner_isAllowedByBothTheSqlAndThePolicy(): void
    {
        $ownerId = $this->createTestUser(['email' => self::OWNER_EMAIL], true);
        $carId   = $this->createEligibleCar($ownerId, self::OWNER_EMAIL);

        $this->assertContains($carId, $this->allVerificationEligibleCarIds(), 'SQL: a clean owner is eligible');
        $decision = $this->policy->forUser(MailKind::Verification, $ownerId);
        $this->assertTrue($decision->allowed, 'Policy: a clean owner is allowed');
        $this->assertNull($this->policy->verificationReason($decision));
    }

    #[DataProvider('verificationFlagProvider')]
    public function test_flagThatPolicyBlocks_alsoExcludesTheOwnerInSql(string $flag): void
    {
        $flaggedOwner = $this->createOwnerWithFlag($flag, self::OWNER_EMAIL);
        $flaggedCar   = $this->createEligibleCar($flaggedOwner, self::OWNER_EMAIL);
        $controlOwner = $this->createTestUser(['email' => 'parity-control@example.com'], true);
        $controlCar   = $this->createEligibleCar($controlOwner, 'parity-control@example.com');

        $eligible = $this->allVerificationEligibleCarIds();
        $decision = $this->policy->forUser(MailKind::Verification, $flaggedOwner);

        $this->assertContains($flag, $this->policy->blockingFlags(MailKind::Verification));
        $this->assertFalse($decision->allowed, "Policy: {$flag} must block verification mail");
        $this->assertContains($flag, $decision->flags);
        $this->assertNotContains($flaggedCar, $eligible, "SQL: {$flag} must exclude the owner's car");
        $this->assertContains($controlCar, $eligible, 'Control: the clean owner car stays eligible');
        $this->assertTrue(
            $this->policy->forUser(MailKind::Verification, $controlOwner)->allowed,
            'Control: the policy allows the clean owner'
        );
    }

    #[DataProvider('verificationFlagProvider')]
    public function test_everyFlagFromTheTable_neverGivesADisagreement(string $flag): void
    {
        $ownerId = $this->createOwnerWithFlag($flag, self::OWNER_EMAIL);
        $carId   = $this->createEligibleCar($ownerId, self::OWNER_EMAIL);

        $sqlExcludes  = !in_array($carId, $this->allVerificationEligibleCarIds(), true);
        $policyBlocks = !$this->policy->forUser(MailKind::Verification, $ownerId)->allowed;

        $this->assertSame($policyBlocks, $sqlExcludes, "SQL and policy disagree for {$flag}");
    }

    /**
     * Both read the flag through the address match, so a block or a bounce
     * on an old address stops applying in the SQL and in the policy together.
     */
    #[DataProvider('addressBoundFlagProvider')]
    public function test_flagOnAnOldAddress_isIgnoredByBothTheSqlAndThePolicy(string $flag): void
    {
        $ownerId = $this->createOwnerWithFlag($flag, self::OWNER_EMAIL);
        $carId   = $this->createEligibleCar($ownerId, self::OWNER_EMAIL);
        $this->assertNotContains($carId, $this->allVerificationEligibleCarIds(), 'Test setup: the matching flag excludes');
        $this->assertFalse($this->policy->forUser(MailKind::Verification, $ownerId)->allowed, 'Test setup: the policy blocks');

        $newAddress = 'parity-changed-' . uniqid() . '@example.com';
        $this->setUserEmailRaw($ownerId, $newAddress);
        $this->db->query('UPDATE cars SET email = ? WHERE id = ?', [$newAddress, $carId]);

        $this->assertContains($carId, $this->allVerificationEligibleCarIds(), "SQL: a {$flag} on the old address must not exclude");
        $this->assertTrue(
            $this->policy->forUser(MailKind::Verification, $ownerId)->allowed,
            "Policy: a {$flag} on the old address must not block"
        );
    }
}
