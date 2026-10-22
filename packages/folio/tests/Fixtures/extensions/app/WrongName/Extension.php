<?php

declare(strict_types=1);

namespace App\Folio\WrongName;

use Preflow\Folio\Extension\Attributes\FolioExtension;

/** Fixture: Extension.php declaring a class that is not App\Folio\WrongName\Extension. */
#[FolioExtension(name: 'wrong', label: 'Wrong')]
final class WrongNameExtension extends \Preflow\Folio\Extension\Extension
{
}
