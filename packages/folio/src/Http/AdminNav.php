<?php

declare(strict_types=1);

namespace Preflow\Folio\Http;

use Closure;
use Preflow\Folio\FolioText;
use Preflow\Folio\Content\DisplayLabels;
use Preflow\Folio\Content\TypeCatalog;

/**
 * The admin sidebar as data: Dashboard, Pages, Content, the other sections types
 * ("section" in a model JSON) and extensions add, then Library and Settings. One
 * instance per application (container singleton), so items added at boot are there when
 * a template renders.
 */
final class AdminNav
{
    /** Built-in sections => their heading's key in Folio's texts (the dashboard has none). */
    private const BUILTIN = ['dashboard' => '', 'pages' => 'folio.nav.pages', 'content' => 'folio.nav.content', 'library' => 'folio.nav.library', 'settings' => 'folio.nav.settings'];

    private string $prefix;
    private string $path = '';
    /** @var list<array{section: string, label: string|Closure, url: string, icon: string, permission: ?string, order: int, badge: ?Closure}> */
    private array $added = [];
    /** @var (Closure(string): ?int)|null the count badge of a type key; set at boot (Customizations resolve after the nav is built) */
    private ?Closure $badges = null;

    /** @var array<int, ?int> added items' badge results by closure id (the closures live in $added, so ids are stable) */
    private array $badgeCounts = [];

    private readonly DisplayLabels $labels;

    /** @param Closure(string): bool $can */
    public function __construct(
        private readonly TypeCatalog $catalog,
        private readonly Closure $can,
        string $prefix,
        ?DisplayLabels $labels = null,
    ) {
        $this->prefix = rtrim($prefix, '/');
        $this->labels = $labels ?? new DisplayLabels($catalog);
    }

    /**
     * Adds an item to a section; a new section name sits between Content and Library, in the order first used. A null permission shows it to everyone.
     *
     * @param string|Closure(): string $label a closure is asked when the sidebar renders (a text in the interface language)
     * @param (Closure(): mixed)|null $badge a count for the item, asked once and only while the item is visible; anything but an int above 0 (or a throw) shows none
     */
    public function add(string $section, string|Closure $label, string $url, string $icon, ?string $permission, int $order = 100, ?Closure $badge = null): void
    {
        $this->added[] = compact('section', 'label', 'url', 'icon', 'permission', 'order', 'badge');
    }

    /** @param Closure(string): ?int $badges a type key's nav badge count; called only for visible items */
    public function setBadges(Closure $badges): void
    {
        $this->badges = $badges;
    }

    /** The path of the admin request being rendered; decides the active item. */
    public function setCurrentPath(string $path): void
    {
        $this->path = '/' . trim($path, '/');
    }

    /** @return list<array{label: string, items: list<array{label: string, url: string, icon: string, active: bool, badge: ?int}>}> */
    public function sections(): array
    {
        $groups = array_fill_keys(array_keys(self::BUILTIN), []);
        $add = function (string $section, string|Closure $label, string $url, string $icon, ?string $permission, int $order, ?string $type = null, ?Closure $badge = null) use (&$groups): void {
            $groups[$section][] = compact('label', 'url', 'icon', 'permission', 'order', 'type', 'badge');
        };

        $add('dashboard', static fn (): string => FolioText::get('folio.nav.dashboard'), $this->prefix === '' ? '/' : $this->prefix, 'ui-dashboard', 'folio.dashboard', 100);
        foreach ($this->catalog->all() as $listing) {
            if ($listing->nav) {
                $icon = $listing->icon !== '' ? $listing->icon : ($listing->singleton ? 'ui-settings' : 'ui-file');
                $add($listing->section, $this->labels->type($listing->key), $this->prefix . '/' . $listing->key, $icon, $listing->key . '.view', $listing->order, $listing->key);
            }
        }
        $add('library', static fn (): string => FolioText::get('folio.nav.assets'), $this->prefix . '/assets', 'ui-library', 'assets.view', 100);
        foreach ($this->added as $item) {
            $add($item['section'], $item['label'], $item['url'], $item['icon'], $item['permission'], $item['order'], null, $item['badge']);
        }

        $ordered = $groups;
        $groups = [];
        foreach (self::sectionOrder(array_keys($ordered)) as $section) {
            $groups[$section] = $ordered[$section];
        }

        $visible = [];
        foreach ($groups as $section => $items) {
            $items = array_values(array_filter(
                $items,
                fn (array $item): bool => $item['permission'] === null || ($this->can)($item['permission']),
            ));
            usort($items, fn (array $a, array $b): int => $a['order'] <=> $b['order']); // stable
            if ($items !== []) {
                $visible[$section] = $items;
            }
        }

        $active = $this->activeUrl($visible);
        $sections = [];
        foreach ($visible as $section => $items) {
            $sections[] = [
                'label' => isset(self::BUILTIN[$section]) ? self::sectionLabel($section) : $this->labels->section($section),
                'items' => array_map(fn (array $item): array => [
                    'label' => $item['label'] instanceof Closure ? ($item['label'])() : $item['label'],
                    'url' => $item['url'],
                    'icon' => $item['icon'],
                    'active' => $item['url'] === $active,
                    'badge' => $this->badge($item),
                ], $items),
            ];
        }

        return $sections;
    }

    /** @param array{type: ?string, badge: ?Closure} $item */
    private function badge(array $item): ?int
    {
        if ($item['type'] !== null) {
            return $this->badges !== null ? ($this->badges)($item['type']) : null;
        }
        if ($item['badge'] === null) {
            return null;
        }
        $key = spl_object_id($item['badge']);
        if (!array_key_exists($key, $this->badgeCounts)) {
            try {
                $count = ($item['badge'])();
            } catch (\Throwable) {
                $count = null;
            }
            $this->badgeCounts[$key] = is_int($count) && $count > 0 ? $count : null;
        }

        return $this->badgeCounts[$key];
    }

    /**
     * Section names in sidebar order: the built-in ones (dashboard first), with the others
     * of $sections (in the given order, i.e. first used) between Content and Library.
     *
     * @param list<string> $sections
     * @return list<string>
     */
    public static function sectionOrder(array $sections): array
    {
        $custom = array_values(array_diff(array_unique($sections), array_keys(self::BUILTIN)));
        $ordered = [];
        foreach (array_keys(self::BUILTIN) as $section) {
            if ($section === 'library') {
                array_push($ordered, ...$custom);
            }
            $ordered[] = $section;
        }

        return $ordered;
    }

    /** A section's heading: "Pages" for pages (Folio's own sections in the interface language), "Member area" for member_area. */
    public static function sectionLabel(string $section): string
    {
        if (isset(self::BUILTIN[$section])) {
            return self::BUILTIN[$section] === '' ? '' : FolioText::get(self::BUILTIN[$section]);
        }

        return ucfirst(str_replace(['_', '-'], ' ', $section));
    }

    /**
     * Longest URL that is the path or a parent of it; the dashboard only on an exact match.
     *
     * @param array<string, list<array{url: string}>> $visible
     */
    private function activeUrl(array $visible): ?string
    {
        $dashboard = $this->prefix === '' ? '/' : $this->prefix;
        $best = null;
        foreach ($visible as $items) {
            foreach ($items as $item) {
                $url = $item['url'];
                $matches = $url === $dashboard
                    ? $this->path === $url
                    : $this->path === $url || str_starts_with($this->path, rtrim($url, '/') . '/');
                if ($matches && ($best === null || strlen($url) > strlen($best))) {
                    $best = $url;
                }
            }
        }

        return $best;
    }
}
