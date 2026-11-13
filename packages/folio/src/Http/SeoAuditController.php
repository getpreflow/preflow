<?php

declare(strict_types=1);

namespace Preflow\Folio\Http;

use Nyholm\Psr7\Response;
use Preflow\Core\Http\Csrf\CsrfToken;
use Preflow\Folio\Content\TypeCatalog;
use Preflow\Folio\FolioText;
use Preflow\View\Audit\AuditReport;
use Preflow\View\Audit\AuditRunning;
use Preflow\View\Audit\Audits;
use Preflow\View\Audit\Finding;
use Preflow\View\TemplateEngineInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * {prefix}/seo-audit (Settings → SEO audit, permission seo.audit): the last report of the SEO
 * audit. When it ran and on which site, whether it is complete (or how many pages it has done),
 * the findings per severity, and the findings grouped by check, most serious first, each with a
 * link to the page and, for content, to the record or asset to fix (where the user may open it).
 * Works on every site, also one without SQL storage. The screen itself only reads the report.
 *
 * Since 0.74 a user with seo.audit.run also runs the audit here, through preflow/view's Audits
 * (the same steps as `preflow seo:audit`): run posts one step of STEP_SECONDS (fresh=1 starts
 * over) and shows the page with its progress; step (htmx) runs the next one. While the run is
 * incomplete the progress partial posts itself to step until the report is complete, then
 * redirects to the finished report (HX-Redirect). Without JavaScript each Continue is a plain
 * POST that runs one step. A run held elsewhere (the command, another browser) shows a notice
 * with Try again; a step that throws is logged and shows a generic line with Continue. Without Audits (an app
 * that does not set up the audit) the screen stays read only.
 *
 * @since 0.54
 */
final class SeoAuditController
{
    /** Seconds one step may audit pages (at least one page): short enough for any web request. */
    public const int STEP_SECONDS = 20;

    /** The PHP time limit a step raises a shorter one to: the step's budget, the last page and the report's save fit. */
    private const int TIME_LIMIT = 60;

    /** Severities, most serious first. */
    private const array RANK = ['critical' => 0, 'warning' => 1, 'notice' => 2];

    /**
     * @param string                     $file the report file (AuditReport::file())
     * @param \Closure(string): bool     $can  whether the user has a permission
     * @param ?\Closure(): ?Audits       $audits the app's audit service, null where it isn't set up (read only then)
     * @param ?\Closure(string): void    $log  a warning sink for a failed step (else error_log())
     */
    public function __construct(
        private readonly string $file,
        private readonly TypeCatalog $catalog,
        private readonly TemplateEngineInterface $engine,
        private readonly string $prefix,
        private readonly \Closure $can,
        private readonly ?\Closure $audits = null,
        private readonly ?\Closure $log = null,
    ) {}

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $audits = $this->runner();
        $state = $audits instanceof Audits ? ($audits->running() ? 'elsewhere' : 'idle') : null;

