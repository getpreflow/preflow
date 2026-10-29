<?php

declare(strict_types=1);

namespace Preflow\Data\Migration;

final class Table
{
    /** @var array<int, array{name: string, kind: string, length: ?int, nullable: bool, primary: bool, index: bool, unique: bool}> */
    private array $columns = [];

    /** @var list<array{columns: list<string>, unique: bool}> */
    private array $declared = [];

    public function __construct(
        private readonly string $name,
    ) {}

    public function uuid(string $name): self
    {
        return $this->addColumn($name, 'uuid');
    }

    public function string(string $name, int $length = 255): self
    {
        return $this->addColumn($name, 'string', $length);
    }

    public function text(string $name): self
    {
        return $this->addColumn($name, 'text');
    }

    public function integer(string $name): self
    {
        return $this->addColumn($name, 'integer');
    }

    public function json(string $name): self
    {
        return $this->addColumn($name, 'json');
    }

    public function boolean(string $name): self
    {
        return $this->addColumn($name, 'boolean');
    }

    public function timestamps(): self
    {
        $this->addColumn('created_at', 'timestamp');
        $this->columns[array_key_last($this->columns)]['nullable'] = true;
        $this->addColumn('updated_at', 'timestamp');
        $this->columns[array_key_last($this->columns)]['nullable'] = true;
        return $this;
    }

    public function nullable(): self
    {
        if ($this->columns !== []) {
            $this->columns[array_key_last($this->columns)]['nullable'] = true;
        }
        return $this;
    }

    public function primary(): self
    {
        if ($this->columns !== []) {
            $this->columns[array_key_last($this->columns)]['primary'] = true;
        }
        return $this;
    }

    /** Without arguments, indexes the last column; with columns, declares one index over them, in that order. */
    public function index(string ...$columns): self
    {
        return $this->markOrDeclare('index', $columns, false);
    }

    /** Without arguments, makes the last column unique; with columns, declares one unique index over them. */
    public function unique(string ...$columns): self
    {
        return $this->markOrDeclare('unique', $columns, true);
    }

    /**
     * Column definitions in the shape SchemaGrammar::createTable() takes.
     *
     * @return list<array{name: string, kind: string, length: ?int, nullable: bool, primary: bool}>
     */
    public function columns(): array
    {
        return array_map(static fn (array $col): array => [
            'name' => $col['name'],
            'kind' => $col['kind'],
            'length' => $col['length'],
            'nullable' => $col['nullable'],
            'primary' => $col['primary'],
        ], array_values($this->columns));
    }

    /**
     * @return string[]
     */
    public function getIndexes(): array
    {
        $indexes = [];
        foreach ($this->columns as $col) {
            if ($col['index'] && !$col['primary']) {
                $indexes[] = $col['name'];
            }
        }
        return $indexes;
    }

    /**
     * Every index to create: the column modifiers in column order, then the declared ones.
     *
     * @return list<array{columns: list<string>, unique: bool}>
     */
    public function getIndexDefinitions(): array
    {
        $definitions = [];
        foreach ($this->columns as $col) {
            if ($col['primary']) {
                continue;
            }
            if ($col['index']) {
                $definitions[] = ['columns' => [$col['name']], 'unique' => false];
            }
            if ($col['unique']) {
                $definitions[] = ['columns' => [$col['name']], 'unique' => true];
            }
        }
        return [...$definitions, ...$this->declared];
    }

    public function getName(): string
    {
        return $this->name;
    }

    /** @param string[] $columns */
    private function markOrDeclare(string $flag, array $columns, bool $unique): self
    {
        if ($columns !== []) {
            $this->declared[] = ['columns' => array_values($columns), 'unique' => $unique];
        } elseif ($this->columns !== []) {
            $this->columns[array_key_last($this->columns)][$flag] = true;
        }
        return $this;
    }

    private function addColumn(string $name, string $kind, ?int $length = null): self
    {
        $this->columns[] = [
            'name' => $name,
            'kind' => $kind,
            'length' => $length,
            'nullable' => false,
            'primary' => false,
            'index' => false,
            'unique' => false,
        ];
        return $this;
    }
}
