<?php

declare(strict_types=1);

namespace Preflow\Data;

final class Query
{
    /** @var array<int, array{field: string, operator: string, value: mixed, boolean: string}> */
    private array $wheres = [];

    /** @var array<int, array{field: string, direction: SortDirection}> */
    private array $orderBy = [];

    private ?int $limit = null;
    private ?int $offset = null;
    private ?string $searchTerm = null;
    /** @var string[] */
    private array $searchFields = [];

    public function where(string $field, mixed $operator, mixed $value = null): self
    {
        if ($value === null) {
            $value = $operator;
            $operator = '=';
        }

        $this->wheres[] = [
            'field' => $field,
            'operator' => $operator,
            'value' => $value,
            'boolean' => 'AND',
        ];

        return $this;
    }

    public function orWhere(string $field, mixed $operator, mixed $value = null): self
    {
        if ($value === null) {
            $value = $operator;
            $operator = '=';
        }

        $this->wheres[] = [
            'field' => $field,
            'operator' => $operator,
            'value' => $value,
            'boolean' => 'OR',
        ];

        return $this;
    }

    public function orderBy(string $field, SortDirection $direction = SortDirection::Asc): self
    {
        $this->orderBy[] = [
            'field' => $field,
            'direction' => $direction,
        ];

        return $this;
    }

    public function limit(int $limit): self
    {
        $this->limit = $limit;
        return $this;
    }

    public function offset(int $offset): self
    {
        $this->offset = $offset;
        return $this;
    }

    public function search(string $term, array $fields = []): self
    {
        $this->searchTerm = $term;
        $this->searchFields = $fields;
        return $this;
    }

    /** @return array<int, array{field: string, operator: string, value: mixed, boolean: string}> */
    public function getWheres(): array { return $this->wheres; }

    /** @return array<int, array{field: string, direction: SortDirection}> */
    public function getOrderBy(): array { return $this->orderBy; }

    public function getLimit(): ?int { return $this->limit; }
    public function getOffset(): ?int { return $this->offset; }
    public function getSearchTerm(): ?string { return $this->searchTerm; }
    /** @return string[] */
    public function getSearchFields(): array { return $this->searchFields; }
}
