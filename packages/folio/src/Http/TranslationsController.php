<?php

declare(strict_types=1);

namespace Preflow\Folio\Http;

use Nyholm\Psr7\Response;
use Preflow\Auth\Authenticatable;
use Preflow\Folio\FolioText;
use Preflow\Folio\Translations\EditableTexts;
use Preflow\Folio\Translations\StoredOverrides;
use Preflow\View\TemplateEngineInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * {prefix}/translations (Settings → Translations, permission translations.edit): the interface
 * texts of a group in one language, 50 a page, searchable over keys and texts, each with its
 * override field. An override is plain text and wins on admin requests only (StoredOverrides).
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
    ) {}

    /** GET {prefix}/translations?group=…&locale=…&q=…&page=… */
    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $query = $request->getQueryParams();
        $groups = $this->texts->groups();
        $group = is_string($query['group'] ?? null) && in_array($query['group'], $groups, true)
            ? $query['group']
            : (in_array('folio', $groups, true) ? 'folio' : $groups[0]);
        $locale = is_string($query['locale'] ?? null) && $this->locales->offers($query['locale']) ? $query['locale'] : $this->locales->current();
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
            'locales' => array_intersect_key($this->locales->names(), array_flip($this->locales->all())),
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
        ]));
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
        if (!$this->locales->offers($locale)) {
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
        }

        $user = $request->getAttribute(Authenticatable::class);
        $this->overrides->set($locale, $key, $text, $user instanceof Authenticatable ? (string) $user->getAuthId() : null);
        self::flash($request, FolioText::get($text === null ? 'folio.translations.reset_done' : 'folio.translations.saved', ['key' => $key]));

        $group = is_string($body['group'] ?? null) && in_array($body['group'], $this->texts->groups(), true) ? $body['group'] : (string) strstr($key, '.', true);
        $page = (int) ($body['page'] ?? 1);

        return new Response(302, ['Location' => $this->url([
            'group' => $group,
            'locale' => $locale,
            'q' => is_string($body['q'] ?? null) ? trim($body['q']) : '',
            'page' => $page,
        ])]);
    }

    /** @param array{key: string, delivered: ?string, site: ?string, override: ?string} $row */
    private static function matches(string $q, array $row): bool
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
