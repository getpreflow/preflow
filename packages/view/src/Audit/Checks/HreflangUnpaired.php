<?php

declare(strict_types=1);

namespace Preflow\View\Audit\Checks;

use Preflow\View\Audit\AuditRegistry;
use Preflow\View\Audit\RenderedPage;
use Preflow\View\Audit\RunCheck;
use Preflow\View\Audit\Severity;
use Preflow\View\Seo\PublicPages;

/**
 * `hreflang.unpaired` (warning): a language version a page names (hreflang) that
 * isn't one of the site's public pages, or (when both pages were audited) doesn't
 * name the page back. x-default only has to be a public page. One finding per
 * page and language version. A language version that is public but wasn't
 * audited (a quick check, or it answered other than 200 and has its own
 * status finding) isn't reported here.
 *
 * @since 0.54
 */
final class HreflangUnpaired implements RunCheck
{
    /** @var array<string, array{u: string, l: string, a: array<string, string>}> URL key => URL, locale, alternates */
    private array $collected = [];

    /** @param list<string> $locales the site's locales, whose public pages count as language versions */
    public function __construct(
        private readonly AuditRegistry $registry,
        private readonly PublicPages $pages,
        private readonly array $locales,
    ) {}

    public function id(): string
    {
        return 'hreflang.unpaired';
    }

    public function collect(RenderedPage $page): void
    {
        $this->collected[Urls::key($page->url)] = ['u' => $page->url, 'l' => $page->locale, 'a' => Html::alternates($page)];
    }

    public function finish(): array
    {
        $public = null; // the public pages' URL keys, read on first need
        $findings = [];
        foreach ($this->collected as $key => $page) {
            foreach ($page['a'] as $lang => $href) {
                $other = Urls::key($href);
                if ($other === $key) {
                    continue;
                }
                if (isset($this->collected[$other])) {
                    if ($lang === 'x-default' || $this->namesBack($this->collected[$other]['a'], $key)) {
                        continue;
                    }
                    $message = "The {$lang} version {$href} doesn't link back to this page with hreflang; name each other in both pages.";
                } else {
                    $public ??= $this->publicKeys($page['u']);
                    if (isset($public[$other])) {
                        continue;
                    }
                    $message = "The {$lang} version {$href} isn't one of the site's public pages; point hreflang at the page's address in that language or remove it.";
                }
                $findings[] = Html::finding($this->registry, $this->id(), Severity::Warning, $message, $page['u'], $page['l']);
            }
        }

        return $findings;
    }

    public function state(): array
    {
        return $this->collected;
    }

    public function restore(array $state): void
    {
        $this->collected = [];
        foreach ($state as $key => $page) {
            if (is_array($page) && is_string($page['u'] ?? null) && is_array($page['a'] ?? null)) {
                $this->collected[(string) $key] = [
                    'u' => $page['u'],
                    'l' => (string) ($page['l'] ?? ''),
                    'a' => array_map('strval', $page['a']),
                ];
            }
        }
    }

    /**
     * The URL keys of the public pages in every locale (one source call per locale).
     *
     * @return array<string, true>
     */
    private function publicKeys(string $base): array
    {
        $keys = [];
        foreach ($this->locales as $locale) {
            foreach ($this->pages->for($locale) as $page) {
                $url = Urls::resolve($page->url, $base);
                if ($url !== null) {
                    $keys[Urls::key($url)] = true;
                }
            }
        }

        return $keys;
    }

    /** @param array<string, string> $alternates */
    private function namesBack(array $alternates, string $key): bool
    {
        foreach ($alternates as $lang => $href) {
            if ($lang !== 'x-default' && Urls::key($href) === $key) {
                return true;
            }
        }

        return false;
    }
}
