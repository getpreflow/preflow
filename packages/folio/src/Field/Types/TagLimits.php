<?php

declare(strict_types=1);

namespace Preflow\Folio\Field\Types;

use Preflow\Folio\FolioText;
use Preflow\Validation\ValidationContext;
use Preflow\Validation\ValidationRule;

/**
 * The tag field's limits (0.77): at most $max tags, each at most $maxLength characters. It judges
 * the record's value as the field stores it (the JSON list) or as a list; an empty field passes
 * ("required" judges that).
 */
final class TagLimits implements ValidationRule
{
    public function __construct(
        public readonly int $max,
        public readonly int $maxLength,
        private readonly TagsFieldType $type = new TagsFieldType(),
    ) {}

    public function validate(mixed $value, ValidationContext $context): true|string
    {
        $tags = $this->type->fromStorage($value);
        if (count($tags) > $this->max) {
            return FolioText::get('folio.fields.tags_too_many', ['max' => $this->max]);
        }
        foreach ($tags as $tag) {
            if (mb_strlen($tag) > $this->maxLength) {
                return FolioText::get('folio.fields.tags_too_long', ['max' => $this->maxLength]);
            }
        }

        return true;
    }
}
