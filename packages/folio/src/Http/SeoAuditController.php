<?php

declare(strict_types=1);

namespace Preflow\Folio\Http;

use Nyholm\Psr7\Response;
use Preflow\Folio\Content\TypeCatalog;
use Preflow\Folio\FolioText;
use Preflow\View\Audit\AuditReport;
use Preflow\View\Audit\Finding;
use Preflow\View\TemplateEngineInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * {prefix}/seo-audit (Settings → SEO audit, permission seo.audit): the last report of
 * `preflow seo:audit`, read only. When it ran and on which site, whether it is complete (or
 * how many pages it has done), the findings per severity, and the findings grouped by check,
 * most serious first, each with a link to the page and, for content, to the record or asset
 * to fix (where the user may open it). It never starts a run and never writes: it only reads
 * the report file. Works on every site, also one without SQL storage.
 *
 * @since 0.54
 */
final class SeoAuditController
{
    /** Severities, most serious first. */
    private const array RANK = ['critical' => 0, 'warning' => 1, 'notice' => 2];

    /**
     * @param string                     $file the report file (AuditReport::file())
     * @param \Closure(string): bool     $can  whether the user has a permission
     */
    public function __construct(
        private readonly string $file,
        private readonly TypeCatalog $catalog,
        private readonly TemplateEngineInterface $engine,
        private readonly string $prefix,
        private readonly \Closure $can,
    ) {}

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $report = AuditReport::load($this->file);

        return new Response(200, ['Content-Type' => 'text/html; charset=UTF-8'], $this->engine->render('@folio/admin/seo-audit/index.twig', [
            'prefix' => $this->prefix,
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
