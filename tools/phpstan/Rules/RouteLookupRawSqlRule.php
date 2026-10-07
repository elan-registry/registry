<?php

declare(strict_types=1);

namespace ElanRegistry\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\InterpolatedStringPart;
use PhpParser\Node\Scalar\String_;
use PhpParser\NodeFinder;
use PHPStan\Analyser\Scope;
use PHPStan\Node\FileNode;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * The listed action files must look up cars and owners through the Car and
 * Owner classes (#962, #1148). A string literal that selects FROM cars or
 * FROM users is a raw lookup.
 *
 * @implements Rule<FileNode>
 */
final class RouteLookupRawSqlRule implements Rule
{
    private const RAW_LOOKUP_PATTERN = '/\bFROM\s+`?(cars|users)`?\b/i';

    private string $projectRoot;

    /**
     * @param string $projectRoot Absolute path that the $files entries are relative to
     * @param list<string> $files Files that must not contain a raw lookup
     */
    public function __construct(string $projectRoot, private array $files)
    {
        $this->projectRoot = rtrim($projectRoot, '/') . '/';
    }

    public function getNodeType(): string
    {
        return FileNode::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $file = $scope->getFile();
        if (!str_starts_with($file, $this->projectRoot)
            || !in_array(substr($file, strlen($this->projectRoot)), $this->files, true)) {
            return [];
        }

        $strings = (new NodeFinder())->find(
            $node->getNodes(),
            static fn (Node $inner): bool => $inner instanceof String_ || $inner instanceof InterpolatedStringPart
        );

        $errors = [];
        foreach ($strings as $string) {
            /** @var String_|InterpolatedStringPart $string */
            if (preg_match(self::RAW_LOOKUP_PATTERN, $string->value) === 1) {
                $errors[] = RuleErrorBuilder::message(
                    'Raw SELECT FROM cars/users in an action file. Use new Car() or new Owner() for the lookup (#962).'
                )
                    ->identifier('elanRegistry.rawDomainLookup')
                    ->line($string->getStartLine())
                    ->build();
            }
        }

        return $errors;
    }
}
