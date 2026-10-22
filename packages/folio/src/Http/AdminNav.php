<?php

declare(strict_types=1);

namespace Preflow\Folio\Http;

use Closure;
use Preflow\Folio\Content\TypeCatalog;

/**
 * The admin sidebar as data: Dashboard, Content, Library, Settings, then sections
 * extensions add. Static because extension screens register items at boot, long
 * before any template renders; FolioServiceProvider::boot() configures (and so
 * resets) it once per application.
 */
final class AdminNav
{
    private const BUILTIN = ['dashboard' => '', 'content' => 'Content', 'library' => 'Library', 'settings' => 'Settings'];

    private static ?TypeCatalog $catalog = null;
    /** @var Closure(string): bool */
    private static Closure $can;
    private static string $prefix = '/folio';
    private static string $path = '';
    /** @var list<array{section: string, label: string, url: string, icon: string, permission: ?string, order: int}> */
    private static array $added = [];

    /** @param Closure(string): bool $can */
    public static function configure(TypeCatalog $catalog, Closure $can, string $prefix): void
    {
        self::$catalog = $catalog;
        self::$can = $can;
        self::$prefix = rtrim($prefix, '/');
        self::$path = '';
        self::$added = [];
    }

    /** Adds an item to a section; a new section name is appended after the built-in ones. A null permission shows it to everyone. */
    public static function add(string $section, string $label, string $url, string $icon, ?string $permission, int $order = 100): void
    {
        self::$added[] = compact('section', 'label', 'url', 'icon', 'permission', 'order');
    }

    /** The path of the admin request being rendered; decides the active item. */
    public static function setCurrentPath(string $path): void
    {
        self::$path = '/' . trim($path, '/');
    }

    /** @return list<array{label: string, items: list<array{label: string, url: string, icon: string, active: bool}>}> */
    public static function sections(): array
    {
        $groups = array_fill_keys(array_keys(self::BUILTIN), []);
        $add = static function (string $section, string $label, string $url, string $icon, ?string $permission, int $order) use (&$groups): void {
            $groups[$section][] = compact('label', 'url', 'icon', 'permission', 'order');
        };

        $add('dashboard', 'Dashboard', self::$prefix === '' ? '/' : self::$prefix, 'ui-dashboard', 'folio.dashboard', 100);
        foreach (self::$catalog?->all() ?? [] as $listing) {
            $add($listing->section === 'settings' ? 'settings' : 'content', $listing->label, self::$prefix . '/' . $listing->key, $listing->singleton ? 'ui-settings' : 'ui-file', $listing->key . '.view', 100);
        }
        $add('library', 'Assets', self::$prefix . '/assets', 'ui-library', 'assets.view', 100);
        foreach (self::$added as $item) {
            $add($item['section'], $item['label'], $item['url'], $item['icon'], $item['permission'], $item['order']);
        }

        $visible = [];
        foreach ($groups as $section => $items) {
            $items = array_values(array_filter(
                $items,
                static fn (array $item): bool => $item['permission'] === null || (self::$can)($item['permission']),
            ));
            usort($items, static fn (array $a, array $b): int => $a['order'] <=> $b['order']); // stable
            if ($items !== []) {
                $visible[$section] = $items;
            }
        }

        $active = self::activeUrl($visible);
        $sections = [];
        foreach ($visible as $section => $items) {
            $sections[] = [
                'label' => self::BUILTIN[$section] ?? $section,
                'items' => array_map(static fn (array $item): array => [
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
    private static function activeUrl(array $visible): ?string
    {
        $dashboard = self::$prefix === '' ? '/' : self::$prefix;
        $best = null;
        foreach ($visible as $items) {
            foreach ($items as $item) {
                $url = $item['url'];
                $matches = $url === $dashboard
                    ? self::$path === $url
                    : self::$path === $url || str_starts_with(self::$path, rtrim($url, '/') . '/');
                if ($matches && ($best === null || strlen($url) > strlen($best))) {
                    $best = $url;
                }
            }
        }

        return $best;
    }
}