        return $this->page($request, AuditReport::load($this->file), $state);
    }

    /**
     * POST {prefix}/seo-audit/run (fresh=1 starts over): runs one step and shows the page with its
     * progress, or redirects to the report once it is complete. Run, Continue, Start over, Run
     * again and Try again all post here; without JavaScript each press is one step.
     */
    public function run(ServerRequestInterface $request): ResponseInterface
    {
        $audits = $this->runner();
        if (!$audits instanceof Audits) {
            return $this->refused($audits);
        }
        $body = $request->getParsedBody();

        return $this->advance($request, $audits, is_array($body) && ($body['fresh'] ?? null) === '1');
    }

    /** POST {prefix}/seo-audit/step (htmx, from the progress partial): the next step, answered with the partial. */
    public function step(ServerRequestInterface $request): ResponseInterface
    {
        $audits = $this->runner();
        if (!$audits instanceof Audits) {
            return $this->refused($audits);
        }

        return $this->advance($request, $audits, false);
    }

    /** The audit service when the user may run it, else why not (403 without seo.audit.run, 404 without the service). */
    private function runner(): Audits|int
    {
        if (!($this->can)('seo.audit.run')) {
            return 403;
        }
        $audits = $this->audits !== null ? ($this->audits)() : null;

        return $audits instanceof Audits ? $audits : 404;
    }

    private function refused(int $status): ResponseInterface
    {
        return new Response($status, ['Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'no-store'], $status === 403 ? 'Forbidden' : 'Not Found');
    }

    private function advance(ServerRequestInterface $request, Audits $audits, bool $fresh): ResponseInterface
    {
        LongRequest::allow($request, self::TIME_LIMIT);
        $state = 'stepping';
        try {
            $report = $audits->step(self::STEP_SECONDS, $fresh);
        } catch (AuditRunning) {
            $state = 'elsewhere';
            $report = $audits->report();
        } catch (\Throwable $e) {
            $state = 'failed';
            // The message may name server paths or SQL: the log has it, the screen a generic line.
            $message = 'SEO audit: a step run from Folio failed: ' . $e::class . ': ' . $e->getMessage();
            $this->log !== null ? ($this->log)($message) : error_log($message);
            $report = $audits->report();
        }
        $htmx = $request->getHeaderLine('HX-Request') === 'true';
        if ($state === 'stepping' && $report->complete()) {
            $url = $this->prefix . '/seo-audit';

            return $htmx
                ? new Response(200, ['HX-Redirect' => $url, 'Cache-Control' => 'no-store'])
                : new Response(303, ['Location' => $url, 'Cache-Control' => 'no-store']);
        }
        if ($htmx) {
            return new Response(200, ['Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'no-store'], $this->engine->render(
                '@folio/admin/seo-audit/_progress.twig',
                ['audit' => $this->runView($request, $report, $state), 'prefix' => $this->prefix],
            ));
        }

        return $this->page($request, $report, $state);
    }

    /**
     * The screen. $state is null where the user can't run the audit here (read only), else idle,
     * stepping (the partial polls step), elsewhere (another run holds the lock) or failed.
     */
    private function page(ServerRequestInterface $request, ?AuditReport $report, ?string $state): ResponseInterface
    {
        return new Response(200, ['Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'no-store'], $this->engine->render('@folio/admin/seo-audit/index.twig', [
            'prefix' => $this->prefix,
            'audit' => $state === null ? null : $this->runView($request, $report, $state),
            'editor_assets' => $state === 'stepping' ? ['htmx.js'] : [],
            'report' => $report === null ? null : [
                'started_at' => $report->startedAt,
                'finished_at' => $report->finishedAt,
                'complete' => $report->complete(),
                'origin' => $report->origin,
                'done' => $report->done(),
                'total' => $report->total,
                'counts' => $report->counts(),
                'groups' => $this->groups($report->findings),
            ],
        ]));
    }

    /** @return array<string, mixed> what _progress.twig shows */
    private function runView(ServerRequestInterface $request, ?AuditReport $report, string $state): array
    {
        return [
            'state' => $state,
            'started' => $report !== null,
            'complete' => $report?->complete() ?? false,
            'done' => $report?->done() ?? 0,
            'total' => $report?->total ?? 0,
            'run_url' => $this->prefix . '/seo-audit/run',
            'step_url' => $this->prefix . '/seo-audit/step',
            'csrf' => $request->getAttribute(CsrfToken::class)?->getValue() ?? '',
        ];
    }

    /**
     * The findings by check: the most serious check first (then by id), its findings most
     * serious first in the report's order.
     *
     * @param list<Finding> $findings
     * @return list<array{check: string, label: ?string, severity: string, findings: list<array<string, mixed>>}>
     */
    private function groups(array $findings): array
    {
        $groups = [];
        foreach ($findings as $finding) {
            $severity = $finding->severity->value;
            $group = &$groups[$finding->check];
            $group ??= ['check' => $finding->check, 'label' => self::label($finding->check), 'severity' => $severity, 'findings' => []];
            if (self::RANK[$severity] < self::RANK[$group['severity']]) {
                $group['severity'] = $severity;
            }
            $group['findings'][] = $this->row($finding);
            unset($group);
        }
        foreach ($groups as &$group) {
            // usort is stable: the report's order within a severity.
            usort($group['findings'], static fn (array $a, array $b): int => self::RANK[$a['severity']] <=> self::RANK[$b['severity']]);
        }
        unset($group);
        $groups = array_values($groups);
        usort($groups, static fn (array $a, array $b): int => [self::RANK[$a['severity']], $a['check']] <=> [self::RANK[$b['severity']], $b['check']]);

        return $groups;
    }

    /** @return array{severity: string, message: string, url: string, locale: string, page: ?string, edit: ?string, asset: ?string} */
    private function row(Finding $finding): array
    {
        $target = $finding->target;
        $record = $target !== null && $target->kind === 'record' && $target->id !== ''
            && $this->catalog->has($target->type) && ($this->can)($target->type . '.view');
        $asset = $target !== null && $target->kind === 'asset' && $target->id !== '' && ($this->can)('assets.view');

        return [
            'severity' => $finding->severity->value,
            'message' => $finding->message,
            'url' => $finding->url,
            'locale' => $finding->locale,
            // Only a web address is a link: the report is a file anyone with access to the server can edit.
            'page' => (preg_match('#^https?://#i', $finding->url) === 1 || (str_starts_with($finding->url, '/') && !str_starts_with($finding->url, '//'))) ? $finding->url : null,
            'edit' => $record ? $this->prefix . '/' . rawurlencode($target->type) . '/' . rawurlencode($target->id) . '/edit' : null,
            'asset' => $asset ? $this->prefix . '/assets/' . rawurlencode($target->id) : null,
        ];
    }

    /**
     * A check's name in the interface language (folio.audit.checks.{id with "_" for "."}: ids nest,
     * "etag" and "etag.cookie"), or null for one Folio doesn't know (a site's check: shown by its id).
     */
    private static function label(string $check): ?string
    {
        if (preg_match('/^[a-z0-9_-]+(\.[a-z0-9_-]+)*$/', $check) !== 1) {
            return null;
        }
        $key = 'folio.audit.checks.' . str_replace('.', '_', $check);
        $text = FolioText::get($key);

        return $text !== $key ? $text : null;
    }
}
