<?php

declare(strict_types=1);

namespace Preflow\BlockLibrary\Tests;

use Preflow\BlockLibrary\ColumnLayouts;
use Preflow\Blocks\BlockConfigException;
use Preflow\Blocks\BlockRegistry;
use Preflow\Blocks\BlocksServiceProvider;
use Preflow\Blocks\Layouts\AreaLayoutSets;
use Preflow\I18n\Translator;

/** The columns block in a site without Folio (Twig): what renders, what a column takes, and what the provider brings. */
final class ColumnsBlockTest extends BlockLibraryAppTestCase
{
    public function test_columns_renders_placed_columns_with_their_widths(): void
    {
        $page = [self::columns('col1', '2-1', ['c1' => ['A', 'A2'], 'c2' => ['B']])];

        $html = $this->render('page.twig', '<main>{{ blocks(page, true) }}</main>{{ head() }}', ['page' => $page], 'de');
        [$main, $assets] = explode('</main>', $html, 2);

        $note = static fn (string $uid, string $title): string => '<div id="Note-[0-9a-f]+-' . $uid . '" data-block="' . $uid . '" data-block-label="Note"><p class="note">' . $title . '</p>\s*</div>';
        $this->assertMatchesRegularExpression(
            '#^<main><div id="Columns-[0-9a-f]+-col1" data-block="col1" data-block-label="block-library.columns.label">'
            . '<div class="pf-columns pf-columns--2-1">'
            . '<div class="pf-columns__cell pf-columns__cell--w2">' . $note('A', 'A-de') . $note('A2', 'A2-de') . '</div>'
            . '<div class="pf-columns__cell pf-columns__cell--w1">' . $note('B', 'B-de') . '</div>'
            . '</div>\s*</div>$#',
            $main,
            'one cell per column with its width, the nested blocks in the page\'s locale, the preview marks on blocks inside columns',
        );
        $this->assertMatchesRegularExpression('#<style nonce="[^"]+">[^<]*\.pf-columns__cell--w12 \{ flex-grow: 12; \}#', $assets, 'the CSS reaches the page\'s style element with its nonce (no inline style attribute)');
        $this->assertStringNotContainsString('style="', $main);

        $plain = $this->render('plain.twig', '{{ blocks(page) }}', ['page' => $page]);
        $this->assertStringNotContainsString('data-block=', $plain, 'no marks outside a preview');
        $this->assertStringContainsString('<p class="note">A</p>', $plain);
    }

    public function test_dropped_column_never_renders(): void
    {
        $page = [
            // Switched from three columns to two: c3 is kept in the value, not shown.
            self::columns('two', '1-1', ['c1' => ['Left'], 'c2' => [], 'c3' => ['Dropped']]),
            // A key the list no longer has, three stored columns: the first layout with three.
            self::columns('gone', 'gone', ['c1' => ['X'], 'c2' => ['Y'], 'c3' => ['Z']]),
            // Nothing stored yet: "full", one empty cell.
            ['_uid' => 'empty', '_block' => 'columns', 'columns' => []],
        ];

        $html = $this->render('page.twig', '{{ blocks(page) }}', ['page' => $page]);

        $this->assertStringNotContainsString('Dropped', $html);
        $this->assertMatchesRegularExpression('#<div class="pf-columns pf-columns--1-1"><div class="pf-columns__cell pf-columns__cell--w1">.*Left.*</div><div class="pf-columns__cell pf-columns__cell--w1"></div></div>#sU', $html, 'an empty column is an empty cell');
        $this->assertMatchesRegularExpression('#<div class="pf-columns pf-columns--1-1-1">(<div class="pf-columns__cell pf-columns__cell--w1">.*</div>){3}</div>#sU', $html);
        $this->assertStringContainsString('<div class="pf-columns pf-columns--full"><div class="pf-columns__cell pf-columns__cell--w1"></div></div>', $html);
    }

    public function test_site_layouts_render_with_their_widths(): void
    {
        $this->blocksConfig("'column_layouts' => [['key' => 'wide', 'label' => 'Wide', 'widths' => [3, 1]]]");

        $html = $this->render('page.twig', '{{ blocks(page) }}', ['page' => [self::columns('w', 'wide', ['c1' => ['Main'], 'c2' => ['Side']])]]);

        $this->assertMatchesRegularExpression('#<div class="pf-columns pf-columns--wide"><div class="pf-columns__cell pf-columns__cell--w3">.*Main.*</div><div class="pf-columns__cell pf-columns__cell--w1">.*Side.*</div></div>#s', $html);
    }

