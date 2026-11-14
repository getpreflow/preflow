<?php

declare(strict_types=1);

namespace Preflow\Folio\Dashboard;

use Preflow\Core\Cache\Bytes;
use Preflow\Core\Cache\CacheRegistry;
use Preflow\Data\DataManager;
use Preflow\Data\TypeFamily;
use Preflow\Folio\Content\DisplayLabels;
use Preflow\Folio\Content\TypeCatalog;
use Preflow\Folio\Content\TypeListing;
use Preflow\Folio\Extension\Customization\Customizations;
use Preflow\Folio\FolioText;
use Preflow\Folio\Http\AdminNav;
use Preflow\Folio\Http\CacheController;
use Preflow\Publishing\PublishState;
use Preflow\Publishing\Publisher;

/**
 * What the dashboard shows, as data (templates/admin/dashboard.twig renders it): the "New …"
 * button, the ink band's stats line, the "Needs you" cards and the content by sidebar section,
 * and the caches' size for the footer line. Every count is an aggregate count (no record is
 * loaded to be counted); every part only holds what the signed-in user may see ($can).
 *
 * @since 0.76
 */
final class Dashboard
{
    /**
     * @param (\Closure(): ?\Preflow\Folio\Redirects\NotFoundEntries)|null $notFound the not-found list, when the site keeps one
     * @param (\Closure(): ?int)|null $assetTotal the number of live assets (MediaLibrary::query(perPage: 1)->total), when there is a library
     */
    public function __construct(
        private readonly TypeCatalog $catalog,
        private readonly DataManager $dm,
        private readonly DisplayLabels $labels,
        private readonly string $prefix,
        private readonly ?Publisher $publisher = null,
        private readonly ?Customizations $customizations = null,
        private readonly ?\Closure $notFound = null,
        private readonly ?\Closure $assetTotal = null,
        private readonly ?CacheRegistry $caches = null,
    ) {}

    /**
     * The page itself: the header's button, the band's stats and the content. "Needs you" (needs())
     * and the cache line (cache()) come in their own requests after the page (the dashboard loads
     * them with htmx), so their slower work never holds the page up.
     *
     * @param \Closure(string): bool $can whether the signed-in user holds a permission
     * @return array{new: ?array{label: string, url: string}, stats: list<string>, sections: list<array{name: string, label: string, rows: list<array{key: string, label: string, family: ?TypeFamily, icon: string, count: ?int, meta: string, url: string, singleton: bool, create_url: ?string, create_label: ?string}>}>}
     */
    public function view(\Closure $can): array
    {
        $listings = array_values(array_filter(
            $this->catalog->all(),
            static fn (TypeListing $listing): bool => $listing->nav && $can($listing->key . '.view'),
        ));
        $counts = [];
        foreach ($listings as $listing) {
            $counts[$listing->key] = $this->count($listing->key);
        }
        $assets = $can('assets.view') && $this->assetTotal !== null ? $this->assetCount() : null;
        $sections = $this->sections($listings, $counts, $assets, $can);

        return [
            'new' => $this->newButton($sections, $can),
            'stats' => $this->stats($listings, $counts, $assets),
            'sections' => $sections,
        ];
    }

    /**
     * The "Needs you" cards: Folio's own, then the #[DashboardItem] ones (in #[DashboardNotice]'s
     * order: by type as the customizations were registered, then extension name).
     *
     * @param \Closure(string): bool $can
     * @param int $reviewCount records with machine translations waiting for review that the user may see (0: no card)
     * @return list<NeedsYou>
     */
    public function needs(\Closure $can, int $reviewCount = 0): array
    {
        return [...$this->builtIn($can, $reviewCount), ...($this->customizations?->dashboardItems(static fn (string $type): bool => $can($type . '.view'), $this->prefix) ?? [])];
    }

    /** The type's records, by an aggregate count; null when its storage can't be read (a table not migrated). */
    private function count(string $type): ?int
    {
        try {
            $rows = $this->dm->queryType($type)->aggregate()->count()->get();

            return (int) ($rows[0]['count'] ?? 0);
        } catch (\Throwable) {
            return null;
        }
    }

