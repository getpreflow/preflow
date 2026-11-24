<?php

declare(strict_types=1);

namespace Preflow\Twig;

use Twig\Attribute\YieldReady;
use Twig\Compiler;
use Twig\Node\Node;

/**
 * At the start of a template with regions: keeps the context it was rendered with under
 * RegionExtension::CONTEXT, for its regions (RegionNode), before any {% set %} changes it.
 *
 * @internal added by RegionBlocks
 * @since 0.86.0
 */
#[YieldReady]
final class RegionContextNode extends Node
{
    public function __construct()
    {
        parent::__construct([], [], 0);
    }

    public function compile(Compiler $compiler): void
    {
        $key = var_export(RegionExtension::CONTEXT, true);
        $compiler
            ->write("unset(\$context[{$key}]);\n")
            ->write("\$context[{$key}] = \$context;\n");
    }
}
