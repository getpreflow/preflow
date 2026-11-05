<?php

declare(strict_types=1);

namespace Preflow\View\Audit\Checks;

use Preflow\View\Audit\AuditRegistry;
use Preflow\View\Audit\RenderedPage;
use Preflow\View\Audit\RunCheck;
use Preflow\View\Audit\Severity;

/**
 * `hreflang.unpaired` (warning): a language version a page names (hreflang) that
 * isn't among the audited pages answering 200, or that doesn't name the page back.
 * x-default only has to exist. One finding per page and language version.
 *
 * @since 0.54
 */
final class HreflangUnpaired implements RunCheck
{
    /** @var array<string, array{u: string, l: string, a: array<string, string>}> URL key => URL, locale, alternates */
    private array $pages = [];

    public function __construct(private readonly AuditRegistry $registry) {}

    public function id(): string
    {
        return 'hreflang.unpaired';
    }

    public function collect(RenderedPage $page): void
    {
        $this->pages[Urls::key($page->url)] = ['u' => $page->url, 'l' => $page->locale, 'a' => Html::alternates($page)];
    }

    public function finish(): array
    {
        $findings = [];
        foreach ($this->pages as $key => $page) {
            foreach ($page['a'] as $lang => $href) {
                $other = Urls::key($href);
                if ($other === $key) {
                    continue;
                }
                if (!isset($this->pages[$other])) {
                    $message = "The {$lang} version {$href} isn't a public page answering 200; point hreflang at the page's address in that language or remove it.";
                } elseif ($lang !== 'x-default' && !$this->namesBack($this->pages[$other]['a'], $key)) {
                    $message = "The {$lang} version {$href} doesn't link back to this page with hreflang; name each other in both pages.";
                } else {
                    continue;
                }
                $findings[] = Html::finding($this->registry, $this->id(), Severity::Warning, $message, $page['u'], $page['l']);
            }
        }

        return $findings;
    }

    public function state(): array
    {
        return $this->pages;
    }

    public function restore(array $state): void
    {
        $this->pages = [];
        foreach ($state as $key => $page) {
            if (is_array($page) && is_string($page['u'] ?? null) && is_array($page['a'] ?? null)) {
                $this->pages[(string) $key] = [
                    'u' => $page['u'],
                    'l' => (string) ($page['l'] ?? ''),
                    'a' => array_map('strval', $page['a']),
                ];
            }
        }
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
