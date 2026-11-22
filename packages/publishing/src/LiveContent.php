<?php

declare(strict_types=1);

namespace Preflow\Publishing;

use Preflow\Core\Context\Contexts;
use Preflow\Core\Context\ReadMode;
use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;
use Preflow\Data\QueryBuilder;
use Preflow\Data\TypeDefinition;

/**
 * Reads records for rendering (Folio's relations, matrix placements, folio_url and
 * singletons; PublishedModels). Since 0.81 it reads what the current render context's read
 * mode says (Contexts::current()->reads): published snapshots of publishing types for public
 * renders, so drafts never reach the site; working copies where a render asks for them (an
 * admin route, its live preview, a share preview: RenderContext::workingCopies()). The mode
 * belongs to the render's frame, so it ends with that render and never reaches another one.
 * A read as of a date (RenderContext::asOf()) throws UnsupportedReadMode.
 *
 * Outside every render (a script, a unit test; ruling R1) it reads published snapshots.
 */
final class LiveContent
{
    public function __construct(
        private readonly DataManager $dm,
        private readonly PublishingTypes $types,
    ) {}

    /** Whether reads of publishing types see working copies now (the current render's read mode). */
    public function showsWorkingCopies(): bool
    {
        return $this->mode()?->readsWorkingCopies() ?? false;
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
        $registry = $this->dm->getTypeRegistry() ?? throw new \LogicException('Publishing needs a TypeRegistry.');

        return $registry->get($type);
    }

    /**
     * Whether reads of $type see its working copies: where the render's read mode says so (an
     * admin route, a preview), and always for a type that has no published copies
     * (PublishingTypes says it does not publish).
     *
     * @throws UnsupportedReadMode in a render as of a date (RenderContext::asOf())
     */
    public function readsWorkingCopies(string $type): bool
    {
        $mode = $this->mode();
        if ($mode?->at !== null) {
            throw UnsupportedReadMode::asOf();
        }

        return ($mode?->readsWorkingCopies() ?? false) || !$this->types->publishes($type);
    }

    /** The current render's read mode; null outside every render (ruling R1). */
    private function mode(): ?ReadMode
    {
        return Contexts::peek()?->reads;
    }
}
