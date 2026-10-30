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
use Preflow\I18n\Machine\Estimator;
use Preflow\I18n\Machine\MachineTranslator;
use Preflow\I18n\Machine\ReportsCost;
use Preflow\I18n\Machine\Scope;
use Preflow\I18n\Machine\TextSources;
use Preflow\I18n\Machine\TranslationUnit;
use Preflow\I18n\Machine\UnitStatus;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The edit form's Translate button: what a record's translation into one language would cost
 * (estimate) and the AI texts for it (suggest). Units come from the type's TextSource, the same
 * code path whole-type runs use. Nothing is written: the form fills its inputs with the texts
 * and the editor reviews and saves them like typed ones.
 *
 * The routes exist only while AI is on (FolioRoutes); the check here covers a container whose
 * AI was switched off after boot. The route asks the type's edit permission
 * (FolioPermissionMiddleware); this controller adds ai.translate.
 */
final class AiTranslateController
{
    public function __construct(
        private readonly TypeCatalog $catalog,
        private readonly TypeRegistry $registry,
        private readonly DataManager $dm,
        private readonly TextSources $sources,
        private readonly MachineTranslator $translator,
        private readonly Estimator $estimator,
        private readonly Ai $ai,
        private readonly ?Access $access = null,
    ) {}

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
