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
        public string $publishedValue,
    ) {}

    /** @param array<string, mixed> $frontend */
    public static function fromConfig(string $type, array $frontend): self
    {
        $pattern = '/' . trim((string) ($frontend['route'] ?? '/{slug}'), '/');
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
            publishedValue: (string) ($published['value'] ?? 'published'),
        );
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
