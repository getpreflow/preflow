<?php

declare(strict_types=1);

namespace Preflow\Data;

/** The content locales configured for an app (config/i18n.php). */
final readonly class Locales
{
    /** @var list<string> */
    public array $available;

    public string $fallback;

    /** @param list<string> $available */
    public function __construct(
        array $available,
        public string $default,
        ?string $fallback = null,
    ) {
        $list = array_values(array_unique(array_map('strval', $available)));
        if (!in_array($default, $list, true)) {
            array_unshift($list, $default);
        }
        $this->available = $list;
        $this->fallback = $fallback !== null && in_array($fallback, $list, true) ? $fallback : $default;
    }

    public static function single(string $locale): self
    {
        return new self([$locale], $locale);
    }

    /** Minimal set for comparing values in one locale against a default (drivers). */
    public static function fromPair(string $locale, string $default): self
    {
        return new self([$default, $locale], $default);
    }

    public function isMultilingual(): bool
    {
        return count($this->available) > 1;
    }

    public function has(string $locale): bool
    {
        return in_array($locale, $this->available, true);
    }

    /**
     * Locales to try for a value, in order: the locale, the fallback, the default.
     *
     * @return list<string>
     */
    public function chain(string $locale): array
    {
        $chain = $this->has($locale) ? [$locale] : [];
        $chain[] = $this->fallback;
        $chain[] = $this->default;

        return array_values(array_unique($chain));
    }
}
