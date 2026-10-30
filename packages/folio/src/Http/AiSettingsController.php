<?php

declare(strict_types=1);

namespace Preflow\Folio\Http;

use Nyholm\Psr7\Response;
use Preflow\Ai\Ai;
use Preflow\Ai\AiCheck;
use Preflow\Ai\AiCheckResult;
use Preflow\Ai\Usage\UsageLog;
use Preflow\Data\TypeRegistry;
use Preflow\Folio\Content\ContentLocale;
use Preflow\Folio\Content\TypeCatalog;
use Preflow\Folio\FolioText;
use Preflow\I18n\Machine\Glossary;
use Preflow\I18n\Machine\GlossaryEntry;
use Preflow\I18n\Machine\TranslationSettings;
use Preflow\View\TemplateEngineInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Settings → AI ({prefix}/ai, permission ai.settings): the connection status with a Test button
 * and the usage of this and last month, the translation instructions per level (tuning) and the
 * glossary. Edits go to the core's TranslationSettings, which stores them apart from config/ai.php.
 *
 * The screens exist while the owner's switch in config/ai.php is on or a key is present, so an
 * admin can see why AI is not working ("enabled: yes, key: no"); with neither they answer 404,
 * like AI off. The key itself is never rendered, only whether it exists.
 */
final class AiSettingsController
{
    /** A field level key: {type}.{field} or {type}.{field}:{kind}. */
    private const FIELD_LEVEL = '/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+(:[A-Za-z0-9_-]+)?$/';

    public function __construct(
        private readonly Ai $ai,
        private readonly UsageLog $usage,
        private readonly TranslationSettings $settings,
        private readonly TypeCatalog $catalog,
        private readonly TypeRegistry $registry,
        private readonly ContentLocale $contentLocale,
        private readonly TemplateEngineInterface $engine,
        private readonly string $prefix,
    ) {}

    /** GET {prefix}/ai */
    public function status(ServerRequestInterface $request): ResponseInterface
    {
        return $this->guard() ?? $this->statusPage($request);
    }

    /** POST {prefix}/ai/test: the same check as `preflow ai:check`; failures are shown, never thrown. */
    public function test(ServerRequestInterface $request): ResponseInterface
    {
        if ($off = $this->guard()) {
            return $off;
        }
        $result = (new AiCheck($this->ai))->run();
        $params = ['message' => (string) $result->message];
        $text = match ($result->state) {
            AiCheckResult::OK => FolioText::get('folio.ai_settings.test_ok', [
                'input' => $result->usage?->input ?? 0,
                'output' => $result->usage?->output ?? 0,
                'cost' => $result->cost === null ? FolioText::get('folio.ai_settings.no_price') : '$' . number_format($result->cost, 6),
            ]),
            AiCheckResult::OFF => FolioText::get('folio.ai_settings.test_off'),
            AiCheckResult::UNEXPECTED => FolioText::get('folio.ai_settings.test_unexpected'),
            default => FolioText::get('folio.ai_settings.test_failed', $params),
        };

        return $this->statusPage($request, ['ok' => $result->ok(), 'text' => $text]);
    }

    /** GET {prefix}/ai/tuning */
    public function tuning(ServerRequestInterface $request): ResponseInterface
    {
        return $this->guard() ?? $this->tuningPage($request, []);
    }

    /** POST {prefix}/ai/tuning: every level's textarea (one instruction per line), plus an optional new field level. */
    public function saveTuning(ServerRequestInterface $request): ResponseInterface
    {
        if ($off = $this->guard()) {
            return $off;
        }
        $body = self::body($request);
        $edited = $this->settings->edited();
        $known = array_keys($this->settings->levels());

        $newKey = trim((string) (is_string($body['new_key'] ?? null) ? $body['new_key'] : ''));
        $newText = is_string($body['new_text'] ?? null) ? $body['new_text'] : '';
        if ($newKey !== '' && preg_match(self::FIELD_LEVEL, $newKey) !== 1) {
            return $this->tuningPage($request, ['new_key' => $newKey, 'new_text' => $newText], FolioText::get('folio.ai_settings.bad_level_key'), 422);
        }

        $posted = is_array($body['levels'] ?? null) ? $body['levels'] : [];
        foreach ($posted as $key => $text) {
            if (!is_string($text) || !in_array((string) $key, $known, true)) {
                continue;
            }
            $lines = self::lines($text);
            if ($lines !== ($edited[(string) $key] ?? [])) {
                $this->settings->saveLevel((string) $key, $lines);
            }
        }
        if ($newKey !== '') {
            $this->settings->saveLevel($newKey, self::lines($newText));
        }
        self::flash($request, 'success', FolioText::get('folio.ai_settings.saved'));

        return new Response(302, ['Location' => $this->prefix . '/ai/tuning']);
    }

