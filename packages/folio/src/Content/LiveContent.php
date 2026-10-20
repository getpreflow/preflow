<?php

declare(strict_types=1);

namespace Preflow\Folio\Content;

use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;
use Preflow\Data\QueryBuilder;
use Preflow\Data\TypeDefinition;
use Preflow\Folio\Publishing\ShadowTypes;

/**
 * Reads referenced records for rendering (relations, matrix placements,
 * folio_url, singletons). Public renders see published snapshots of publishing
 * types, so drafts never reach the site; the admin, its preview and share
 * previews switch to working copies. Request-scoped: one instance per request.
 */
final class LiveContent
{
    private bool $workingCopies = false;

    public function __construct(
        private readonly DataManager $dm,
        private readonly TypeCatalog $catalog,
    ) {}

    public function showWorkingCopies(bool $on = true): void
    {
        $this->workingCopies = $on;
    }

    public function showsWorkingCopies(): bool
    {
        return $this->workingCopies;
    }

    public function find(string $type, string $id): ?DynamicRecord
    {
        if ($this->readsWorkingCopies($type)) {
            return $this->dm->findType($type, $id);
        }

        return $this->dm->findIn(ShadowTypes::published($this->definition($type)), $id);
    }

    /** A query over the same records find() reads (snapshots or working copies). */
    public function query(string $type): QueryBuilder
    {
        return $this->readsWorkingCopies($type)
            ? $this->dm->queryType($type)
            : $this->dm->queryIn(ShadowTypes::published($this->definition($type)));
    }

    public function definition(string $type): TypeDefinition
    {
        $registry = $this->dm->getTypeRegistry() ?? throw new \LogicException('Folio needs a TypeRegistry.');

        return $registry->get($type);
    }

    private function readsWorkingCopies(string $type): bool
    {
        return $this->workingCopies || !($this->catalog->get($type)?->publishing ?? false);
    }
}
