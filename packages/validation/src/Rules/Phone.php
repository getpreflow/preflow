<?php

declare(strict_types=1);

namespace Preflow\Validation\Rules;

use Preflow\Validation\Message;
use Preflow\Validation\ValidationContext;
use Preflow\Validation\ValidationRule;

/**
 * A phone number as people type it: digits, an optional leading "+", and spaces (also a
 * non-breaking or narrow non-breaking one), dashes, dots, slashes and parentheses between them; 3 to
 * 15 digits (short service numbers such as 112 or 1414 are numbers too; E.164 allows 15; no line
 * breaks or tabs). Only a string counts: a number as an int has lost its leading zero. An empty value
 * passes ("required" decides). dialable() is the same number for a tel: link.
 *
 * @since 0.91.0
 */
final class Phone implements ValidationRule
{
    /** The characters a typed number may hold besides its digits and the leading "+". */
    private const string SEPARATORS = '/^\+?[0-9 \x{00A0}\x{202F}\-.\/()]+$/u';

    public function validate(mixed $value, ValidationContext $context): true|Message
    {
        if ($value === null || $value === '') {
            return true;
        }

        return is_string($value) && self::dialable($value) !== null ? true : new Message('validation.phone');
    }

    /** "+41 44 123 45 67" as "+41441234567": the leading "+" and the digits; null when it is no phone number. */
    public static function dialable(string $typed): ?string
    {
        $typed = trim($typed);
        if ($typed === '' || preg_match(self::SEPARATORS, $typed) !== 1) {
            return null;
        }
        $digits = (string) preg_replace('/\D+/', '', $typed);
        $length = strlen($digits);
        if ($length < 3 || $length > 15) {
            return null;
        }

        return (str_starts_with($typed, '+') ? '+' : '') . $digits;
    }
}
