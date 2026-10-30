<?php

declare(strict_types=1);

namespace Preflow\Folio\Http;

use Nyholm\Psr7\Response;
use Preflow\Ai\Ai;
use Preflow\Ai\AiUnavailable;
use Preflow\Auth\Access\Access;
use Preflow\Data\DataManager;
use Preflow\Data\TypeRegistry;
use Preflow\Folio\Content\TypeCatalog;
use Preflow\Folio\FolioText;
use Preflow\I18n\Machine\Estimator;
use Preflow\I18n\Machine\Job;
use Preflow\I18n\Machine\JobRunner;
use Preflow\I18n\Machine\JobStore;
use Preflow\I18n\Machine\MachineTranslator;
use Preflow\I18n\Machine\ReportsCost;
use Preflow\I18n\Machine\Scope;
use Preflow\I18n\Machine\TextSources;
use Preflow\I18n\Machine\TranslationUnit;
use Preflow\I18n\Machine\UnitStatus;
use Preflow\View\TemplateEngineInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The edit form's Translate button: what a record's translation into one language would cost
 * (estimate) and the AI texts for it (suggest). Units come from the type's TextSource, the same
 * code path whole-type runs use. Nothing is written: the form fills its inputs with the texts
 * and the editor reviews and saves them like typed ones.
 *
 * The run screen translates a whole type (or the records ticked in the list) with the core
 * JobRunner: a plan with its estimate, then one record per step request while the progress
 * partial polls itself (htmx), until the job is done or stopped. Publishing types get working
 * copies; a type without publishing writes live, so its Start waits for an explicit confirmation.
 *
 * The routes exist only while AI is on (FolioRoutes); the check here covers a container whose
 * AI was switched off after boot. The route asks the type's edit permission
 * (FolioPermissionMiddleware); this controller adds ai.translate.
 */
final class AiTranslateController
{
    /** The longest list of finished items the progress shows while the job runs (it is re-rendered every step). */
    private const RECENT_ITEMS = 10;

    public function __construct(
        private readonly TypeCatalog $catalog,
        private readonly TypeRegistry $registry,
        private readonly DataManager $dm,
        private readonly TextSources $sources,
        private readonly MachineTranslator $translator,
        private readonly Estimator $estimator,
        private readonly Ai $ai,
        private readonly JobRunner $runner,
        private readonly JobStore $jobs,
        private readonly TemplateEngineInterface $engine,
        private readonly string $prefix,
        private readonly ?Access $access = null,
    ) {}

    /** GET {type}/_ai/run?ids[]=…: languages, scope and note; without ids the whole type. */
    public function runForm(ServerRequestInterface $request): ResponseInterface
    {
        $type = $this->runType($request);
        if ($type instanceof ResponseInterface) {
            return $type;
        }

        return $this->page($request, $type, ['ids' => self::ids($request->getQueryParams()['ids'] ?? null)]);
    }

    /**
     * POST {type}/_ai/run. With step=estimate (locales[], scope, note?, ids[]?) the job is planned
     * and its summary shown; without it the form, for the ids[] the list's bulk bar posts.
     */
    public function plan(ServerRequestInterface $request): ResponseInterface
    {
        $type = $this->runType($request);
        if ($type instanceof ResponseInterface) {
            return $type;
        }
        $body = self::body($request);
        $ids = self::ids($body['ids'] ?? null);
        $note = trim((string) (is_string($body['note'] ?? null) ? $body['note'] : ''));
        $form = ['ids' => $ids, 'note' => $note];
        if (($body['step'] ?? null) !== 'estimate') {
            return $this->page($request, $type, $form);
        }

        $languages = $this->languages($type);
        $locales = array_values(array_unique(array_filter((array) ($body['locales'] ?? []), 'is_string')));
        $scope = is_string($body['scope'] ?? null) ? Scope::tryFrom($body['scope']) : null;
        $form += ['locales' => $locales, 'scope' => $scope?->value ?? Scope::Needed->value];
        if ($locales === [] || array_diff($locales, array_keys($languages)) !== []) {
            return $this->page($request, $type, $form + ['error' => FolioText::get('folio.ai_run.choose_language')], 422);
        }
        if ($scope === null) {
            return $this->page($request, $type, $form + ['error' => FolioText::get('folio.ai_run.choose_scope')], 422);
        }

        $job = $this->runner->plan($type, $locales, $scope, $ids === [] ? null : $ids, $note === '' ? null : $note);
        $estimate = $job->estimate;
        $parts = [
            'records' => FolioText::choice('folio.ai_run.records', count($job->items)),
            'texts' => FolioText::choice('folio.ai_run.texts', $estimate->units),
            'requests' => FolioText::choice('folio.ai_run.requests', $estimate->requests),
        ];
        $cost = self::cost($estimate->costUsd);

        return $this->page($request, $type, $form + ['summary' => [
            'text' => $cost === null
                ? FolioText::get('folio.ai_run.summary_unpriced', $parts)
                : FolioText::get('folio.ai_run.summary', $parts + ['cost' => $cost]),
            'texts' => $estimate->units,
            'start_url' => $this->jobUrl($type, $job, 'start'),
            'languages' => array_map(static fn (string $l): string => $languages[$l] ?? $l, $job->locales),
            'live' => $this->sources->get($type)->writesLive(),
        ]]);
    }