    private function assetCount(): ?int
    {
        try {
            return ($this->assetTotal)();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The visible types by sidebar section (AdminNav::sectionOrder(), each section's types by
     * "order"), the asset library in Library. A row: the type tile, label, count, its list and,
     * for {type}.create on a type that is not a singleton, the "+" to a new record.
     *
     * @param list<TypeListing> $listings
     * @param array<string, ?int> $counts
     * @param \Closure(string): bool $can
     * @return list<array{name: string, label: string, rows: list<array<string, mixed>>}>
     */
    private function sections(array $listings, array $counts, ?int $assets, \Closure $can): array
    {
        $bySection = [];
        foreach ($listings as $listing) {
            $bySection[$listing->section][] = $listing;
        }
        $sections = [];
        foreach (AdminNav::sectionOrder(array_keys($bySection)) as $section) {
            $rows = [];
            $items = $bySection[$section] ?? [];
            usort($items, static fn (TypeListing $a, TypeListing $b): int => $a->order <=> $b->order); // stable
            foreach ($items as $listing) {
                $creates = !$listing->singleton && $can($listing->key . '.create');
                $rows[] = [
                    'key' => $listing->key,
                    'label' => $this->labels->type($listing->key),
                    'family' => $listing->family,
                    'icon' => $listing->glyph(),
                    'count' => $counts[$listing->key] ?? null,
                    'meta' => $listing->singleton ? '' : self::meta('folio.dashboard.records', $counts[$listing->key] ?? null),
                    'url' => $this->prefix . '/' . $listing->key,
                    'singleton' => $listing->singleton,
                    'create_url' => $creates ? $this->prefix . '/' . $listing->key . '/new' : null,
                    'create_label' => $creates ? FolioText::get('folio.form.new', ['label' => $this->labels->noun($listing->key, 1)]) : null,
                ];
            }
            if ($section === 'library' && $can('assets.view') && $this->assetTotal !== null) {
                $rows[] = [
                    'key' => 'assets',
                    'label' => FolioText::get('folio.nav.assets'),
                    'family' => TypeFamily::Asset,
                    'icon' => 'ui-library',
                    'count' => $assets,
                    'meta' => self::meta('folio.dashboard.files', $assets),
                    'url' => $this->prefix . '/assets',
                    'singleton' => false,
                    'create_url' => null,
                    'create_label' => null,
                ];
            }
            if ($rows !== []) {
                $sections[] = [
                    'name' => $section,
                    'label' => AdminNav::isBuiltIn($section) ? AdminNav::sectionLabel($section) : $this->labels->section($section),
                    'rows' => $rows,
                ];
            }
        }

        return $sections;
    }

    /**
     * The header's primary: "New page" for the first page-family type (in sidebar order) that the
     * user may create and that is not a singleton; null when there is none.
     *
     * @param list<array{rows: list<array<string, mixed>>}> $sections
     * @param \Closure(string): bool $can
     * @return array{label: string, url: string}|null
     */
    private function newButton(array $sections, \Closure $can): ?array
    {
        foreach ($sections as $section) {
            foreach ($section['rows'] as $row) {
                if ($row['family'] === TypeFamily::Page && $row['create_url'] !== null) {
                    return ['label' => (string) $row['create_label'], 'url' => (string) $row['create_url']];
                }
            }
        }

        return null;
    }

    /**
     * The ink band's line after the site name: the two largest families, each by its largest type
     * ("2 pages", "6 references"; singletons and types not counted are left out), then the assets.
     *
     * @param list<TypeListing> $listings
     * @param array<string, ?int> $counts
     * @return list<string>
     */
    private function stats(array $listings, array $counts, ?int $assets): array
    {
        $largest = [];
        foreach ($listings as $listing) {
            $count = $counts[$listing->key] ?? null;
            if ($listing->singleton || $count === null || $listing->family === TypeFamily::Asset) {
                continue;
            }
            $family = $listing->family?->value ?? '';
            if (!isset($largest[$family]) || $count > $largest[$family]['count']) {
                $largest[$family] = ['key' => $listing->key, 'count' => $count];
            }
        }
        usort($largest, static fn (array $a, array $b): int => $b['count'] <=> $a['count']); // stable: ties keep sidebar order
        $stats = [];
        foreach (array_slice($largest, 0, 2) as $entry) {
            $stats[] = self::number($entry['count']) . ' ' . $this->labels->noun($entry['key'], $entry['count']);
        }
        if ($assets !== null) {
            $stats[] = self::number($assets) . ' ' . FolioText::choice('folio.dashboard.asset_noun', $assets);
        }

        return $stats;
    }

    /**
     * Folio's own "Needs you" cards: each publishing type with changes that are not live (for
     * {type}.view and {type}.publish), the not-found addresses to check (redirects.manage) and the
     * machine translations waiting for review.
     *
     * @param \Closure(string): bool $can
     * @return list<NeedsYou>
     */
    private function builtIn(\Closure $can, int $reviewCount): array
    {
        $items = [];
        if ($this->publisher !== null) {
            foreach ($this->catalog->all() as $listing) {
                if (!$this->publisher->enabled($listing->key) || !$can($listing->key . '.view') || !$can($listing->key . '.publish')) {
                    continue;
                }
                try {
                    $changed = count(array_filter($this->publisher->states($listing->key), static fn (PublishState $state): bool => $state === PublishState::Changed));
                } catch (\Throwable) {
                    continue;
                }
                if ($changed > 0) {
                    $items[] = new NeedsYou(
                        FolioText::choice('folio.dashboard.needs.changes', $changed, ['type' => $this->labels->type($listing->key)]),
                        FolioText::get('folio.dashboard.needs.changes_text'),
                        $this->prefix . '/' . $listing->key . '?status=changed',
                        'warning',
                        'ui-history',
                    );
                }
            }
        }
        if ($this->notFound !== null && $can('redirects.manage')) {
            try {
                $open = count(($this->notFound)()?->visible() ?? []);
            } catch (\Throwable) {
                $open = 0; // a site not migrated yet
            }
            if ($open > 0) {
                $items[] = new NeedsYou(
                    FolioText::choice('folio.dashboard.needs.not_found', $open),
                    FolioText::get('folio.dashboard.needs.not_found_text'),
                    $this->prefix . '/redirects?tab=not-found',
                    'info',
                    'ui-redirect',
                );
            }
        }
        if ($reviewCount > 0) {
            $items[] = new NeedsYou(
                FolioText::choice('folio.dashboard.needs.review', $reviewCount),
                FolioText::get('folio.dashboard.needs.review_text'),
                '#folio-review',
                'info',
                'ui-spark',
            );
        }

        return $items;
    }

    /**
     * All caches' size and the largest one's name, for a user with folio.cache (what the Cache screen
     * asks); null for anyone else, without caches or bytes. It walks the cache directories, so the
     * dashboard asks for it after the page.
     *
     * @param \Closure(string): bool $can
     * @return array{size: string, largest: string}|null
     */
    public function cache(\Closure $can): ?array
    {
        if ($this->caches === null || !$can('folio.cache')) {
            return null;
        }
        $total = 0;
        $largest = null;
        $largestBytes = -1;
        try {
            $sources = $this->caches->all();
        } catch (\Throwable) {
            return null;
        }
        foreach ($sources as $source) {
            try {
                $bytes = $source->stats()->bytes;
            } catch (\Throwable) {
                continue;
            }
            $total += max(0, $bytes);
            if ($bytes > $largestBytes) {
                $largestBytes = $bytes;
                $largest = $source;
            }
        }
        if ($largest === null || $total <= 0) {
            return null;
        }

        return ['size' => Bytes::human($total), 'largest' => FolioText::noun(CacheController::label($largest))];
    }

    /** A row's count line ("6 records", "6 files"); "Not counted" when the count failed. */
    private static function meta(string $key, ?int $count): string
    {
        return $count === null
            ? FolioText::get('folio.dashboard.unknown_count')
            : FolioText::choice($key, $count, ['count' => self::number($count)]);
    }

    private static function number(int $n): string
    {
        return (new \Preflow\Folio\Http\AdminFormat())->number($n);
    }
}
