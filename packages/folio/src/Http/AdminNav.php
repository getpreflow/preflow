<?php

declare(strict_types=1);

namespace Preflow\Folio\Http;

use Closure;
use Preflow\Data\TypeFamily;
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
    /** @var list<array{section: string, label: string|Closure, url: string, icon: string, permission: ?string, order: int, badge: ?Closure, family: ?TypeFamily}> */
    private array $added = [];
    /** @var (Closure(string): ?int)|null the count badge of a type key; set at boot (Customizations resolve after the nav is built) */
    private ?Closure $badges = null;

    /** @var array<int, ?int> added items' badge results by closure id (the closures live in $added, so ids are stable) */
    private array $badgeCounts = [];

    /** @var (Closure(): ?array<string, bool>)|null the current person's saved open state by section name; null from the closure = no person */
    private ?Closure $saved = null;
    /** @var list<string> sections that start closed when nothing is saved */
    private array $collapsedDefaults = [];

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
     * Adds an item to a section; a new section name sits between Content and Library, in the order first used. A null permission shows it to everyone. The section name is [a-z][a-z0-9_-]{0,63} (what the sidebar can save); anything else throws \InvalidArgumentException.
     *
     * @param string|Closure(): string $label a closure is asked when the sidebar renders (a text in the interface language)
     * @param (Closure(): mixed)|null $badge a count for the item, asked once and only while the item is visible; anything but an int above 0 (or a throw) shows none
     * @param TypeFamily|null $family what kind of content the item leads to, for its tint; null: none (@since 0.75)
     */
    public function add(string $section, string|Closure $label, string $url, string $icon, ?string $permission, int $order = 100, ?Closure $badge = null, ?TypeFamily $family = null): void
    {
        if (preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $section) !== 1) {
            throw new \InvalidArgumentException('Nav section "' . $section . '" is not a valid name: lower case letters, digits, "_" and "-", starting with a letter, at most 64 characters.');
        }
        $this->added[] = compact('section', 'label', 'url', 'icon', 'permission', 'order', 'badge', 'family');
    }

    /** @param Closure(string): ?int $badges a type key's nav badge count; called only for visible items */
    public function setBadges(Closure $badges): void
    {
        $this->badges = $badges;
    }

    /**
     * @param Closure(): ?array<string, bool> $saved the current person's saved open state, asked once per render; null (no person, nothing can be saved) opens every section
     * @param list<string> $collapsedDefaults sections that start closed when the person saved nothing for them
     */
    public function setOpenState(Closure $saved, array $collapsedDefaults): void
    {
        $this->saved = $saved;
        $this->collapsedDefaults = array_values($collapsedDefaults);
    }

    /** The path of the admin request being rendered; decides the active item. */
    public function setCurrentPath(string $path): void
    {
        $this->path = '/' . trim($path, '/');
    }

    /**
     * Each item's "family" is the type's (TypeListing::$family), the asset family for the media
     * library, and what add() was given for an added item (@since 0.75).
     *
     * @return list<array{name: string, label: string, badge: ?int, open: bool, items: list<array{label: string, url: string, icon: string, active: bool, badge: ?int, family: ?TypeFamily}>}>
     */
    public function sections(): array
    {
        $groups = array_fill_keys(array_keys(self::BUILTIN), []);
        $add = function (string $section, string|Closure $label, string $url, string $icon, ?string $permission, int $order, ?string $type = null, ?Closure $badge = null, ?TypeFamily $family = null) use (&$groups): void {
            $groups[$section][] = compact('label', 'url', 'icon', 'permission', 'order', 'type', 'badge', 'family');
        };

        $add('dashboard', static fn (): string => FolioText::get('folio.nav.dashboard'), $this->prefix === '' ? '/' : $this->prefix, 'ui-dashboard', 'folio.dashboard', 100);
        foreach ($this->catalog->all() as $listing) {
            if ($listing->nav) {
                $add($listing->section, $this->labels->type($listing->key), $this->prefix . '/' . $listing->key, $listing->glyph(), $listing->key . '.view', $listing->order, $listing->key, null, $listing->family);
            }
        }
        $add('library', static fn (): string => FolioText::get('folio.nav.assets'), $this->prefix . '/assets', 'ui-library', 'assets.view', 100, null, null, TypeFamily::Asset);
        foreach ($this->added as $item) {
            $add($item['section'], $item['label'], $item['url'], $item['icon'], $item['permission'], $item['order'], null, $item['badge'], $item['family']);
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
        $saved = $this->saved !== null ? ($this->saved)() : null;
        $sections = [];
        foreach ($visible as $section => $items) {
            $name = (string) $section;
            $mapped = array_map(fn (array $item): array => [
                'label' => $item['label'] instanceof Closure ? ($item['label'])() : $item['label'],
                'url' => $item['url'],
                'icon' => $item['icon'],
                'active' => $item['url'] === $active,
                'badge' => $this->badge($item),
                'family' => $item['family'],
            ], $items);
            $badges = array_sum(array_map(static fn (array $item): int => max(0, $item['badge'] ?? 0), $mapped));
            $hasActive = in_array(true, array_column($mapped, 'active'), true);
            $sections[] = [
                'name' => $name === 'dashboard' ? '' : $name,
                'badge' => $badges > 0 ? $badges : null,
                'open' => $saved === null || $hasActive
                    || ($saved[$name] ?? !in_array($name, $this->collapsedDefaults, true)),
                'label' => isset(self::BUILTIN[$section]) ? self::sectionLabel($section) : $this->labels->section($section),
                'items' => $mapped,
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

    /** Whether a section is one of Folio's own (dashboard, pages, content, library, settings), named by sectionLabel(). @since 0.76 */
    public static function isBuiltIn(string $section): bool
    {
        return isset(self::BUILTIN[$section]);
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
