<?php

declare(strict_types=1);

namespace Preflow\Twig;

use Twig\Attribute\YieldReady;
use Twig\Compiler;
use Twig\Node\Expression\AbstractExpression;
use Twig\Node\Node;
use Twig\Node\NodeOutputInterface;

/**
 * A {% region %} tag (RegionTokenParser): the region element (RegionExtension::open()), its block
 * rendered with the context the template was rendered with (RegionExtension::CONTEXT, the
 * current one where none was kept), and the closing tag.
 *
 * @since 0.86.0
 */
#[YieldReady]
final class RegionNode extends Node implements NodeOutputInterface
{
    public function __construct(string $name, string $block, ?AbstractExpression $attributes, int $lineno)
    {
        parent::__construct($attributes !== null ? ['attributes' => $attributes] : [], ['name' => $name, 'block' => $block], $lineno);
    }

    public function compile(Compiler $compiler): void
    {
        $compiler
            ->addDebugInfo($this)
            ->write('$__preflow_region_context = $context[')
            ->string(RegionExtension::CONTEXT)
            ->raw("] ?? \$context;\n")
            ->write('yield $this->env->getExtension(' . var_export(RegionExtension::class, true) . ')->open($this, $__preflow_region_context, ')
            ->string($this->getAttribute('name'))
            ->raw(', ');
        if ($this->hasNode('attributes')) {
            $compiler->subcompile($this->getNode('attributes'));
        } else {
            $compiler->raw('[]');
        }
        $compiler
            ->raw(");\n")
            ->write('yield from $this->unwrap()->yieldBlock(')
            ->string($this->getAttribute('block'))
            ->raw(", \$__preflow_region_context, \$blocks);\n")
            ->write("yield \\Preflow\\View\\Regions::close();\n");
    }
}
