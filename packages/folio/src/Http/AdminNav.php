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

    /** The path of the admin request being rendered; decides the active item. */
    public function setCurrentPath(string $path): void
    {
        $this->path = '/' . trim($path, '/');
    }

    /** @return list<array{label: string, items: list<array{label: string, url: string, icon: string, active: bool}>}> */
    public function sections(): array
    {
        $groups = array_fill_keys(array_keys(self::BUILTIN), []);
        $add = function (string $section, string $label, string $url, string $icon, ?string $permission, int $order) use (&$groups): void {
            $groups[$section][] = compact('label', 'url', 'icon', 'permission', 'order');
        };

        $add('dashboard', 'Dashboard', $this->prefix === '' ? '/' : $this->prefix, 'ui-dashboard', 'folio.dashboard', 100);
        foreach ($this->catalog->all() as $listing) {
            if ($listing->nav) {
                $icon = $listing->icon !== '' ? $listing->icon : ($listing->singleton ? 'ui-settings' : 'ui-file');
                $add($listing->section, $listing->label, $this->prefix . '/' . $listing->key, $icon, $listing->key . '.view', $listing->order);
            }
        }
        $add('library', 'Assets', $this->prefix . '/assets', 'ui-library', 'assets.view', 100);
        foreach ($this->added as $item) {
            $add($item['section'], $item['label'], $item['url'], $item['icon'], $item['permission'], $item['order']);
        }

        // Custom sections (in the order first used) go between Content and Library.
        $custom = array_diff_key($groups, self::BUILTIN);
        $ordered = $groups;
        $groups = [];
        foreach (self::BUILTIN as $section => $_) {
            if ($section === 'library') {
                $groups += $custom;
            }
            $groups[$section] = $ordered[$section] ?? [];
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
                'label' => self::BUILTIN[$section] ?? ucfirst(str_replace(['_', '-'], ' ', $section)),
                'items' => array_map(fn (array $item): array => [
                    'label' => $item['label'],
                    'url' => $item['url'],
                    'icon' => $item['icon'],
                    'active' => $item['url'] === $active,
                ], $items),
            ];
        }

        return $sections;
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
