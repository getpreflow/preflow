<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\PageCache;

use PHPUnit\Framework\TestCase;
use Preflow\Core\PageCache\LivePlaceholder;

final class LivePlaceholderTest extends TestCase
{
    public function test_a_placeholder_carries_its_recipe_signed(): void
    {
        $placeholders = new LivePlaceholder('secret-one');
        $recipe = ['t' => '@folio/frontend/post.twig', 'b' => '__live_3', 'w' => ['number' => 7, 'tags' => ['a', 'ü'], 'price' => 1.0, 'none' => null]];
        $marker = $placeholders->make('twig', $recipe);

        $this->assertMatchesRegularExpression('/^<!--pc:l:[A-Za-z0-9_-]+:[0-9a-f]{32}-->$/', $marker);
        $this->assertSame([$marker => ['twig', $recipe]], $placeholders->find("<p>{$marker}</p><p>{$marker}</p>"), 'each once');
    }

    public function test_only_what_verifies_is_found(): void
    {
        $mine = new LivePlaceholder('secret-one');
        $marker = $mine->make('twig', ['t' => 'a.twig', 'b' => '__live_1', 'w' => []]);
        [, $payload, $sig] = explode(':', substr($marker, 4, -3));
        $edited = (new LivePlaceholder('secret-one'))->make('twig', ['t' => 'b.twig', 'b' => '__live_1', 'w' => []]);
        [, $otherPayload] = explode(':', substr($edited, 4, -3));

        $this->assertSame([], (new LivePlaceholder('secret-two'))->find($marker), 'another key');
        $this->assertSame([], $mine->find("<!--pc:l:{$otherPayload}:{$sig}-->"), 'another payload under this signature');
        $this->assertSame([], $mine->find("<!--pc:l:{$payload}:" . str_repeat('a', 32) . '-->'), 'a made-up signature');
        $this->assertSame([], $mine->find('<!--pc:l:' . $payload . '-->'), 'no signature');

        // Signed but not a recipe: never decoded into anything.
        $sign = static fn (string $p): string => '<!--pc:l:' . $p . ':' . substr(hash_hmac('sha256', 'pc:l:' . $p, 'secret-one'), 0, 32) . '-->';
        $this->assertSame([], $mine->find($sign(rtrim(strtr(base64_encode('"text"'), '+/', '-_'), '='))));
        $this->assertSame([], $mine->find($sign(rtrim(strtr(base64_encode('{"t":"a"}'), '+/', '-_'), '='))), 'no kind');
        $this->assertSame([], $mine->find($sign('not-base64-@')));
    }

    public function test_values_are_scalars_and_arrays(): void
    {
        LivePlaceholder::assertPlain(['a' => 1, 'b' => [true, 'x', 2.5, null, ['c' => 'd']]]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("'b.1.c' is stdClass");
        LivePlaceholder::assertPlain(['a' => 1, 'b' => [true, ['c' => new \stdClass()]]]);
    }
}
