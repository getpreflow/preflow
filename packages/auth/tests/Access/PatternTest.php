<?php

declare(strict_types=1);

namespace Preflow\Auth\Tests\Access;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Preflow\Auth\Access\Pattern;

final class PatternTest extends TestCase
{
    /** @return array<string, array{string, string, bool}> */
    public static function cases(): array
    {
        return [
            'wildcard family' => ['page.*', 'page.edit', true],
            'deep family' => ['page.*', 'page.blocks.edit', true],
            'not a lookalike' => ['page.*', 'pages.edit', false],
            'not the bare prefix' => ['page.*', 'page', false],
            'everything' => ['*', 'anything.at.all', true],
            'exact' => ['page.edit', 'page.edit', true],
            'exact only' => ['page.edit', 'page.editor', false],
            'other family' => ['news.*', 'page.edit', false],
        ];
    }

    #[DataProvider('cases')]
    public function test_matches(string $pattern, string $permission, bool $expected): void
    {
        $this->assertSame($expected, Pattern::matches($pattern, $permission));
    }
}
