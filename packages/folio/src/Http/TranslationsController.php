<?php

declare(strict_types=1);

namespace Preflow\Folio\Http;

use Nyholm\Psr7\Response;
use Preflow\Ai\Ai;
use Preflow\Auth\Access\Access;
use Preflow\Auth\Authenticatable;
use Preflow\Folio\FolioText;
use Preflow\Folio\Translations\EditableTexts;
use Preflow\Folio\Translations\StoredOverrides;
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
    public const int PER_PAGE = 50;

    public function __construct(
        private readonly StoredOverrides $overrides,
        private readonly EditableTexts $texts,
        private readonly InterfaceLocales $locales,
        private readonly TemplateEngineInterface $engine,
        private readonly string $prefix,
        private readonly ?Ai $ai = null,
        private readonly ?Access $access = null,
    ) {}

    /** GET {prefix}/translations?group=…&locale=…&q=…&page=… */
    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $query = $request->getQueryParams();
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
            if ($q === '' || self::matches($q, $row)) {
                $rows[] = $row;
            }
        }
        $total = count($rows);
        $lastPage = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($lastPage, max(1, (int) ($query['page'] ?? 1)));
        $view = ['group' => $group, 'locale' => $locale, 'q' => $q];

        $pages = [];
        for ($n = 1; $n <= $lastPage; $n++) {
            $pages[] = ['n' => $n, 'current' => $n === $page, 'href' => $this->url($view + ['page' => $n])];
        }

        return new Response(200, ['Content-Type' => 'text/html; charset=UTF-8'], $this->engine->render('@folio/admin/translations/index.twig', [
            'prefix' => $this->prefix,
            'groups' => $groups,
            'site_group' => EditableTexts::SITE,
            'locales' => array_combine($offered, array_map(LocaleNames::native(...), $offered)),
            'site_texts' => $this->texts->isEditableGroup($group),
            'view' => $view,
            'page' => $page,
            'rows' => array_slice($rows, ($page - 1) * self::PER_PAGE, self::PER_PAGE),
            'pager' => [
                'total' => $total,
                'from' => $total === 0 ? 0 : ($page - 1) * self::PER_PAGE + 1,
                'to' => min($total, $page * self::PER_PAGE),
                'last_page' => $lastPage,
                'pages' => $pages,
                'prev' => $page > 1 ? $this->url($view + ['page' => $page - 1]) : null,
                'next' => $page < $lastPage ? $this->url($view + ['page' => $page + 1]) : null,
            ],
            'csrf' => $request->getAttribute(\Preflow\Core\Http\Csrf\CsrfToken::class)?->getValue() ?? '',
            'ai_translate' => $this->aiTranslate($group, $locale, $q, $page),
            'default_language' => LocaleNames::native($this->texts->sourceLocale($locale) ?? 'en'),
            'editor_assets' => $this->aiOn() ? ['ai-translate.css', 'ai-translate-core.js', 'ai-translate.js'] : [],
        ]));
    }

    private function aiOn(): bool
    {
        return ($this->ai?->enabled() ?? false) && ($this->access?->can('ai.translate') ?? true);
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
            'marks' => false,
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

    private function store(ServerRequestInterface $request, ?string $text): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $locale = is_string($body['locale'] ?? null) ? $body['locale'] : '';
        $key = is_string($body['key'] ?? null) ? $body['key'] : '';
        $group = (string) strstr($key, '.', true);
        if (!in_array($locale, $this->texts->locales($group, $this->locales->all()), true)) {
            return self::refuse(FolioText::get('folio.locale.not_offered'));
        }
        if (!$this->texts->editable($key, $locale)) {
            return self::refuse(FolioText::get('folio.translations.not_editable'));
        }
        if ($text !== null) {
            if (mb_strlen($text) > StoredOverrides::MAX_LENGTH) {
                return self::refuse(FolioText::get('folio.translations.too_long', ['max' => StoredOverrides::MAX_LENGTH]));
            }
            // Plain text only: it is printed unescaped, also inside HTML attributes.
            $text = StoredOverrides::validText($text);
            if ($text === null) {
                return self::refuse(FolioText::get('folio.translations.no_markup'));
            }
            if ($this->texts->isEditableGroup($group)) {
                // Its texts are filled in by code (a mail's :link): the same placeholders, no more, no less.
                $needed = $this->texts->placeholders($key, $locale);
                $used = EditableTexts::placeholdersIn($text);
                foreach (array_diff($needed, $used) as $name) {
                    return self::refuse(FolioText::get('folio.translations.placeholder_missing', ['placeholder' => ':' . $name]));
                }
                foreach (array_diff($used, $needed) as $name) {
                    return self::refuse(FolioText::get('folio.translations.placeholder_unknown', ['placeholder' => ':' . $name]));
                }
            }
        }

        $user = $request->getAttribute(Authenticatable::class);
        $this->overrides->set($locale, $key, $text, $user instanceof Authenticatable ? (string) $user->getAuthId() : null);
        self::flash($request, FolioText::get($text === null ? 'folio.translations.reset_done' : 'folio.translations.saved', ['key' => $key]));

        $group = is_string($body['group'] ?? null) && in_array($body['group'], $this->texts->groups(), true) ? $body['group'] : $group;
        $page = (int) ($body['page'] ?? 1);

        return new Response(302, ['Location' => $this->url([
            'group' => $group,
            'locale' => $locale,
            'q' => is_string($body['q'] ?? null) ? trim($body['q']) : '',
            'page' => $page,
        ])]);
    }

    /**
     * The search over a row's key and texts: the screen's, and the same for the AI button's units.
     *
     * @param array{key: string, delivered: ?string, site: ?string, override: ?string} $row
     */
    public static function matches(string $q, array $row): bool
    {
        foreach ([$row['key'], $row['delivered'], $row['site'], $row['override']] as $text) {
            if ($text !== null && mb_stripos($text, $q) !== false) {
                return true;
            }
        }

        return false;
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
