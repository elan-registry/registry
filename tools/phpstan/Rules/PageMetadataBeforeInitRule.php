<?php

declare(strict_types=1);

namespace ElanRegistry\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\AssignOp\Coalesce;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Include_;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\NodeFinder;
use PHPStan\Analyser\Scope;
use PHPStan\Node\FileNode;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * A page must assign $pageTitle and $pageDescription before it requires
 * users/init.php (#1432, #1484). The loader reads isset($pageTitle) once, at
 * init time. A later assignment has no effect and the page shows the site-wide
 * defaults.
 *
 * A page is a file under app/, docs/, or error/ (or a listed root/usersc file)
 * that calls securePage(). Action handlers, scripts, and API endpoints are not
 * pages. A page in the exemption list may omit $pageTitle. When an exempt page
 * gets $pageTitle, the rule reports it so that the list does not go stale.
 *
 * @implements Rule<FileNode>
 */
final class PageMetadataBeforeInitRule implements Rule
{
    private const PAGE_DIRECTORIES = ['app/', 'docs/', 'error/'];
    private const PAGE_FILES = [
        'index.php',
        'usersc/account.php',
        'usersc/join.php',
        'usersc/login.php',
        'usersc/user_settings.php',
    ];
    private const NON_PAGE_DIRECTORIES = ['app/admin/includes/', 'app/admin/scripts/', 'app/api/'];
    private const METADATA_VARIABLES = ['pageTitle', 'pageDescription'];

    private string $projectRoot;

    /**
     * @param string $projectRoot Absolute path that $exemptPages entries are relative to
     * @param list<string> $exemptPages Pages that do not set $pageTitle yet
     */
    public function __construct(string $projectRoot, private array $exemptPages)
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
        $relative = $this->pageCandidatePath($scope->getFile());
        if ($relative === null) {
            return [];
        }

        $finder = new NodeFinder();
        $stmts = $node->getNodes();

        $callsSecurePage = $finder->findFirst(
            $stmts,
            static fn (Node $inner): bool => $inner instanceof FuncCall
                && $inner->name instanceof Name
                && $inner->name->getLast() === 'securePage'
        ) !== null;
        if (!$callsSecurePage) {
            return [];
        }

        $assignLines = [];
        foreach (self::METADATA_VARIABLES as $variable) {
            $assignLines[$variable] = $this->firstAssignmentLine($finder, $stmts, $variable);
        }

        if (in_array($relative, $this->exemptPages, true)) {
            if ($assignLines['pageTitle'] === null) {
                return [];
            }

            return [
                RuleErrorBuilder::message(
                    "This page now sets \$pageTitle. Remove '$relative' from the PageMetadataBeforeInitRule exemption list in phpstan.neon."
                )
                    ->identifier('elanRegistry.pageMetadataStaleExemption')
                    ->line($assignLines['pageTitle'])
                    ->build(),
            ];
        }

        $initLine = $this->initRequireLine($finder, $stmts);
        $errors = [];

        foreach ($assignLines as $variable => $line) {
            if ($line === null) {
                $errors[] = RuleErrorBuilder::message(sprintf(
                    'This page calls securePage() but does not set $%s. Set $pageTitle and $pageDescription before '
                    . "require_once 'users/init.php' (elanregistry_overrides section 6).",
                    $variable
                ))
                    ->identifier('elanRegistry.pageMetadataMissing')
                    ->line(1)
                    ->build();
                continue;
            }

            if ($initLine === null) {
                $errors[] = RuleErrorBuilder::message(sprintf(
                    'This page sets $%s but has no require_once of users/init.php, so the order cannot be checked.',
                    $variable
                ))
                    ->identifier('elanRegistry.pageMetadataNoInit')
                    ->line($line)
                    ->build();
                continue;
            }

            if ($line >= $initLine) {
                $errors[] = RuleErrorBuilder::message(sprintf(
                    'Assign $%s before the require_once of users/init.php (line %d). '
                    . 'The loader reads it at init time, so a later assignment has no effect.',
                    $variable,
                    $initLine
                ))
                    ->identifier('elanRegistry.pageMetadataAfterInit')
                    ->line($line)
                    ->build();
            }
        }

        return $errors;
    }

    private function pageCandidatePath(string $file): ?string
    {
        if (!str_starts_with($file, $this->projectRoot)) {
            return null;
        }

        $relative = substr($file, strlen($this->projectRoot));

        foreach (self::NON_PAGE_DIRECTORIES as $prefix) {
            if (str_starts_with($relative, $prefix)) {
                return null;
            }
        }

        if (in_array($relative, self::PAGE_FILES, true)) {
            return $relative;
        }

        foreach (self::PAGE_DIRECTORIES as $prefix) {
            if (str_starts_with($relative, $prefix)) {
                return $relative;
            }
        }

        return null;
    }

    /**
     * @param array<Node> $stmts
     */
    private function firstAssignmentLine(NodeFinder $finder, array $stmts, string $variable): ?int
    {
        $assign = $finder->findFirst(
            $stmts,
            static fn (Node $inner): bool => ($inner instanceof Assign || $inner instanceof Coalesce)
                && $inner->var instanceof Variable
                && $inner->var->name === $variable
        );

        return $assign?->getStartLine();
    }

    /**
     * @param array<Node> $stmts
     */
    private function initRequireLine(NodeFinder $finder, array $stmts): ?int
    {
        $include = $finder->findFirst(
            $stmts,
            static fn (Node $inner): bool => $inner instanceof Include_
                && $inner->type === Include_::TYPE_REQUIRE_ONCE
                && $finder->findFirst(
                    [$inner->expr],
                    static fn (Node $part): bool => $part instanceof String_ && str_ends_with($part->value, 'init.php')
                ) !== null
        );

        return $include?->getStartLine();
    }
}
