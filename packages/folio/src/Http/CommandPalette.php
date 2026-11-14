<?php

declare(strict_types=1);

namespace Preflow\Folio\Http;

use Closure;
use Preflow\Data\TypeFamily;
use Preflow\Folio\Content\DisplayLabels;
use Preflow\Folio\Content\TypeCatalog;
use Preflow\Folio\Content\TypeListing;
use Preflow\Folio\FolioText;

/**
 * What the ⌘K palette knows before anyone types (partials/_commands.twig, assets/command.js): the
 * screens of the sidebar the person sees, "New …" for each type they may create (not singletons),
 * the targets of the g-chords (g d dashboard, g a assets, g p the first page type) and where the
 * records are searched (SearchController). Everything is filtered by the person's permissions here,
 * so the page carries nothing they could not open.
 *
 * @since 0.77
 */
final class CommandPalette
{
    /** @param Closure(string): bool $can */
    public function __construct(
        private readonly TypeCatalog $catalog,
        private readonly DisplayLabels $labels,
        private readonly Closure $can,
        private readonly string $prefix,
    ) {}

    /**
     * @param list<array{items: list<array{label: string, url: string, icon: string, family: ?TypeFamily}>}> $sections AdminNav::sections() (the sidebar being rendered)
     * @return array{search: string, screens: list<array{label: string, url: string, icon: string, family: ?string}>, actions: list<array{label: string, url: string, icon: string, family: ?string}>, jumps: array<string, array{label: string, url: string}>}
     */
    public function data(array $sections): array
    {
        $screens = [];
        foreach ($sections as $section) {
            foreach ($section['items'] as $item) {
                $screens[] = ['label' => $item['label'], 'url' => $item['url'], 'icon' => $item['icon'], 'family' => $item['family']?->value];
            }
        }

        $actions = [];
        foreach ($this->viewable() as $listing) {
            if (!$listing->singleton && ($this->can)($listing->key . '.create')) {
                $actions[] = [
                    'label' => FolioText::get('folio.form.new', ['label' => $this->labels->noun($listing->key, 1)]),
                    'url' => $this->prefix . '/' . $listing->key . '/new',
                    'icon' => $listing->glyph(),
                    'family' => $listing->family?->value,
                ];
            }
        }

        $jumps = [];
        if (($this->can)('folio.dashboard')) {
            $jumps['d'] = ['label' => FolioText::get('folio.nav.dashboard'), 'url' => $this->prefix === '' ? '/' : $this->prefix];
        }
        if (($this->can)('assets.view')) {
            $jumps['a'] = ['label' => FolioText::get('folio.nav.assets'), 'url' => $this->prefix . '/assets'];
        }
        $pages = $this->pagesJump();
        if ($pages !== null) {
            $jumps['p'] = $pages;
        }

        return ['search' => $this->prefix . '/_search', 'screens' => $screens, 'actions' => $actions, 'jumps' => $jumps];
    }

    /** The JSON of data(), safe inside a script block. */
    public function json(array $sections): string
    {
        return json_encode(
            $this->data($sections),
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
    }

    /**
     * Where g p goes: the first type of the page family (not a singleton) the person may view, in
     * sidebar order; null when there is none (and the dashboard tip does not mention it).
     *
     * @return array{label: string, url: string}|null
     */
    public function pagesJump(): ?array
    {
        foreach ($this->viewable() as $listing) {
            if ($listing->family === TypeFamily::Page && !$listing->singleton) {
                return ['label' => $this->labels->type($listing->key), 'url' => $this->prefix . '/' . $listing->key];
            }
        }

        return null;
    }

    /**
     * The types whose records the palette searches: listed in the sidebar, viewable, not singletons
     * (a singleton is a screen), in sidebar order.
     *
     * @return list<TypeListing>
     */
    public function searchTypes(): array
    {
        return array_values(array_filter($this->viewable(), static fn (TypeListing $l): bool => !$l->singleton));
    }

    /**
     * The sidebar's types the person may view, in sidebar order (sections as AdminNav orders them,
     * then "order" within a section).
     *
     * @return list<TypeListing>
     */
    private function viewable(): array
    {
        $listings = array_values(array_filter(
            $this->catalog->all(),
            fn (TypeListing $l): bool => $l->nav && ($this->can)($l->key . '.view'),
        ));
        $rank = array_flip(AdminNav::sectionOrder(array_map(static fn (TypeListing $l): string => $l->section, $listings)));
        usort($listings, static fn (TypeListing $a, TypeListing $b): int => [$rank[$a->section], $a->order] <=> [$rank[$b->section], $b->order]); // stable

        return $listings;
    }
}
