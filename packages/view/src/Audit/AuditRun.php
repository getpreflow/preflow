<?php

declare(strict_types=1);

namespace Preflow\View\Audit;

use Preflow\View\Seo\PublicPages;

/**
 * One run of the SEO audit, in batches. It audits the site's public pages
 * (every locale) one by one: renders each, runs the page checks and feeds the
 * run checks on pages that answer 2xx with HTML, and reports a redirect as
 * `status.redirect` (warning) and any other status as `status.error`
 * (critical). A check that fails is reported once, with the number of pages it
 * failed on, and the run goes on. Progress goes into the report file as it
 * goes, and the run stops itself before its time budget; the next run
 * continues where it stopped. Once every page is done, the run checks report and the site checks
 * run, and the report is complete.
 *
 * A run starts over when asked to ($fresh), when the last run was complete, or
 * when there is no report to continue (none, unreadable, or for another site).
 * The run checks' finish() and the site checks run after the last page
 * whatever the budget. Only one full run at a time: a second one throws
 * AuditRunning. Quick checks ($limit, $onlyUrl) start their own run, take no
 * lock and leave the report file alone, so a saved run can still be continued
 * (and the admin only ever shows full runs).
 *
 * @since 0.54
 */
final class AuditRun
{
    /** Save progress at least this often (seconds of work); a killed run loses no more. */
    private const SAVE_EVERY = 2.0;

    /** A failed check's error is cut to this many characters. */
    private const ERROR_LENGTH = 200;

    /** How a page check's failure finding starts; the number is the pages it failed on. */
    private const FAILED = '/^The check failed on (\d+) pages?/';

    /** @var array<string, array{0: int, 1: int}>|null check id => [index of its failure finding, pages]; null until read */
    private ?array $failures = null;

    /**
     * @param string   $file the report file (AuditReport::file($basePath))
     * @param \Closure(): \DateTimeImmutable $now the clock
     */
    public function __construct(
        private readonly AuditRegistry $registry,
        private readonly PageRenderer $renderer,
        private readonly PublicPages $pages,
        private readonly string $file,
        private readonly \Closure $now,
    ) {}

