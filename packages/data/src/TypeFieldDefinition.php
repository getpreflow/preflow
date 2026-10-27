<?php

declare(strict_types=1);

namespace Preflow\Data;

final readonly class TypeFieldDefinition
{
    /**
     * @param list<string> $validate
     * @param array<string, mixed> $config
     * @param array<string, string> $labels the label per language when the model file gives a map ("label": {"en": …, "de": …}); $label is then its "en" entry, else the first
     * @param array<string, string> $helps  the same for the help text
     * @param bool|list<string> $unique true, or the names of the fields that scope the uniqueness
     */
    public function __construct(
        public string $name,
        public string $type = 'string',
        public ?bool $searchable = null,
        public ?string $transform = null,
        public array $validate = [],
        public ?string $label = null,
        public ?string $help = null,
        public array $config = [],
        public bool $translatable = false,
        public array $labels = [],
        public array $helps = [],
        public bool|array $unique = false,
        public bool $index = false,
        public ?int $length = null,
    ) {}

    /** This field marked indexed (a unique field's scope field is stored as an indexed one). */
    public function withIndex(): self
    {
        return new self(
            $this->name, $this->type, $this->searchable, $this->transform, $this->validate, $this->label, $this->help,
            $this->config, $this->translatable, $this->labels, $this->helps, $this->unique, true, $this->length,
        );
    }
}
