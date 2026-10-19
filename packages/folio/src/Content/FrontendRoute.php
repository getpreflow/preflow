<?php

declare(strict_types=1);

namespace Preflow\Folio\Content;

use Preflow\Folio\Routing\PatternCompiler;

/** One content type's public route, from its "frontend" block. */
final readonly class FrontendRoute
{
    /** @param list<string> $paramNames */
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
    ) {}

    /** @param array<string, mixed> $frontend */
    public static function fromConfig(string $type, array $frontend): self
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
        );
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
