<?php

declare(strict_types=1);

namespace Preflow\Twig;

use Twig\Node\BlockNode;
use Twig\Node\Node;
use Twig\Token;
use Twig\TokenParser\AbstractTokenParser;

/**
 * {% live with {number: object.number} %}…{% endlive %}: a live part of a cached page.
 *
 * The body is compiled to a block of the template (named __live_{line}), and the tag to a
 * LiveNode that renders that block with only the `with` values (scalars and arrays) and the
 * globals, never the variables around it. While a page renders for the page cache it prints the
 * signed placeholder of template, block and values instead (LiveExtension), and the block is
 * rendered for every visitor when the page is served. `with` may be left out ({% live %}).
 * Live blocks don't nest, and the block name prefix __live_ is theirs (LiveBlockNames).
 */
final class LiveTokenParser extends AbstractTokenParser
{
    public function parse(Token $token): Node
    {
        $lineno = $token->getLine();
        $stream = $this->parser->getStream();

        foreach ($this->parser->getBlockStack() as $open) {
            if (str_starts_with((string) $open, '__live_')) {
                throw new \Twig\Error\SyntaxError("{% live %} blocks can't be nested.", $lineno, $stream->getSourceContext());
            }
        }

        $with = null;
        if ($stream->nextIf(Token::NAME_TYPE, 'with')) {
            $with = $this->parser->parseExpression();
        }
        $stream->expect(Token::BLOCK_END_TYPE);

        $name = '__live_' . $lineno;
        for ($n = 2; $this->parser->hasBlock($name); $n++) {
            $name = '__live_' . $lineno . '_' . $n;
        }
        $block = new BlockNode($name, new \Twig\Node\EmptyNode(), $lineno);
        $block->setAttribute(LiveBlockNames::ATTRIBUTE, true); // a template's own __live_ block is an error
        $this->parser->setBlock($name, $block);
        $this->parser->pushLocalScope();
        $this->parser->pushBlockStack($name);
        $body = $this->parser->subparse([$this, 'decideLiveEnd'], true);
        $stream->expect(Token::BLOCK_END_TYPE);
        $block->setNode('body', $body);
        $this->parser->popBlockStack();
        $this->parser->popLocalScope();

        return new LiveNode($name, $with, $lineno);
    }

    public function decideLiveEnd(Token $token): bool
    {
        return $token->test('endlive');
    }

    public function getTag(): string
    {
        return 'live';
    }
}
