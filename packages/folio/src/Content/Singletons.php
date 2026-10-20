<?php

declare(strict_types=1);

namespace Preflow\Folio\Content;

use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;

/**
 * One-off content (site settings…): a type flagged "singleton" holds one
 * record whose id is the type key. Values come back localized in the
 * request's content locale and are memoized per request.
 */
final class Singletons
{
    /** @var array<string, DynamicRecord|null> */
    private array $records = [];

    /** @var array<string, array<string, mixed>|null> */
    private array $values = [];

    public function __construct(
        private readonly DataManager $dm,
        private readonly TypeCatalog $catalog,
        private readonly ContentLocale $contentLocale,
        private readonly ?LiveContent $live = null,
    ) {}

    /** @return array<string, mixed>|null the localized values; null before the first save */
    public function get(string $type): ?array
    {
        $key = $type . '@' . $this->contentLocale->get();
        if (!array_key_exists($key, $this->values)) {
            $this->values[$key] = $this->record($type)?->localized($this->contentLocale->get())->toArray();
        }

        return $this->values[$key];
    }

    public function record(string $type): ?DynamicRecord
    {
        if (!array_key_exists($type, $this->records)) {
            $this->records[$type] = $this->catalog->get($type)?->singleton === true
                ? ($this->live !== null ? $this->live->find($type, $type) : $this->dm->findType($type, $type))
                : null;
        }

        return $this->records[$type];
    }
}
