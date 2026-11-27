<?php

declare(strict_types=1);

namespace Preflow\BlockLibrary\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Preflow\BlockLibrary\ColumnLayouts;
use Preflow\Blocks\Layouts\AreaLayout;
use Preflow\Blocks\Layouts\AreaLayoutSet;
use Preflow\Blocks\Layouts\LayoutConfigException;

/** The column layout set "columns" from config/blocks.php: the default set, a site's own list, and what is refused. */
final class ColumnLayoutsTest extends TestCase
{
    /** @return array<string, array{key: string, label: string, widths: list<int|null>, areas: list<string>}> */
    private static function described(AreaLayoutSet $set): array
    {
        $out = [];
        foreach ($set->all() as $layout) {
            $out[$layout->key] = self::layout($layout);
        }

        return $out;
    }

    /** @return array{key: string, label: string, widths: list<int|null>, areas: list<string>} */
    private static function layout(AreaLayout $layout): array
    {
        return [
            'key' => $layout->key,
            'label' => $layout->label,
            'widths' => array_values(array_map(static fn ($area): ?int => $area->width, $layout->areas)),
            'areas' => $layout->areaNames(),
        ];
    }

    public function test_default_set_has_the_six_layouts_in_order(): void
    {
        $this->assertSame([
            ['key' => 'full', 'label' => 'block-library.layouts.full', 'widths' => [1]],
            ['key' => '1-1', 'label' => '1/2 + 1/2', 'widths' => [1, 1]],
            ['key' => '2-1', 'label' => '2/3 + 1/3', 'widths' => [2, 1]],
            ['key' => '1-2', 'label' => '1/3 + 2/3', 'widths' => [1, 2]],
            ['key' => '1-1-1', 'label' => '1/3 × 3', 'widths' => [1, 1, 1]],
            ['key' => '1-1-1-1', 'label' => '1/4 × 4', 'widths' => [1, 1, 1, 1]],
        ], ColumnLayouts::defaults());

        $set = ColumnLayouts::fromConfig([]);
        $this->assertSame('columns', $set->name);
        $this->assertSame('full', $set->fallback);
        $this->assertSame(['full', '1-1', '2-1', '1-2', '1-1-1', '1-1-1-1'], array_keys(self::described($set)), 'in the order the editor lists them');
        $this->assertSame(['key' => '2-1', 'label' => '2/3 + 1/3', 'widths' => [2, 1], 'areas' => ['c1', 'c2']], self::described($set)['2-1']);
        $this->assertSame(['c1', 'c2', 'c3', 'c4'], self::described($set)['1-1-1-1']['areas'], 'columns are c1…cN, never numeric');

        $column = $set->get('1-1')?->areas['c2'];
        $this->assertSame('block-library.columns.column', $column?->label);
        $this->assertSame(['n' => 2], $column?->labelParams, 'translated as "Column 2"');
        $this->assertSame([], $column?->allowed);
        $this->assertSame(['columns'], $column?->except, 'every block but the columns block itself');
    }

    public function test_site_list_replaces_the_defaults_and_can_spread_them(): void
    {
        $own = ColumnLayouts::fromConfig(['column_layouts' => [
            ['key' => 'wide', 'label' => 'Wide and narrow', 'widths' => [3, 1]],
            ['key' => 'six', 'label' => 'Six', 'widths' => [1, 1, 1, 1, 1, 1]],
        ]]);
        $this->assertSame(['wide', 'six'], array_keys(self::described($own)), 'the site\'s list replaces the default set');
        $this->assertSame([3, 1], self::described($own)['wide']['widths']);
        $this->assertSame('wide', $own->resolve('full')?->key, 'a list without "full": a key it lacks resolves to its first');
        $this->assertSame('six', $own->resolve('gone', 6)?->key, 'or to its first layout with as many columns');

        $spread = ColumnLayouts::fromConfig(['column_layouts' => [...ColumnLayouts::defaults(), ['key' => '3-1', 'label' => '3/4 + 1/4', 'widths' => [3, 1]]]]);
        $this->assertSame(['full', '1-1', '2-1', '1-2', '1-1-1', '1-1-1-1', '3-1'], array_keys(self::described($spread)), 'the defaults spread, one added');
        $this->assertSame('full', $spread->resolve('gone', 5)?->key, 'no layout with five columns: "full"');

        $allowed = ColumnLayouts::fromConfig(['column_blocks' => ['allowed' => ['text', 'image']]]);
        $this->assertSame(['text', 'image'], $allowed->get('1-1')?->areas['c1']->allowed);
        $this->assertSame([], $allowed->get('1-1')?->areas['c1']->except);
        $nested = ColumnLayouts::fromConfig(['column_blocks' => ['except' => []]]);
        $this->assertTrue($nested->get('full')?->areas['c1']->accepts('columns'), 'an empty "except": columns inside columns too');
    }

