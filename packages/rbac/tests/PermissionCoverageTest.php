<?php

declare(strict_types=1);

namespace Preflow\Rbac\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Preflow\Rbac\PermissionCoverage;

/** Which held patterns cover (may grant) which permission or pattern. */
final class PermissionCoverageTest extends TestCase
{
    /** @return array<string, array{list<string>, string, bool}> held, pattern, covered */
    public static function table(): array
    {
        return [
            'everything covers everything' => [['*'], '*', true],
            'everything covers a name' => [['*'], 'page.edit', true],
            'everything covers a pattern' => [['*'], 'page.*', true],
            'a family covers its pattern' => [['page.*'], 'page.*', true],
            'a family covers its names' => [['page.*'], 'page.edit', true],
            'a family covers deeper names' => [['page.*'], 'page.draft.edit', true],
            'a family covers deeper patterns' => [['page.*'], 'page.draft.*', true],
            'a family does not cover its bare prefix' => [['page.*'], 'page', false],
            'a family does not cover a lookalike' => [['page.*'], 'pages.edit', false],
            'a family does not cover everything' => [['page.*'], '*', false],
            'a family does not cover a broader family' => [['page.draft.*'], 'page.*', false],
            'an exact name covers itself' => [['page.edit'], 'page.edit', true],
            'an exact name does not cover a sibling' => [['page.edit'], 'page.view', false],
            'an exact name does not cover its family' => [['page.edit'], 'page.*', false],
            'an exact name does not cover everything' => [['page.edit'], '*', false],
            'any held pattern may cover' => [['assets.view', 'page.*'], 'page.view', true],
            'nothing held covers nothing' => [[], 'page.view', false],
        ];
    }

    /** @param list<string> $held */
    #[DataProvider('table')]
    public function test_covers(array $held, string $pattern, bool $covered): void
    {
        $this->assertSame($covered, PermissionCoverage::covers($held, $pattern));
    }

    public function test_covers_all_and_uncovered(): void
    {
        $held = ['page.*', 'assets.view'];

        $this->assertTrue(PermissionCoverage::coversAll($held, ['page.edit', 'page.*', 'assets.view']));
        $this->assertTrue(PermissionCoverage::coversAll($held, []));
        $this->assertFalse(PermissionCoverage::coversAll($held, ['page.edit', 'assets.*']));
        $this->assertSame(['assets.*', '*'], PermissionCoverage::uncovered($held, ['page.edit', 'assets.*', 'assets.view', '*']));
    }
}
