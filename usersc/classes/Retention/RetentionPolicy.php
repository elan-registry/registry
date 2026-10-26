<?php

declare(strict_types=1);

namespace ElanRegistry\Retention;

use ElanRegistry\DatabaseInterface;

/**
 * The one list of data retention rules that
 * {@see \ElanRegistry\Cron\RetentionPurgeJob} runs.
 *
 * To add a rule: write a {@see RetentionRule} class, add its window as a
 * `define()` constant in usersc/includes/config.php (and mirror it in
 * tests/bootstrap-unit.php), and add one line here. A rule with no window,
 * such as {@see DeletedUserAddressesBacklogRule}, needs no constant.
 *
 * @package ElanRegistry\Retention
 * @since v2.30.10
 * @see https://github.com/elan-registry/registry/issues/2384
 */
final class RetentionPolicy
{
    /**
     * Build the retention rules.
     *
     * @param DatabaseInterface $db The database connection for the rules
     * @return list<RetentionRule> The rules, in run order
     */
    public static function rules(DatabaseInterface $db): array
    {
        return [
            new SendinblueMessagesRetentionRule($db, RETENTION_SENDINBLUE_MESSAGES_DAYS),
            new DeletedUserAddressesBacklogRule($db),
        ];
    }
}
