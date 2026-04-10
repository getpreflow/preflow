<?php

declare(strict_types=1);

namespace Preflow\I18n;

final class Translator
{
    private string $locale;

    /** @var array<string, array<string, mixed>> Cache: "locale.group" => translations */
    private array $loaded = [];

    public function __construct(
        private readonly string $langPath,
        string $locale,
        private readonly string $fallbackLocale,
    ) {
        $this->locale = $locale;
    }

    /**
     * Translate a key with optional parameter replacement.
     *
     * @param array<string, string|int> $params Parameters to replace (:name => value)
     */
    public function get(string $key, array $params = []): string
    {
        $value = $this->resolve($key, $this->locale);

        // Fallback to default locale
        if ($value === null && $this->locale !== $this->fallbackLocale) {
            $value = $this->resolve($key, $this->fallbackLocale);
        }

        // Key not found — return the key itself
        if ($value === null) {
            return $key;
        }

        if (!is_string($value)) {
            return $key;
        }

        return $this->replaceParams($value, $params);
    }

    /**
     * Translate with pluralization.
     *
     * @param array<string, string|int> $params
     */
    public function choice(string $key, int $count, array $params = []): string
    {
        $value = $this->resolve($key, $this->locale);

        if ($value === null && $this->locale !== $this->fallbackLocale) {
            $value = $this->resolve($key, $this->fallbackLocale);
        }

        if ($value === null || !is_string($value)) {
            return $key;
        }

        $resolver = new PluralResolver();
        $resolved = $resolver->resolve($value, $count);

        $params['count'] = (string) $count;

        return $this->replaceParams($resolved, $params);
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function setLocale(string $locale): void
    {
        $this->locale = $locale;
    }

    /**
     * Resolve a dot-notation key to its value.
     */
    private function resolve(string $key, string $locale): mixed
    {
        $parts = explode('.', $key, 2);
        $group = $parts[0];
        $subKey = $parts[1] ?? null;

        $translations = $this->loadGroup($locale, $group);

        if ($subKey === null) {
            return $translations[$group] ?? null;
        }

        // Support nested keys: "nested.deep"
        $segments = explode('.', $subKey);
        $current = $translations;

        foreach ($segments as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return null;
            }
            $current = $current[$segment];
        }

        return $current;
    }

    /**
     * @return array<string, mixed>
     */
    private function loadGroup(string $locale, string $group): array
    {
        $cacheKey = "{$locale}.{$group}";

        if (isset($this->loaded[$cacheKey])) {
            return $this->loaded[$cacheKey];
        }

        $path = $this->langPath . '/' . $locale . '/' . $group . '.php';

        if (!file_exists($path)) {
            $this->loaded[$cacheKey] = [];
            return [];
        }

        $translations = require $path;

        if (!is_array($translations)) {
            $this->loaded[$cacheKey] = [];
            return [];
        }

        $this->loaded[$cacheKey] = $translations;

        return $translations;
    }

    /**
     * @param array<string, string|int> $params
     */
    private function replaceParams(string $message, array $params): string
    {
        foreach ($params as $key => $value) {
            $message = str_replace(':' . $key, (string) $value, $message);
        }

        return $message;
    }
}
