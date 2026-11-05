<?php

declare(strict_types=1);

namespace Preflow\View\Audit;

/**
 * The SEO audit's checks, in the order they were added, and the resolver that
 * tells which record or asset a page comes from. One per app (bound in the
 * container); packages and sites add their checks from a service provider, and
 * they run with the built-in ones.
 *
 * @since 0.54
 */
final class AuditRegistry
{
    /** @var list<PageCheck> */
    private array $pageChecks = [];

    /** @var list<SiteCheck> */
    private array $siteChecks = [];

    /** @var list<RunCheck> */
    private array $runChecks = [];

    /** @var (\Closure(string, string): ?Target)|null */
    private ?\Closure $targets = null;

    public function addPage(PageCheck $check): void
    {
        $this->pageChecks[] = $check;
    }

    public function addSite(SiteCheck $check): void
    {
        $this->siteChecks[] = $check;
    }

    public function addRun(RunCheck $check): void
    {
        $this->runChecks[] = $check;
    }

    /**
     * Where to fix a page's content: given its URL and locale, the record or asset
     * it shows, or null. Set by the admin (Folio); the last one set wins.
     *
     * @param \Closure(string $url, string $locale): ?Target $targets
     */
    public function setTargets(\Closure $targets): void
    {
        $this->targets = $targets;
    }

    /** @return list<PageCheck> */
    public function pageChecks(): array
    {
        return $this->pageChecks;
    }

    /** @return list<SiteCheck> */
    public function siteChecks(): array
    {
        return $this->siteChecks;
    }

    /** @return list<RunCheck> */
    public function runChecks(): array
    {
        return $this->runChecks;
    }

    /** The record or asset the page at $url ($locale) comes from; null when unknown or nobody resolves it. */
    public function target(string $url, string $locale): ?Target
    {
        return $this->targets !== null ? ($this->targets)($url, $locale) : null;
    }
}
