<?php

declare(strict_types=1);

namespace Preflow\Folio\Redirects;

/**
 * The addresses that send visitors on to a record (every redirect entry targeting it: its own
 * earlier addresses, hand-made entries, areas), as visitors see them: the editor's "Old
 * addresses" note. An area entry ends in "/…".
 *
 * Reading never breaks the editor: a failure (a table not migrated yet) is passed to $warn and
 * gives no addresses.
 */
final readonly class OldAddresses
{
    /** @param (\Closure(string): void)|null $warn */
    public function __construct(
        private RedirectEntries $entries,
        private VisibleAddress $visible,
        private ?\Closure $warn = null,
    ) {}

    /** @return list<string> sorted, each once */
    public function of(string $type, string $id): array
    {
        try {
            $rows = $this->entries->targeting($type, $id);
        } catch (\Throwable $e) {
            if ($this->warn !== null) {
                ($this->warn)("Old addresses of {$type} {$id} not read: " . $e->getMessage());
            }

            return [];
        }

        $addresses = [];
        foreach ($rows as $row) {
            $address = $this->visible->of($row['locale'], $row['path']);
            $addresses[$row['area'] ? rtrim($address, '/') . '/…' : $address] = true;
        }
        $addresses = array_map('strval', array_keys($addresses));
        sort($addresses, SORT_STRING);

        return $addresses;
    }
}
