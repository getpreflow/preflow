<?php

declare(strict_types=1);

namespace Preflow\Folio\Content;

use Preflow\Data\PaginatedResult;
use Preflow\Data\TypeDefinition;

/**
 * What the admin list's toolbar, chips, headers and pager show for one request: the search
 * term, the hidden inputs that keep the active filters and sort when the search form is sent,
 * the "+ Filter" menu (filters not in use), a removable chip per active filter, sort links per
 * column and the page links. Every link is built from ListQuery::query(), so it carries only
 * validated parameters (never "_partial").
 */
final class ListView
{
    /** Filter kinds the list can offer; other field types are never listed in "list.filters" by ListQuery. */
    private const array FILTER_TYPES = ['select', 'boolean', 'date', 'relation'];

    /**
     * @param array<string, mixed>  $listConfig the type's "list" block
     * @param array<string, string> $columns    field => header label
     * @param \Closure(string): bool $canView   may the admin view records of this type (relation filters)
     * @param bool                   $withFilterMenu false: leave the "+ Filter" entries out (and their option queries),
     *                                               for renders that never show the menu (the results partial)
     * @return array<string, mixed>
     */
    public static function build(
        ListQuery $query,
        TypeDefinition $def,
        array $listConfig,
        array $columns,
        PaginatedResult $result,
        string $base,
        RelationLabels $relations,
        \Closure $canView,
        bool $withFilterMenu = true,
    ): array {
        $params = $query->query();
        unset($params['page']);
        $href = static fn (array $p): string => $base . ($p === [] ? '' : '?' . http_build_query($p, '', '&', PHP_QUERY_RFC3986));

        $hidden = $params;
        unset($hidden['q']);

        $filters = [];
        $chips = [];
        foreach ((array) ($listConfig['filters'] ?? []) as $name) {
            if (!is_string($name) || !isset($def->fields[$name]) || !in_array($def->fields[$name]->type, self::FILTER_TYPES, true)) {
                continue;
            }
            $field = $def->fields[$name];
            $label = $field->label ?? (new \Preflow\Form\FieldRenderer())->humanize($name);
            $target = (string) ($field->config['relation']['to'] ?? '');
            if ($field->type === 'relation' && ($target === '' || !$canView($target))) {
                continue;
            }

            if (array_key_exists($name, $query->filters)) {
                $without = $params;
                unset($without['f'][$name]);
                if ($without['f'] === []) {
                    unset($without['f']);
                }
                $chips[] = [
                    'field' => $name,
                    'label' => $label . ': ' . self::chipValue($field->type, $field->config, $query->filters[$name], $relations, $target, RelationLabels::labelField($def, $name)),
                    'name' => $label,
                    'remove' => $href($without),
                ];
                continue;
            }
            if (!$withFilterMenu) {
                continue;
            }

            $filter = ['name' => $name, 'label' => $label, 'kind' => $field->type, 'options' => [], 'target' => $target];
            if ($field->type === 'select') {
                $filter['options'] = self::selectOptions($field->config);
            } elseif ($field->type === 'boolean') {
                $filter['options'] = ['1' => 'Yes', '0' => 'No'];
            } elseif ($field->type === 'relation') {
                $options = $relations->options($target, RelationLabels::labelField($def, $name));
                if ($options === null) {
                    $filter['kind'] = 'relation-search'; // too many to list: Task 9 searches remotely
                } else {
                    $filter['options'] = $options;
                }
            }
            $filters[] = $filter;
        }

        $headers = [];
        foreach ($columns as $name => $label) {
            $dir = $query->sort === $name ? ($query->desc ? 'desc' : 'asc') : null;
            $sortable = $def->fields[$name]->type !== 'relation';
            $next = $params;
            unset($next['sort']);
            if ($dir === null) {
                $next['sort'] = $name;
            } elseif ($dir === 'asc') {
                $next['sort'] = '-' . $name;
            } // desc: a third click returns to the list's own order
            $headers[$name] = ['label' => $label, 'dir' => $dir, 'href' => $sortable ? $href($next) : null];
        }

        $page = $result->currentPage;
        $pageHref = static fn (int $n): string => $href($n > 1 ? $params + ['page' => (string) $n] : $params);
        $pages = [];
        $previous = 0;
        for ($n = 1; $n <= $result->lastPage; $n++) {
            if ($n === 1 || $n === $result->lastPage || abs($n - $page) <= 2) {
                if ($previous > 0 && $n - $previous > 1) {
                    $pages[] = ['n' => null, 'href' => null, 'current' => false];
                }
                $pages[] = ['n' => $n, 'href' => $pageHref($n), 'current' => $n === $page];
                $previous = $n;
            }
        }
        $from = $result->total === 0 ? 0 : ($page - 1) * $result->perPage + 1;

        $clear = array_intersect_key($params, ['sort' => true]);

        return [
            'q' => $query->q,
            'base' => $base,
            'hidden' => self::flatten($hidden),
            'filters' => $filters,
            'chips' => $chips,
            'headers' => $headers,
            'sorted' => $query->sort !== null,
            'filtered' => $query->q !== '' || $query->filters !== [],
            'total' => $result->total,
            'from' => $from,
            'to' => $from === 0 ? 0 : $from + count($result->items) - 1,
            'page' => $page,
            'last_page' => $result->lastPage,
            'pages' => $pages,
            'prev' => $page > 1 ? $pageHref($page - 1) : null,
            'next' => $page < $result->lastPage ? $pageHref($page + 1) : null,
            'clear' => $href($clear),
        ];
    }

    /**
     * Form field names and values for nested parameters: f[status], f[due][from], f[tags][].
     *
     * @param array<string, mixed> $params
     * @return list<array{name: string, value: string}>
     */
    private static function flatten(array $params, string $prefix = ''): array
    {
        $out = [];
        foreach ($params as $key => $value) {
            $name = $prefix === '' ? (string) $key : $prefix . '[' . (is_int($key) ? '' : $key) . ']';
            if (is_array($value)) {
                array_push($out, ...self::flatten($value, $name));
            } else {
                $out[] = ['name' => $name, 'value' => (string) $value];
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $config
     * @param string|list<string>|array{from?: string, to?: string} $value
     */
    private static function chipValue(string $type, array $config, string|array $value, RelationLabels $relations, string $target, ?string $labelField): string
    {
        if ($type === 'select') {
            return self::selectOptions($config)[(string) $value] ?? (string) $value;
        }
        if ($type === 'boolean') {
            return $value === '1' ? 'Yes' : 'No';
        }
        if ($type === 'date') {
            $range = (array) $value;
            if (isset($range['from'], $range['to'])) {
                return $range['from'] . ' – ' . $range['to'];
            }

            return isset($range['from']) ? 'from ' . $range['from'] : 'until ' . ($range['to'] ?? '');
        }
        $ids = array_map('strval', (array) $value);
        $labels = $relations->labels($target, $ids, $labelField);

        return implode(', ', array_map(static fn (string $id) => $labels[$id] ?? $id, $ids));
    }

    /**
     * A select field's options as value => label ("options" is a map, or a list whose values are the labels).
     *
     * @param array<string, mixed> $config
     * @return array<string, string>
     */
    private static function selectOptions(array $config): array
    {
        $raw = (array) ($config['options'] ?? []);
        $out = [];
        foreach ($raw as $key => $label) {
            $out[array_is_list($raw) ? (string) $label : (string) $key] = (string) $label;
        }

        return $out;
    }
}
