<?php

declare(strict_types=1);

namespace Preflow\Form;

use Preflow\View\SafeHtml;

/**
 * A field's `call` option: the SafeHtml a short call returns (`hd.post(...)`), an attribute string
 * the field renderer writes as it is into the control's opening tag.
 *
 * @internal
 */
final class FieldCall
{
    /**
     * The call of a field's options, or null without one. Anything but SafeHtml (or null) is refused.
     */
    public static function of(string $field, mixed $call): ?SafeHtml
    {
        if ($call === null || $call instanceof SafeHtml) {
            return $call;
        }

        throw new \InvalidArgumentException(sprintf(
            "Field '%s': call takes the result of a short call (hd.post(...), a SafeHtml), %s given. "
            . 'Plain attributes go in attrs.',
            $field,
            self::describe($call),
        ));
    }

    /**
     * The attribute names a call writes, lower-cased, in order.
     *
     * @return list<string>
     */
    public static function attributeNames(SafeHtml $call): array
    {
        preg_match_all(
            '/([^\s"\'=<>\/`]+)(?:\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s"\'=<>`]+))?/',
            $call->html,
            $matches,
        );

        return array_values(array_unique(array_map('strtolower', $matches[1])));
    }

    /**
     * The names in $names that $attrs also writes (an attribute set to false or null is left out, so it
     * writes nothing).
     *
     * @param list<string>         $names
     * @param array<string, mixed> $attrs
     * @return list<string>
     */
    public static function overlap(array $names, array $attrs): array
    {
        $written = [];
        foreach ($attrs as $key => $value) {
            if ($value !== false && $value !== null) {
                $written[strtolower((string) $key)] = true;
            }
        }

        return array_values(array_filter($names, static fn (string $name): bool => isset($written[$name])));
    }

    private static function describe(mixed $value): string
    {
        return match (true) {
            is_object($value) => $value::class,
            is_string($value) => 'a string',
            is_array($value)  => 'an array',
            is_int($value)    => 'an int',
            is_float($value)  => 'a float',
            is_bool($value)   => 'a bool',
            default           => get_debug_type($value),
        };
    }
}
