<?php

declare(strict_types=1);

namespace Preflow\Core\Http;

/**
 * The one rule for locale URL prefixes: which locale a path names, and the prefix a
 * locale gets. Built from config/i18n.php (`available`, `default`, `url_strategy`,
 * `prefix_default`, `redirect_bare`, `home_slash`). With the defaults the default locale has no
 * prefix ('/faq') and every other available locale has one ('/de/faq').
 */
final readonly class LocaleUrls
{
    /**
     * @param list<string> $locales every available locale, default first or not
     * @param bool $prefixed      url_strategy "prefix" (false: "none", no locale in URLs)
     * @param bool $prefixDefault the default locale gets a prefix too ('/en/faq')
     * @param bool $redirectBare  bare paths ('/faq') redirect to the visitor's locale
     * @param bool $homeSlash     a prefixed locale's root ends in a slash ('/de/', not '/de'); the
     *                            unprefixed default's root stays '/' (@since 0.78.2)
     */
    public function __construct(
        public array $locales,
        public string $default,
        public bool $prefixed = true,
        public bool $prefixDefault = false,
        public bool $redirectBare = false,
        public bool $homeSlash = false,
    ) {}

    /**
     * From config/i18n.php (null: none) and app.locale.
     *
     * @param array<string, mixed>|null $i18n
     * @throws \RuntimeException when a flag is not a bool, or is on while url_strategy is "none"
     */
    public static function fromConfig(?array $i18n, string $appLocale = 'en'): self
    {
        $i18n ??= [];
        $default = (string) ($i18n['default'] ?? $appLocale);
        $locales = array_values(array_map('strval', (array) ($i18n['available'] ?? [$default])));
        $prefixed = ($i18n['url_strategy'] ?? 'prefix') === 'prefix';

        $flags = [];
        foreach (['prefix_default', 'redirect_bare', 'home_slash'] as $key) {
            $value = $i18n[$key] ?? false;
            if (!is_bool($value)) {
                throw new \RuntimeException("config/i18n.php: {$key} must be true or false.");
            }
            if ($value && !$prefixed) {
                throw new \RuntimeException("config/i18n.php: {$key} needs url_strategy \"prefix\".");
            }
            $flags[$key] = $value;
        }

        return new self($locales, $default, $prefixed, $flags['prefix_default'], $flags['redirect_bare'], $flags['home_slash']);
    }

    /** '/de'; '' for the default unless prefixDefault; '' when not prefixed or for an unknown locale. */
    public function prefix(string $locale): string
    {
        if (!$this->prefixed || !in_array($locale, $this->locales, true)) {
            return '';
        }
        if ($locale === $this->default && !$this->prefixDefault) {
            return '';
        }

        return '/' . $locale;
    }

    /** '/de/faq'; '/de' for '/' or ''; '/faq' when the prefix is ''. $path is a path without prefix. */
    public function path(string $locale, string $path): string
    {
        $path = trim($path, '/');
        $prefix = $this->prefix($locale);

        if ($path === '') {
            if ($prefix === '') {
                return '/';
            }

            return $this->homeSlash ? $prefix . '/' : $prefix;
        }

        return $prefix . '/' . $path;
    }

    /**
     * Whether $path is a locale root in its configured form: '/', or a prefixed locale's root
     * ('/de', or '/de/' with homeSlash). '/de/' without homeSlash and '/de' with it are not.
     *
     * @since 0.78.2
     */
    public function isHome(string $path): bool
    {
        if ($path === '/') {
            return true;
        }

        foreach ($this->locales as $locale) {
            $prefix = $this->prefix($locale);
            if ($prefix !== '' && $path === ($this->homeSlash ? $prefix . '/' : $prefix)) {
                return true;
            }
        }

        return false;
    }

    /** The locale named by the first segment (prefixed sites only, any available locale), else null. */
    public function localeOf(string $path): ?string
    {
        if (!$this->prefixed) {
            return null;
        }

        $segment = explode('/', ltrim($path, '/'), 2)[0];

        return $segment !== '' && in_array($segment, $this->locales, true) ? $segment : null;
    }

    /** $path without its locale segment ('/' when only the prefix was there). */
    public function strip(string $path): string
    {
        $locale = $this->localeOf($path);
        if ($locale === null) {
            return $path;
        }

        $rest = substr(ltrim($path, '/'), strlen($locale));

        return $rest === '' || $rest === '/' ? '/' : $rest;
    }
}
