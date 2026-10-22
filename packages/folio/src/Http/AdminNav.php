<?php

declare(strict_types=1);

namespace Preflow\Folio\Http;

use Closure;
use Preflow\Folio\Content\TypeCatalog;

/**
 * The admin sidebar as data: Dashboard, Pages, Content, the other sections types
 * ("section" in a model JSON) and extensions add, then Library and Settings. One
 * instance per application (container singleton), so items added at boot are there when
 * a template renders.
 */
final class AdminNav
{
    private const BUILTIN = ['dashboard' => '', 'pages' => 'Pages', 'content' => 'Content', 'library' => 'Library', 'settings' => 'Settings'];

    private string $prefix;
    private string $path = '';
    /** @var list<array{section: string, label: string, url: string, icon: string, permission: ?string, order: int}> */
    private array $added = [];
    /** @var (Closure(string): ?int)|null the count badge of a type key; set at boot (Customizations resolve after the nav is built) */
    private ?Closure $badges = null;

    /** @param Closure(string): bool $can */
    public function __construct(
        private readonly TypeCatalog $catalog,
        private readonly Closure $can,
        string $prefix,
    ) {
        $this->prefix = rtrim($prefix, '/');
    }

    /** Adds an item to a section; a new section name sits between Content and Library, in the order first used. A null permission shows it to everyone. */
    public function add(string $section, string $label, string $url, string $icon, ?string $permission, int $order = 100): void
    {
        $this->added[] = compact('section', 'label', 'url', 'icon', 'permission', 'order');
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
        $add = function (string $section, string $label, string $url, string $icon, ?string $permission, int $order, ?string $type = null) use (&$groups): void {
            $groups[$section][] = compact('label', 'url', 'icon', 'permission', 'order', 'type');
        };

        $add('dashboard', 'Dashboard', $this->prefix === '' ? '/' : $this->prefix, 'ui-dashboard', 'folio.dashboard', 100);
        foreach ($this->catalog->all() as $listing) {
            if ($listing->nav) {
                $icon = $listing->icon !== '' ? $listing->icon : ($listing->singleton ? 'ui-settings' : 'ui-file');
                $add($listing->section, $listing->label, $this->prefix . '/' . $listing->key, $icon, $listing->key . '.view', $listing->order, $listing->key);
            }
        }
        $add('library', 'Assets', $this->prefix . '/assets', 'ui-library', 'assets.view', 100);
        foreach ($this->added as $item) {
            $add($item['section'], $item['label'], $item['url'], $item['icon'], $item['permission'], $item['order']);
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
                'label' => self::sectionLabel($section),
                'items' => array_map(fn (array $item): array => [
                    'label' => $item['label'],
                    'url' => $item['url'],
                    'icon' => $item['icon'],
                    'active' => $item['url'] === $active,
                    'badge' => $item['type'] !== null && $this->badges !== null ? ($this->badges)($item['type']) : null,
                ], $items),
            ];
        }

        return $sections;
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

    /** A section's heading: "Pages" for pages, "Member area" for member_area. */
    public static function sectionLabel(string $section): string
    {
        return self::BUILTIN[$section] ?? ucfirst(str_replace(['_', '-'], ' ', $section));
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
