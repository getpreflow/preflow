<?php

declare(strict_types=1);

namespace Preflow\Twig;

use Preflow\View\Regions;
use Twig\Error\SyntaxError;
use Twig\Node\BlockNode;
use Twig\Node\Node;
use Twig\Token;
use Twig\TokenParser\AbstractTokenParser;
use Twig\TokenStream;

/**
 * {% region 'results' {class: 'job-search__results'} %}…{% endregion %}: a part of a component's
 * template that renders in place and can be rendered alone (TwigEngine::renderRegion()).
 *
 * The body is compiled to a block of the template named __region_{hex of the name}
 * (RegionExtension::blockName()), and the tag to a RegionNode that prints the region element and
 * renders that block with the context the template was rendered with, never variables set before
 * it (RegionExtension::CONTEXT). The name is a quoted string (Regions::NAME_PATTERN), unique in its
 * template; the attributes, a mapping, may be left out. Regions don't nest, and the block name
 * prefix __region_ is theirs (RegionBlocks).
 *
 * @since 0.86.0
 */
final class RegionTokenParser extends AbstractTokenParser
{
    /** @var \WeakMap<TokenStream, array<string, true>> the region names per compiled source */
    private \WeakMap $names;

    /** @var \WeakMap<TokenStream, string> the region open per compiled source */
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
        $source = $stream->getSourceContext();

        if (!$stream->test(Token::STRING_TYPE)) {
            throw new SyntaxError("{% region %} takes its name as a quoted string: {% region 'results' %}.", $lineno, $source);
        }
        $name = (string) $stream->next()->getValue();
        if (!Regions::validName($name)) {
            throw new SyntaxError(sprintf("'%s' is no region name (a letter, then letters, digits, - and _).", $name), $lineno, $source);
        }
        $attributes = $stream->test(Token::BLOCK_END_TYPE) ? null : $this->parser->parseExpression();
        $stream->expect(Token::BLOCK_END_TYPE);

        if (isset($this->open[$stream])) {
            throw new SyntaxError(sprintf("The region '%s' sits inside the region '%s': regions don't nest.", $name, $this->open[$stream]), $lineno, $source);
        }
        $taken = $this->names[$stream] ?? [];
        if (isset($taken[$name])) {
            throw new SyntaxError(Regions::duplicate($source->getName(), $name)->getMessage(), $lineno, $source);
        }
        $taken[$name] = true;
        $this->names[$stream] = $taken;

        $blockName = RegionExtension::blockName($name);
        $block = new BlockNode($blockName, new \Twig\Node\EmptyNode(), $lineno);
        $block->setAttribute(RegionBlocks::ATTRIBUTE, $name);
        $this->parser->setBlock($blockName, $block);
        $this->parser->pushLocalScope();
        $this->parser->pushBlockStack($blockName);
        $this->open[$stream] = $name;
        try {
            $body = $this->parser->subparse([$this, 'decideRegionEnd'], true);
        } finally {
            unset($this->open[$stream]);
        }
        $stream->expect(Token::BLOCK_END_TYPE);
        $block->setNode('body', $body);
        $this->parser->popBlockStack();
        $this->parser->popLocalScope();

        return new RegionNode($name, $blockName, $attributes, $lineno);
    }

    public function decideRegionEnd(Token $token): bool
    {
        return $token->test('endregion');
    }

    public function getTag(): string
    {
        return 'region';
    }
}
