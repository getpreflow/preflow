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
 * {% live %} blocks don't nest: a live block whose tag was open inside another live block of the
 * same template (LiveTokenParser::PARENT_ATTRIBUTE), directly, through a {% block %} or a
 * {% macro %} inside it, is a syntax error at the inner tag. A template embedded inside a live
 * block ({% embed %}) is a template of its own: its live blocks name a parent it does not have.
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
        foreach ($blocks as $block) {
            $parent = $block->hasAttribute(LiveTokenParser::PARENT_ATTRIBUTE) ? $block->getAttribute(LiveTokenParser::PARENT_ATTRIBUTE) : null;
            if (is_string($parent) && isset($blocks[$parent])) {
                throw new SyntaxError("{% live %} blocks can't be nested.", $block->getTemplateLine(), $node->getSourceContext());
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
}
