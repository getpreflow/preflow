<?php

declare(strict_types=1);

namespace App\Folio\Crew;

use Preflow\Folio\Extension\Attributes\FolioExtension;

/** Project extension fixture with an opt-in model part (copied to a temp app's app/Folio/Crew by the tests). */
#[FolioExtension(name: 'crew', label: 'Crew', icon: 'ui-users', version: '1.0.0')]
final class Extension extends \Preflow\Folio\Extension\Extension
{
}
