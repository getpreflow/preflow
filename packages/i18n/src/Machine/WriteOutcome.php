<?php

declare(strict_types=1);

namespace Preflow\I18n\Machine;

/** What a source did with a write. */
final readonly class WriteOutcome
{
    public function __construct(
        public WriteOutcomeStatus $status,
        public ?string $message = null,
        /** The item had unpublished edits, so the machine text went to a working copy next to them. */
        public bool $hadDraftChanges = false,
    ) {}
}