    /**
     * POST {type}/_ai/jobs/{job}/start: runs a planned job, or continues a stopped one. A type
     * that writes live needs confirm_live=1 (the screen's checkbox).
     */
    public function start(ServerRequestInterface $request): ResponseInterface
    {
        $job = $this->runJob($request);
        if ($job instanceof ResponseInterface) {
            return $job;
        }
        $startable = $job->state === 'planned' || $job->state === 'stopped';
        if ($startable && $this->sources->get($job->collection)->writesLive() && (self::body($request)['confirm_live'] ?? null) !== '1') {
            return $this->progress($request, $job, FolioText::get('folio.ai_run.confirm_required'), 422);
        }

        return $this->progress($request, $this->runner->start($job->id));
    }

    /**
     * POST {type}/_ai/jobs/{job}/step: translates the next record of a running job (only a
     * started job runs: a step never skips the live confirmation). A provider that cannot be
     * used stops the job, and the progress says why.
     */
    public function step(ServerRequestInterface $request): ResponseInterface
    {
        $job = $this->runJob($request);
        if ($job instanceof ResponseInterface) {
            return $job;
        }
        if ($job->state === 'running') {
            try {
                $job = $this->runner->step($job->id, 1);
            } catch (AiUnavailable) {
                $job = $this->jobs->get($job->id) ?? $job; // stopped and saved by the runner, with the message
            }
        }

        return $this->progress($request, $job);
    }

    /** POST {type}/_ai/jobs/{job}/stop: records done so far stay done; Start continues later. */
    public function stop(ServerRequestInterface $request): ResponseInterface
    {
        $job = $this->runJob($request);
        if ($job instanceof ResponseInterface) {
            return $job;
        }

        return $this->progress($request, $this->runner->stop($job->id));
    }

    /** Body: locale, scope (needed|all), prefix?. Answers {units, requests, costUsd}. */
    public function estimate(ServerRequestInterface $request): ResponseInterface
    {
        $units = $this->units($request);
        if ($units instanceof ResponseInterface) {
            return $units;
        }
        $estimate = $this->estimator->estimate($units);

        return self::json(200, ['units' => $estimate->units, 'requests' => $estimate->requests, 'costUsd' => $estimate->costUsd]);
    }

    /**
     * Body: locale, scope (needed|all), note?, prefix?. Answers
     * {texts: {path: {text, source, previous, warnings}}, failed: {path: reason}, costUsd}.
     * A provider that cannot be used at all answers 503 with its message.
     */
    public function suggest(ServerRequestInterface $request): ResponseInterface
    {
        $units = $this->units($request);
        if ($units instanceof ResponseInterface) {
            return $units;
        }
        if ($units === []) {
            return self::json(200, ['texts' => new \stdClass(), 'failed' => new \stdClass(), 'costUsd' => 0.0]);
        }

        $note = trim((string) (self::body($request)['note'] ?? ''));
        $cost = 0.0;
        if ($this->translator instanceof ReportsCost) {
            $this->translator->reportCostTo(static function (float $usd) use (&$cost): void {
                $cost += $usd;
            });
        }
        try {
            $results = $this->translator->translateUnits($units, $note === '' ? null : $note);
        } catch (AiUnavailable $e) {
            return self::json(503, ['error' => $e->getMessage()]);
        } finally {
            if ($this->translator instanceof ReportsCost) {
                $this->translator->reportCostTo(null);
            }
        }

        $byId = [];
        foreach ($units as $unit) {
            $byId[$unit->id] = $unit;
        }
        $texts = $failed = [];
        foreach ($results as $result) {
            $unit = $byId[$result->id] ?? null;
            if ($unit === null) {
                continue;
            }
            if ($result->status === UnitStatus::Translated && $result->text !== null) {
                $texts[$result->id] = [
                    'text' => $result->text,
                    'source' => $unit->source,
                    'previous' => $unit->current,
                    'warnings' => $result->warnings,
                ];
            } else {
                $failed[$result->id] = $result->error ?? 'No translation.';
            }
        }

        return self::json(200, ['texts' => (object) $texts, 'failed' => (object) $failed, 'costUsd' => round($cost, 6)]);
    }

