<?php

declare(strict_types=1);

namespace Preflow\I18n\Machine;

/** Which items a bulk run picks up: needed = missing plus outdated translations. */
enum Scope: string
{
    case Needed = 'needed';
    case All = 'all';
}
