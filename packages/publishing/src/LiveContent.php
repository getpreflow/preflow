<?php

declare(strict_types=1);

namespace Preflow\Publishing;

use Preflow\Core\Context\Contexts;
use Preflow\Core\Context\Deprecations;
use Preflow\Core\Context\ReadMode;
use Preflow\Core\Context\RenderContext;
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
 * Outside every render (a script, a unit test; ruling R1) it reads published snapshots, unless
 * the deprecated showWorkingCopies() or isolate() changed that default; a render never sees it.
 */
final class LiveContent
{
    /** What reads outside every render see (ruling R1: published); only the deprecated setters change it. */
    private bool $workingCopiesOutsideRenders = false;

    public function __construct(
        private readonly DataManager $dm,
        private readonly PublishingTypes $types,
    ) {}

    /**
     * Switches the current render to working copies (or back to published snapshots) for the
     * rest of its frame: Contexts::narrow() with RenderContext::workingCopies() or published().
     * Outside every render it sets what reads outside renders see.
     *
     * @deprecated 0.81, removed in 0.83: Contexts::narrow(fn ($ctx) => $ctx->workingCopies()) for
     *             the rest of the render, Contexts::within($ctx->workingCopies(), $fn) for a stretch of it
     */
    public function showWorkingCopies(bool $on = true): void
    {
        Deprecations::once('Preflow\Publishing\LiveContent::showWorkingCopies() is deprecated since 0.81 and is removed in 0.83; use Contexts::narrow(fn ($ctx) => $ctx->workingCopies()).');
        if (Contexts::depth() === 0) {
            $this->workingCopiesOutsideRenders = $on;

            return;
        }
        Contexts::narrow(static fn (RenderContext $ctx): RenderContext => $on ? $ctx->workingCopies() : $ctx->published());
    }

    /** Whether reads of publishing types see working copies now (the current render's read mode). */
    public function showsWorkingCopies(): bool
    {
        return $this->mode()?->readsWorkingCopies() ?? $this->workingCopiesOutsideRenders;
    }

    /**
     * Switches the current render to published snapshots, for the rest of its frame, and returns
     * what puts its earlier read mode back. The closure only acts in the frame isolate() was
     * called in; anywhere else (that frame closed, another render) it does nothing. Outside every
     * render it switches what reads outside renders see.
     *
     * Not needed since 0.81: a render the page cache may store (a visitor's miss, a rebuild, the
     * audit) runs under a published context of its own.
     *
     * @deprecated 0.81, removed in 0.83: render under RenderContext::published() (Contexts::run()
     *             or Contexts::within()), and key memos on RenderContext::memoKey()
     *
     * @return \Closure(): void
     */
    public function isolate(): \Closure
    {
        Deprecations::once('Preflow\Publishing\LiveContent::isolate() is deprecated since 0.81 and is removed in 0.83; use a render under RenderContext::published() and memos keyed on RenderContext::memoKey().');
        if (Contexts::depth() === 0) {
            $before = $this->workingCopiesOutsideRenders;
            $this->workingCopiesOutsideRenders = false;

            return function () use ($before): void {
                if (Contexts::depth() === 0) {
                    $this->workingCopiesOutsideRenders = $before;
                }
            };
        }

        $frame = Contexts::frame();
        $before = Contexts::current()->reads;
        if ($before->key() === ReadMode::published()->key()) {
            return static function (): void {};
        }
        Contexts::narrow(static fn (RenderContext $ctx): RenderContext => $ctx->published());

        return static function () use ($frame, $before): void {
            if (Contexts::depth() === 0 || Contexts::frame() !== $frame) {
                return;
            }
            Contexts::narrow(static fn (RenderContext $ctx): RenderContext => match (true) {
                $before->at !== null => $ctx->asOf($before->at),
                $before->readsWorkingCopies() => $ctx->workingCopies(),
                default => $ctx->published(),
            });
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

        return ($mode?->readsWorkingCopies() ?? $this->workingCopiesOutsideRenders) || !$this->types->publishes($type);
    }

    /** The current render's read mode; null outside every render (ruling R1). */
    private function mode(): ?ReadMode
    {
        return Contexts::peek()?->reads;
    }
}