    public function test_columns_takes_every_block_but_itself_by_default(): void
    {
        $set = $this->app()->container()->get(AreaLayoutSets::class)->get(ColumnLayouts::SET);
        foreach ($set->all() as $layout) {
            foreach ($layout->areas as $name => $area) {
                $this->assertTrue($area->accepts('note'), $layout->key . ' ' . $name);
                $this->assertFalse($area->accepts('columns'), $layout->key . ' ' . $name);
            }
        }
        $definition = $this->app()->container()->get(BlockRegistry::class)->get('columns');
        $this->assertSame('block-library.columns.label', $definition?->label);
        $this->assertSame('block-library.groups.layout', $definition?->group);
        $this->assertSame(['layouts' => 'columns'], $definition?->fields['columns']->config);
    }

    public function test_column_blocks_from_config(): void
    {
        $this->blocksConfig("'column_blocks' => ['allowed' => ['note']]");

        $area = $this->app()->container()->get(AreaLayoutSets::class)->get(ColumnLayouts::SET)->get('1-1')?->areas['c2'];
        $this->assertTrue($area?->accepts('note'));
        $this->assertFalse($area?->accepts('columns'));
        $this->assertFalse($area?->accepts('quote'), 'only the blocks listed');
    }

    public function test_a_config_mistake_stops_the_registry_naming_the_entry(): void
    {
        $this->blocksConfig("'column_layouts' => [['key' => 'big', 'label' => 'Big', 'widths' => [13]]]");

        try {
            $this->app()->container()->get(BlockRegistry::class);
            $this->fail('a width of 13 was taken');
        } catch (BlockConfigException $e) {
            $this->assertSame('Block "columns": field "columns": config/blocks.php column_layouts[0] ("big"): the width of column 1 is 13; widths are whole numbers from 1 to 12 (a column\'s share of the row), such as [2, 1].', $e->getMessage());
        }
    }

    public function test_without_the_provider_the_registry_names_the_missing_set(): void
    {
        $this->providers([BlocksServiceProvider::class]);

        try {
            $this->app()->container()->get(BlockRegistry::class);
            $this->fail('the columns block was taken without its column layouts');
        } catch (BlockConfigException $e) {
            $this->assertSame('Block "columns": field "columns": No package registers the area layout set "columns". The package that brings the block registers it in its service provider (config/providers.php).', $e->getMessage());
        }
    }

    public function test_texts_in_english_german_and_french(): void
    {
        $translator = $this->app()->container()->get(Translator::class);
        $texts = static fn (string $locale): array => [
            $translator->get('block-library.columns.label', [], $locale),
            $translator->get('block-library.groups.layout', [], $locale),
            $translator->get('block-library.layouts.full', [], $locale),
            $translator->get('block-library.columns.column', ['n' => 2], $locale),
        ];

        $this->assertSame(['Columns', 'Layout', 'Full width', 'Column 2'], $texts('en'));
        $this->assertSame(['Spalten', 'Layout', 'Volle Breite', 'Spalte 2'], $texts('de'));
        $this->assertSame(['Colonnes', 'Mise en page', 'Pleine largeur', 'Colonne 2'], $texts('fr'));
        $this->assertSame('public', $translator->scope('block-library'), 'texts a site\'s pages show too');

        foreach (['de', 'fr'] as $locale) {
            $this->assertSame(
                self::keys(require dirname(__DIR__) . '/lang/en/block-library.php'),
                self::keys(require dirname(__DIR__) . '/lang/' . $locale . '/block-library.php'),
                $locale . ' has every key en has',
            );
        }
    }

    /**
     * @param array<mixed> $texts
     * @return list<string>
     */
    private static function keys(array $texts, string $prefix = ''): array
    {
        $keys = [];
        foreach ($texts as $key => $value) {
            array_push($keys, ...(is_array($value) ? self::keys($value, $prefix . $key . '.') : [$prefix . $key]));
        }

        return $keys;
    }
}
