<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Support;

use Preflow\Data\Observe\DataObserver;

/** Notes the reads a DataManager reports ("table" or "table#id"), for page cache dependency tests. */
final class ReadLog implements DataObserver
{
    /** @var list<string> */
    public array $reads = [];

    public function read(string $storage, string $type, ?string $id): void
    {
        $this->reads[] = $type . ($id === null ? '' : '#' . $id);
    }

    public function wrote(string $storage, string $type, ?string $id): void {}

    public function rawRead(string $storage, string $sql): void
    {
        $this->reads[] = 'raw: ' . $sql;
    }

    public function rawWrote(string $storage, string $sql): void {}

    /** @return list<string> the reads since the last call */
    public function take(): array
    {
        $reads = $this->reads;
        $this->reads = [];

        return $reads;
    }
}
