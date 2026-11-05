<?php

declare(strict_types=1);

namespace Preflow\Folio\Audit;

use Preflow\Folio\Content\FrontendResolver;
use Preflow\View\Audit\Target;

/**
 * Where to fix an SEO audit finding, for the audit's registry (AuditRegistry::setTargets()):
 * a page's URL is the record it shows (FrontendResolver::match(), as a visitor's request finds
 * it), a file under the media URL ({media}/{Y}/{m}/{id}…: the original or one of its variants)
 * is its asset. Anything else has no target.
 *
 * A non-default locale's "/de" prefix is taken off before the page is looked up, as
 * FrontendResolver::urlFor() puts it on. A lookup that fails (storage not reachable, a table
 * not migrated yet) is no target: the finding still stands, only without the link.
 *
 * @since 0.54
 */
final class AuditTargets
{
    /** A file under the media URL: {Y}/{m}/{32-hex id}, then the original's extension or a variant's suffix. */
    private const string MEDIA_FILE = '#^\d{4}/\d{2}/([a-f0-9]{32})(?:[-.][^/]*)?$#';

    private ?FrontendResolver $resolver = null;

    private ?string $mediaUrl = null;

    /** @var array<string, ?Target> url and locale => target, for findings on the same page */
    private array $seen = [];

    /**
     * @param \Closure(): FrontendResolver $resolverFactory built on the first page looked up
     * @param \Closure(): string           $mediaUrlFactory the media core's URL (config/media.php "url"), read on first use
     * @param string                       $defaultLocale   the site's default language (its URLs carry no prefix)
     */
    public function __construct(
        private readonly \Closure $resolverFactory,
        private readonly \Closure $mediaUrlFactory,
        private readonly string $defaultLocale,
    ) {}

    /** The record or asset behind $url (an absolute URL or a path) in $locale; null when there is none. */
    public function target(string $url, string $locale): ?Target
    {
        $key = $locale . ' ' . $url;
        if (!array_key_exists($key, $this->seen)) {
            $this->seen[$key] = $this->find($url, $locale);
        }

        return $this->seen[$key];
    }

    private function find(string $url, string $locale): ?Target
    {
        if ($url === '') {
            return null;
        }
        $path = parse_url($url, PHP_URL_PATH);
        $path = is_string($path) && $path !== '' ? $path : '/';

        return $this->asset($path) ?? $this->page($path, $locale);
    }

    private function asset(string $path): ?Target
    {
        $this->mediaUrl ??= rtrim(($this->mediaUrlFactory)(), '/');
        if ($this->mediaUrl === '' || !str_starts_with($path, $this->mediaUrl . '/')) {
            return null;
        }

        return preg_match(self::MEDIA_FILE, substr($path, strlen($this->mediaUrl) + 1), $m) === 1
            ? new Target('asset', 'asset', $m[1])
            : null;
    }

    private function page(string $path, string $locale): ?Target
    {
        if ($locale !== '' && $locale !== $this->defaultLocale
            && preg_match('#^/' . preg_quote($locale, '#') . '(/.*)?$#', $path, $m) === 1) {
            $path = ($m[1] ?? '') === '' ? '/' : $m[1];
        }
        $lookup = $locale !== '' ? $locale : null;

        try {
            $this->resolver ??= ($this->resolverFactory)();
            // match() compares route values unencoded: "/%C3%BCber-uns" is the page "über-uns".
            $found = $this->resolver->match($path, $lookup)
                ?? ($path !== rawurldecode($path) ? $this->resolver->match(rawurldecode($path), $lookup) : null);
        } catch (\Throwable) {
            return null;
        }

        return $found === null ? null : new Target('record', $found->route->type, (string) $found->record->getId());
    }
}
