<?php

declare(strict_types=1);

namespace Preflow\Twig;

use Twig\Node\Node;
use Twig\Token;
use Twig\TokenParser\AbstractTokenParser;

/**
 * {% freshness 'next-visit' %}…{% endfreshness %} and
 * {% freshness 'timed' every '12h' %}…{% endfreshness %}: what the body reads is a dependency of
 * the cached page with that freshness ('fresh', the default outside; 'next-visit': a write marks
 * the page stale; 'timed': writes are ignored and the page expires after the time). Durations:
 * '30m', '12h', '2d' or seconds. Name and duration are expressions. Nested, the innermost wins;
 * inside a {% live %} block it does nothing (a live part's reads are no dependencies).
 */
final class FreshnessTokenParser extends AbstractTokenParser
{
    public function parse(Token $token): Node
    {
        $lineno = $token->getLine();
        $stream = $this->parser->getStream();

        $name = $this->parser->parseExpression();
        $every = null;
        if ($stream->nextIf(Token::NAME_TYPE, 'every')) {
            $every = $this->parser->parseExpression();
        }
        $stream->expect(Token::BLOCK_END_TYPE);
        $body = $this->parser->subparse([$this, 'decideFreshnessEnd'], true);
        $stream->expect(Token::BLOCK_END_TYPE);

        return new FreshnessNode($name, $every, $body, $lineno);
    }

    public function decideFreshnessEnd(Token $token): bool
    {
        return $token->test('endfreshness');
    }

    public function getTag(): string
    {
        return 'freshness';
    }
}
