<?php

declare(strict_types=1);

namespace Preflow\Routing\Tests\Redirect;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Preflow\Routing\Redirect\RedirectUrl;

final class RedirectUrlTest extends TestCase
{
    #[DataProvider('urls')]
    public function test_validity(string $url, bool $valid): void
    {
        self::assertSame($valid, RedirectUrl::isValid($url));
    }

    /** @return iterable<string, array{string, bool}> */
    public static function urls(): iterable
    {
        yield 'path' => ['/new', true];
        yield 'https url' => ['https://example.test/a?b=1', true];
        yield 'protocol-relative' => ['//evil.test', false];
        yield 'backslash host' => ['/\\evil.test', false];
        yield 'no host' => ['https:///x', false];
        yield 'other scheme' => ['javascript:alert(1)', false];
        yield 'whitespace' => ['/a b', false];
        yield 'control character' => ["/a\x01", false];
        yield 'too long' => ['/' . str_repeat('a', 2000), false];
        yield 'empty' => ['', false];
    }
}