    /**
     * The record's units for the posted language and scope, limited to the posted prefix; or the
     * error response (404 AI off, unknown type or record; 403 without ai.translate; 422 bad input).
     *
     * @return list<TranslationUnit>|ResponseInterface
     */
    private function units(ServerRequestInterface $request): array|ResponseInterface
    {
        if (!$this->ai->enabled()) {
            return new Response(404, [], 'Not found');
        }
        $type = (string) $request->getAttribute('type', '');
        $id = (string) $request->getAttribute('id', '');
        if (!$this->catalog->has($type) || !$this->sources->has($type)) {
            return self::json(404, ['error' => 'Unknown type.']);
        }
        if (!($this->access?->can('ai.translate') ?? true) || !($this->access?->can($type . '.edit') ?? true)) {
            return self::json(403, ['error' => 'Forbidden.']);
        }

        $body = self::body($request);
        $locale = (string) ($body['locale'] ?? '');
        $locales = $this->registry->get($type)->locales;
        if (!$locales->has($locale) || $locale === $locales->default) {
            return self::json(422, ['error' => 'Choose a language to translate into.']);
        }
        $scope = Scope::tryFrom((string) ($body['scope'] ?? Scope::Needed->value));
        if ($scope === null) {
            return self::json(422, ['error' => 'The scope is "needed" or "all".']);
        }
        if ($this->dm->findType($type, $id) === null) {
            return self::json(404, ['error' => 'Record not found.']);
        }

        $units = $this->sources->get($type)->units($id, $locale, $scope);
        $prefix = trim((string) ($body['prefix'] ?? ''));
        if ($prefix !== '') {
            $units = array_values(array_filter(
                $units,
                static fn (TranslationUnit $u): bool => $u->id === $prefix || str_starts_with($u->id, $prefix . '.'),
            ));
        }

        return $units;
    }

    /**
     * The route's type when the run screen may be used for it, or the error response (404 AI off
     * or a type with nothing to translate; 403 without ai.translate or the type's edit permission).
     */
    private function runType(ServerRequestInterface $request): string|ResponseInterface
    {
        if (!$this->ai->enabled()) {
            return new Response(404, [], 'Not found');
        }
        $type = (string) $request->getAttribute('type', '');
        if (!$this->catalog->has($type) || !$this->sources->has($type) || $this->languages($type) === []) {
            return new Response(404, ['Content-Type' => 'text/plain; charset=UTF-8'], FolioText::get('folio.errors.unknown_type'));
        }
        if (!($this->access?->can('ai.translate') ?? true) || !($this->access?->can($type . '.edit') ?? true)) {
            return Forbidden::response($request, $this->engine, $this->prefix, $this->catalog);
        }

        return $type;
    }

    /** The route's job, or 404 for an unknown one and for one of another type than the route's. */
    private function runJob(ServerRequestInterface $request): Job|ResponseInterface
    {
        $type = $this->runType($request);
        if ($type instanceof ResponseInterface) {
            return $type;
        }
        $job = $this->jobs->get((string) $request->getAttribute('job', ''));
        if ($job === null || $job->collection !== $type) {
            return new Response(404, ['Content-Type' => 'text/plain; charset=UTF-8'], FolioText::get('folio.ai_run.unknown_job'));
        }

        return $job;
    }

    /**
     * The languages a type's texts can be translated into: every one but the default (the
     * source); none when the type has no translatable field.
     *
     * @return array<string, string> code => name
     */
    private function languages(string $type): array
    {
        $def = $this->registry->get($type);
        if ($def->translatableFields() === [] || !$def->locales->isMultilingual()) {
            return [];
        }
        $names = [];
        foreach ($def->locales->available as $code) {
            if ($code !== $def->locales->default) {
                $key = 'folio.languages.' . $code;
                $name = FolioText::get($key);
                $names[$code] = $name !== $key ? $name : strtoupper($code);
            }
        }

        return $names;
    }

