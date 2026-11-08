<?php

declare(strict_types=1);

namespace Preflow\Twig;

use Twig\Attribute\YieldReady;
use Twig\Compiler;
use Twig\Node\Expression\AbstractExpression;
use Twig\Node\Node;
use Twig\Node\NodeOutputInterface;

/** A {% live %} tag (LiveTokenParser): its block, through LiveExtension::live(). */
#[YieldReady]
final class LiveNode extends Node implements NodeOutputInterface
{
    public function __construct(string $block, ?AbstractExpression $with, int $lineno)
    {
        parent::__construct($with !== null ? ['with' => $with] : [], ['block' => $block], $lineno);
    }

    public function compile(Compiler $compiler): void
    {
        $compiler
            ->addDebugInfo($this)
            ->write('yield $this->env->getExtension(' . var_export(LiveExtension::class, true) . ')->live($this->env, $this, ')
            ->string($this->getAttribute('block'))
            ->raw(", \$context['componentClass'] ?? null, ");
        if ($this->hasNode('with')) {
            $compiler->subcompile($this->getNode('with'));
        } else {
            $compiler->raw('[]');
        }
        $compiler->raw(");\n");
    }
}
