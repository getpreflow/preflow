<?php

declare(strict_types=1);

namespace Preflow\Blocks\Layouts;

use Preflow\Blocks\BlockLists;
use Preflow\Blocks\BlockRegistry;
use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;
use Preflow\Data\LocaleValue;
use Preflow\Data\SortDirection;
use Preflow\Data\TypeDefinition;
use Preflow\Data\TypeFieldDefinition;
use Preflow\Data\TypeRegistry;

/**
 * What needs a person's attention in the page layouts of a site, as lines `preflow migrate` and
 * `db:check` print (MigrationRegistry::warning(), registered as "blocks.layouts"; usable without
 * either): for each type with "layouts", an allowed or pinned layout no class declares, a pin whose
 * page does not exist yet, pages on a layout that is not available, and pages with blocks in areas
 * their layout does not show; and (with the area layout sets and the blocks) for every type,
 * records with an areas field (their own, or a block's: the columns block) on a layout its set does
 * not have, or with blocks in areas that layout does not show. None of it is an error: such pages
 * render with the default layout (an areas field with its set's fallback) and keep every block.
 *
 * The pages are the working copies (the type's own table), read in pages of $pageSize, so a large
 * table is never loaded at once; each line names at most $named pages, then "and N more".
 *
 * @since 0.92.0
 */
final class LayoutReport
{
    public function __construct(
        private readonly PageLayouts $layouts,
        private readonly LayoutRegistry $registry,
        private readonly TypeRegistry $types,
        private readonly DataManager $data,
        private readonly int $pageSize = 100,
        private readonly int $named = 20,
        /** With the sets and the blocks (0.92): areas fields on a layout their set lacks, or with areas it does not show. */
        private readonly ?AreaLayoutSets $sets = null,
        private readonly ?BlockRegistry $blocks = null,
    ) {}

    /**
     * @return list<string> one line each, in English; [] when nothing needs attention
     */
    public function lines(): array
    {
        $lines = [];
        foreach ($this->types->keys() as $key) {
            $type = null;
            $broken = false;
            try {
                $type = $this->layouts->forType($key);
                if ($type !== null) {
                    array_push($lines, ...$this->typeLines($this->types->get($key), $type));
                }
            } catch (LayoutConfigException $e) {
                $lines[] = $e->getMessage();
                $broken = true;
            }
            // A type whose "layouts" cannot work is named above; its records are not read here.
            $checks = $this->sets !== null && $this->blocks !== null && !$broken ? new AreasFieldChecks($this->sets, $this->blocks, $this->layouts) : null;
            if ($checks !== null && $checks->appliesTo($this->types->get($key))) {
                array_push($lines, ...$this->areasLines($this->types->get($key), $type !== null));
            }
        }

        return $lines;
    }

