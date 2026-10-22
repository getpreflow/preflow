<?php

declare(strict_types=1);

namespace Preflow\Folio\Content;

use Preflow\Folio\Routing\PatternCompiler;

/** One content type's public route, from its "frontend" block. */
final readonly class FrontendRoute
{
    /**
     * @param list<string>                      $paramNames
     * @param string|array<string, string>|null $login      the login path, or one per locale
     */
    public function __construct(
        public string $type,
        public string $pattern,
        public string $regex,
        public array $paramNames,
        public bool $isCatchAll,
        public int $staticSegments,
        public ?string $home,
        public ?string $template,
        public string $publishedField,
        public string|int|float|bool $publishedValue,
        public bool $publishing = false,
        public ?string $permission = null,
        public string|array|null $login = null,
    ) {}

    /**
     * @param array<string, mixed> $frontend
     * @param bool                 $publishing records are read from published snapshots; the
     *                                         "published" field convention is then ignored
     */
    public static function fromConfig(string $type, array $frontend, bool $publishing = false): self
    {
        $pattern = '/' . trim((string) ($frontend['route'] ?? '/{slug}'), '/');
        self::assertValidPattern($type, $pattern);
        $compiled = PatternCompiler::compile($pattern);
        $published = (array) ($frontend['published'] ?? []);

        $static = 0;
        foreach (explode('/', trim($pattern, '/')) as $segment) {
            if ($segment !== '' && !str_contains($segment, '{')) {
                $static++;
            }
        }

        return new self(
            type: $type,
            pattern: $pattern,
            regex: $compiled['regex'],
            paramNames: $compiled['paramNames'],
            isCatchAll: $compiled['isCatchAll'],
            staticSegments: $static,
            home: isset($frontend['home']) ? (string) $frontend['home'] : null,
            template: isset($frontend['template']) ? (string) $frontend['template'] : null,
            publishedField: (string) ($published['field'] ?? 'status'),
            publishedValue: self::publishedValue($type, $published['value'] ?? 'published'),
            publishing: $publishing,
            permission: self::permission($type, $frontend['permission'] ?? null),
            login: self::login($type, $frontend['login'] ?? null),
        );
    }

    /**
     * The login path for a locale: its own, the default locale's, or the first
     * one; null when the type has no login (a refused visitor gets a 404).
     */
    public function loginFor(string $locale, string $default): ?string
    {
        if (!is_array($this->login)) {
            return $this->login;
        }

        return $this->login[$locale] ?? $this->login[$default] ?? $this->login[array_key_first($this->login)];
    }

    private static function permission(string $type, mixed $permission): ?string
    {
        if ($permission === null) {
            return null;
        }
        if (!is_string($permission) || $permission === '') {
            throw new FrontendConfigException(sprintf('Folio type "%s": frontend.permission must be a permission name, e.g. "members.access".', $type));
        }

        return $permission;
    }

    /**
     * A same-origin path ("/tools/login"), or a map of them by locale.
     *
     * @return string|array<string, string>|null
     */
    private static function login(string $type, mixed $login): string|array|null
    {
        if ($login === null) {
            return null;
        }
        $paths = is_array($login) ? $login : [$login];
        foreach ($paths as $locale => $path) {
            if ((is_array($login) && !is_string($locale)) || !is_string($path) || !str_starts_with($path, '/') || str_starts_with($path, '//') || str_contains($path, '\\')) {
                throw new FrontendConfigException(sprintf('Folio type "%s": frontend.login must be a path on this site ("/login") or a map of them by locale ({"en": "/login", "de": "/de/login"}).', $type));
            }
        }
        if ($paths === []) {
            throw new FrontendConfigException(sprintf('Folio type "%s": frontend.login must not be empty.', $type));
        }

        return $login;
    }

    /**
     * The configured value keeps its JSON type: storage compares strictly, so a
     * boolean field needs true, not "1".
     */
    private static function publishedValue(string $type, mixed $value): string|int|float|bool
    {
        if (!is_scalar($value)) {
            throw new FrontendConfigException(sprintf('Folio type "%s": frontend.published.value must be a string, number or boolean.', $type));
        }

        return $value;
    }

    /**
     * Rejects patterns PatternCompiler can't handle (it would loop on an unclosed
     * brace) or that could never match, with a message naming the type.
     */
    private static function assertValidPattern(string $type, string $pattern): void
    {
        $fail = static fn (string $why) => new FrontendConfigException(
            sprintf('Folio type "%s": invalid frontend route "%s": %s.', $type, $pattern, $why),
        );

        if (substr_count($pattern, '{') !== substr_count($pattern, '}') || preg_match('/\{[^}]*\{|\}[^{]*\}/', $pattern) === 1
            || preg_match('/\{[^}]*$/', $pattern) === 1) {
            throw $fail('unclosed or nested brace');
        }

        $segments = explode('/', trim($pattern, '/'));
        $params = 0;
        foreach ($segments as $i => $segment) {
            if (preg_match_all('/\{(\.\.\.)?([^}]*)\}/', $segment, $m, PREG_SET_ORDER) === 0) {
                continue;
            }
            foreach ($m as [, $catchAll, $name]) {
                if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) !== 1) {
                    throw $fail(sprintf('parameter name "%s" must match [A-Za-z_][A-Za-z0-9_]*', $name));
                }
                if ($catchAll !== '' && $i !== array_key_last($segments)) {
                    throw $fail('a catch-all {...name} must be the last segment');
                }
                $params++;
            }
        }

        if (substr_count($pattern, '{...') > 1) {
            throw $fail('only one catch-all {...name} is allowed');
        }
        if ($params === 0) {
            throw $fail('it needs at least one parameter, e.g. "/{slug}" (use "home" to serve "/")');
        }
    }

    /** The pattern with parameter names erased, for duplicate detection ("/{slug}" ≡ "/{title}"). */
    public function shape(): string
    {
        return (string) preg_replace('/\{(\.\.\.)?[^}]+\}/', '{$1}', $this->pattern);
    }

    /**
     * Templates to try, in order: the type's userland template, then a
     * per-type @folio template, then the generic page template.
     *
     * @return list<string>
     */
    public function templates(): array
    {
        return array_values(array_unique(array_filter([
            $this->template,
            '@folio/frontend/' . $this->type . '.twig',
            '@folio/frontend/page.twig',
        ])));
    }
}
