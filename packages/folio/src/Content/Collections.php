<?php

declare(strict_types=1);

namespace Preflow\Folio\Content;

/**
 * Records of a collection for public templates and app code (folio_list,
 * folio_find): live content only (published snapshots of publishing types;
 * working copies in the admin preview), localized to the content locale, in
 * the type's list order. Memoized per request. A type gated by
 * frontend.permission lists nothing for visitors without it (FrontendGate).
 */
final class Collections
{
    /** @var array<string, list<array<string, mixed>>> */
    private array $lists = [];

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

        $locale = $this->locale->get();
        $key = $type . '@' . $locale . ($this->live->showsWorkingCopies() ? '#working' : '');
        if (!isset($this->lists[$key])) {
            $query = $this->live->query($type);
            $records = [];
            foreach (ListOrder::apply($query, $this->live->definition($type), $listing->list)->get()->items() as $record) {
                $records[] = $this->safe($record->localized($locale)->toArray(), $type);
            }
            $this->lists[$key] = $records;
        }

        return $this->lists[$key];
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
