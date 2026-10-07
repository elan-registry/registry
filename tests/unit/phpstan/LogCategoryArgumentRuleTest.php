<?php

declare(strict_types=1);

use ElanRegistry\PHPStan\Rules\LogCategoryArgumentRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * @extends RuleTestCase<LogCategoryArgumentRule>
 */
#[Group('phpstan-rules')]
final class LogCategoryArgumentRuleTest extends RuleTestCase
{
    private const LOGGER_ERROR = 'The category argument of logger() is a string literal. Use a LogCategories::LOG_CATEGORY_* constant.';
    private const WITH_LOGGING_ERROR = 'The category argument of withLogging() is a string literal. Use a LogCategories::LOG_CATEGORY_* constant.';

    protected function getRule(): Rule
    {
        return new LogCategoryArgumentRule();
    }

    public function testReportsLiteralCategoriesOnly(): void
    {
        $this->analyse([__DIR__ . '/fixtures/log-category/logger-calls.php'], [
            [self::LOGGER_ERROR, 5],
            [self::LOGGER_ERROR, 7],
            [self::LOGGER_ERROR, 9],
            [self::LOGGER_ERROR, 10],
            [self::LOGGER_ERROR, 12],
            [self::WITH_LOGGING_ERROR, 13],
            [self::WITH_LOGGING_ERROR, 15],
        ]);
    }
}
