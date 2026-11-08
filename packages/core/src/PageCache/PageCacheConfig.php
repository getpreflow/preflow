<?php

declare(strict_types=1);

namespace Preflow\Core\PageCache;

/**
 * The page cache settings of a site, config/page_cache.php. Every key is optional; the
 * defaults make the zero-configuration promise hold:
 *
 *   enabled            bool, false (an upgraded site opts in; the skeleton switches it on)
 *   max_age            int seconds, 14400 (4 h): the safety maximum age of every cached page
 *   rebuild_budget     float seconds, 2.0: after-response rebuilds stop after this per request
 *   cache_query        bool, true: pages with a query string (search, filters) are cached
 *   ignore_query       list of fnmatch() patterns of query parameters left out of the key
 *   vary_headers       list of request headers a cached fragment's key includes
 *   signed_in_cookies  list of cookie names that mean "signed in" (bypass the cache); the auth
 *                      marker and the guards' remember cookies are added by the wiring
 */
final readonly class PageCacheConfig
{
    public const int DEFAULT_MAX_AGE = 14400;

    public const float DEFAULT_REBUILD_BUDGET = 2.0;

    public const array DEFAULT_IGNORE_QUERY = ['utm_*', 'fbclid', 'gclid', 'mc_cid', 'mc_eid'];

    public const array DEFAULT_VARY_HEADERS = ['HX-Request', 'HX-Target'];

    /**
     * @param list<string> $ignoreQuery
     * @param list<string> $varyHeaders
     * @param list<string> $signedInCookies
     */
    public function __construct(
        public bool $enabled = false,
        public int $maxAge = self::DEFAULT_MAX_AGE,
        public float $rebuildBudget = self::DEFAULT_REBUILD_BUDGET,
        public bool $cacheQuery = true,
        public array $ignoreQuery = self::DEFAULT_IGNORE_QUERY,
        public array $varyHeaders = self::DEFAULT_VARY_HEADERS,
        public array $signedInCookies = [],
    ) {}

    /**
     * @param array<string, mixed> $config the array config/page_cache.php returns
     * @throws \InvalidArgumentException on a value of the wrong kind
     */
    public static function fromArray(array $config): self
    {
        return new self(
            enabled: self::bool($config, 'enabled', false),
            maxAge: self::seconds($config, 'max_age', self::DEFAULT_MAX_AGE),
            rebuildBudget: self::float($config, 'rebuild_budget', self::DEFAULT_REBUILD_BUDGET),
            cacheQuery: self::bool($config, 'cache_query', true),
            ignoreQuery: self::strings($config, 'ignore_query', self::DEFAULT_IGNORE_QUERY),
            varyHeaders: self::strings($config, 'vary_headers', self::DEFAULT_VARY_HEADERS),
            signedInCookies: self::strings($config, 'signed_in_cookies', []),
        );
    }

    /** @param array<string, mixed> $config */
    private static function bool(array $config, string $key, bool $default): bool
    {
        $value = $config[$key] ?? $default;
        if (is_bool($value) || $value === 0 || $value === 1 || $value === '0' || $value === '1') {
            return (bool) $value;
        }

        throw new \InvalidArgumentException("page_cache.{$key} must be true or false.");
    }

    /** @param array<string, mixed> $config */
    private static function seconds(array $config, string $key, int $default): int
    {
        $value = $config[$key] ?? $default;
        if (is_int($value) && $value >= 0) {
            return $value;
        }

        throw new \InvalidArgumentException("page_cache.{$key} must be a whole number of seconds (0 or more).");
    }

    /** @param array<string, mixed> $config */
    private static function float(array $config, string $key, float $default): float
    {
        $value = $config[$key] ?? $default;
        if ((is_int($value) || is_float($value)) && $value >= 0) {
            return (float) $value;
        }

        throw new \InvalidArgumentException("page_cache.{$key} must be a number of seconds (0 or more).");
    }

    /**
     * @param array<string, mixed> $config
     * @param list<string> $default
     * @return list<string>
     */
    private static function strings(array $config, string $key, array $default): array
    {
        $value = $config[$key] ?? $default;
        if (is_array($value)) {
            $list = [];
            foreach ($value as $item) {
                if (!is_string($item) || $item === '') {
                    throw new \InvalidArgumentException("page_cache.{$key} must be a list of non-empty strings.");
                }
                $list[] = $item;
            }

            return array_values(array_unique($list));
        }

        throw new \InvalidArgumentException("page_cache.{$key} must be a list of strings.");
    }
}