    /**
     * The run screen: the form, the plan's summary, or a job's progress.
     *
     * @param array<string, mixed> $view
     */
    private function page(ServerRequestInterface $request, string $type, array $view, int $status = 200): ResponseInterface
    {
        $source = $this->sources->get($type);

        return new Response($status, ['Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'no-store'], $this->engine->render('@folio/admin/ai/run.twig', $view + [
            'prefix' => $this->prefix,
            'type' => $type,
            'label' => $source->label(),
            'csrf' => self::csrf($request),
            'editor_assets' => ['htmx.js'],
            'action' => $this->prefix . '/' . $type . '/_ai/run',
            'languages' => $this->languages($type),
            'locales' => array_keys($this->languages($type)),
            'scope' => Scope::Needed->value,
            'ids' => [],
            'note' => '',
            'error' => null,
            'summary' => null,
            'progress' => null,
        ]));
    }

    /** The progress partial for htmx, else the run screen around it. */
    private function progress(ServerRequestInterface $request, Job $job, ?string $error = null, int $status = 200): ResponseInterface
    {
        $view = $this->progressView($request, $job, $error);
        if ($request->getHeaderLine('HX-Request') !== 'true') {
            return $this->page($request, $job->collection, ['progress' => $view], $status);
        }

        return new Response($status, ['Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'no-store'], $this->engine->render('@folio/admin/ai/run-progress.twig', $view));
    }

    /** @return array<string, mixed> what run-progress.twig shows */
    private function progressView(ServerRequestInterface $request, Job $job, ?string $error): array
    {
        $type = $job->collection;
        $source = $this->sources->get($type);
        $titles = [];
        $row = function (string $id, array $item) use ($source, $type, &$titles): array {
            return [
                'id' => $id,
                'title' => $titles[$id] ??= $source->title($id),
                'url' => $this->prefix . '/' . $type . '/' . rawurlencode($id) . '/edit',
            ] + $item;
        };

        $finished = $problems = $drafts = [];
        $review = 0;
        foreach ($job->items as $id => $item) {
            $id = (string) $id; // numeric ids come back as int keys
            if ($item['status'] === 'pending') {
                continue;
            }
            $finished[$id] = $item;
            if ($item['status'] === 'failed' || $item['status'] === 'conflict') {
                $problems[] = $row($id, $item);
            }
            if ($item['draft_changes'] ?? false) {
                $drafts[] = $row($id, $item);
            }
            if (($item['translated'] ?? 0) > 0) {
                $review++;
            }
        }
        $running = $job->state === 'running';
        $shown = $running ? array_slice($finished, -self::RECENT_ITEMS, null, true) : $finished;

        return [
            'state' => $job->state,
            'running' => $running,
            'message' => $job->message,
            'error' => $error,
            'done' => count($finished),
            'total' => count($job->items),
            'cost' => self::cost($job->costUsd) ?? self::cost(0.0),
            'rows' => array_map(static fn (string $id, array $item): array => $row($id, $item), array_map('strval', array_keys($shown)), $shown),
            'recent_only' => $running && count($finished) > count($shown),
            'problems' => $problems,
            'drafts' => $drafts,
            'review' => $review,
            'review_url' => $this->prefix . '/' . $type . '?unchecked=1',
            'step_url' => $this->jobUrl($type, $job, 'step'),
            'stop_url' => $this->jobUrl($type, $job, 'stop'),
            'start_url' => $this->jobUrl($type, $job, 'start'),
            'can_continue' => $job->state === 'stopped' && $job->pending() !== [],
            'live' => $source->writesLive(),
            'csrf' => self::csrf($request),
        ];
    }

    private function jobUrl(string $type, Job $job, string $action): string
    {
        return $this->prefix . '/' . $type . '/_ai/jobs/' . $job->id . '/' . $action;
    }

    /**
     * Posted or queried record ids: strings, each once.
     *
     * @return list<string>
     */
    private static function ids(mixed $ids): array
    {
        return is_array($ids) ? array_values(array_unique(array_filter($ids, static fn (mixed $id): bool => is_string($id) && $id !== ''))) : [];
    }

    /** USD as the edit form's estimate shows it; null when the model has no price. */
    private static function cost(?float $usd): ?string
    {
        if ($usd === null) {
            return null;
        }

        return $usd > 0 && $usd < 0.01 ? '< $0.01' : '$' . number_format($usd, 2);
    }

    private static function csrf(ServerRequestInterface $request): string
    {
        return $request->getAttribute(\Preflow\Core\Http\Csrf\CsrfToken::class)?->getValue() ?? '';
    }

    /** @return array<string, mixed> */
    private static function body(ServerRequestInterface $request): array
    {
        $body = $request->getParsedBody();

        return is_array($body) ? $body : [];
    }

    /** @param array<string, mixed> $data */
    private static function json(int $status, array $data): ResponseInterface
    {
        return new Response(
            $status,
            ['Content-Type' => 'application/json; charset=UTF-8', 'Cache-Control' => 'no-store'],
            json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION),
        );
    }
}
