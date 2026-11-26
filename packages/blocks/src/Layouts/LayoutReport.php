<?php

declare(strict_types=1);

namespace Preflow\Blocks\Layouts;

use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;
use Preflow\Data\LocaleValue;
use Preflow\Data\SortDirection;
use Preflow\Data\TypeDefinition;
use Preflow\Data\TypeRegistry;

/**
 * What needs a person's attention in the page layouts of a site, as lines `preflow migrate` and
 * `db:check` print (MigrationRegistry::warning(), registered as "blocks.layouts"; usable without
 * either): for each type with "layouts", an allowed layout no class declares, a pin whose page does
 * not exist yet, pages on a layout that is not available, and pages with blocks in areas their
 * layout does not show. None of it is an error: such pages render with the default layout and keep
 * every block.
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
    ) {}

    /**
     * @return list<string> one line each, in English; [] when nothing needs attention
     */
    public function lines(): array
    {
        $lines = [];
        foreach ($this->types->keys() as $key) {
            try {
                $type = $this->layouts->forType($key);
                if ($type !== null) {
                    array_push($lines, ...$this->typeLines($this->types->get($key), $type));
                }
            } catch (LayoutConfigException $e) {
                $lines[] = $e->getMessage();
            }
        }

        return $lines;
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
        $unplaced = ['count' => 0, 'names' => []];
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

    /** @param array{count?: int, names?: list<string>}|null $into */
    private function add(?array &$into, string $label): void
    {
        $into ??= ['count' => 0, 'names' => []];
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
