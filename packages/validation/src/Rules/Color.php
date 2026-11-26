<?php

declare(strict_types=1);

namespace Preflow\Validation\Rules;

use Preflow\Validation\Message;
use Preflow\Validation\ValidationContext;
use Preflow\Validation\ValidationRule;

/**
 * A colour as hex text: "#rrggbb", or "#rrggbbaa" with an alpha channel (upper or lower case; the
 * colour field stores it lowercase). With $palette only those colours pass ("palette only"):
 * they are compared as colours, so "#C00000" matches "#c00000". An empty value passes
 * ("required" decides).
 *
 * @since 0.91.0
 */
final class Color implements ValidationRule
{
    /** @param list<string>|null $palette the colours allowed, as hex text; null: any colour */
    public function __construct(
        private readonly ?array $palette = null,
    ) {}

    public function validate(mixed $value, ValidationContext $context): true|Message
    {
        if ($value === null || $value === '') {
            return true;
        }
        if (!is_string($value) || preg_match('/^#(?:[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', $value) !== 1) {
            return new Message('validation.color');
        }
        if ($this->palette !== null && !in_array(strtolower($value), array_map('strtolower', $this->palette), true)) {
            return new Message('validation.color_palette', ['values' => implode(', ', $this->palette)]);
        }

        return true;
    }
}
