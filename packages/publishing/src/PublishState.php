<?php

declare(strict_types=1);

namespace Preflow\Publishing;

/** Derived, never stored: compares the working copy with its published snapshot. */
enum PublishState: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Changed = 'changed';
}
