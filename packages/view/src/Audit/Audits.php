<?php

declare(strict_types=1);

namespace Preflow\View\Audit;

use Preflow\View\Seo\PublicPages;

/**
 * The SEO audit as a service: one step of the saved full audit at a time, the
 * quick checks, the saved report, and whether a full run is going on. The
 * console command (seo:audit) and the admin (Folio) both run the audit through
 * it. A step is an AuditRun with a time budget: it continues the saved run
 * where the last step stopped (or starts over when asked, when the last run was
 * complete or when there is none), saves its progress and returns the report;
 * steps one after another complete it. Only one full run at a time: a step
 * while another holds the lock throws AuditRunning. Pages are rendered in the
 * app's internal render scopes, so mail a page queues while it renders is
 * dropped, never sent, also when the step runs inside a web request.
 *
 * One per app (bound in the container next to the PageRenderer, built on first
 * use, so a request that never audits doesn't build the checks).
 *
 * @since 0.74
 */
final class Audits
{
    /** @var \Closure(): \DateTimeImmutable */
    private readonly \Closure $now;

    /**
     * @param string $file the report file (AuditReport::file($basePath))
     * @param ?\Closure(): \DateTimeImmutable $now the clock (default: the system's)
     */
    public function __construct(
        private readonly AuditRegistry $registry,
        private readonly PageRenderer $renderer,
        private readonly PublicPages $pages,
        private readonly string $file,
        ?\Closure $now = null,
    ) {
        $this->now = $now ?? static fn (): \DateTimeImmutable => new \DateTimeImmutable();
    }

    /**
     * One step of the full audit: audits pages until all are done or the time budget would be
     * passed (at least one page), saves the report and returns it.
     *
     * @param bool $fresh start over even when the saved run can be continued
     * @param ?\Closure(int $done, int $total, string $url): void $progress called after each page
     *
     * @throws AuditRunning when another full run of this site is going on
     */
    public function step(int $timeBudgetSeconds, bool $fresh = false, ?\Closure $progress = null): AuditReport
    {
        return $this->run()->run($timeBudgetSeconds, null, null, $fresh, $progress);
    }

    /**
     * A quick check: the first $limit public pages, or the one page at $url (path or URL, without
     * the site checks). It takes no lock and leaves the saved report alone.
     *
     * @param ?\Closure(int $done, int $total, string $url): void $progress called after each page
     */
    public function quick(?int $limit, ?string $url, int $timeBudgetSeconds, ?\Closure $progress = null): AuditReport
    {
        if ($limit === null && $url === null) {
            throw new \InvalidArgumentException('A quick check needs a limit or a URL; a full run is step().');
        }

        return $this->run()->run($timeBudgetSeconds, $limit, $url, false, $progress);
    }

    /** The saved report; null when there is none (or it can't be read). */
    public function report(): ?AuditReport
    {
        return AuditReport::load($this->file);
    }

    /**
     * Whether another full run holds the lock right now. Never blocks and never keeps the lock
     * (it is taken for the probe only, when free); without a lock file nothing is running.
     */
    public function running(): bool
    {
        $file = AuditRun::lockFile($this->file);
        if (!is_file($file)) {
            return false;
        }
        $lock = @fopen($file, 'r');
        if ($lock === false) {
            return false;
        }
        try {
            if (flock($lock, LOCK_EX | LOCK_NB)) {
                flock($lock, LOCK_UN);

                return false;
            }

            return true;
        } finally {
            fclose($lock);
        }
    }

    /** The report file. */
    public function file(): string
    {
        return $this->file;
    }

    private function run(): AuditRun
    {
        return new AuditRun($this->registry, $this->renderer, $this->pages, $this->file, $this->now);
    }
}
