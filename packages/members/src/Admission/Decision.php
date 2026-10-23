<?php

declare(strict_types=1);

namespace Preflow\Members\Admission;

/** What follows a confirmed e-mail: membership at once, or the owner decides. */
enum Decision
{
    case Accept;
    case Pending;
}
