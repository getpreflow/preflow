<?php

declare(strict_types=1);

namespace Preflow\Folio\Http;

use Nyholm\Psr7\Response;
use Preflow\Auth\Access\Access;
use Preflow\Auth\Authenticatable;
use Preflow\Folio\FolioText;
use Preflow\Folio\Translations\EditableTexts;
use Preflow\Folio\Translations\LabelSearch;
use Preflow\Folio\Translations\StoredOverrides;
use Preflow\I18n\OverrideRefusal;
use Preflow\I18n\OverrideRefused;
use Preflow\I18n\OverrideRules;
use Preflow\I18n\LocaleNames;
use Preflow\View\TemplateEngineInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * {prefix}/translations (Settings → Translations, permission translations.edit): the interface
 * texts of a group in one language, 50 a page, searchable over keys and texts, each with its
 * override field. An override is plain text and wins on admin requests only (StoredOverrides),
 * except for the site's editable groups: offered in every language of the site, their overrides
 * apply on every request and keep the delivered text's placeholders.
 */
final class TranslationsController
{
    public function __construct(
        private readonly StoredOverrides $overrides,
        private readonly EditableTexts $texts,
        private readonly InterfaceLocales $locales,
        private readonly TemplateEngineInterface $engine,
        private readonly string $prefix,
        /** Asked whether machine translation is available (since 0.66; before, the AI connection). */
        private readonly ?\Preflow\I18n\Machine\MachineTranslator $translator = null,
        private readonly ?Access $access = null,
    ) {}

    /** GET {prefix}/translations?group=…&locale=…&q=…&page=… */
    public function index(ServerRequestInterface $request): ResponseInterface
    {
        return $this->screen($request, $request->getQueryParams());
    }

    /**
     * The screen for $query. After a "Save all filled" with refused texts, $errors (key => message)
     * shows under each refused row and $values (key => posted text) keeps what was typed there.
     *
     * @param array<string, mixed> $query
     * @param array<string, string> $errors
     * @param array<string, string> $values
     */
    private function screen(ServerRequestInterface $request, array $query, array $errors = [], array $values = [], int $status = 200): ResponseInterface
    {
        $groups = $this->texts->groups();
        $group = is_string($query['group'] ?? null) && in_array($query['group'], $groups, true)
            ? $query['group']
            : (in_array('folio', $groups, true) ? 'folio' : $groups[0]);
        $offered = $this->texts->locales($group, $this->locales->all());
        $locale = is_string($query['locale'] ?? null) && in_array($query['locale'], $offered, true)
            ? $query['locale']
            : (in_array($this->locales->current(), $offered, true) ? $this->locales->current() : ($offered[0] ?? $this->locales->current()));
        $q = is_string($query['q'] ?? null) ? trim($query['q']) : '';

        $overrides = $this->overrides->all($locale);
        $rows = [];
        foreach ($this->texts->rows($group, $locale) as $key => $row) {
            $row['override'] = $overrides[$key] ?? null;
            if ($q === '' || LabelSearch::matches($q, $row)) {
                $rows[] = $row;
            }
        }
        $total = count($rows);
        $lastPage = max(1, (int) ceil($total / LabelSearch::PER_PAGE));
        $page = min($lastPage, max(1, (int) ($query['page'] ?? 1)));
        $view = ['group' => $group, 'locale' => $locale, 'q' => $q];

        $pages = [];
        for ($n = 1; $n <= $lastPage; $n++) {
            $pages[] = ['n' => $n, 'current' => $n === $page, 'href' => $this->url($view + ['page' => $n])];
        }

        return new Response($status, ['Content-Type' => 'text/html; charset=UTF-8'], $this->engine->render('@folio/admin/translations/index.twig', [
            'prefix' => $this->prefix,
            'groups' => $groups,
            'site_group' => EditableTexts::SITE,
            'locales' => array_combine($offered, array_map(LocaleNames::native(...), $offered)),
            'site_texts' => $this->texts->isEditableGroup($group),
            'view' => $view,
            'page' => $page,
            'rows' => array_slice($rows, ($page - 1) * LabelSearch::PER_PAGE, LabelSearch::PER_PAGE),
            'pager' => [
                'total' => $total,
                'from' => $total === 0 ? 0 : ($page - 1) * LabelSearch::PER_PAGE + 1,
                'to' => min($total, $page * LabelSearch::PER_PAGE),
                'last_page' => $lastPage,
                'pages' => $pages,
                'prev' => $page > 1 ? $this->url($view + ['page' => $page - 1]) : null,
                'next' => $page < $lastPage ? $this->url($view + ['page' => $page + 1]) : null,
            ],
            'csrf' => $request->getAttribute(\Preflow\Core\Http\Csrf\CsrfToken::class)?->getValue() ?? '',
            'errors' => $errors,
            'values' => $values,
            'ai_translate' => $this->aiTranslate($group, $locale, $q, $page),
            'choose_target' => $this->aiOn() && $this->texts->sourceLocale($locale) === null,
            'default_language' => LocaleNames::native($this->texts->sourceLocale($locale) ?? 'en'),
            'editor_assets' => $this->aiOn() ? ['ai-translate.css', 'ai-translate-core.js', 'ai-translate.js'] : [],
        ]));
    }

    private function aiOn(): bool
    {
        return ($this->translator?->available() ?? false) && ($this->access?->can('ai.translate') ?? true);
    }