    /** @return array<string, array{array<mixed>, string}> */
    public static function refusals(): array
    {
        $two = ['key' => '1-1', 'label' => 'Two', 'widths' => [1, 1]];

        return [
            'too many columns' => [
                ['column_layouts' => [$two, ['key' => '2-1', 'label' => 'Wide', 'widths' => [2, 1]], ['key' => '1-1-1-1-1-1-1', 'label' => 'Seven', 'widths' => [1, 1, 1, 1, 1, 1, 1]]]],
                'config/blocks.php column_layouts[2] ("1-1-1-1-1-1-1"): 7 column widths; a layout has 1 to 6 columns, one width each, such as [2, 1].',
            ],
            'no widths' => [
                ['column_layouts' => [['key' => 'none', 'label' => 'None', 'widths' => []]]],
                'config/blocks.php column_layouts[0] ("none"): 0 column widths; a layout has 1 to 6 columns, one width each, such as [2, 1].',
            ],
            'widths not a list' => [
                ['column_layouts' => [['key' => 'map', 'label' => 'Map', 'widths' => ['left' => 1]]]],
                'config/blocks.php column_layouts[0] ("map"): the widths are not a list; a layout has 1 to 6 columns, one width each, such as [2, 1].',
            ],
            'width above 12' => [
                ['column_layouts' => [$two, ['key' => 'big', 'label' => 'Big', 'widths' => [13, 1]]]],
                'config/blocks.php column_layouts[1] ("big"): the width of column 1 is 13; widths are whole numbers from 1 to 12 (a column\'s share of the row), such as [2, 1].',
            ],
            'width not whole' => [
                ['column_layouts' => [['key' => 'half', 'label' => 'Half', 'widths' => [1, 1.5]]]],
                'config/blocks.php column_layouts[0] ("half"): the width of column 2 is 1.5; widths are whole numbers from 1 to 12 (a column\'s share of the row), such as [2, 1].',
            ],
            'width zero' => [
                ['column_layouts' => [['key' => 'zero', 'label' => 'Zero', 'widths' => [0, 1]]]],
                'config/blocks.php column_layouts[0] ("zero"): the width of column 1 is 0; widths are whole numbers from 1 to 12 (a column\'s share of the row), such as [2, 1].',
            ],
            'key format' => [
                ['column_layouts' => [['key' => 'Two Cols', 'label' => 'Two', 'widths' => [1, 1]]]],
                'config/blocks.php column_layouts[0] ("Two Cols"): the key is lower-case letters, digits, "-" and "_", starting with a letter or digit (it is stored with the block and names the CSS class pf-columns--<key>); write one such as "2-1".',
            ],
            'key missing' => [
                ['column_layouts' => [['label' => 'Two', 'widths' => [1, 1]]]],
                'config/blocks.php column_layouts[0]: the key is lower-case letters, digits, "-" and "_", starting with a letter or digit (it is stored with the block and names the CSS class pf-columns--<key>); write one such as "2-1".',
            ],
            'key twice' => [
                ['column_layouts' => [$two, ['key' => 'full', 'label' => 'Full', 'widths' => [1]], $two]],
                'config/blocks.php column_layouts[2] ("1-1"): column_layouts[0] has the key "1-1" already; give each layout its own key.',
            ],
            'label missing' => [
                ['column_layouts' => [['key' => '1-1', 'widths' => [1, 1]]]],
                'config/blocks.php column_layouts[0] ("1-1"): the label is missing; give it a text, or a translation key such as "block-library.layouts.full".',
            ],
            'entry not an array' => [
                ['column_layouts' => ['1-1']],
                'config/blocks.php column_layouts[0]: an entry is an array such as [\'key\' => \'2-1\', \'label\' => \'2/3 + 1/3\', \'widths\' => [2, 1]].',
            ],
            'empty list' => [
                ['column_layouts' => []],
                'config/blocks.php column_layouts is a list of column layouts, such as [...ColumnLayouts::defaults(), [\'key\' => \'3-1\', \'label\' => \'3/4 + 1/4\', \'widths\' => [3, 1]]]; leave the key out to use the default set.',
            ],
            'allowed and except' => [
                ['column_blocks' => ['allowed' => ['text'], 'except' => ['columns']]],
                'config/blocks.php column_blocks has both "allowed" and "except"; use one: "allowed" lists the only blocks a column takes, "except" the blocks it leaves out of all.',
            ],
            'column_blocks unknown key' => [
                ['column_blocks' => ['only' => ['text']]],
                'config/blocks.php column_blocks is [\'except\' => [block keys]] or [\'allowed\' => [block keys]], such as [\'except\' => [\'columns\']].',
            ],
            'empty allowed' => [
                ['column_blocks' => ['allowed' => []]],
                'config/blocks.php column_blocks "allowed" is a list of block keys, such as [\'text\', \'image\'].',
            ],
            'except not keys' => [
                ['column_blocks' => ['except' => 'columns']],
                'config/blocks.php column_blocks "except" is a list of block keys, such as [\'columns\'].',
            ],
        ];
    }

    /** @param array<mixed> $config */
    #[DataProvider('refusals')]
    public function test_config_refusal_names_the_entry(array $config, string $message): void
    {
        try {
            ColumnLayouts::fromConfig($config);
            $this->fail('the config was taken');
        } catch (LayoutConfigException $e) {
            $this->assertSame($message, $e->getMessage());
        }
    }
}
