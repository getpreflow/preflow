<?php

declare(strict_types=1);

namespace Preflow\Twig;

use Twig\Environment;
use Twig\Node\BlockNode;
use Twig\Node\BodyNode;
use Twig\Node\ModuleNode;
use Twig\Node\Node;
use Twig\Node\Nodes;
use Twig\NodeVisitor\NodeVisitorInterface;

/**
 * A {% live %} block inside an {% embed %} is a block of the embedded template, which Twig
 * compiles as a class of its own, named by an index it draws at random for every compilation.
 * A stored page's recipe can't name that index (the next compilation has another one), so it
 * names the file and the block, as for any live block, and the template answers for the live
 * blocks of its embeds: for each one it gets a block of the same name that renders the embedded
 * template's block (LiveEmbeddedBlockNode). Live block names are unique across a file and its
 * embeds (LiveTokenParser), and the __live_ prefix is reserved (LiveBlockNames), so such a block
 * never stands in for another. An embed inside an embed answers for its own embeds first, so
 * the chain reaches every depth.
 *
 * @internal registered by LiveExtension
 * @since 0.82.0
 */
final class LiveEmbeddedBlocks implements NodeVisitorInterface
{
    public function enterNode(Node $node, Environment $env): Node
    {
        return $node;
    }

    public function leaveNode(Node $node, Environment $env): ?Node
    {
        if (!$node instanceof ModuleNode) {
            return $node;
        }
        $blocks = [];
        foreach ($node->getNode('blocks') as $name => $body) {
            $blocks[(string) $name] = $body;
        }
        $added = false;
        foreach ($node->getAttribute('embedded_templates') as $embedded) {
            $index = $embedded instanceof ModuleNode ? $embedded->getAttribute('index') : null;
            if (!is_int($index)) {
                continue;
            }
            foreach ($embedded->getNode('blocks') as $name => $body) {
                $name = (string) $name;
                if (isset($blocks[$name]) || !self::live($body)) {
                    continue;
                }
                $line = $body->getTemplateLine();
                $block = new BlockNode($name, new LiveEmbeddedBlockNode($name, $index, $line), $line);
                $block->setAttribute(LiveBlockNames::ATTRIBUTE, true);
                $block->setSourceContext($node->getSourceContext());
                $blocks[$name] = new BodyNode([$block], [], $line);
                $added = true;
            }
        }
        if ($added) {
            $node->setNode('blocks', new Nodes($blocks));
        }

        return $node;
    }

    /** Whether $body (an entry of a module's blocks) is a block {% live %} declared, or one standing in for it. */
    private static function live(Node $body): bool
    {
        foreach ($body as $block) {
            if ($block instanceof BlockNode && $block->hasAttribute(LiveBlockNames::ATTRIBUTE) && $block->getAttribute(LiveBlockNames::ATTRIBUTE) === true) {
                return true;
            }
        }

        return false;
    }

    public function getPriority(): int
    {
        return 0;
    }
}
