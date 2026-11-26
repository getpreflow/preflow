<?php

declare(strict_types=1);

namespace Preflow\Blocks\Layouts;

/**
 * A named set of layouts a package offers (the columns block's column layouts). resolve() always
 * gives a usable one, so a stored key the set no longer has still renders.
 *
 * @since 0.92.0
 */
final class AreaLayoutSet
{
    /** @var array<string, AreaLayout> */
    private array $byKey = [];

    /** @param list<AreaLayout> $layouts */
    public function __construct(public readonly string $name, array $layouts, private readonly ?string $fallback = null)
    {
        foreach ($layouts as $layout) {
            $this->byKey[$layout->key] = $layout;
        }
    }

    public function get(?string $key): ?AreaLayout
    {
        return $key === null ? null : ($this->byKey[$key] ?? null);
    }

    /**
     * The layout for a stored key: that layout; else the first with the same number of areas (a key
     * of numbers joined by "-", such as "2-1", names its count); else the fallback; else the first.
     * Null only for an empty set.
     */
    public function resolve(?string $key): ?AreaLayout
    {
        $found = $this->get($key);
        if ($found !== null) {
            return $found;
        }
        $count = self::countOf($key);
        if ($count !== null) {
            foreach ($this->byKey as $layout) {
                if (count($layout->areas) === $count) {
                    return $layout;
                }
            }
        }

        return $this->get($this->fallback) ?? (array_values($this->byKey)[0] ?? null);
    }

    /** @return list<AreaLayout> */
    public function all(): array
    {
        return array_values($this->byKey);
    }

    private static function countOf(?string $key): ?int
    {
        return $key !== null && preg_match('/^\d+(-\d+)*$/', $key) === 1 ? substr_count($key, '-') + 1 : null;
    }
}
