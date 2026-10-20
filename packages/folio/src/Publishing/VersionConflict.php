<?php

declare(strict_types=1);

namespace Preflow\Folio\Publishing;

/** A save was based on an older _version than the stored working copy. */
final class VersionConflict extends \RuntimeException
{
    public function __construct(public readonly int $current)
    {
        parent::__construct("The record was changed by someone else (now at version {$current}).");
    }
}