    /** GET {prefix}/ai/glossary */
    public function glossary(ServerRequestInterface $request): ResponseInterface
    {
        if ($off = $this->guard()) {
            return $off;
        }
        $rows = [];
        foreach ($this->settings->glossary()->toArray() as $row) {
            $rows[] = $this->glossaryRow($row);
        }

        return $this->glossaryPage($request, $rows);
    }

    /**
     * POST {prefix}/ai/glossary. action=save stores the rows and goes back to the screen;
     * action=add stores them too and shows one more empty row. Ticked "remove" rows and rows
     * without a term are dropped.
     */
    public function saveGlossary(ServerRequestInterface $request): ResponseInterface
    {
        if ($off = $this->guard()) {
            return $off;
        }
        $body = self::body($request);
        $languages = array_keys($this->languages());
        $entries = [];
        foreach (is_array($body['rows'] ?? null) ? $body['rows'] : [] as $row) {
            if (!is_array($row) || ($row['remove'] ?? null) === '1') {
                continue;
            }
            $translations = [];
            foreach ($languages as $locale) {
                if (($row['keep'][$locale] ?? null) === '1') {
                    $translations[$locale] = null;
                } elseif (is_string($row['tr'][$locale] ?? null) && trim($row['tr'][$locale]) !== '') {
                    $translations[$locale] = trim($row['tr'][$locale]);
                }
            }
            $types = array_values(array_unique(array_filter((array) ($row['types'] ?? []), 'is_string')));
            $note = is_string($row['note'] ?? null) ? trim($row['note']) : '';
            $entries[] = new GlossaryEntry(is_string($row['term'] ?? null) ? trim($row['term']) : '', $translations, $types, $note === '' ? null : $note);
        }
        $glossary = new Glossary($entries);
        $this->settings->saveGlossary($glossary);

        if (($body['action'] ?? '') === 'add') {
            $kept = array_map($this->glossaryRow(...), $glossary->toArray());
            $kept[] = $this->glossaryRow([]);

            return $this->glossaryPage($request, $kept);
        }
        self::flash($request, 'success', FolioText::get('folio.ai_settings.saved'));

        return new Response(302, ['Location' => $this->prefix . '/ai/glossary']);
    }

    /** 404 unless the owner switched AI on in config/ai.php or a key is present. */
    private function guard(): ?ResponseInterface
    {
        if (!$this->ai->switchedOn() && !$this->ai->status()->keyPresent) {
            return new Response(404, ['Content-Type' => 'text/plain; charset=UTF-8'], 'Not found');
        }

        return null;
    }

    /** @param array{ok: bool, text: string}|null $test */
    private function statusPage(ServerRequestInterface $request, ?array $test = null): ResponseInterface
    {
        $status = $this->ai->status();
        $now = new \DateTimeImmutable('first day of this month 00:00:00');
        $months = [];
        foreach ([[$now, 'this'], [$now->modify('-1 month'), 'last']] as [$from, $which]) {
            $months[] = $this->month($from, $which);
        }

        return $this->render('status', $request, [
            'provider' => $status->provider,
            'model' => $status->model,
            'key_present' => $status->keyPresent,
            'switched_on' => $this->ai->switchedOn(),
            'active' => $status->enabled,
            'test' => $test,
            'months' => $months,
        ]);
    }

    /**
     * Calls, tokens and cost of one month per purpose and model. A model without a price shows
     * no cost ("—"), never 0; a mixed row shows the priced part with a "+".
     *
     * @return array{label: string, title: string, rows: list<array<string, mixed>>}
     */
    private function month(\DateTimeImmutable $from, string $which): array
    {
        $entries = $this->usage->between($from, $from->modify('last day of this month')->setTime(23, 59, 59));
        $rows = [];
        foreach ($entries as $e) {
            $row = &$rows[$e->purpose . "\0" . $e->model];
            $row ??= ['purpose' => $e->purpose, 'model' => $e->model, 'calls' => 0, 'input' => 0, 'output' => 0, 'cost' => 0.0, 'priced' => 0, 'unpriced' => 0];
            $row['calls']++;
            $row['input'] += $e->usage->input;
            $row['output'] += $e->usage->output;
            if ($e->costUsd === null) {
                $row['unpriced']++;
            } else {
                $row['cost'] += $e->costUsd;
                $row['priced']++;
            }
            unset($row);
        }
        ksort($rows);

        return [
            'label' => $from->format('Y-m'),
            'title' => FolioText::get('folio.ai_settings.' . $which . '_month'),
            'rows' => array_values(array_map(static fn (array $r): array => [
                'purpose' => $r['purpose'],
                'model' => $r['model'],
                'calls' => $r['calls'],
                'input' => $r['input'],
                'output' => $r['output'],
                'cost' => $r['priced'] === 0 ? '—' : '$' . number_format($r['cost'], 4) . ($r['unpriced'] > 0 ? '+' : ''),
            ], $rows)),
        ];
    }

