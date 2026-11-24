<?php

declare(strict_types=1);

namespace Preflow\Twig;

use Twig\Environment;
use Twig\Error\SyntaxError;
use Twig\Node\BlockNode;
use Twig\Node\ModuleNode;
use Twig\Node\Node;
use Twig\NodeVisitor\NodeVisitorInterface;

/**
 * The block name prefix __region_ is reserved for {% region %} (RegionTokenParser): a template's
 * own {% block __region_… %} is a syntax error. A template with regions keeps the context it was
 * rendered with (RegionContextNode). A region inside an {% embed %} is refused: it would belong to
 * the embedded template, which can't be rendered alone.
 *
 * @internal registered by RegionExtension
 * @since 0.86.0
 */
final class RegionBlocks implements NodeVisitorInterface
{
    /** The attribute RegionTokenParser sets on the blocks it declares: the region's name. */
    public const string ATTRIBUTE = 'preflow_region';

    public function enterNode(Node $node, Environment $env): Node
    {
        if ($node instanceof BlockNode && str_starts_with((string) $node->getAttribute('name'), RegionExtension::BLOCK_PREFIX)
            && !$node->hasAttribute(self::ATTRIBUTE)) {
            throw new SyntaxError(sprintf("The block name '%s' is reserved for {%% region %%} blocks (names starting with %s).", $node->getAttribute('name'), RegionExtension::BLOCK_PREFIX), $node->getTemplateLine(), $node->getSourceContext());
        }

        return $node;
    }

    public function leaveNode(Node $node, Environment $env): Node
    {
        if (!$node instanceof ModuleNode) {
            return $node;
        }
        foreach ($node->getAttribute('embedded_templates') as $embedded) {
            if ($embedded instanceof ModuleNode && ($region = self::firstRegion($embedded)) !== null) {
                throw new SyntaxError(sprintf("The region '%s' sits inside {%% embed %%}: a region belongs to the template itself, so it can be rendered alone.", $region->getAttribute(self::ATTRIBUTE)), $region->getTemplateLine(), $node->getSourceContext());
            }
        }
        if (self::firstRegion($node) !== null) {
            $node->getNode('display_start')->setNode('preflow_region_context', new RegionContextNode());
        }

        return $node;
    }

    /** The first region block of $module, null without one. */
    private static function firstRegion(ModuleNode $module): ?BlockNode
    {
        foreach ($module->getNode('blocks') as $body) {
            foreach ($body as $block) {
                if ($block instanceof BlockNode && $block->hasAttribute(self::ATTRIBUTE)) {
                    return $block;
                }
            }
        }

        return null;
    }

    public function getPriority(): int
    {
        return 0;
    }
}
