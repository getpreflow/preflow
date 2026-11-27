<?php

declare(strict_types=1);

namespace Preflow\Data\Values\Core;

use Preflow\Data\TypeFieldDefinition;
use Preflow\Data\Values\CoreValues;
use Preflow\Data\Values\DescribesShapes;
use Preflow\Data\Values\FieldValue;

/**
 * A "tags" field's value, shape "string_list".
 *
 * Stored: a list, its JSON text or a comma string as the tags: trimmed, without control
 * characters and empty ones, in NFC, each once (case ignored, the first spelling kept); a tag
 * cannot hold a comma, the comma separates tags. Nothing is cut: a list over a limit stays so for
 * the rules to refuse. No tags is null (no value), so "required" refuses an empty field. A value
 * that is no list or text passes through. (Folio's TagsFieldType::normalizeInput() and
 * toStorage() of 0.92, moved.)
 *
 * Output: the stored list (JSON text on SQL, a list in a JSON file or class model), or a legacy
 * comma string split into its parts, each trimmed; nothing stored is [].
 *
 * @since 0.93.0
 */
final class TagsValue implements FieldValue, DescribesShapes
{
    public function toStored(mixed $input, TypeFieldDefinition $field): mixed
    {
        if ($input === null) {
            return null;
        }
        if (is_string($input) && str_starts_with(trim($input), '[')) {
            $decoded = json_decode(trim($input), true);
            $input = is_array($decoded) ? $decoded : $input;
        }
        if (is_string($input)) {
            $input = explode(',', $input);
        }
        if (!is_array($input)) {
            return $input;
        }
        $out = [];
        $seen = [];
        foreach ($input as $item) {
            if (!is_string($item) && !is_int($item) && !is_float($item)) {
                continue;
            }
            $tag = self::nfc(trim((string) preg_replace('/\p{Cc}+/u', '', (string) $item)));
            if ($tag === '') {
                continue;
            }
            $fold = mb_strtolower($tag);
            if (isset($seen[$fold])) {
                continue;
            }
            $seen[$fold] = true;
            $out[] = $tag;
        }

        return $out === [] ? null : $out;
    }

    /** @return list<string> */
    public function toOutput(mixed $stored, TypeFieldDefinition $field): array
    {
        if (is_string($stored)) {
            $trimmed = trim($stored);
            if ($trimmed === '') {
                return [];
            }
            if (str_starts_with($trimmed, '[')) {
                $decoded = json_decode($trimmed, true);
                if (is_array($decoded)) {
                    $stored = $decoded;
                }
            }
            if (is_string($stored)) {
                $stored = explode(',', $stored);
            }
        }
        if (!is_array($stored)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn ($v): string => is_string($v) || is_int($v) || is_float($v) ? trim((string) $v) : '', $stored),
            static fn (string $v): bool => $v !== '',
        ));
    }

    public function shape(TypeFieldDefinition $field): string
    {
        return 'string_list';
    }

    public function shapes(): array
    {
        return CoreValues::shapes('string_list');
    }

    private static function nfc(string $tag): string
    {
        if ($tag === '' || !class_exists(\Normalizer::class)) {
            return $tag;
        }
        $normal = \Normalizer::normalize($tag, \Normalizer::FORM_C);

        return is_string($normal) ? $normal : $tag;
    }
}