    /**
     * The Translate button's settings for the shown page, or null: AI is off, the user lacks
     * ai.translate, or the language is the source (English has nothing to translate it from).
     *
     * @return array<string, mixed>|null
     */
    private function aiTranslate(string $group, string $locale, string $q, int $page): ?array
    {
        if (!$this->aiOn() || $this->texts->sourceLocale($locale) === null) {
            return null;
        }

        return [
            'suggest_url' => $this->prefix . '/translations/_ai/suggest',
            'estimate_url' => '',
            'locale' => $locale,
            'language' => LocaleNames::native($locale),
            'form' => '.folio-translations',
            'extra' => ['group' => $group, 'q' => $q, 'page' => $page],
            'intro' => FolioText::get('folio.ai_translate.intro_labels'),
            'scope_needed' => FolioText::get('folio.ai_translate.scope_needed_labels'),
            'filled' => FolioText::get('folio.ai_translate.filled_labels'),
        ];
    }

    /** POST {prefix}/translations: locale, key, text (empty: back to the delivered text). */
    public function save(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $text = is_string($body['text'] ?? null) ? str_replace("\r\n", "\n", $body['text']) : '';

        return $this->store($request, trim($text) === '' ? null : $text);
    }

    /** POST {prefix}/translations/reset: locale, key. */
    public function reset(ServerRequestInterface $request): ResponseInterface
    {
        return $this->store($request, null);
    }

    /**
     * POST {prefix}/translations/save-all: locale, keys[] and texts[] (pairs by position), plus the
     * view (group, q, page). "Save all filled": every row the AI filled or someone changed, in one
     * request, so saving one row no longer reloads the page over the others. Each text goes through
     * the same checks as a single save; the valid ones are stored, a refused one stays in its field
     * with the reason under it (422).
     */
    public function saveAll(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $locale = is_string($body['locale'] ?? null) ? $body['locale'] : '';
        $keys = is_array($body['keys'] ?? null) ? array_values($body['keys']) : [];
        $texts = is_array($body['texts'] ?? null) ? array_values($body['texts']) : [];
        $user = $request->getAttribute(Authenticatable::class);
        $by = $user instanceof Authenticatable ? (string) $user->getAuthId() : null;

        $saved = 0;
        $errors = $values = [];
        foreach ($keys as $i => $key) {
            if (!is_string($key) || !is_string($texts[$i] ?? null)) {
                continue;
            }
            $text = str_replace("\r\n", "\n", $texts[$i]);
            try {
                $this->overrides->set($locale, $key, trim($text) === '' ? null : $text, $by);
            } catch (OverrideRefused $e) {
                $errors[$key] = self::message($e->refusal);
                $values[$key] = $text;
                continue;
            }
            $saved++;
        }

        $view = $this->view($body, $locale, (string) strstr(is_string($keys[0] ?? null) ? $keys[0] : '', '.', true));
        if ($errors !== []) {
            return $this->screen($request, $view, $errors, $values, 422);
        }
        self::flash($request, FolioText::get('folio.translations.saved_all', ['count' => $saved]));

        return new Response(302, ['Location' => $this->url($view)]);
    }

    private function store(ServerRequestInterface $request, ?string $text): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $locale = is_string($body['locale'] ?? null) ? $body['locale'] : '';
        $key = is_string($body['key'] ?? null) ? $body['key'] : '';
        $user = $request->getAttribute(Authenticatable::class);
        try {
            $this->overrides->set($locale, $key, $text, $user instanceof Authenticatable ? (string) $user->getAuthId() : null);
        } catch (OverrideRefused $e) {
            return self::refuse(self::message($e->refusal));
        }
        self::flash($request, FolioText::get($text === null ? 'folio.translations.reset_done' : 'folio.translations.saved', ['key' => $key]));

        return new Response(302, ['Location' => $this->url($this->view($body, $locale, (string) strstr($key, '.', true)))]);
    }

    /**
     * The view to go back to after a save: the posted group (else $group), language, search and page.
     *
     * @param array<mixed> $body
     * @return array{group: string, locale: string, q: string, page: int}
     */
    private function view(array $body, string $locale, string $group): array
    {
        return [
            'group' => is_string($body['group'] ?? null) && in_array($body['group'], $this->texts->groups(), true) ? $body['group'] : $group,
            'locale' => $locale,
            'q' => is_string($body['q'] ?? null) ? trim($body['q']) : '',
            'page' => (int) ($body['page'] ?? 1),
        ];
    }

    /** The words for a refusal of the override rules. */
    private static function message(OverrideRefusal $refusal): string
    {
        return FolioText::get(match ($refusal->reason) {
            OverrideRules::NOT_OFFERED => 'folio.locale.not_offered',
            OverrideRules::NOT_EDITABLE => 'folio.translations.not_editable',
            OverrideRules::TOO_LONG => 'folio.translations.too_long',
            OverrideRules::NO_MARKUP => 'folio.translations.no_markup',
            OverrideRules::PLACEHOLDER_MISSING => 'folio.translations.placeholder_missing',
            OverrideRules::PLACEHOLDER_UNKNOWN => 'folio.translations.placeholder_unknown',
        }, $refusal->params);
    }

    /** @param array<string, string|int> $params */
    private function url(array $params): string
    {
        $params = array_filter($params, static fn (string|int $value, string $name): bool => $value !== '' && !($name === 'page' && (int) $value <= 1), ARRAY_FILTER_USE_BOTH);

        return $this->prefix . '/translations' . ($params === [] ? '' : '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986));
    }

    private static function refuse(string $message): ResponseInterface
    {
        return new Response(422, ['Content-Type' => 'text/plain; charset=UTF-8'], $message);
    }

    private static function flash(ServerRequestInterface $request, string $message): void
    {
        $session = $request->getAttribute(\Preflow\Core\Http\Session\SessionInterface::class);
        if ($session instanceof \Preflow\Core\Http\Session\SessionInterface) {
            $session->flash('success', $message);
        }
    }
}
