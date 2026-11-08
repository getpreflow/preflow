<?php

declare(strict_types=1);

namespace Preflow\Twig;

use Twig\Environment;
use Twig\Error\SyntaxError;
use Twig\Node\BlockNode;
use Twig\Node\Node;
use Twig\NodeVisitor\NodeVisitorInterface;

/**
 * The block name prefix __live_ is reserved for {% live %} (LiveTokenParser): a template's own
 * {% block __live_… %} is a syntax error, so no block of a template is ever taken for a live part.
 */
final class LiveBlockNames implements NodeVisitorInterface
{
    /** The attribute LiveTokenParser sets on the blocks it declares. */
    public const string ATTRIBUTE = 'preflow_live';

    public function enterNode(Node $node, Environment $env): Node
    {
        if ($node instanceof BlockNode && str_starts_with((string) $node->getAttribute('name'), '__live_')
            && !($node->hasAttribute(self::ATTRIBUTE) && $node->getAttribute(self::ATTRIBUTE) === true)) {
            throw new SyntaxError(sprintf("The block name '%s' is reserved for {%% live %%} blocks (names starting with __live_).", $node->getAttribute('name')), $node->getTemplateLine(), $node->getSourceContext());
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
