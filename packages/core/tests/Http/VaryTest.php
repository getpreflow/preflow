<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\Http;

use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Http\Vary;

/** Vary::add() (0.84.0): the one merge every middleware that says what an answer varies on uses. */
final class VaryTest extends TestCase
{
    public function test_names_are_added_after_the_ones_there_each_once(): void
    {
        $response = Vary::add(new Response(200, ['Vary' => 'Accept-Encoding, origin']), 'Origin', 'HX-Request', 'hx-request');

        $this->assertSame('Accept-Encoding, origin, HX-Request', $response->getHeaderLine('Vary'), 'case-insensitive: origin is there');
    }

    public function test_several_header_lines_are_merged(): void
    {
        $response = Vary::add((new Response(200))->withAddedHeader('Vary', 'Accept')->withAddedHeader('Vary', 'Cookie'), 'Accept-Language');

        $this->assertSame(['Accept, Cookie, Accept-Language'], $response->getHeader('Vary'));
    }

    public function test_a_star_and_nothing_new_leave_the_response_as_it_is(): void
    {
        $star = new Response(200, ['Vary' => '*']);
        $this->assertSame($star, Vary::add($star, 'Origin'));

        $there = new Response(200, ['Vary' => 'Origin']);
        $this->assertSame($there, Vary::add($there, 'origin'));
        $this->assertSame($there, Vary::add($there));
    }

    public function test_a_response_without_vary_gets_the_names(): void
    {
        $this->assertSame('Accept, Cookie', Vary::add(new Response(200), 'Accept', ' Cookie ', '')->getHeaderLine('Vary'));
    }
}
