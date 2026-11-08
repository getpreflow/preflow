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
 *   hosts              list of extra host names that are cached (lowercase, no port); the host of
 *                      app.url always is. A request for any other host (an alias domain, a forged
 *                      Host header) is never cached. No app.url and no hosts: nothing is cached.
 *   max_query_variants int, 50: at most this many pages with a query per URL path; the oldest
 *                      is evicted when another is stored
 *   max_pages          int, 50000: at most this many cached pages in a release; the oldest are
 *                      evicted first
 *   explain            bool, false: a miss that was not stored says why in X-Page-Cache-Skip
 *                      (for audits with curl -I; off, the reason is not sent to visitors)
 *   verify             bool, false: before a page is stored it is rendered once more as another
 *                      anonymous visitor (another address, no cookies); a page that differs
 *                      outside its live parts and per-request values is not stored, and logged.
 *                      For tests and a site's staging check: it doubles the cost of a miss.
 */
final readonly class PageCacheConfig
{
    public const int DEFAULT_MAX_AGE = 14400;

    public const float DEFAULT_REBUILD_BUDGET = 2.0;

    public const array DEFAULT_IGNORE_QUERY = ['utm_*', 'fbclid', 'gclid', 'mc_cid', 'mc_eid'];

    public const array DEFAULT_VARY_HEADERS = ['HX-Request', 'HX-Target'];

    public const int DEFAULT_MAX_QUERY_VARIANTS = 50;

    public const int DEFAULT_MAX_PAGES = 50000;

    /**
     * The cookie preflow/auth sets while someone is signed in (SessionGuard::SIGNED_IN_COOKIE):
     * always a signed-in cookie, with the guards' remember-me cookies (withSignedInCookies()).
     */
    public const string SIGNED_IN_MARKER = 'preflow_auth';

    /**
     * @param list<string> $ignoreQuery
     * @param list<string> $varyHeaders
     * @param list<string> $signedInCookies
     * @param list<string> $hosts lowercase host names
     */
    public function __construct(
        public bool $enabled = false,
        public int $maxAge = self::DEFAULT_MAX_AGE,
        public float $rebuildBudget = self::DEFAULT_REBUILD_BUDGET,
        public bool $cacheQuery = true,
        public array $ignoreQuery = self::DEFAULT_IGNORE_QUERY,
        public array $varyHeaders = self::DEFAULT_VARY_HEADERS,
        public array $signedInCookies = [],
        public array $hosts = [],
        public int $maxQueryVariants = self::DEFAULT_MAX_QUERY_VARIANTS,
        public int $maxPages = self::DEFAULT_MAX_PAGES,
        public bool $explain = false,
        public bool $verify = false,
    ) {}

    /**
     * This config with $names added to signed_in_cookies (the wiring adds the auth marker and
     * every guard's remember-me cookie; a site's own list adds to them, never replaces them).
     *
     * @param list<string> $names
     */
    public function withSignedInCookies(array $names): self
    {
        return new self(
            enabled: $this->enabled,
            maxAge: $this->maxAge,
            rebuildBudget: $this->rebuildBudget,
            cacheQuery: $this->cacheQuery,
            ignoreQuery: $this->ignoreQuery,
            varyHeaders: $this->varyHeaders,
            signedInCookies: array_values(array_unique([...$names, ...$this->signedInCookies])),
            hosts: $this->hosts,
            maxQueryVariants: $this->maxQueryVariants,
            maxPages: $this->maxPages,
            explain: $this->explain,
            verify: $this->verify,
        );
    }

    /** Whether pages of $host are cached: app.url's host or one in hosts. */
    public function cachesHost(string $host): bool
    {
        return in_array(strtolower($host), $this->hosts, true);
    }

    /**
     * @param array<string, mixed> $config the array config/page_cache.php returns
     * @param string|null $appUrl app.url: its host is cached
     * @throws \InvalidArgumentException on a value of the wrong kind
     */
    public static function fromArray(array $config, ?string $appUrl = null): self
    {
        $hosts = array_map(strtolower(...), self::strings($config, 'hosts', []));
        $appHost = is_string($appUrl) ? parse_url($appUrl, PHP_URL_HOST) : null;
        if (is_string($appHost) && $appHost !== '') {
            array_unshift($hosts, strtolower($appHost));
        }

        $enabled = self::bool($config, 'enabled', false);
        if ($enabled && $hosts === []) {
            self::warn($appUrl);
        }

        return new self(
            enabled: $enabled,
            maxAge: self::seconds($config, 'max_age', self::DEFAULT_MAX_AGE),
            rebuildBudget: self::float($config, 'rebuild_budget', self::DEFAULT_REBUILD_BUDGET),
            cacheQuery: self::bool($config, 'cache_query', true),
            ignoreQuery: self::strings($config, 'ignore_query', self::DEFAULT_IGNORE_QUERY),
            varyHeaders: self::strings($config, 'vary_headers', self::DEFAULT_VARY_HEADERS),
            signedInCookies: self::strings($config, 'signed_in_cookies', []),
            hosts: array_values(array_unique($hosts)),
            maxQueryVariants: self::count($config, 'max_query_variants', self::DEFAULT_MAX_QUERY_VARIANTS),
            maxPages: self::count($config, 'max_pages', self::DEFAULT_MAX_PAGES),
            explain: self::bool($config, 'explain', false),
            verify: self::bool($config, 'verify', false),
        );
    }

    /**
     * Logs once per process that a switched-on cache caches nothing: no host from app.url (missing,
     * or without a scheme, like "example.com") and no `hosts`.
     */
    private static function warn(?string $appUrl): void
    {
        static $warned = false;
        if ($warned) {
            return;
        }
        $warned = true;
        $reason = $appUrl === null || $appUrl === ''
            ? 'app.url is not set'
            : "app.url '{$appUrl}' has no scheme or host (write it like https://example.com)";
        error_log("The page cache is enabled but caches nothing: {$reason} and page_cache.hosts is empty.");
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
    private static function count(array $config, string $key, int $default): int
    {
        $value = $config[$key] ?? $default;
        if (is_int($value) && $value >= 1) {
            return $value;
        }

        throw new \InvalidArgumentException("page_cache.{$key} must be a whole number of 1 or more.");
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
