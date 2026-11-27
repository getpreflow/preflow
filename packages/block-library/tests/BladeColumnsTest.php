<?php

declare(strict_types=1);

namespace Preflow\BlockLibrary\Tests;

/** The columns block with its Blade template: the same markup as with Twig. */
final class BladeColumnsTest extends BlockLibraryAppTestCase
{
    protected string $engine = 'blade';

    public function test_columns_renders_placed_columns_with_their_widths(): void
    {
        $page = [self::columns('col1', '1-2', ['c1' => ['A'], 'c2' => ['B', 'B2'], 'c3' => ['Dropped']])];

        $html = $this->render('page.blade.php', '<main>@blocks($page, true)</main>', ['page' => $page], 'de');

        $note = static fn (string $uid, string $title): string => '<div id="Note-[0-9a-f]+-' . $uid . '" data-block="' . $uid . '" data-block-label="Note"><p class="note">' . $title . '</p>\s*</div>';
        $this->assertMatchesRegularExpression(
            '#^<main><div id="Columns-[0-9a-f]+-col1" data-block="col1" data-block-label="block-library.columns.label">'
            . '<div class="pf-columns pf-columns--1-2">'
            . '<div class="pf-columns__cell pf-columns__cell--w1">' . $note('A', 'A-de') . '</div>'
            . '<div class="pf-columns__cell pf-columns__cell--w2">' . $note('B', 'B-de') . $note('B2', 'B2-de') . '</div>'
            . '</div>\s*</div></main>$#',
            $html,
            'one cell per placed column with its width, nested blocks rendered and marked; the dropped column never renders',
        );
    }
}
