<?php

declare(strict_types=1);

namespace Preflow\Blocks\Layouts\Rules;

use Preflow\Blocks\Layouts\PageLayouts;
use Preflow\Data\DataManager;
use Preflow\Data\TypeDefinition;
use Preflow\Validation\Message;
use Preflow\Validation\ValidationContext;
use Preflow\Validation\ValidationRule;

/**
 * "_layout" of a page: a changed key must be one the type allows (on a pinned page: its pin). The
 * key stored on the page passes unchanged, also when the type no longer allows it or its class is
 * gone (the page renders with the default, PageLayouts), so such a page stays savable; no key
 * (null, "") is the default.
 *
 * @since 0.92.0
 */
final readonly class LayoutChoice implements ValidationRule
{
    public function __construct(
        private PageLayouts $layouts,
        private DataManager $data,
        private TypeDefinition $def,
    ) {}

    public function validate(mixed $value, ValidationContext $context): true|Message
    {
        $type = $this->layouts->forType($this->def->key);
        if ($type === null || $value === null || $value === '') {
            return true;
        }
        $pin = $type->pinFor(PageLayouts::slugOf($this->def, $type, $context->data));
        $allowed = $pin !== null ? [$pin] : $type->allowed;
        if (is_string($value) && in_array($value, $allowed, true)) {
            return true;
        }
        if (is_string($value) && $value === ($this->stored($context)[PageLayouts::LAYOUT] ?? null)) {
            return true;
        }

        return new Message('validation.layout.choice', [
            'key' => is_scalar($value) ? (string) $value : (string) json_encode($value),
            'allowed' => implode(', ', $allowed),
        ]);
    }

    /** @return array<string, mixed>|null the stored record's values */
    private function stored(ValidationContext $context): ?array
    {
        $id = $context->data[$this->def->idField] ?? null;

        return is_scalar($id) && (string) $id !== '' ? $this->data->findIn($this->def, (string) $id)?->toArray() : null;
    }
}
