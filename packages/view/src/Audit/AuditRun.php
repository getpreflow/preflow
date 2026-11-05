<?php

declare(strict_types=1);

namespace Preflow\View\Audit;

use Preflow\View\Seo\PublicPages;

/**
 * One run of the SEO audit, in batches. It audits the site's public pages
 * (every locale) one by one: renders each, runs the page checks and feeds the
 * run checks on pages that answer 2xx with HTML, and reports any other status
 * as `status.error`. Progress goes into the report file as it goes, and the run
 * stops itself before its time budget; the next run continues where it
 * stopped. Once every page is done, the run checks report and the site checks
 * run, and the report is complete.
 *
 * A run starts over when asked to ($fresh), for a limited run ($limit), when
 * the last run was complete, or when there is no report to continue (none,
 * unreadable, or for another site). The run checks' finish() and the site
 * checks run after the last page whatever the budget.
 *
 * @since 0.54
 */
final class AuditRun
{
    /** Save progress at least this often (seconds of work); a killed run loses no more. */
    private const SAVE_EVERY = 2.0;

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
     * @param ?int    $limit             audit only the first $limit public pages (starts over)
     * @param ?string $onlyUrl           audit this one page (path or URL) without the site checks; the report
     *                                   file is left alone, so a saved run can still be continued
     * @param bool    $fresh             start over even when the last run can be continued
     * @param ?\Closure(int $done, int $total, string $url): void $progress called after each page
     */
    public function run(int $timeBudgetSeconds, ?int $limit, ?string $onlyUrl, bool $fresh, ?\Closure $progress): AuditReport
    {
        $start = $this->seconds();

        if ($onlyUrl !== null) {
            $report = $this->start([[self::path($onlyUrl), '']]);
        } else {
            $saved = $fresh || $limit !== null ? null : AuditReport::load($this->file);
            $report = $saved !== null && !$saved->complete() && $saved->origin === $this->renderer->origin()
                ? $this->continue($saved)
                : $this->start($this->queue($limit));
        }
        $save = $onlyUrl === null;

        $lastSave = $start;
        $longest = 0.0; // the slowest page so far: the next one may take as long
        $audited = 0;
        while ($report->cursor < $report->total) {
            if ($audited > 0 && $this->seconds() - $start + $longest > $timeBudgetSeconds) {
                break;
            }
            $began = $this->seconds();
            [$path, $locale] = $report->queue[$report->cursor];
            $page = $this->audit($report, $path, $locale);
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
            $check->restore([]);
        }

        return $report;
    }

    private function continue(AuditReport $report): AuditReport
    {
        $report->total = count($report->queue);
        $report->cursor = min(max(0, $report->cursor), $report->total);
        foreach ($this->registry->runChecks() as $check) {
            $check->restore($report->run[$check->id()] ?? []);
        }

        return $report;
    }

    /** Render one page and add what the checks found to the report. */
    private function audit(AuditReport $report, string $path, string $locale): RenderedPage
    {
        $page = $this->renderer->render($path);
        if ($locale !== '' && $locale !== $page->locale) {
            $page = new RenderedPage($page->url, $locale, $page->status, $page->headers, $page->body);
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
            array_push($report->findings, ...$this->guarded($check->id(), fn (): array => $check->check($page), $page));
        }
        foreach ($this->registry->runChecks() as $check) {
            array_push($report->findings, ...$this->guarded($check->id(), function () use ($check, $page): array {
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
            array_push($report->findings, ...$this->guarded($check->id(), $check->finish(...)));
        }
        if ($siteChecks) {
            foreach ($this->registry->siteChecks() as $check) {
                array_push($report->findings, ...$this->guarded($check->id(), fn (): array => $check->check($this->renderer)));
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
     * What a check found; a check that fails is reported as a warning, so one broken check
     * doesn't stop the audit.
     *
     * @param \Closure(): list<Finding> $check
     * @return list<Finding>
     */
    private function guarded(string $id, \Closure $check, ?RenderedPage $page = null): array
    {
        try {
            return array_values($check());
        } catch (\Throwable $e) {
            return [new Finding(
                $id,
                Severity::Warning,
                "The check failed and was skipped: {$e->getMessage()} (" . $e::class . ')',
                $page->url ?? '',
                $page->locale ?? '',
            )];
        }
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