    /**
     * Audit pages until all are done or the time budget would be passed (at least one page per run).
     *
     * @param int     $timeBudgetSeconds stop before this much time has passed
     * @param ?int    $limit             a quick check of the first $limit public pages; the report file is left alone
     * @param ?string $onlyUrl           a quick check of this one page (path or URL), without the site checks; the
     *                                   report file is left alone
     * @param bool    $fresh             start over even when the last run can be continued
     * @param ?\Closure(int $done, int $total, string $url): void $progress called after each page
     *
     * @throws AuditRunning when another full run of this site is going on
     */
    public function run(int $timeBudgetSeconds, ?int $limit, ?string $onlyUrl, bool $fresh, ?\Closure $progress): AuditReport
    {
        $save = $onlyUrl === null && $limit === null;
        $lock = $save ? $this->lock() : null;
        try {
            return $this->audit($timeBudgetSeconds, $limit, $onlyUrl, $fresh, $progress, $save);
        } finally {
            if ($lock !== null) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    /** @param ?\Closure(int, int, string): void $progress */
    private function audit(int $timeBudgetSeconds, ?int $limit, ?string $onlyUrl, bool $fresh, ?\Closure $progress, bool $save): AuditReport
    {
        $start = $this->seconds();

        if ($onlyUrl !== null) {
            $report = $this->start([[self::path($onlyUrl), '']]);
        } else {
            $saved = $fresh || !$save ? null : AuditReport::load($this->file);
            $report = $saved !== null && !$saved->complete() && $saved->origin === $this->renderer->origin()
                ? $this->continue($saved)
                : $this->start($this->queue($limit));
        }
        $this->failures = null;

        $lastSave = $start;
        $longest = 0.0; // the slowest page so far: the next one may take as long
        $audited = 0;
        while ($report->cursor < $report->total) {
            if ($audited > 0 && $this->seconds() - $start + $longest > $timeBudgetSeconds) {
                break;
            }
            $began = $this->seconds();
            [$path, $locale] = $report->queue[$report->cursor];
            $page = $this->auditPage($report, $path, $locale);
            $report->cursor++;
            $audited++;
            $ended = $this->seconds();
            $longest = max($longest, $ended - $began);

            if ($progress !== null) {
                $progress($report->cursor, $report->total, $page->url);
            }
            if ($save && $ended - $lastSave >= self::SAVE_EVERY && $report->cursor < $report->total) {
                $this->keepRunState($report);
                $report->save($this->file);
                $lastSave = $ended;
            }
        }

        if ($report->cursor >= $report->total) {
            $this->finish($report, siteChecks: $onlyUrl === null);
        } else {
            $this->keepRunState($report);
        }
        if ($save) {
            $report->save($this->file);
        }

        return $report;
    }

    /**
     * The lock that keeps a second full run away: held (non-blocking) on the report's .lock file.
     *
     * @return resource
     */
    private function lock()
    {
        $dir = dirname($this->file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $lock = @fopen($this->file . '.lock', 'c');
        if ($lock === false) {
            throw new \RuntimeException("Can't open the SEO audit's lock file: {$this->file}.lock");
        }
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            throw AuditRunning::at($this->file);
        }

        return $lock;
    }

    /**
     * Every public page once, in each locale's order, as [path, locale]; the first $limit when given.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function queue(?int $limit): array
    {
        $queue = [];
        $seen = [];
        foreach ($this->renderer->locales() as $locale) {
            foreach ($this->pages->for($locale) as $page) {
                $path = self::path($page->url);
                if (!isset($seen[$path])) {
                    $seen[$path] = true;
                    $queue[] = [$path, $locale];
                }
            }
        }

        return $limit !== null ? array_slice($queue, 0, max(0, $limit)) : $queue;
    }

    /** @param list<array{0: string, 1: string}> $queue */
    private function start(array $queue): AuditReport
    {
        $report = new AuditReport(($this->now)()->format(DATE_ATOM), $this->renderer->origin());
        $report->queue = $queue;
        $report->total = count($queue);
        foreach ($this->registry->runChecks() as $check) {
            self::restore($check, []);
        }

        return $report;
    }

    private function continue(AuditReport $report): AuditReport
    {
        $report->total = count($report->queue);
        $report->cursor = min(max(0, $report->cursor), $report->total);
        foreach ($this->registry->runChecks() as $check) {
            self::restore($check, $report->run[$check->id()] ?? []);
        }

        return $report;
    }

    /**
     * Give a run check its state; one that can't take it starts fresh.
     *
     * @param array<array-key, mixed> $state
     */
    private static function restore(RunCheck $check, array $state): void
    {
        try {
            $check->restore($state);
        } catch (\Throwable) {
            if ($state === []) {
                return;
            }
            try {
                $check->restore([]);
            } catch (\Throwable) {
                // Nothing left to fall back to; it reports what it can.
            }
        }
    }

    /** Render one page and add what the checks found to the report. */
    private function auditPage(AuditReport $report, string $path, string $locale): RenderedPage
    {
        $page = $this->renderer->render($path);
        if ($locale !== '' && $locale !== $page->locale) {
            $page = new RenderedPage($page->url, $locale, $page->status, $page->headers, $page->body);
        }

        if ($page->status >= 300 && $page->status <= 399) {
            $location = $page->header('Location');
            $report->findings[] = new Finding(
                'status.redirect',
                Severity::Warning,
                $location !== ''
                    ? "The page redirects ({$page->status}) to {$location}. List the address it ends at among the public pages instead."
                    : "The page redirects ({$page->status}) without saying where. List the address it ends at among the public pages instead.",
                $page->url,
                $page->locale,
            );

            return $page;
        }
        if ($page->status < 200 || $page->status > 299) {
            $report->findings[] = new Finding(
                'status.error',
                Severity::Critical,
                "The page answers {$page->status} in this environment instead of 200, so visitors and search engines can't read it. Fix the page or take it off the public pages.",
                $page->url,
                $page->locale,
            );

            return $page;
        }
        if ($page->dom() === null) {
            return $page;
        }

        foreach ($this->registry->pageChecks() as $check) {
            array_push($report->findings, ...$this->guarded($report, $check->id(), fn (): array => $check->check($page), $page));
        }
        foreach ($this->registry->runChecks() as $check) {
            array_push($report->findings, ...$this->guarded($report, $check->id(), function () use ($check, $page): array {
                $check->collect($page);

                return [];
            }, $page));
        }

        return $page;
    }

    /** The run checks' reports and (unless a one-page check) the site checks; the report is complete. */
    private function finish(AuditReport $report, bool $siteChecks): void
    {
        foreach ($this->registry->runChecks() as $check) {
            array_push($report->findings, ...$this->guarded($report, $check->id(), $check->finish(...)));
        }
        if ($siteChecks) {
            foreach ($this->registry->siteChecks() as $check) {
                array_push($report->findings, ...$this->guarded($report, $check->id(), fn (): array => $check->check($this->renderer)));
            }
        }

        $report->run = [];
        $report->queue = [];
        $report->cursor = $report->total;
        $report->finishedAt = ($this->now)()->format(DATE_ATOM);
    }

    private function keepRunState(AuditReport $report): void
    {
        $report->run = [];
        foreach ($this->registry->runChecks() as $check) {
            $report->run[$check->id()] = $check->state();
        }
    }

    /**
     * What a check found. A check that fails is reported as a warning, so one broken check
     * doesn't stop the audit: once per page check (its count of pages goes up each time it
     * fails again, the first failure's page and error kept), once for a run's or the site's.
     *
     * @param \Closure(): list<Finding> $check
     * @return list<Finding>
     */
    private function guarded(AuditReport $report, string $id, \Closure $check, ?RenderedPage $page = null): array
    {
        try {
            return array_values($check());
        } catch (\Throwable $e) {
            $error = $e->getMessage();
            if (mb_strlen($error) > self::ERROR_LENGTH) {
                $error = mb_substr($error, 0, self::ERROR_LENGTH - 1) . '…';
            }
            $error .= ' (' . $e::class . ')';

            if ($page === null) {
                return [new Finding($id, Severity::Warning, "The check failed and was skipped: {$error}")];
            }

            return $this->failedOnPage($report, $id, $error, $page);
        }
    }

    /**
     * Fold a page check's failure into its one finding (also one from before a continued run).
     *
     * @return list<Finding> the finding to add, if it is the check's first failure
     */
    private function failedOnPage(AuditReport $report, string $id, string $error, RenderedPage $page): array
    {
        if ($this->failures === null) {
            $this->failures = [];
            foreach ($report->findings as $index => $finding) {
                if ($finding->severity === Severity::Warning && preg_match(self::FAILED, $finding->message, $m) === 1) {
                    $this->failures[$finding->check] = [$index, (int) $m[1]];
                }
            }
        }

        if (!isset($this->failures[$id])) {
            $this->failures[$id] = [count($report->findings), 1];

            return [new Finding($id, Severity::Warning, "The check failed on 1 page and was skipped there: {$error}", $page->url, $page->locale)];
        }

        [$index, $count] = $this->failures[$id];
        $count++;
        $this->failures[$id] = [$index, $count];
        $first = $report->findings[$index];
        $report->findings[$index] = new Finding(
            $first->check,
            $first->severity,
            (string) preg_replace(self::FAILED, "The check failed on {$count} pages", $first->message, 1),
            $first->url,
            $first->locale,
            $first->target,
        );

        return [];
    }

    /** The path of a page's URL (absolute or a path), without query or fragment. */
    private static function path(string $url): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);

        return '/' . ltrim($path, '/');
    }

    /** The clock, in seconds. */
    private function seconds(): float
    {
        return (float) ($this->now)()->format('U.u');
    }
}
