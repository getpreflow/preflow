<?php

declare(strict_types=1);

namespace Preflow\Folio\Content;

use Preflow\Data\PaginatedResult;
use Preflow\Data\QueryBuilder;
use Preflow\Data\SortDirection;
use Preflow\Data\TypeDefinition;
use Psr\Http\Message\ServerRequestInterface;

/**
 * What an admin list request asks for, read from the URL: "q" (search), "f[field]" (filters),
 * "sort" ("field" / "-field") and "page". Every field name is checked against the type and the
 * list's allow-lists ("list.filters", the list columns) before it can reach a query; values are
 * only ever bound. Anything unknown or malformed is dropped, never an error.
 */
final readonly class ListQuery
{
    public const int PER_PAGE = 50;

    /** Field types the admin list shows when "list.columns" is not set. */
    public const array LIST_TYPES = ['string', 'number', 'int', 'integer', 'float', 'select', 'boolean', 'date'];

    /** Upper bound for the requested page, so (page - 1) * PER_PAGE can never overflow an int. */
    private const int MAX_PAGE = 1_000_000;

    private const array NUMERIC_TYPES = ['number', 'int', 'integer', 'float'];

    /**
     * @param array<string, string|list<string>|array{from?: string, to?: string}> $filters field => value (date: from / to; relation: ids)
     * @param array<string, mixed>                                                  $listConfig the type's "list" block
     */
    private function __construct(
        public string $q,
        public array $filters,
        public ?string $sort,
        public bool $desc,
        public int $page,
        public bool $unchecked,
        private TypeDefinition $def,
        private array $listConfig,
        private string $locale,
    ) {}

    /**
     * The columns the admin list shows: "list.columns" (known fields only), else the first three
     * fields of a plain-valued type that are not hidden and not the id field.
     *
     * @param array<string, mixed> $listConfig
     * @return list<string>
     */
    public static function columns(TypeDefinition $def, array $listConfig): array
    {
        $names = array_values(array_filter(
            (array) ($listConfig['columns'] ?? []),
            static fn ($name) => is_string($name) && isset($def->fields[$name]),
        ));

        if ($names === []) {
            foreach ($def->fields as $name => $fieldDef) {
                if ($name !== $def->idField && in_array($fieldDef->type, self::LIST_TYPES, true) && ($fieldDef->config['hidden'] ?? false) !== true) {
                    $names[] = $name;
                }
                if (count($names) === 3) {
                    break;
                }
            }
        }

        return $names;
    }

    /** @param array<string, mixed> $listConfig */
    public static function fromRequest(ServerRequestInterface $request, TypeDefinition $def, array $listConfig, string $locale): self
    {
        $params = $request->getQueryParams();

        $q = is_string($params['q'] ?? null) ? mb_substr(trim($params['q']), 0, 200) : '';

        $filters = [];
        $raw = is_array($params['f'] ?? null) ? $params['f'] : [];
        foreach ((array) ($listConfig['filters'] ?? []) as $name) {
            if (!is_string($name) || !isset($def->fields[$name]) || !array_key_exists($name, $raw)) {
                continue;
            }
            $value = self::filterValue($def->fields[$name]->type, $raw[$name]);
            if ($value !== null) {
                $filters[$name] = $value;
            }
        }

        $sort = null;
        $desc = false;
        $rawSort = is_string($params['sort'] ?? null) ? $params['sort'] : '';
        if ($rawSort !== '') {
            $field = ltrim($rawSort, '-');
            if (
                in_array($field, self::columns($def, $listConfig), true)
                && !self::isRelation($def->fields[$field]->type)
            ) {
                $sort = $field;
                $desc = str_starts_with($rawSort, '-');
            }
        }

        $page = is_scalar($params['page'] ?? null) ? min(max(1, (int) $params['page']), self::MAX_PAGE) : 1;

        // "unchecked=1": only records holding machine translations nobody has checked yet.
        $unchecked = ($params['unchecked'] ?? null) === '1' && $def->translatableFields() !== [];

        return new self($q, $filters, $sort, $desc, $page, $unchecked, $def, $listConfig, $locale);
    }

    /** Search, filters, order and the requested page on the query. */
    public function apply(QueryBuilder $qb): QueryBuilder
    {
        $qb->locale($this->locale);

        if ($this->q !== '') {
            $qb->searchLoose($this->q);
        }

        foreach ($this->filters as $name => $value) {
            $type = $this->def->fields[$name]->type;
            if ($type === 'date') {
                $range = (array) $value;
                if (isset($range['from'])) {
                    $qb->where($name, '>=', $range['from']);
                }
                if (isset($range['to'])) {
                    // A date-only upper bound includes the whole day of date-time values.
                    $to = $range['to'];
                    $qb->where($name, '<=', strlen($to) === 10 ? $to . 'T23:59:59' : $to);
                }
            } elseif ($type === 'boolean') {
                $qb->where($name, '=', $value === '1');
            } elseif (self::isRelation($type)) {
                $multiple = (bool) (($this->def->fields[$name]->config['relation']['multiple'] ?? false));
                $qb->whereAny([['field' => $name, 'operator' => $multiple ? 'HAS' : 'IN', 'value' => (array) $value]]);
            } else {
                $qb->where($name, '=', $value);
            }
        }

        if ($this->sort !== null) {
            $numeric = in_array($this->def->fields[$this->sort]->type, self::NUMERIC_TYPES, true);
            $qb->orderBy($this->sort, $this->desc ? SortDirection::Desc : SortDirection::Asc, $numeric);
        } else {
            ListOrder::apply($qb, $this->def, $this->listConfig);
        }
        // The id as the last order: rows that tie (or a list without any order) keep one position, so
        // LIMIT / OFFSET pages and the export's chunks never repeat or skip a record. An orderable
        // list (position, then id) and a sort on the id already end on it.
        $orders = (fn (): array => $this->query->getOrderBy())->call($qb);
        if ($orders === [] || $orders[array_key_last($orders)]['field'] !== $this->def->idField) {
            $qb->orderBy($this->def->idField);
        }

        return $qb->limit(self::PER_PAGE)->offset(($this->page - 1) * self::PER_PAGE);
    }

    /**
     * Runs the query for the requested page; a page past the end gives the last page instead.
     * The returned result's currentPage is the page actually shown.
     */
    public function fetch(QueryBuilder $qb, ?\Closure $isUnchecked = null): PaginatedResult
    {
        if ($this->unchecked && $isUnchecked !== null) {
            return $this->fetchUnchecked($qb, $isUnchecked);
        }
        $this->apply($qb);
        $result = $qb->paginate(self::PER_PAGE, $this->page);
        if ($this->page > $result->lastPage) {
            $result = $qb->paginate(self::PER_PAGE, $result->lastPage);
        }

        return $result;
    }

    /**
     * The "unchecked" filter: whether a record holds machine translations nobody has checked yet
     * lives in a JSON value no storage driver can query, so the matches of the other filters are
     * read and narrowed here, then paged.
     *
     * @param \Closure(\Preflow\Data\DynamicRecord): bool $isUnchecked
     */
    private function fetchUnchecked(QueryBuilder $qb, \Closure $isUnchecked): PaginatedResult
    {
        $this->apply($qb);
        $qb->limit(self::MAX_PAGE * self::PER_PAGE)->offset(0);
        $items = array_values(array_filter($qb->get()->items(), $isUnchecked));

        $total = count($items);
        $last = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($this->page, $last);

        return new PaginatedResult(array_slice($items, ($page - 1) * self::PER_PAGE, self::PER_PAGE), $total, self::PER_PAGE, $page);
    }

    /**
     * The URL parameters of this request, for links (empty ones left out).
     *
     * @return array<string, mixed>
     */
    public function query(): array
    {
        $query = [];
        if ($this->q !== '') {
            $query['q'] = $this->q;
        }
        if ($this->filters !== []) {
            $query['f'] = $this->filters;
        }
        if ($this->unchecked) {
            $query['unchecked'] = '1';
        }
        if ($this->sort !== null) {
            $query['sort'] = ($this->desc ? '-' : '') . $this->sort;
        }
        if ($this->page > 1) {
            $query['page'] = (string) $this->page;
        }

        return $query;
    }

    private static function isRelation(string $type): bool
    {
        return $type === 'relation';
    }

    /** @return string|list<string>|array{from?: string, to?: string}|null null: not a usable filter value */
    private static function filterValue(string $type, mixed $raw): string|array|null
    {
        if ($type === 'date') {
            if (!is_array($raw)) {
                return null;
            }
            $range = [];
            foreach (['from', 'to'] as $edge) {
                $v = $raw[$edge] ?? null;
                if (is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}(T\d{2}:\d{2}(:\d{2})?)?$/', $v) === 1) {
                    $range[$edge] = $v;
                }
            }

            return $range === [] ? null : $range;
        }
        if ($type === 'boolean') {
            return $raw === '1' || $raw === '0' ? $raw : null;
        }
        if (self::isRelation($type)) {
            $ids = array_values(array_filter(
                array_map(static fn ($v) => is_string($v) || is_int($v) ? (string) $v : '', (array) $raw),
                static fn (string $v) => strlen($v) <= 128 && RecordId::usable($v),
            ));

            return $ids === [] ? null : $ids;
        }
        if ($type === 'select') {
            return is_string($raw) && $raw !== '' && strlen($raw) <= 255 ? $raw : null;
        }

        return null;
    }
}
