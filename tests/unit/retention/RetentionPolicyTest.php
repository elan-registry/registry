<?php

declare(strict_types=1);

use ElanRegistry\Retention\DeletedUserAddressesBacklogRule;
use ElanRegistry\Retention\RetentionPolicy;
use ElanRegistry\Retention\SendinblueMessagesRetentionRule;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeDatabase;

require_once __DIR__ . '/../../Support/FakeDatabase.php';

/**
 * Unit tests for RetentionPolicy and the rule constructor guard (#2384).
 */
#[Group('fast')]
final class RetentionPolicyTest extends TestCase
{
    public function testRulesHoldTheSendinblueRuleAndTheBacklogRule(): void
    {
        $rules = RetentionPolicy::rules(new FakeDatabase());

        $this->assertCount(2, $rules);
        $this->assertInstanceOf(SendinblueMessagesRetentionRule::class, $rules[0]);
        $this->assertSame('sendinblue_messages', $rules[0]->name());
        $this->assertInstanceOf(DeletedUserAddressesBacklogRule::class, $rules[1]);
        $this->assertSame('deleted_user_addresses_backlog', $rules[1]->name());
    }

    public function testRuleNamesAreUnique(): void
    {
        $names = array_map(
            static fn ($rule): string => $rule->name(),
            RetentionPolicy::rules(new FakeDatabase())
        );

        $this->assertNotSame([], $names);
        $this->assertSame($names, array_values(array_unique($names)));
    }

    /**
     * app/owner/privacy.php promises 90 days. A config edit above 90 must fail here.
     */
    public function testSendinblueWindowDoesNotExceedThePrivacyPromise(): void
    {
        $this->assertLessThanOrEqual(90, RETENTION_SENDINBLUE_MESSAGES_DAYS);
        $this->assertGreaterThanOrEqual(1, RETENTION_SENDINBLUE_MESSAGES_DAYS);
    }

    public function testBootstrapAndConfigHoldTheSameValue(): void
    {
        $config = (string) file_get_contents(__DIR__ . '/../../../usersc/includes/config.php');
        $bootstrap = (string) file_get_contents(__DIR__ . '/../../bootstrap-unit.php');
        $pattern = "/define\\(\\s*'RETENTION_SENDINBLUE_MESSAGES_DAYS'\\s*,\\s*(\\d+)\\s*\\)/";

        $this->assertSame(1, preg_match($pattern, $config, $configMatch), 'config.php must define the constant');
        $this->assertSame(1, preg_match($pattern, $bootstrap, $bootstrapMatch), 'bootstrap-unit.php must define the constant');
        $this->assertSame($configMatch[1], $bootstrapMatch[1]);
    }

    /**
     * @return array<string, array{int}>
     */
    public static function invalidWindows(): array
    {
        return ['zero' => [0], 'negative' => [-5]];
    }

    #[DataProvider('invalidWindows')]
    public function testRuleRefusesAWindowBelowOneDay(int $days): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SendinblueMessagesRetentionRule(new FakeDatabase(), $days);
    }

    public function testRuleAcceptsAWindowOfOneDay(): void
    {
        $rule = new SendinblueMessagesRetentionRule(new FakeDatabase(), 1);

        $this->assertSame('sendinblue_messages', $rule->name());
    }
}
