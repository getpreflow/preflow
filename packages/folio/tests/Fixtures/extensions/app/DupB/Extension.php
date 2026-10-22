<?php

declare(strict_types=1);

namespace App\Folio\DupB;

use Preflow\Folio\Extension\Attributes\FolioExtension;

/** Fixture: two extensions named "dup". */
#[FolioExtension(name: 'dup', label: 'DupB')]
final class Extension extends \Preflow\Folio\Extension\Extension
{
}
