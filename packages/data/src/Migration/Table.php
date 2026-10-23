<?php

declare(strict_types=1);

namespace Preflow\Data\Migration;

final class Table
{
    /** @var array<int, array{name: string, kind: string, length: ?int, nullable: bool, primary: bool, index: bool}> */
    private array $columns = [];

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

    public function index(): self
    {
        if ($this->columns !== []) {
            $this->columns[array_key_last($this->columns)]['index'] = true;
        }
        return $this;
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

    public function getName(): string
    {
        return $this->name;
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
        ];
        return $this;
    }
}
