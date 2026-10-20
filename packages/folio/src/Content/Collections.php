<?php

declare(strict_types=1);

namespace Preflow\Folio\Content;

/**
 * Records of a collection for public templates and app code (folio_list,
 * folio_find): live content only (published snapshots of publishing types;
 * working copies in the admin preview), localized to the content locale, in
 * the type's list order. Memoized per request.
 */
final class Collections
{
    /** @var array<string, list<array<string, mixed>>> */
    private array $lists = [];

    public function __construct(
        private readonly LiveContent $live,
        private readonly TypeCatalog $catalog,
        private readonly ContentLocale $locale,
    ) {}

    /** @return list<array<string, mixed>> */
    public function list(string $type): array
    {
        $listing = $this->catalog->get($type);
        if ($listing === null) {
            return [];
        }

        $locale = $this->locale->get();
        $key = $type . '@' . $locale . ($this->live->showsWorkingCopies() ? '#working' : '');
        if (!isset($this->lists[$key])) {
            $query = $this->live->query($type);
            $records = [];
            foreach (ListOrder::apply($query, $this->live->definition($type), $listing->list)->get()->items() as $record) {
                $records[] = $record->localized($locale)->toArray();
            }
            $this->lists[$key] = $records;
        }

        return $this->lists[$key];
    }

    /** @return array<string, mixed>|null */
    public function find(string $type, string $id): ?array
    {
        if (!$this->catalog->has($type)) {
            return null;
        }

        return $this->live->find($type, $id)?->localized($this->locale->get())->toArray();
    }
}
