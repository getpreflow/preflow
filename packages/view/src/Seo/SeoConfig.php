<?php

declare(strict_types=1);

namespace Preflow\View\Seo;

/**
 * SEO settings from `config/seo.php`. Without the file, or with
 * `'enabled' => false`, SEO is off and nothing changes.
 */
final readonly class SeoConfig
{
    /**
     * @param array<string, string> $siteFields where name/description come from in Folio's `site` singleton
     * @param ?array{type: string, name: string, url: ?string, logo: ?string, same_as: list<string>} $owner
     * @param array<string, array{title: list<string>, description: list<string>, image: list<string>, og_type: string}> $types
     *        fields each type's title, description and share image are read from (first non-empty wins)
     * @param ?list<string>         $listedTypes types in the sitemap; null lists every public routed type
     * @param list<string>          $disallow    robots.txt Disallow paths
     * @param list<string>          $robotsExtra extra robots.txt lines
     * @param array<string, string> $themeColor  `'' => '#hex'`, or `'light'`/`'dark'` => '#hex'
     */
    public function __construct(
        public bool $enabled = false,
        public ?string $name = null,
        public array $siteFields = ['name' => 'name', 'description' => 'meta_description'],
        public ?array $owner = null,
        public array $types = [],
        public ?array $listedTypes = null,
        public bool $robotsEnabled = true,
        public array $disallow = ['/--component/', '/*/--component/'],
        public bool $aiTraining = true,
        public array $robotsExtra = [],
        public bool $sitemap = true,
        public ?string $securityContact = null,
        public ?string $securityPolicy = null,
        public array $themeColor = [],
        public bool $etag = true,
    ) {}

    /** Read a config file; a missing file means SEO is off. */
    public static function fromFile(string $path): self
    {
        if (!is_file($path)) {
            return new self();
        }

        return self::fromArray((array) require $path);
    }

    /** @param array<string, mixed> $config */
    public static function fromArray(array $config): self
    {
        $robots = (array) ($config['robots'] ?? []);
        $security = (array) ($config['security_txt'] ?? []);

        return new self(
            enabled: (bool) ($config['enabled'] ?? false),
            name: self::string($config['name'] ?? null),
            siteFields: array_map('strval', (array) ($config['site_fields'] ?? ['name' => 'name', 'description' => 'meta_description'])),
            owner: self::owner($config['owner'] ?? null),
            types: self::types($config['types'] ?? []),
            listedTypes: isset($config['listed_types']) ? self::strings($config['listed_types']) : null,
            robotsEnabled: (bool) ($robots['enabled'] ?? true),
            disallow: self::strings($robots['disallow'] ?? ['/--component/', '/*/--component/']),
            aiTraining: (bool) ($robots['ai_training'] ?? true),
            robotsExtra: self::strings($robots['extra'] ?? []),
            sitemap: (bool) ($config['sitemap'] ?? true),
            securityContact: self::string($security['contact'] ?? null),
            securityPolicy: self::string($security['policy'] ?? null),
            themeColor: self::themeColor($config['theme_color'] ?? null),
            etag: (bool) ($config['etag'] ?? true),
        );
    }

    public function enabled(): bool
    {
        return $this->enabled;
    }

    private static function string(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    /** @return list<string> */
    private static function strings(mixed $value): array
    {
        return array_values(array_filter(
            array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '', (array) $value),
            static fn (string $v): bool => $v !== '',
        ));
    }

    /** @return ?array{type: string, name: string, url: ?string, logo: ?string, same_as: list<string>} */
    private static function owner(mixed $owner): ?array
    {
        $name = is_array($owner) ? self::string($owner['name'] ?? null) : null;
        if ($name === null) {
            return null;
        }

        return [
            'type' => ($owner['type'] ?? null) === 'Person' ? 'Person' : 'Organization',
            'name' => $name,
            'url' => self::string($owner['url'] ?? null),
            'logo' => self::string($owner['logo'] ?? null),
            'same_as' => self::strings($owner['same_as'] ?? []),
        ];
    }

    /** @return array<string, array{title: list<string>, description: list<string>, image: list<string>, og_type: string}> */
    private static function types(mixed $types): array
    {
        $result = [];
        foreach ((array) $types as $type => $settings) {
            if (!is_array($settings)) {
                continue;
            }
            $result[(string) $type] = [
                'title' => self::strings($settings['title'] ?? []),
                'description' => self::strings($settings['description'] ?? []),
                'image' => self::strings($settings['image'] ?? []),
                'og_type' => ($settings['og_type'] ?? null) === 'article' ? 'article' : 'website',
            ];
        }

        return $result;
    }

    /** @return array<string, string> */
    private static function themeColor(mixed $value): array
    {
        if (is_string($value)) {
            return trim($value) === '' ? [] : ['' => trim($value)];
        }

        $colors = [];
        foreach (['light', 'dark'] as $scheme) {
            $color = self::string(is_array($value) ? ($value[$scheme] ?? null) : null);
            if ($color !== null) {
                $colors[$scheme] = trim($color);
            }
        }

        return $colors;
    }
}
