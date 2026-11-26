<?php

declare(strict_types=1);

namespace Preflow\Fields\Values;

/**
 * The value of a colour field: "#rrggbb", or "#rrggbbaa" with an alpha channel, always lowercase.
 * It is stored as that text.
 *
 * parse() reads what people type: with or without the "#", upper or lower case, and the short
 * forms "#rgb" and "#rgba" ("#C00" is "#cc0000"). Anything else is no colour (null).
 *
 * @since 0.91.0
 */
final readonly class Color
{
    /** @param string $hex "#rrggbb" or "#rrggbbaa", lowercase */
    private function __construct(
        public string $hex,
    ) {}

    /** The colour of $text, or null when it is none. */
    public static function parse(mixed $text): ?self
    {
        if (!is_string($text)) {
            return null;
        }
        $digits = strtolower(ltrim(trim($text), '#'));
        if (preg_match('/^(?:[0-9a-f]{3,4}|[0-9a-f]{6}|[0-9a-f]{8})$/', $digits) !== 1) {
            return null;
        }
        if (strlen($digits) <= 4) {
            $digits = implode('', array_map(static fn (string $c): string => $c . $c, str_split($digits)));
        }

        return new self('#' . $digits);
    }

    /** The colour a stored value holds; null for an empty value or text that is no colour. */
    public static function fromStored(mixed $stored): ?self
    {
        return self::parse($stored);
    }

    public function toStored(): string
    {
        return $this->hex;
    }

    /** The alpha channel as 0 to 1 (1 without one). */
    public function alpha(): float
    {
        return strlen($this->hex) === 9 ? round(hexdec(substr($this->hex, 7, 2)) / 255, 3) : 1.0;
    }

    /** The colour without its alpha channel: "#rrggbb". */
    public function opaque(): string
    {
        return substr($this->hex, 0, 7);
    }
}
