<?php

declare(strict_types=1);

namespace Preflow\Data;

/**
 * The load-time checks of a select's "options_from" (0.89; in blocks since 0.90): the select takes
 * its options from another field of the same field set, a record type's fields or a block's. Kept
 * here, below every reader of field definitions, so a type (Folio's TypeCatalog) and a block
 * (BlockRegistry) give the same errors, each naming its own owner ("type", "block").
 *
 * @since 0.90.0
 */
final class SelectOptionsFrom
{
    /**
     * The "options_from" option against the field set's names: it names another field of the set,
     * the select has no "options" beside it, and it is not shown as "display": "cards".
     *
     * @param array<string, mixed> $config the select's field definition (or its config)
     * @param list<string>         $fields the names of the fields beside it (the select's own included)
     * @param string               $owner  what holds the fields, for the message: "type" or "block"
     * @throws \LogicException otherwise, with a message saying what to do instead
     */
    public static function check(array $config, string $name, array $fields, string $owner = 'type'): void
    {
        if (!array_key_exists('options_from', $config)) {
            return;
        }
        $source = $config['options_from'];
        if (!is_string($source) || $source === '') {
            throw new \LogicException('Select field option "options_from" must be the name of another field of this ' . $owner . '.');
        }
        if (array_key_exists('options', $config)) {
            throw new \LogicException('Select field has both "options" and "options_from"; keep one of them.');
        }
        if ($source === $name) {
            throw new \LogicException('Select field option "options_from" must name another field of this ' . $owner . ', not the select itself.');
        }
        if (!in_array($source, $fields, true)) {
            $others = array_values(array_filter($fields, static fn (string $field): bool => $field !== $name));
            throw new \LogicException('Select field option "options_from" names "' . $source . '", which is not a field of this ' . $owner . '; name one of its fields (' . implode(', ', $others) . ').');
        }
        self::checkDisplay($config);
    }

    /**
     * "display": "cards" needs fixed options with descriptions, so it never goes with "options_from".
     *
     * @param array<string, mixed> $config
     * @throws \LogicException
     */
    public static function checkDisplay(array $config): void
    {
        if (($config['display'] ?? null) === 'cards' && array_key_exists('options_from', $config)) {
            throw new \LogicException('Select field option "display": "cards" needs fixed "options" with descriptions; it cannot take "options_from". Use "select" or "radio".');
        }
    }
}
