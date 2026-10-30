<?php

declare(strict_types=1);

namespace Preflow\I18n\Machine;

enum WriteOutcomeStatus: string
{
    case Written = 'written';
    /** Someone changed the item meanwhile; nothing was written. */
    case Conflict = 'conflict';
    case Skipped = 'skipped';
}
