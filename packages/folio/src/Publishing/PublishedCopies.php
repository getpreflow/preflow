<?php

declare(strict_types=1);

namespace Preflow\Folio\Publishing;

use Preflow\Data\DataManager;
use Preflow\Data\Driver\PdoDriver;
use Preflow\Data\Migration\Schema;
use Preflow\Data\TypeDefinition;

/**
 * The published snapshot definition of a publishing type, once its storage exists (on SQL the
 * table is made by `preflow migrate`; a JSON directory that isn't there has no rows) — the
 * "copies" a delete plan counts for restrict (RecordDeleter, a member's own deletion).
 */
final class PublishedCopies
{
    public function __construct(
        private readonly DataManager $dm,
        private readonly ?Publisher $publisher,
    ) {}

    /** @return list<TypeDefinition> */
    public function of(TypeDefinition $def): array
    {
        if ($this->publisher === null || !$this->publisher->enabled($def->key)) {
            return [];
        }
        $shadow = ShadowTypes::published($def);
        $driver = $this->dm->driver($def->storage);
        if ($driver instanceof PdoDriver && !(new Schema($driver->pdo()))->hasTable($shadow->table)) {
            return [];
        }

        return [$shadow];
    }
}
