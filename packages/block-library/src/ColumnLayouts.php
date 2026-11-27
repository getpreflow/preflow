<?php

declare(strict_types=1);

namespace Preflow\BlockLibrary;

use Preflow\Blocks\Layouts\AreaDefinition;
use Preflow\Blocks\Layouts\AreaLayout;
use Preflow\Blocks\Layouts\AreaLayoutSet;
use Preflow\Blocks\Layouts\LayoutConfigException;

/**
 * The column layouts of the columns block: the area layout set "columns", from config/blocks.php.
 *
 *   'column_layouts'  the list an editor picks from; replaces the default set when present (spread
 *                     ColumnLayouts::defaults() into it to add to the defaults). Each entry:
 *                     ['key' => '2-1', 'label' => '2/3 + 1/3', 'widths' => [2, 1]] — the key is
 *                     stored with the block, the label is a text or a translation key, the widths
 *                     are each column's share of the row (whole numbers 1–12, 1–6 columns)
 *   'column_blocks'   which blocks a column takes: ['except' => ['columns']] (the default: every
 *                     block but the columns block itself) or ['allowed' => ['text', 'image']]
 *
 * The columns are areas c1…cN (never numeric names, so the stored map stays a map in JSON). A
 * stored key the list no longer has renders with the list's first layout of as many columns, else
 * "full", else the first (AreaLayoutSet::resolve()); the columns it lacks are kept, not shown.
 *
 * @since 0.92.0
 */
final class ColumnLayouts
{
    /** The set's name, as the columns block's areas field names it ("layouts": "columns"). */
    public const string SET = 'columns';

    /** The layout a stored key falls back to when the list has none with as many columns. */
    public const string FALLBACK = 'full';

    public const int MAX_COLUMNS = 6;

    public const int MAX_WIDTH = 12;

    /**
     * The default set, in the order the editor lists it.
     *
     * @return list<array{key: string, label: string, widths: list<int>}>
     */
    public static function defaults(): array
    {
        return [
            ['key' => 'full', 'label' => 'block-library.layouts.full', 'widths' => [1]],
            ['key' => '1-1', 'label' => '1/2 + 1/2', 'widths' => [1, 1]],
            ['key' => '2-1', 'label' => '2/3 + 1/3', 'widths' => [2, 1]],
            ['key' => '1-2', 'label' => '1/3 + 2/3', 'widths' => [1, 2]],
            ['key' => '1-1-1', 'label' => '1/3 × 3', 'widths' => [1, 1, 1]],
            ['key' => '1-1-1-1', 'label' => '1/4 × 4', 'widths' => [1, 1, 1, 1]],
        ];
    }

