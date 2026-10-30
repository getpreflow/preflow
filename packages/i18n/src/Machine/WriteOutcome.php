<?php

declare(strict_types=1);

namespace Preflow\I18n\Machine;

/** What a source did with a write. */
final readonly class WriteOutcome
{
    /**
     * @param list<string> $leftAlone unit ids not written because their text changed since units()
     *                                read it; the rest was written (or the status says why not)
     */
    public function __construct(
        public WriteOutcomeStatus $status,
        public ?string $message = null,
        /** The item had unpublished edits, so the machine text went to a working copy next to them. */
        public bool $hadDraftChanges = false,
        public array $leftAlone = [],
    ) {}
}
