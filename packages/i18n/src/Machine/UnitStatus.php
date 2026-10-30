<?php

declare(strict_types=1);

namespace Preflow\I18n\Machine;

/** Outcome of one unit: a usable text, or a failure that leaves the stored text untouched. */
enum UnitStatus: string
{
    case Translated = 'translated';
    case Failed = 'failed';
}
