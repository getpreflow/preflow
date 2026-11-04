<?php

declare(strict_types=1);

namespace Preflow\Routing\Redirect;

/** What an area redirect does with the rest of the path. */
enum AreaMode: string
{
    /** Everything below the old area goes to one target. */
    case One = 'one';

    /** The rest of the path is kept and appended to the new prefix. */
    case Prefix = 'prefix';
}
