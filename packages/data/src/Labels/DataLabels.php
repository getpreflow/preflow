<?php

declare(strict_types=1);

namespace Preflow\Data\Labels;

use Preflow\Data\FieldLabels;
use Preflow\Data\TypeDefinition;
use Preflow\Data\TypeFieldDefinition;
use Preflow\Data\TypeLabels;

/**
 * The names of types and fields for people, from where a site keeps them: the bound TypeLabels
 * (an admin package's display labels) and, where it implements FieldLabels, its field labels;
 * else the schema's own labels (DeclaredLabels). DataManager::labels() gives it.
 *
 * @since 0.93.0
 */
final class DataLabels
{
    public function __construct(
        private readonly ?TypeLabels $bound,
        private readonly DeclaredLabels $declared,
    ) {}

    public function type(string $type, string $locale): string
    {
        return ($this->bound ?? $this->declared)->label($type, $locale);
    }

    public function field(TypeFieldDefinition $field, string $locale, ?TypeDefinition $type = null): string
    {
        $source = $this->bound instanceof FieldLabels ? $this->bound : $this->declared;

        return $source->fieldLabel($field, $locale, $type);
    }
}
