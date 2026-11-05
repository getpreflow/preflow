<?php

declare(strict_types=1);

namespace Preflow\View\Audit;

/**
 * The SEO audit's checks, in the order they were added, and the resolver that
 * tells which record or asset a page comes from. One per app (bound in the
 * container); packages and sites add their checks from a service provider, and
 * they run after the built-in ones. The built-in checks are added the first
 * time a list is read, so a request that never audits doesn't build them.
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

    /**
     * @param ?\Closure(self): bool $builtIn adds the built-in checks on the first read of a list; returns false
     *                                       when it can't yet (it is asked again on the next read)
     */
    public function __construct(
        private ?\Closure $builtIn = null,
    ) {}

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
        $this->addBuiltIn();

        return $this->pageChecks;
    }

    /** @return list<SiteCheck> */
    public function siteChecks(): array
    {
        $this->addBuiltIn();

        return $this->siteChecks;
    }

    /** @return list<RunCheck> */
    public function runChecks(): array
    {
        $this->addBuiltIn();

        return $this->runChecks;
    }

    /** The record or asset the page at $url ($locale) comes from; null when unknown or nobody resolves it. */
    public function target(string $url, string $locale): ?Target
    {
        return $this->targets !== null ? ($this->targets)($url, $locale) : null;
    }

    /** The built-in checks, ahead of those added so far. */
    private function addBuiltIn(): void
    {
        if ($this->builtIn === null) {
            return;
        }
        $register = $this->builtIn;
        $this->builtIn = null;
        [$page, $site, $run] = [$this->pageChecks, $this->siteChecks, $this->runChecks];
        $this->pageChecks = $this->siteChecks = $this->runChecks = [];

        try {
            if ($register($this) === false) {
                $this->builtIn = $register;
            }
        } finally {
            array_push($this->pageChecks, ...$page);
            array_push($this->siteChecks, ...$site);
            array_push($this->runChecks, ...$run);
        }
    }
}
