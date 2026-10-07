<?php

declare(strict_types=1);

namespace ElanRegistry\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\BinaryOp\Coalesce;
use PhpParser\Node\Expr\BinaryOp\Concat;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Match_;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\Ternary;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\InterpolatedString;
use PhpParser\Node\Scalar\String_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Reports a logger() or withLogging() call whose category argument can be a
 * string literal. The category must come from a LogCategories constant.
 *
 * @implements Rule<CallLike>
 */
final class LogCategoryArgumentRule implements Rule
{
    /**
     * Category parameter position and name for each checked call.
     */
    private const CATEGORY_POSITION = 1;
    private const LOGGER_CATEGORY_NAME = 'logtype';
    private const WITH_LOGGING_CATEGORY_NAME = 'category';

    public function getNodeType(): string
    {
        return CallLike::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $categoryName = $this->categoryParameterName($node);
        if ($categoryName === null) {
            return [];
        }

        $categoryArg = $this->findCategoryArg($node, $categoryName);
        if ($categoryArg === null) {
            return [];
        }

        if (!$this->canEvaluateToLiteral($categoryArg->value)) {
            return [];
        }

        $callName = $categoryName === self::LOGGER_CATEGORY_NAME ? 'logger()' : 'withLogging()';

        return [
            RuleErrorBuilder::message(sprintf(
                'The category argument of %s is a string literal. Use a LogCategories::LOG_CATEGORY_* constant.',
                $callName
            ))
                ->identifier('elanRegistry.logCategoryLiteral')
                ->line($categoryArg->getStartLine())
                ->build(),
        ];
    }

    /**
     * True when a string literal can be the value of the expression. A literal
     * that is only an array key or a comparison operand does not count.
     */
    private function canEvaluateToLiteral(Expr $expr): bool
    {
        if ($expr instanceof String_ || $expr instanceof InterpolatedString) {
            return true;
        }

        if ($expr instanceof Ternary) {
            return $this->canEvaluateToLiteral($expr->if ?? $expr->cond) || $this->canEvaluateToLiteral($expr->else);
        }

        if ($expr instanceof Coalesce || $expr instanceof Concat) {
            return $this->canEvaluateToLiteral($expr->left) || $this->canEvaluateToLiteral($expr->right);
        }

        if ($expr instanceof Match_) {
            foreach ($expr->arms as $arm) {
                if ($this->canEvaluateToLiteral($arm->body)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function categoryParameterName(CallLike $node): ?string
    {
        if ($node instanceof FuncCall && $node->name instanceof Name && $node->name->getLast() === 'logger') {
            return self::LOGGER_CATEGORY_NAME;
        }

        if (
            ($node instanceof MethodCall || $node instanceof NullsafeMethodCall)
            && $node->name instanceof Identifier
            && $node->name->toLowerString() === 'withlogging'
        ) {
            return self::WITH_LOGGING_CATEGORY_NAME;
        }

        return null;
    }

    private function findCategoryArg(CallLike $node, string $categoryName): ?Arg
    {
        if ($node->isFirstClassCallable()) {
            return null;
        }

        $positional = 0;
        foreach ($node->getArgs() as $arg) {
            if ($arg->name !== null) {
                if ($arg->name->toString() === $categoryName) {
                    return $arg;
                }
                continue;
            }

            if ($positional === self::CATEGORY_POSITION) {
                return $arg;
            }
            $positional++;
        }

        return null;
    }
}
