<?php

declare(strict_types=1);

namespace Preflow\View\Audit\Checks;

use Preflow\View\Audit\AuditRegistry;
use Preflow\View\Audit\RenderedPage;
use Preflow\View\Audit\RunCheck;
use Preflow\View\Audit\Severity;
use Preflow\View\Seo\SeoConfig;

/**
 * `geo.type-schema-missing` (notice): SEO is on, and a group of at least three
 * pages under the same first address segment (`/spiele/…`, `/de/spiele/…`; a
 * language prefix counts with it) carries no JSON-LD node of its own, only the
 * generic ones (WebSite, WebPage, BreadcrumbList…). Search engines and AI
 * assistants then can't tell what the pages are about (a game, a product, an
 * event). One finding per group, at its first page. Top-level pages are no
 * group. With SEO off it finds nothing.
 *
 * @since 0.55
 */
final class GeoTypeSchemaMissing implements RunCheck
{
    /** Fewer pages than this under one segment are no group of their own. */
    private const MIN_PAGES = 3;

    /** Nodes every page or site has; any other type says what a page is about. */
    private const GENERIC = [
        'WebSite', 'WebPage', 'Organization', 'Person', 'BreadcrumbList', 'ListItem', 'ImageObject',
        'SearchAction', 'EntryPoint', 'SiteNavigationElement', 'ContactPoint', 'PostalAddress',
    ];

    /** @var array<string, array{n: int, own: bool, url: string, locale: string}> group prefix => what was seen */
    private array $groups = [];

    public function __construct(
        private readonly AuditRegistry $registry,
        private readonly SeoConfig $seo,
    ) {}

    public function id(): string
    {
        return 'geo.type-schema-missing';
    }

    public function collect(RenderedPage $page): void
    {
        $prefix = self::prefix($page);
        $dom = $prefix !== null && $this->seo->enabled() ? $page->dom() : null;
        if ($dom === null) {
            return;
        }

        $group = $this->groups[$prefix] ?? ['n' => 0, 'own' => false, 'url' => $page->url, 'locale' => $page->locale];
        $group['n']++;
        $group['own'] = $group['own'] || array_diff(self::types($dom), self::GENERIC) !== [];
        $this->groups[$prefix] = $group;
    }

    public function finish(): array
    {
        $findings = [];
        foreach ($this->groups as $prefix => $group) {
            if ($group['n'] >= self::MIN_PAGES && !$group['own']) {
                $findings[] = Html::finding($this->registry, $this->id(), Severity::Notice,
                    "The {$group['n']} pages under {$prefix} carry no structured data of their own, only the generic page nodes, so search engines and AI assistants can't tell what they are about. If they describe things (a game, a product, an event), add a node: a SchemaRegistry builder for a Folio type, or seo({schema: …}) in the template.",
                    $group['url'], $group['locale']);
            }
        }

        return $findings;
    }

    public function state(): array
    {
        return $this->groups;
    }

    public function restore(array $state): void
    {
        $this->groups = $state;
    }

    /** "/spiele/" or "/de/spiele/" for a page below it; null for a top-level page. */
    private static function prefix(RenderedPage $page): ?string
    {
        $segments = array_values(array_filter(explode('/', (string) parse_url($page->url, PHP_URL_PATH)), static fn (string $s): bool => $s !== ''));
        $lead = isset($segments[0]) && $segments[0] === $page->locale ? [array_shift($segments)] : [];
        if (count($segments) < 2) {
            return null;
        }

        return '/' . implode('/', [...$lead, $segments[0]]) . '/';
    }

    /**
     * The @type values of the page's JSON-LD, nested nodes included.
     *
     * @return list<string>
     */
    private static function types(\DOMDocument $dom): array
    {
        $types = [];
        foreach ($dom->getElementsByTagName('script') as $script) {
            if (!str_contains(strtolower($script->getAttribute('type')), 'ld+json')) {
                continue;
            }
            self::collectTypes(json_decode((string) $script->textContent, true), $types);
        }

        return $types;
    }

    /** @param list<string> $types gets every @type in $node (a string or a list of them) */
    private static function collectTypes(mixed $node, array &$types): void
    {
        if (!is_array($node)) {
            return;
        }
        foreach ($node as $key => $value) {
            if ($key === '@type') {
                // "https://schema.org/WebPage" and "schema:WebPage" are WebPage too.
                $names = array_filter((array) $value, 'is_string');
                array_push($types, ...array_map(static fn (string $t): string => (string) preg_replace('~^(?:https?://schema\.org/|schema:)~i', '', $t), $names));
            } else {
                self::collectTypes($value, $types);
            }
        }
    }
}