    /**
     * The set "columns" from config/blocks.php ($blocksConfig: the file's array).
     *
     * @param array<mixed> $blocksConfig
     * @throws LayoutConfigException naming the entry that is wrong and what to write instead
     */
    public static function fromConfig(array $blocksConfig): AreaLayoutSet
    {
        [$allowed, $except] = self::columnBlocks($blocksConfig['column_blocks'] ?? null);
        $entries = array_key_exists('column_layouts', $blocksConfig) ? $blocksConfig['column_layouts'] : self::defaults();
        if (!is_array($entries) || !array_is_list($entries) || $entries === []) {
            throw new LayoutConfigException('config/blocks.php column_layouts is a list of column layouts, such as [...ColumnLayouts::defaults(), [\'key\' => \'3-1\', \'label\' => \'3/4 + 1/4\', \'widths\' => [3, 1]]]; leave the key out to use the default set.');
        }

        $layouts = [];
        $seen = [];
        foreach ($entries as $i => $entry) {
            $key = is_array($entry) ? ($entry['key'] ?? null) : null;
            $where = sprintf('config/blocks.php column_layouts[%d]%s', $i, is_string($key) ? sprintf(' ("%s")', $key) : '');
            if (!is_array($entry)) {
                throw new LayoutConfigException($where . ': an entry is an array such as [\'key\' => \'2-1\', \'label\' => \'2/3 + 1/3\', \'widths\' => [2, 1]].');
            }
            if (!is_string($key) || preg_match('/^[a-z0-9][a-z0-9_-]*$/', $key) !== 1) {
                throw new LayoutConfigException($where . ': the key is lower-case letters, digits, "-" and "_", starting with a letter or digit (it is stored with the block and names the CSS class pf-columns--<key>); write one such as "2-1".');
            }
            if (isset($seen[$key])) {
                throw new LayoutConfigException(sprintf('%s: column_layouts[%d] has the key "%s" already; give each layout its own key.', $where, $seen[$key], $key));
            }
            $seen[$key] = $i;
            $label = $entry['label'] ?? null;
            if (!is_string($label) || trim($label) === '') {
                throw new LayoutConfigException($where . ': the label is missing; give it a text, or a translation key such as "block-library.layouts.full".');
            }
            $widths = $entry['widths'] ?? null;
            if (!is_array($widths) || !array_is_list($widths) || $widths === [] || count($widths) > self::MAX_COLUMNS) {
                throw new LayoutConfigException(sprintf(
                    '%s: %s; a layout has 1 to %d columns, one width each, such as [2, 1].',
                    $where,
                    is_array($widths) && array_is_list($widths) ? sprintf('%d column widths', count($widths)) : 'the widths are not a list',
                    self::MAX_COLUMNS,
                ));
            }
            $areas = [];
            foreach ($widths as $n => $width) {
                if (!is_int($width) || $width < 1 || $width > self::MAX_WIDTH) {
                    throw new LayoutConfigException(sprintf(
                        '%s: the width of column %d is %s; widths are whole numbers from 1 to %d (a column\'s share of the row), such as [2, 1].',
                        $where,
                        $n + 1,
                        var_export($width, true),
                        self::MAX_WIDTH,
                    ));
                }
                $name = 'c' . ($n + 1);
                $areas[$name] = new AreaDefinition($name, 'block-library.columns.column', allowed: $allowed, except: $except, width: $width);
            }
            $layouts[] = new AreaLayout($key, $label, $areas);
        }

        return new AreaLayoutSet(self::SET, $layouts, self::FALLBACK);
    }

    /**
     * config/blocks.php column_blocks as [allowed, except].
     *
     * @return array{list<string>, list<string>}
     * @throws LayoutConfigException
     */
    private static function columnBlocks(mixed $config): array
    {
        if ($config === null) {
            return [[], [Columns\Columns::KEY]];
        }
        if (!is_array($config) || array_diff(array_keys($config), ['allowed', 'except']) !== []) {
            throw new LayoutConfigException('config/blocks.php column_blocks is [\'except\' => [block keys]] or [\'allowed\' => [block keys]], such as [\'except\' => [\'columns\']].');
        }
        if (isset($config['allowed'], $config['except'])) {
            throw new LayoutConfigException('config/blocks.php column_blocks has both "allowed" and "except"; use one: "allowed" lists the only blocks a column takes, "except" the blocks it leaves out of all.');
        }
        foreach ($config as $name => $keys) {
            // An empty "except" lets a column take every block, columns too (nesting stays within max_depth).
            if (!is_array($keys) || !array_is_list($keys) || ($keys === [] && $name === 'allowed') || array_filter($keys, static fn (mixed $key): bool => !is_string($key) || $key === '') !== []) {
                throw new LayoutConfigException(sprintf('config/blocks.php column_blocks "%s" is a list of block keys, such as %s.', $name, $name === 'allowed' ? '[\'text\', \'image\']' : '[\'columns\']'));
            }
        }

        /** @var list<string> $allowed */
        $allowed = $config['allowed'] ?? [];
        /** @var list<string> $except */
        $except = $config['except'] ?? [];

        return [$allowed, $except];
    }
}
