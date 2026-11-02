<?php

declare(strict_types=1);

namespace ElanRegistry;

/**
 * The outcome of one AccountDeletionPrivacyStep::run() call.
 *
 * @package ElanRegistry
 * @since v2.31.0
 * @see AccountDeletionPrivacyResult
 */
enum AccountDeletionPrivacyOutcome
{
    /** The step deleted the rows and committed. */
    case Committed;

    /** A stage failed and the step rolled back, so the personal data remains. */
    case RolledBack;

    /** The commit threw, so the server may or may not have committed the deletes. */
    case CommitUnknown;

    /** A guard stopped the run before any delete. */
    case Refused;
}
