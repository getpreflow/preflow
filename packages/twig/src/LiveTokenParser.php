<?php

declare(strict_types=1);

namespace Preflow\Twig;

use Twig\Node\BlockNode;
use Twig\Node\Node;
use Twig\Token;
use Twig\TokenParser\AbstractTokenParser;
use Twig\TokenStream;

/**
 * {% live with {number: object.number} %}…{% endlive %}: a live part of a cached page.
 *
 * The body is compiled to a block of the template (named __live_{line}), and the tag to a
 * LiveNode that renders that block with only the `with` values (scalars and arrays) and the
 * globals, never the variables around it. While a page renders for the page cache it prints the
 * signed placeholder of template, block and values instead (LiveExtension), and the block is
 * rendered for every visitor when the page is served. `with` may be left out ({% live %}).
 * Live blocks don't nest: each live block carries the name of the live tag open around it
 * (PARENT_ATTRIBUTE), and LiveBlockNesting refuses one whose parent is a live block of the same
 * template once it is parsed. The block name prefix __live_ is theirs (LiveBlockNames). The second live block
 * on a line is named __live_{line}_2, the third __live_{line}_3, and so on. Since 0.81.1 this
 * uses no Parser method deprecated in Twig 3.12 (getBlockStack(), hasBlock()).
 */
final class LiveTokenParser extends AbstractTokenParser
{
    /**
     * The attribute on a live block naming the live block open around its tag (null: none).
     *
     * @internal LiveBlockNesting reads it
     * @since 0.81.1
     */
    public const string PARENT_ATTRIBUTE = 'preflow_live_parent';

    /** @var \WeakMap<TokenStream, array<string, true>> the block names given out per compiled source (its embedded templates included) */
    private \WeakMap $names;

    /** @var \WeakMap<TokenStream, list<string>> the live tags open per compiled source, innermost last */
    private \WeakMap $open;

    public function __construct()
    {
        $this->names = new \WeakMap();
        $this->open = new \WeakMap();
    }

    public function parse(Token $token): Node
    {
        $lineno = $token->getLine();
        $stream = $this->parser->getStream();

        $with = null;
        if ($stream->nextIf(Token::NAME_TYPE, 'with')) {
            $with = $this->parser->parseExpression();
        }
        $stream->expect(Token::BLOCK_END_TYPE);

        $name = $this->name($stream, $lineno);
        $block = new BlockNode($name, new \Twig\Node\EmptyNode(), $lineno);
        $block->setAttribute(LiveBlockNames::ATTRIBUTE, true); // a template's own __live_ block is an error
        $open = $this->open[$stream] ?? [];
        $block->setAttribute(self::PARENT_ATTRIBUTE, $open === [] ? null : $open[array_key_last($open)]);
        $this->parser->setBlock($name, $block);
        $this->parser->pushLocalScope();
        $this->parser->pushBlockStack($name);
        $this->open[$stream] = [...$open, $name];
        try {
            $body = $this->parser->subparse([$this, 'decideLiveEnd'], true);
        } finally {
            $this->open[$stream] = $open;
        }
        $stream->expect(Token::BLOCK_END_TYPE);
        $block->setNode('body', $body);
        $this->parser->popBlockStack();
        $this->parser->popLocalScope();

        return new LiveNode($name, $with, $lineno);
    }

    /**
     * The next free name for a live block on $line of the source $stream compiles. A template's own
     * block never takes a name starting with __live_ (LiveBlockNames), so only live blocks compete.
     */
    private function name(TokenStream $stream, int $line): string
    {
        $taken = $this->names[$stream] ?? [];
        $name = '__live_' . $line;
        for ($n = 2; isset($taken[$name]); $n++) {
            $name = '__live_' . $line . '_' . $n;
        }
        $taken[$name] = true;
        $this->names[$stream] = $taken;

        return $name;
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
