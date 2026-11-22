<?php

declare(strict_types=1);

namespace Preflow\Twig;

use Twig\Attribute\YieldReady;
use Twig\Compiler;
use Twig\Node\Node;

/**
 * The body of a live block a template answers for on behalf of one of its {% embed %}s
 * (LiveEmbeddedBlocks): the block of that embedded template, rendered with the same context.
 *
 * @internal compiled by LiveEmbeddedBlocks
 * @since 0.82.0
 */
#[YieldReady]
final class LiveEmbeddedBlockNode extends Node
{
    public function __construct(string $block, int $index, int $lineno)
    {
        parent::__construct([], ['block' => $block, 'index' => $index], $lineno);
    }

    public function compile(Compiler $compiler): void
    {
        // As EmbedNode loads it: the same compiled file, so the index is this compilation's.
        $compiler
            ->addDebugInfo($this)
            ->write('yield from $this->load($this->getTemplateName(), ')
            ->repr($this->getTemplateLine())
            ->raw(', ')
            ->repr($this->getAttribute('index'))
            ->raw(')->yieldBlock(')
            ->string($this->getAttribute('block'))
            ->raw(", \$context);\n");
    }
}
