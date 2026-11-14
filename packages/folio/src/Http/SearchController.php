<?php

declare(strict_types=1);

namespace Preflow\Folio\Http;

use Closure;
use Nyholm\Psr7\Response;
use Preflow\Data\Search\RecordSearch;
use Preflow\Data\TypeFamily;
use Preflow\Folio\Content\DisplayLabels;
use Preflow\Folio\FolioText;
use Preflow\Media\MediaLibrary;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET {prefix}/_search?q= (the ⌘K palette's records): {groups: [{type, label, family, icon, items:
 * [{id, title, url}]}]}, the records of each type CommandPalette::searchTypes() names (sidebar types
 * the person may view) through RecordSearch, at most PER_TYPE each and TOTAL in all, then the assets
 * as group "assets" while the person has assets.view. Any signed-in staff member reaches it
 * (FolioPermissionMiddleware::SIGNED_IN): the filtering is here, so the answer holds only what the
 * person may open. A term shorter than RecordSearch::MIN_TERM_LENGTH finds nothing.
 *
 * @since 0.77
 */
final class SearchController
{
    public const int PER_TYPE = 5;
    public const int TOTAL = 40;

    /**
     * @param Closure(string): bool $can
     * @param Closure(): ?MediaLibrary $library
     */
    public function __construct(
        private readonly CommandPalette $palette,
        private readonly RecordSearch $search,
        private readonly DisplayLabels $labels,
        private readonly Closure $can,
        private readonly Closure $library,
        private readonly string $prefix,
    ) {}

    public function search(ServerRequestInterface $request): ResponseInterface
    {
        $q = $request->getQueryParams()['q'] ?? '';
        $term = is_string($q) ? trim(mb_substr($q, 0, 200)) : '';

        return new Response(200, ['Content-Type' => 'application/json; charset=UTF-8', 'Cache-Control' => 'no-store'], json_encode(
            ['groups' => mb_strlen($term) < RecordSearch::MIN_TERM_LENGTH ? [] : $this->groups($term)],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ));
    }

    /** @return list<array{type: string, label: string, family: ?string, icon: string, items: list<array{id: string, title: string, url: string}>}> */
    private function groups(string $term): array
    {
        $listings = [];
        foreach ($this->palette->searchTypes() as $listing) {
            $listings[$listing->key] = $listing;
        }

        $groups = [];
        $total = 0;
        foreach ($this->search->search($term, array_keys($listings), self::PER_TYPE) as $hit) {
            if ($total >= self::TOTAL) {
                break;
            }
            $listing = $listings[$hit->type];
            $groups[$hit->type] ??= [
                'type' => $hit->type,
                'label' => $this->labels->type($hit->type),
                'family' => $listing->family?->value,
                'icon' => $listing->glyph(),
                'items' => [],
            ];
            $groups[$hit->type]['items'][] = [
                'id' => $hit->id,
                'title' => $hit->title,
                'url' => $this->prefix . '/' . rawurlencode($hit->type) . '/' . rawurlencode($hit->id) . '/edit',
            ];
            $total++;
        }
        $groups = array_values($groups);

        $room = min(self::PER_TYPE, self::TOTAL - $total);
        $library = $room > 0 && ($this->can)('assets.view') ? ($this->library)() : null;
        if ($library !== null) {
            $items = [];
            foreach ($library->query(search: $term, perPage: $room)->items as $asset) {
                $items[] = ['id' => $asset->id, 'title' => $asset->label(), 'url' => $this->prefix . '/assets/' . rawurlencode($asset->id)];
            }
            if ($items !== []) {
                $groups[] = ['type' => 'assets', 'label' => FolioText::get('folio.nav.assets'), 'family' => TypeFamily::Asset->value, 'icon' => 'ui-library', 'items' => $items];
            }
        }

        return $groups;
    }
}
