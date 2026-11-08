<?php

declare(strict_types=1);

namespace Preflow\Twig;

use Twig\Error\SyntaxError;
use Twig\Node\Node;
use Twig\Token;
use Twig\TokenParser\AbstractTokenParser;

/**
 * {% expires at event.startsAt %} and {% expires in '1h' %}: the cached page is stale from then
 * on (served once more while a fresh one is built). The earliest of several counts. `at` takes a
 * date (DateTimeInterface, a unix time or a date string; null does nothing), `in` a duration
 * ('30m', '12h', '2d' or seconds).
 */
final class ExpiresTokenParser extends AbstractTokenParser
{
    public function parse(Token $token): Node
    {
        $lineno = $token->getLine();
        $stream = $this->parser->getStream();

        if ($stream->nextIf(Token::NAME_TYPE, 'at')) {
            $node = new ExpiresNode($this->parser->parseExpression(), null, $lineno);
        } elseif ($stream->nextIf(Token::OPERATOR_TYPE, 'in') ?? $stream->nextIf(Token::NAME_TYPE, 'in')) {
            $node = new ExpiresNode(null, $this->parser->parseExpression(), $lineno);
        } else {
            throw new SyntaxError("{% expires %} takes `at` a date or `in` a duration: {% expires in '1h' %}.", $lineno, $stream->getSourceContext());
        }
        $stream->expect(Token::BLOCK_END_TYPE);

        return $node;
    }

    public function getTag(): string
    {
        return 'expires';
    }
}
