<?php

declare(strict_types=1);

namespace Preflow\Twig;

use Twig\Attribute\YieldReady;
use Twig\Compiler;
use Twig\Node\Expression\AbstractExpression;
use Twig\Node\Node;
use Twig\Node\NodeOutputInterface;

/** A {% freshness %} block (FreshnessTokenParser): its body, through FreshnessExtension::within(). */
#[YieldReady]
final class FreshnessNode extends Node implements NodeOutputInterface
{
    public function __construct(AbstractExpression $name, ?AbstractExpression $every, Node $body, int $lineno)
    {
        $nodes = ['name' => $name, 'body' => $body];
        if ($every !== null) {
            $nodes['every'] = $every;
        }
        parent::__construct($nodes, [], $lineno);
    }

    public function compile(Compiler $compiler): void
    {
        $compiler
            ->addDebugInfo($this)
            ->write('yield $this->env->getExtension(' . var_export(FreshnessExtension::class, true) . ')->within($this, ')
            ->subcompile($this->getNode('name'))
            ->raw(', ');
        if ($this->hasNode('every')) {
            $compiler->subcompile($this->getNode('every'));
        } else {
            $compiler->raw('null');
        }
        // The body as a generator, as Twig's own capture compiles it: {% set %} inside still sets.
        $compiler
            ->raw(", function () use (&\$context, \$macros, \$blocks) {\n")
            ->indent()
            ->subcompile($this->getNode('body'))
            ->write("yield from [];\n")
            ->outdent()
            ->write("});\n");
    }
}
