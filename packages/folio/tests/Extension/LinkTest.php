<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Extension;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Preflow\Folio\Extension\Link;

final class LinkTest extends TestCase
{
    public function test_admin_relative_and_site_paths_are_links(): void
    {
        $this->assertSame('/folio/member/3/edit', (new Link('x', 'member/3/edit'))->href('/folio'));
        $this->assertSame('/shop/order?id=3', (new Link('x', '/shop/order?id=3'))->href('/folio'));
        $this->assertSame('/folio/_a/b#c', (new Link('x', '_a/b#c'))->href('/folio'));
    }

    /** @return iterable<string, array{string}> */
    public static function notLinks(): iterable
    {
        foreach ([
            'empty' => '', 'query only' => '?state=open', 'fragment only' => '#x',
            'javascript' => 'javascript:alert(1)', 'mixed case scheme' => 'JavaScript:x',
            'https' => 'https://evil.example', 'mailto' => 'mailto:a@b.c', 'data' => 'data:text/html,x',
            'protocol relative' => '//evil.example', 'slash backslash' => '/\\evil.example',
            'backslash' => 'a\\b', 'tab in scheme' => "java\tscript:x", 'space' => '/a b',
            'newline' => "/a\nb", 'nul' => "/a\0b", 'del' => "/a\x7fb", 'colon after path' => 'a:b/c',
            'leading space' => ' member/3',
        ] as $name => $url) {
            yield $name => [$url];
        }
    }

    #[DataProvider('notLinks')]
    public function test_anything_else_is_no_link(string $url): void
    {
        $this->assertNull((new Link('x', $url))->href('/folio'));
    }
}
