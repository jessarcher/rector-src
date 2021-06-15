<?php

declare(strict_types=1);

namespace Rector\DeadCode\Rector\ConstFetch;

use PhpParser\Node;
use PhpParser\Node\Expr\BinaryOp;
use PhpParser\Node\Expr\BinaryOp\GreaterOrEqual;
use PhpParser\Node\Expr\BinaryOp\Smaller;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Scalar\LNumber;
use PhpParser\Node\Stmt\If_;
use Rector\Core\Contract\Rector\ConfigurableRectorInterface;
use Rector\Core\Rector\AbstractRector;
use Rector\Core\Util\PhpVersionFactory;
use Rector\Core\ValueObject\PhpVersion;
use Rector\NodeTypeResolver\Node\AttributeKey;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\ConfiguredCodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

/**
 * @see \Rector\Tests\DeadCode\Rector\ConstFetch\RemovePhpVersionIdCheckRector\RemovePhpVersionIdCheckRectorTest
 */
final class RemovePhpVersionIdCheckRector extends AbstractRector implements ConfigurableRectorInterface
{
    /**
     * @var string
     */
    public const PHP_VERSION_CONSTRAINT = 'phpVersionConstraint';

    private string | int | null $phpVersionConstraint;

    public function __construct(
        private PhpVersionFactory $phpVersionFactory
    ) {
    }

    /**
     * @param array<string, int|string> $configuration
     */
    public function configure(array $configuration): void
    {
        $this->phpVersionConstraint = $configuration[self::PHP_VERSION_CONSTRAINT] ?? null;
    }

    public function getRuleDefinition(): RuleDefinition
    {
        $exampleConfiguration = [
            self::PHP_VERSION_CONSTRAINT => PhpVersion::PHP_80,
        ];
        return new RuleDefinition(
            'Remove unneded PHP_VERSION_ID check',
            [
                new ConfiguredCodeSample(
                    <<<'CODE_SAMPLE'
class SomeClass
{
    public function run()
    {
        if (PHP_VERSION_ID < 80000) {
            return;
        }
        echo 'do something';
    }
}
CODE_SAMPLE
,
                    <<<'CODE_SAMPLE'
class SomeClass
{
    public function run()
    {
        echo 'do something';
    }
}
CODE_SAMPLE
,
                    $exampleConfiguration
                ),
            ],
        );
    }

    /**
     * @return array<class-string<Node>>
     */
    public function getNodeTypes(): array
    {
        return [ConstFetch::class];
    }

    /**
     * @param ConstFetch $node
     */
    public function refactor(Node $node): ?Node
    {
        if (! $this->isName($node, 'PHP_VERSION_ID')) {
            return null;
        }

        /**
         * $this->phpVersionProvider->provide() fallback is here as $currentFileProvider must be accessed after initialization
         */
        $phpVersionConstraint = $this->phpVersionConstraint ?? $this->phpVersionProvider->provide();

        // ensure cast to (string) first to allow string like "8.0" value to be converted to the int value
        $this->phpVersionConstraint = $this->phpVersionFactory->createIntVersion((string) $phpVersionConstraint);

        $if = $this->betterNodeFinder->findParentType($node, If_::class);
        $parent = $node->getAttribute(AttributeKey::PARENT_NODE);
        if ($this->shouldSkip($node, $if, $parent)) {
            return null;
        }

        /** @var If_ $if */
        if ($parent instanceof Smaller && $parent->left === $node) {
            return $this->processSmallerLeft($node, $parent, $if);
        }

        if ($parent instanceof Smaller && $parent->right === $node) {
            return $this->processSmallerRight($node, $parent, $if);
        }

        if ($parent instanceof GreaterOrEqual && $parent->left === $node) {
            return $this->processGreaterOrEqualLeft($node, $parent, $if);
        }

        if (! $parent instanceof GreaterOrEqual) {
            return null;
        }

        return $this->processGreaterOrEqualRight($node, $parent, $if);
    }

    private function shouldSkip(ConstFetch $constFetch, ?If_ $if, ?Node $parent): bool
    {
        $if = $this->betterNodeFinder->findParentType($constFetch, If_::class);
        if (! $if instanceof If_) {
            return true;
        }

        $parent = $constFetch->getAttribute(AttributeKey::PARENT_NODE);
        if (! $parent instanceof BinaryOp) {
            return true;
        }

        return $if->cond !== $parent;
    }

    private function processSmallerLeft(ConstFetch $constFetch, Smaller $smaller, If_ $if): ?ConstFetch
    {
        $value = $smaller->right;
        if (! $value instanceof LNumber) {
            return null;
        }

        if ($this->phpVersionConstraint <= $value->value) {
            $this->removeNode($if);
        }

        return $constFetch;
    }

    private function processSmallerRight(ConstFetch $constFetch, Smaller $smaller, If_ $if): ?ConstFetch
    {
        $value = $smaller->left;
        if (! $value instanceof LNumber) {
            return null;
        }

        if ($this->phpVersionConstraint <= $value->value) {
            $this->addNodesBeforeNode($if->stmts, $if);
            $this->removeNode($if);
        }

        return $constFetch;
    }

    private function processGreaterOrEqualLeft(ConstFetch $constFetch, GreaterOrEqual $greaterOrEqual, If_ $if): ?ConstFetch
    {
        $value = $greaterOrEqual->right;
        if (! $value instanceof LNumber) {
            return null;
        }

        if ($this->phpVersionConstraint <= $value->value) {
            $this->addNodesBeforeNode($if->stmts, $if);
            $this->removeNode($if);
        }

        return $constFetch;
    }

    private function processGreaterOrEqualRight(ConstFetch $constFetch, GreaterOrEqual $greaterOrEqual, If_ $if): ?ConstFetch
    {
        if ($greaterOrEqual->right !== $constFetch) {
            return null;
        }

        $value = $greaterOrEqual->left;
        if (! $value instanceof LNumber) {
            return null;
        }

        if ($this->phpVersionConstraint <= $value->value) {
            $this->removeNode($if);
        }

        return $constFetch;
    }
}