    /**
     * The records of the type with an areas value (in a field of their own, inside a block, in a
     * page's area, unplaced ones too) on a layout its set does not have, or with blocks in areas its
     * layout does not show.
     *
     * @return list<string>
     */
    private function areasLines(TypeDefinition $def, bool $pageAreas): array
    {
        $missing = [];
        $unplaced = self::none();
        $areas = [];
        try {
            for ($offset = 0;; $offset += $this->pageSize) {
                $page = $this->data->queryIn($def)->orderBy($def->idField, SortDirection::Asc)->limit($this->pageSize)->offset($offset)->get()->items();
                foreach ($page as $record) {
                    $values = $record->toArray();
                    $found = [];
                    foreach ($def->fields as $name => $field) {
                        $this->collect($field, $values[$name] ?? null, $found);
                    }
                    if ($pageAreas) {
                        foreach (Placement::areasOf($values[PageLayouts::AREAS] ?? null) as $list) {
                            $this->collectList($list, $found);
                        }
                    }
                    $type = $pageAreas ? $this->layouts->forType($def->key) : null;
                    $label = $this->label($def, $record, $type !== null ? PageLayouts::slugOf($def, $type, $values) : null);
                    $notShown = [];
                    $gone = [];
                    foreach ($found as [$set, $value]) {
                        if ($value->fallback($set)) {
                            $gone[$set->name . "\0" . $value->layout] = true;
                        }
                        foreach ($value->placement($set)->unplaced() as $area => $_) {
                            $notShown[$area] = true;
                        }
                    }
                    foreach (array_keys($gone) as $key) {
                        $missing[$key] ??= self::none();
                        $this->add($missing[$key], $label);
                    }
                    if ($notShown !== []) {
                        $this->add($unplaced, $label);
                        $areas += $notShown;
                    }
                }
                if (count($page) < $this->pageSize) {
                    break;
                }
            }
        } catch (\Throwable $e) {
            return [sprintf('Type "%s": its areas fields could not be checked (%s).', $def->key, $e->getMessage())];
        }

        $lines = [];
        foreach ($missing as $key => $records) {
            [$set, $layout] = explode("\0", (string) $key, 2);
            $lines[] = sprintf(
                'Type "%s": %s the layout "%s" in an areas field, which the area layout set "%s" does not have (they show with the set\'s layout for their number of areas): %s.',
                $def->key,
                self::count($records['count'], 'record uses', 'records use'),
                $layout,
                $set,
                $this->named($records),
            );
        }
        if ($unplaced['count'] > 0) {
            $lines[] = sprintf(
                'Type "%s": %s in areas their areas field\'s layout does not show (%s): %s. Open them in the editor ("Not shown in this layout").',
                $def->key,
                self::count($unplaced['count'], 'record has blocks', 'records have blocks'),
                implode(', ', array_keys($areas)),
                $this->named($unplaced),
            );
        }

        return $lines;
    }

    /**
     * Every areas value in a field's value, with its set: the field's own, and those inside every
     * stored list of blocks it holds (shown or not), to any depth.
     *
     * @param list<array{AreaLayoutSet, AreasValue}> $found
     */
    private function collect(TypeFieldDefinition $field, mixed $value, array &$found): void
    {
        $set = BlockLists::setName($field);
        if ($field->type === BlockLists::AREAS && $set !== null && $this->sets?->has($set)) {
            $found[] = [$this->sets->get($set), AreasValue::fromStored($value)];
        }
        foreach (BlockLists::of($field, $value) as $list) {
            $this->collectList($list, $found);
        }
    }

    /**
     * @param list<array<string, mixed>>             $list
     * @param list<array{AreaLayoutSet, AreasValue}> $found
     */
    private function collectList(array $list, array &$found): void
    {
        foreach ($list as $block) {
            foreach ($this->blocks?->get((string) ($block['_block'] ?? ''))->fields ?? [] as $name => $field) {
                if (BlockLists::holds($field)) {
                    $this->collect($field, $block[$name] ?? null, $found);
                }
            }
        }
    }

    /** @return list<string> */
    private function typeLines(TypeDefinition $def, TypeLayouts $type): array
    {
        $name = $def->key;
        $lines = [];
        $shownAs = $this->layouts->resolveValues($def, [])?->layout?->key;
        $shows = $shownAs === null ? 'they show without a layout' : sprintf('they show as "%s"', $shownAs);

        foreach ($type->allowed as $key) {
            if ($this->registry->get($key) === null) {
                $lines[] = sprintf('Type "%s": layouts.allowed names "%s", which no #[Layout] class declares. Its pages show as %s until you add the class or remove the key.', $name, $key, $shownAs === null ? 'without a layout' : '"' . $shownAs . '"');
            }
        }

        try {
            $scan = $this->scan($def, $type);
        } catch (\Throwable $e) {
            $lines[] = sprintf('Type "%s": its pages could not be checked (%s).', $name, $e->getMessage());

            return $lines;
        }

        foreach ($type->pinned as $slug => $layout) {
            if ($this->registry->get($layout) === null) {
                $lines[] = sprintf('Type "%s": layouts.pinned "%s" names the layout "%s", which no #[Layout] class declares. The page shows as %s until you add the class or change the pin.', $name, $slug, $layout, $shownAs === null ? 'without a layout' : '"' . $shownAs . '"');
            }
            if (!isset($scan['slugs'][$slug])) {
                $lines[] = sprintf('Type "%s": layouts.pinned "%s" names no page with that slug yet. The pin applies once the page exists.', $name, $slug);
            }
        }
        foreach ($scan['missing'] as $key => $pages) {
            $lines[] = sprintf(
                'Type "%s": %s, which is not available (%s): %s.',
                $name,
                self::count($pages['count'], 'page uses', 'pages use') . sprintf(' the layout "%s"', $key),
                $shows,
                $this->named($pages),
            );
        }
        if ($scan['unplaced']['count'] > 0) {
            $lines[] = sprintf(
                'Type "%s": %s in areas their layout does not show (%s): %s. Open them in the editor ("Not shown in this layout").',
                $name,
                self::count($scan['unplaced']['count'], 'page has blocks', 'pages have blocks'),
                implode(', ', array_keys($scan['areas'])),
                $this->named($scan['unplaced']),
            );
        }

        return $lines;
    }