    /**
     * @param array<string, string> $form posted values to show again after an error
     */
    private function tuningPage(ServerRequestInterface $request, array $form, ?string $error = null, int $status = 200): ResponseInterface
    {
        $edited = $this->settings->edited();
        $levels = $this->settings->levels();
        $languages = $this->languages();

        $order = ['project' => FolioText::get('folio.ai_settings.level_project')];
        foreach ($languages as $code => $name) {
            $order['lang:' . $code] = FolioText::get('folio.ai_settings.level_language', ['language' => $name]);
        }
        foreach ($this->translatableTypes() as $type => $label) {
            $order[$type] = FolioText::get('folio.ai_settings.level_type', ['type' => $label]);
        }
        foreach ($levels as $key => $_) {
            $order[$key] ??= match (true) {
                str_contains($key, ':') && str_contains($key, '.') => FolioText::get('folio.ai_settings.level_kind', ['key' => $key]),
                str_contains($key, '.') => FolioText::get('folio.ai_settings.level_field', ['key' => $key]),
                default => $key,
            };
        }

        $rows = [];
        foreach ($order as $key => $label) {
            $rows[] = [
                'key' => $key,
                'label' => $label,
                'value' => implode("\n", $edited[$key] ?? []),
                'placeholder' => implode("\n", $this->settings->defaultFor($key)),
            ];
        }

        return $this->render('tuning', $request, [
            'rows' => $rows,
            'new_key' => $form['new_key'] ?? '',
            'new_text' => $form['new_text'] ?? '',
            'error' => $error,
        ], $status);
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function glossaryPage(ServerRequestInterface $request, array $rows): ResponseInterface
    {
        $types = $this->translatableTypes();
        foreach ($rows as $row) {
            foreach ($row['types'] as $type) {
                $types[$type] ??= $type;
            }
        }

        return $this->render('glossary', $request, [
            'rows' => $rows === [] ? [$this->glossaryRow([])] : $rows,
            'languages' => $this->languages(),
            'types' => $types,
        ]);
    }

    /**
     * One glossary row as the form needs it: a text or a keep flag per language.
     *
     * @param array<string, mixed> $row a Glossary::toArray() row, or [] for an empty one
     * @return array{term: string, note: string, types: list<string>, cells: array<string, array{text: string, keep: bool}>}
     */
    private function glossaryRow(array $row): array
    {
        $cells = [];
        $translations = is_array($row['translations'] ?? null) ? $row['translations'] : [];
        foreach ($this->languages() as $code => $_) {
            $cells[$code] = [
                'text' => is_string($translations[$code] ?? null) ? $translations[$code] : '',
                'keep' => array_key_exists($code, $translations) && $translations[$code] === null,
            ];
        }

        return [
            'term' => (string) ($row['term'] ?? ''),
            'note' => (string) ($row['note'] ?? ''),
            'types' => array_values(array_map('strval', (array) ($row['types'] ?? []))),
            'cells' => $cells,
        ];
    }

    /**
     * The languages texts are translated into: the site's content languages but the default.
     *
     * @return array<string, string> code => name
     */
    private function languages(): array
    {
        $locales = $this->contentLocale->locales();
        $names = [];
        foreach ($locales->available as $code) {
            if ($code !== $locales->default) {
                $key = 'folio.languages.' . $code;
                $name = FolioText::get($key);
                $names[$code] = $name !== $key ? $name : strtoupper($code);
            }
        }

        return $names;
    }

    /**
     * Types with translatable fields in more than one language.
     *
     * @return array<string, string> key => label
     */
    private function translatableTypes(): array
    {
        $types = [];
        foreach ($this->catalog->all() as $listing) {
            $def = $this->registry->get($listing->key);
            if ($def->translatableFields() !== [] && $def->locales->isMultilingual()) {
                $types[$listing->key] = $listing->label;
            }
        }

        return $types;
    }

    /** @param array<string, mixed> $view */
    private function render(string $template, ServerRequestInterface $request, array $view, int $status = 200): ResponseInterface
    {
        return new Response($status, ['Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'no-store'], $this->engine->render('@folio/admin/ai/' . $template . '.twig', $view + [
            'prefix' => $this->prefix,
            'csrf' => $request->getAttribute(\Preflow\Core\Http\Csrf\CsrfToken::class)?->getValue() ?? '',
            'tab' => $template,
        ]));
    }

    /** @return list<string> */
    private static function lines(string $text): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\R/u', $text) ?: []), static fn (string $l): bool => $l !== ''));
    }

    /** @return array<string, mixed> */
    private static function body(ServerRequestInterface $request): array
    {
        $body = $request->getParsedBody();

        return is_array($body) ? $body : [];
    }

    private static function flash(ServerRequestInterface $request, string $type, string $message): void
    {
        $session = $request->getAttribute(\Preflow\Core\Http\Session\SessionInterface::class);
        if ($session instanceof \Preflow\Core\Http\Session\SessionInterface) {
            $session->flash($type, $message);
        }
    }
}
