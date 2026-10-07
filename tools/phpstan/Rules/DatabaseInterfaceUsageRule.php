<?php

declare(strict_types=1);

namespace ElanRegistry\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Name;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Property;
use PhpParser\NodeFinder;
use PHPStan\Analyser\Scope;
use PHPStan\Node\FileNode;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Keeps database collaborators typed as ElanRegistry\DatabaseInterface (#1585).
 *
 * - Production code (app/, usersc/) must not use the concrete UserSpice \DB
 *   class as a parameter or property type. DbAdapter is the one exception,
 *   because it wraps \DB.
 * - No analysed file may declare a global DB or QueryResult class. #1585
 *   removed the shared global mock of these classes from the unit suite.
 *
 * Calls to a \DB-only method on a DatabaseInterface receiver need no rule here.
 * PHPStan reports them as "Call to an undefined method" (identifier
 * `method.notFound`).
 *
 * @implements Rule<FileNode>
 */
final class DatabaseInterfaceUsageRule implements Rule
{
    private const PRODUCTION_PREFIXES = ['app/', 'usersc/'];
    private const ADAPTER_PATH = 'usersc/classes/Database/DbAdapter.php';
    private const BANNED_GLOBAL_CLASSES = ['DB', 'QueryResult'];

    private string $projectRoot;

    public function __construct(string $projectRoot)
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
        $finder = new NodeFinder();
        $stmts = $node->getNodes();
        $errors = [];

        foreach ($finder->findInstanceOf($stmts, Class_::class) as $class) {
            if ($class->name === null) {
                continue;
            }

            $className = isset($class->namespacedName) ? $class->namespacedName->toString() : $class->name->toString();
            if (in_array($className, self::BANNED_GLOBAL_CLASSES, true)) {
                $errors[] = RuleErrorBuilder::message(sprintf(
                    'Do not declare a global %s class. Build a small ElanRegistry\DatabaseInterface test double (#1585).',
                    $className
                ))
                    ->identifier('elanRegistry.globalDbDouble')
                    ->line($class->getStartLine())
                    ->build();
            }
        }

        if (!$this->isProductionFile($scope->getFile())) {
            return $errors;
        }

        $typedNodes = $finder->find(
            $stmts,
            static fn (Node $inner): bool => ($inner instanceof Param || $inner instanceof Property) && $inner->type !== null
        );

        foreach ($typedNodes as $typed) {
            /** @var Param|Property $typed */
            if ($this->typeNamesConcreteDb($finder, $typed->type)) {
                $errors[] = RuleErrorBuilder::message(
                    'Do not type a parameter or property as the concrete \DB class. Use ElanRegistry\DatabaseInterface (#1585).'
                )
                    ->identifier('elanRegistry.concreteDbType')
                    ->line($typed->getStartLine())
                    ->build();
            }
        }

        return $errors;
    }

    private function isProductionFile(string $file): bool
    {
        if (!str_starts_with($file, $this->projectRoot)) {
            return false;
        }

        $relative = substr($file, strlen($this->projectRoot));
        if ($relative === self::ADAPTER_PATH) {
            return false;
        }

        foreach (self::PRODUCTION_PREFIXES as $prefix) {
            if (str_starts_with($relative, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function typeNamesConcreteDb(NodeFinder $finder, Node $type): bool
    {
        foreach ($finder->findInstanceOf([$type], Name::class) as $name) {
            $original = $name->getAttribute('originalName');
            $written = $original instanceof Name ? $original : $name;
            if ($written->toString() === 'DB') {
                return true;
            }
        }

        return false;
    }
}
