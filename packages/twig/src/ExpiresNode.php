<?php

declare(strict_types=1);

namespace Preflow\Twig;

use Twig\Attribute\YieldReady;
use Twig\Compiler;
use Twig\Node\Expression\AbstractExpression;
use Twig\Node\Node;

/** {% expires at|in … %} (ExpiresTokenParser), through FreshnessExtension::expires(). */
#[YieldReady]
final class ExpiresNode extends Node
{
    public function __construct(?AbstractExpression $at, ?AbstractExpression $in, int $lineno)
    {
        $nodes = [];
        if ($at !== null) {
            $nodes['at'] = $at;
        }
        if ($in !== null) {
            $nodes['in'] = $in;
        }
        parent::__construct($nodes, [], $lineno);
    }

    public function compile(Compiler $compiler): void
    {
        $compiler
            ->addDebugInfo($this)
            ->write('$this->env->getExtension(' . var_export(FreshnessExtension::class, true) . ')->expires($this, ');
        $this->hasNode('at') ? $compiler->subcompile($this->getNode('at')) : $compiler->raw('null');
        $compiler->raw(', ');
        $this->hasNode('in') ? $compiler->subcompile($this->getNode('in')) : $compiler->raw('null');
        $compiler->raw(");\n");
    }
}
