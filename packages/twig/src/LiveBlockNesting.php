<?php

declare(strict_types=1);

namespace Preflow\Twig;

use Twig\Environment;
use Twig\Error\SyntaxError;
use Twig\Node\BlockNode;
use Twig\Node\BlockReferenceNode;
use Twig\Node\ModuleNode;
use Twig\Node\Node;
use Twig\NodeVisitor\NodeVisitorInterface;

/**
 * {% live %} blocks don't nest: a live block of a template that holds another, directly or
 * through a {% block %} inside it, is a syntax error at the inner tag. A template embedded inside
 * a live block ({% embed %}) is a template of its own, checked on its own.
 *
 * @internal registered by LiveExtension
 * @since 0.81.1
 */
final class LiveBlockNesting implements NodeVisitorInterface
{
    public function enterNode(Node $node, Environment $env): Node
    {
        if (!$node instanceof ModuleNode) {
            return $node;
        }
        $blocks = [];
        foreach ($node->getNode('blocks') as $name => $body) {
            foreach ($body as $block) {
                if ($block instanceof BlockNode) {
                    $blocks[(string) $name] = $block;
                }
            }
        }
        foreach ($blocks as $name => $block) {
            if (!($block->hasAttribute(LiveBlockNames::ATTRIBUTE) && $block->getAttribute(LiveBlockNames::ATTRIBUTE) === true)) {
                continue;
            }
            $seen = [$name => true];
            $inner = self::liveIn($block->getNode('body'), $blocks, $seen);
            if ($inner !== null) {
                throw new SyntaxError("{% live %} blocks can't be nested.", $inner->getTemplateLine(), $node->getSourceContext());
            }
        }

        return $node;
    }

    public function leaveNode(Node $node, Environment $env): ?Node
    {
        return $node;
    }

    public function getPriority(): int
    {
        return 0;
    }

    /**
     * The first live tag in $node (or $node itself), following the blocks it references.
     *
     * @param array<string, BlockNode> $blocks the template's blocks by name
     * @param array<string, true>      $seen   the blocks already walked
     */
    private static function liveIn(Node $node, array $blocks, array &$seen): ?LiveNode
    {
        if ($node instanceof LiveNode) {
            return $node;
        }
        if ($node instanceof BlockReferenceNode) {
            $name = (string) $node->getAttribute('name');
            if (isset($seen[$name]) || !isset($blocks[$name])) {
                return null;
            }
            $seen[$name] = true;

            return self::liveIn($blocks[$name]->getNode('body'), $blocks, $seen);
        }
        foreach ($node as $child) {
            $found = self::liveIn($child, $blocks, $seen);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }
}
