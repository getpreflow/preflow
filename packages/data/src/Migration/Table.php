<?php

declare(strict_types=1);

namespace Preflow\Data\Migration;

final class Table
{
    /** @var array<int, array{name: string, type: string, nullable: bool, primary: bool, index: bool}> */
    private array $columns = [];

    public function __construct(
        private readonly string $name,
    ) {}

    public function uuid(string $name): self
    {
        return $this->addColumn($name, 'TEXT');
    }

    public function string(string $name, int $length = 255): self
    {
        return $this->addColumn($name, 'TEXT');
    }

    public function text(string $name): self
    {
        return $this->addColumn($name, 'TEXT');
    }

    public function integer(string $name): self
    {
        return $this->addColumn($name, 'INTEGER');
    }

    public function json(string $name): self
    {
        return $this->addColumn($name, 'TEXT');
    }

    public function boolean(string $name): self
    {
        return $this->addColumn($name, 'INTEGER');
    }

    public function timestamps(): self
    {
        $this->addColumn('created_at', 'TEXT');
        $this->columns[array_key_last($this->columns)]['nullable'] = true;
        $this->addColumn('updated_at', 'TEXT');
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
     * Generate the CREATE TABLE SQL.
     */
    public function toSql(): string
    {
        $parts = [];

        foreach ($this->columns as $col) {
            $def = "\"{$col['name']}\" {$col['type']}";

            if ($col['primary']) {
                $def .= ' PRIMARY KEY';
            }

            if (!$col['nullable'] && !$col['primary']) {
                $def .= ' NOT NULL';
            }

            $parts[] = $def;
        }

        $sql = "CREATE TABLE \"{$this->name}\" (\n    " . implode(",\n    ", $parts) . "\n)";

        return $sql;
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

    private function addColumn(string $name, string $type): self
    {
        $this->columns[] = [
            'name' => $name,
            'type' => $type,
            'nullable' => false,
            'primary' => false,
            'index' => false,
        ];
        return $this;
    }
}
