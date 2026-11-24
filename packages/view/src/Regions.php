<?php

declare(strict_types=1);

namespace Preflow\View;

/**
 * What every engine that renders regions (RendersRegions) prints and checks the same way: the
 * names a region may take, the element around a region on a full render, and the error for an
 * unknown region.
 *
 * @since 0.86.0
 */
final class Regions
{
    /** A region name: a letter, then letters, digits, - and _ (it ends an HTML id). */
    public const string NAME_PATTERN = '/^[A-Za-z][A-Za-z0-9_-]*$/';

    /** An attribute name the region element takes. */
    private const string ATTRIBUTE_PATTERN = '/^[A-Za-z_:][A-Za-z0-9_:.-]*$/';

    private function __construct()
    {
    }

    /** Whether $name is a valid region name. */
    public static function validName(string $name): bool
    {
        return preg_match(self::NAME_PATTERN, $name) === 1;
    }

    /**
     * The opening tag of the region $name: `<div id="{componentId}-{name}" …>`, its attributes
     * escaped. A true attribute prints its name only, false and null leave it out. The id is the
     * region's own; an `id` in $attributes is an error.
     *
     * @param array<string, mixed> $context the context the template was rendered with (componentId)
     * @throws \InvalidArgumentException no componentId in $context, a bad name, attribute or value
     */
    public static function open(array $context, string $name, mixed $attributes = []): string
    {
        $componentId = $context['componentId'] ?? null;
        if (!is_string($componentId) || $componentId === '') {
            throw new \InvalidArgumentException(sprintf("The region '%s' needs componentId in its template's context: regions belong to a component's template.", $name));
        }
        if (!self::validName($name)) {
            throw new \InvalidArgumentException(sprintf("'%s' is no region name (a letter, then letters, digits, - and _).", $name));
        }
        if (!is_array($attributes)) {
            throw new \InvalidArgumentException(sprintf("The attributes of the region '%s' are a mapping, %s given.", $name, get_debug_type($attributes)));
        }
        $html = '<div id="' . self::escape($componentId . '-' . $name) . '"';
        foreach ($attributes as $attribute => $value) {
            $attribute = (string) $attribute;
            if (preg_match(self::ATTRIBUTE_PATTERN, $attribute) !== 1 || strtolower($attribute) === 'id') {
                throw new \InvalidArgumentException(sprintf("The region '%s' can't take the attribute '%s'%s.", $name, $attribute, strtolower($attribute) === 'id' ? ' (its id is {componentId}-{name})' : ''));
            }
            if ($value === null || $value === false) {
                continue;
            }
            if ($value === true) {
                $html .= ' ' . $attribute;
                continue;
            }
            if (!is_scalar($value) && !$value instanceof \Stringable) {
                throw new \InvalidArgumentException(sprintf("The attribute '%s' of the region '%s' is a %s, not text.", $attribute, $name, get_debug_type($value)));
            }
            $html .= ' ' . $attribute . '="' . self::escape((string) $value) . '"';
        }

        return $html . '>';
    }

    /** The closing tag of a region element. */
    public static function close(): string
    {
        return '</div>';
    }

    /**
     * The error for a region $template doesn't have.
     *
     * @param list<string> $known the template's regions
     */
    public static function unknown(string $template, string $region, array $known): \InvalidArgumentException
    {
        return new \InvalidArgumentException(sprintf(
            "The template %s has no region '%s'; %s.",
            $template,
            $region,
            $known === [] ? 'it has no regions' : 'its regions: ' . implode(', ', $known),
        ));
    }

    /**
     * The error for a template with two regions of one name.
     */
    public static function duplicate(string $template, string $region): \InvalidArgumentException
    {
        return new \InvalidArgumentException(sprintf("The template %s has two regions named '%s': a region name is unique in its template.", $template, $region));
    }

    /** The error for rendering a region on $engine, which renders no regions. */
    public static function notRendered(object $engine): \LogicException
    {
        return new \LogicException(sprintf(
            '%s renders no regions (it does not implement %s): render the whole template, or use a template engine that renders regions.',
            get_debug_type($engine),
            RendersRegions::class,
        ));
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
