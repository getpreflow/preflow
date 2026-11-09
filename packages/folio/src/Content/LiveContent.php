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

    /**
     * Switches to published snapshots for a render the page cache may store (PageCache::isolate()):
     * a rebuild behind an admin request reads what visitors see. Returns what switches back.
     *
     * @return \Closure(): void
     */
    public function isolate(): \Closure
    {
        $before = $this->workingCopies;
        $this->workingCopies = false;

        return function () use ($before): void {
            $this->workingCopies = $before;
        };
    }

    /**
     * The data manager these reads go through: for memos that replay their reads on a hit
     * (DataManager::captureReads()).
     */
    public function data(): DataManager
    {
        return $this->dm;
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

    /**
     * Whether reads of $type see its working copies: in a preview, and always for a type that
     * has no published copies (publishing off, or not listed in the admin).
     */
    public function readsWorkingCopies(string $type): bool
    {
        return $this->workingCopies || !($this->catalog->get($type)?->publishing ?? false);
    }
}
