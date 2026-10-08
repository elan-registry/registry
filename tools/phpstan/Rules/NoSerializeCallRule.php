<?php

declare(strict_types=1);

namespace ElanRegistry\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Reports calls to serialize() and unserialize(). unserialize() on request
 * data allows PHP object injection. Use json_encode() and json_decode().
 *
 * @implements Rule<FuncCall>
 */
final class NoSerializeCallRule implements Rule
{
    private const BANNED_FUNCTIONS = ['serialize', 'unserialize'];

    public function getNodeType(): string
    {
        return FuncCall::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (!$node->name instanceof Name) {
            return [];
        }

        $function = strtolower($node->name->getLast());
        if (!in_array($function, self::BANNED_FUNCTIONS, true)) {
            return [];
        }

        return [
            RuleErrorBuilder::message(sprintf(
                'Do not call %s(). It can cause PHP object injection. Use json_encode() and json_decode().',
                $function
            ))
                ->identifier('elanRegistry.serializeCall')
                ->build(),
        ];
    }
}
