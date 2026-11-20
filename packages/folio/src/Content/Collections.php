<?php

declare(strict_types=1);

namespace Preflow\Folio\Content;

use Preflow\Core\Context\Contexts;
use Preflow\Publishing\LiveContent;

/**
 * Records of a collection for public templates and app code (folio_list,
 * folio_find): live content only (published snapshots of publishing types;
 * working copies where the render reads them), localized to the content locale, in
 * the type's list order. Memoized per render frame and context (ContentMemo: by type and
 * RenderContext::memoKey()); not memoized outside every render. A type gated by
 * frontend.permission lists nothing for visitors without it (FrontendGate).
 */
final class Collections
{
    public function __construct(
        private readonly LiveContent $live,
        private readonly TypeCatalog $catalog,
        private readonly ContentLocale $locale,
        private readonly ?SafeRichText $richText = null,
        private readonly ?FrontendGate $gate = null,
    ) {}

    /** @return list<array<string, mixed>> */
    public function list(string $type): array
    {
        $listing = $this->catalog->get($type);
        if ($listing === null || !$this->visible($listing)) {
            return [];
        }

        $read = fn (): array => $this->read($type, $listing);
        if (Contexts::depth() === 0) {
            return $read();
        }
        $memo = Contexts::frame()->slot(ContentMemo::class, static fn (): ContentMemo => new ContentMemo());
        $key = 'collections:' . $type . '|' . Contexts::current()->memoKey();
        if (!isset($memo->entries[$key])) {
            $memo->entries[$key] = $this->live->data()->captureReads($read);
        } else {
            // A memo hit is still a read: every page showing the list depends on it (page cache).
            $this->live->data()->replayReads($memo->entries[$key][1]);
        }

        return $memo->entries[$key][0];
    }

    /** @return list<array<string, mixed>> */
    private function read(string $type, TypeListing $listing): array
    {
        $locale = $this->locale->get();
        $records = [];
        foreach (ListOrder::apply($this->live->query($type), $this->live->definition($type), $listing->list)->get()->items() as $record) {
            $records[] = $this->safe($record->localized($locale)->toArray(), $type);
        }

        return $records;
    }

    /** @return array<string, mixed>|null */
    public function find(string $type, string $id): ?array
    {
        $listing = $this->catalog->get($type);
        if ($listing === null || !$this->visible($listing)) {
            return null;
        }

        $values = $this->live->find($type, $id)?->localized($this->locale->get())->toArray();

        return $values !== null ? $this->safe($values, $type) : null;
    }

    /** Without a gate, only types without frontend.permission are visible. */
    private function visible(TypeListing $listing): bool
    {
        return $this->gate?->allowsType($listing) ?? !isset($listing->frontend['permission']);
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private function safe(array $values, string $type): array
    {
        return $this->richText?->fields($values, $this->live->definition($type)->fields) ?? $values;
    }
}