    /**
     * One pass over the type's records, a page of them at a time.
     *
     * @return array{slugs: array<string, true>, missing: array<string, array{count: int, names: list<string>}>, unplaced: array{count: int, names: list<string>}, areas: array<string, true>}
     */
    private function scan(TypeDefinition $def, TypeLayouts $type): array
    {
        $slugs = [];
        $missing = [];
        $unplaced = self::none();
        $areas = [];
        $watched = $type->pinned !== [];

        for ($offset = 0;; $offset += $this->pageSize) {
            $page = $this->data->queryIn($def)->orderBy($def->idField, SortDirection::Asc)->limit($this->pageSize)->offset($offset)->get()->items();
            foreach ($page as $record) {
                $values = $record->toArray();
                $slug = PageLayouts::slugOf($def, $type, $values);
                if ($watched && $slug !== null) {
                    $slugs[$slug] = true;
                }
                $label = $this->label($def, $record, $slug);

                $stored = $values[PageLayouts::LAYOUT] ?? null;
                $pin = $type->pinFor($slug);
                $pinUsed = $pin !== null && $this->registry->get($pin) !== null;
                if (is_string($stored) && $stored !== '' && !$pinUsed && !(in_array($stored, $type->allowed, true) && $this->registry->get($stored) !== null)) {
                    $missing[$stored] ??= self::none();
                    $this->add($missing[$stored], $label);
                }

                $notShown = $this->layouts->resolveValues($def, $values)?->placement->unplaced() ?? [];
                if ($notShown !== []) {
                    $this->add($unplaced, $label);
                    foreach ($notShown as $area => $_) {
                        $areas[$area] = true;
                    }
                }
            }
            if (count($page) < $this->pageSize) {
                break;
            }
        }

        return ['slugs' => $slugs, 'missing' => $missing, 'unplaced' => $unplaced, 'areas' => $areas];
    }

    /** @return array{count: int, names: list<string>} */
    private static function none(): array
    {
        return ['count' => 0, 'names' => []];
    }

    /** @param array{count: int, names: list<string>} $into */
    private function add(array &$into, string $label): void
    {
        $into['count']++;
        if (count($into['names']) < $this->named) {
            $into['names'][] = $label;
        }
    }

    /** @param array{count: int, names: list<string>} $pages */
    private function named(array $pages): string
    {
        $more = $pages['count'] - count($pages['names']);

        return implode(', ', $pages['names']) . ($more > 0 ? ' and ' . $more . ' more' : '');
    }

    private static function count(int $n, string $one, string $many): string
    {
        return $n === 1 ? "1 {$one}" : "{$n} {$many}";
    }

    /** "about (p1)": the slug, else the title, else the id; then the id. */
    private function label(TypeDefinition $def, DynamicRecord $record, ?string $slug): string
    {
        $id = (string) $record->getId();
        $name = $slug;
        if ($name === null && $def->titleField !== null) {
            $title = LocaleValue::pick($record->get($def->titleField), $def->locales, $def->locales->default);
            $name = is_scalar($title) && (string) $title !== '' ? (string) $title : null;
        }

        return $name === null ? $id : sprintf('%s (%s)', $name, $id);
    }
}
