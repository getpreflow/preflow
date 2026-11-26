<?php

declare(strict_types=1);

namespace Preflow\Blocks\Layouts\Rules;

use Preflow\Blocks\Layouts\PageLayouts;
use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;
use Preflow\Data\TypeDefinition;
use Preflow\Validation\Message;
use Preflow\Validation\ValidationContext;
use Preflow\Validation\ValidationRule;

/**
 * The slug field of a type with pins: a stored page whose default-locale slug is pinned
 * ("layouts.pinned") keeps it; a new one is refused, naming the config entry to change first.
 * Other languages' slugs may change, and a save without the slug keeps it. Runs on drafts too
 * (RecordRules, drafts: true), so a renamed working copy never leaves its pin.
 *
 * @since 0.92.0
 */
final readonly class PinnedSlug implements ValidationRule
{
    public function __construct(
        private PageLayouts $layouts,
        private DataManager $data,
        private TypeDefinition $def,
    ) {}

    public function validate(mixed $value, ValidationContext $context): true|Message
    {
        $type = $this->layouts->forType($this->def->key);
        // Only the slug itself: a translatable field's other languages are validated as "slug.<locale>".
        if ($type === null || $type->pinned === [] || $context->field !== $type->slugField) {
            return true;
        }
        // The record's own values where the subject is one (the validator's data has a translatable
        // field reduced to its default-locale value, and a key the save does not write as null).
        $values = $context->subject instanceof DynamicRecord ? $context->subject->toArray() : $context->data;
        if (!array_key_exists($type->slugField, $values)) {
            return true; // a save without the slug keeps the stored one
        }
        $id = $context->data[$this->def->idField] ?? null;
        $stored = is_scalar($id) && (string) $id !== '' ? $this->data->findIn($this->def, (string) $id)?->toArray() : null;
        if ($stored === null) {
            return true;
        }
        $before = PageLayouts::slugOf($this->def, $type, $stored);
        $pin = $type->pinFor($before);
        if ($pin === null || PageLayouts::slugOf($this->def, $type, $values) === $before) {
            return true;
        }

        return new Message('validation.layout.pinned', ['layout' => $pin, 'slug' => (string) $before, 'type' => $this->def->key]);
    }
}
