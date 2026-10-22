<?php

declare(strict_types=1);

namespace App\Folio\Members;

use Preflow\Folio\Extension\Attributes\FolioExtension;

/** Project extension fixture (copied to a temp app's app/Folio/Members by the tests). */
#[FolioExtension(name: 'members', label: 'Members', description: 'Member accounts', icon: 'ui-users', version: '1.2.0')]
final class Extension extends \Preflow\Folio\Extension\Extension
{
    /** @return array<string, array<string, mixed>> */
    public function settings(): array
    {
        return ['welcome' => ['type' => 'string', 'default' => 'Hello']];
    }
}
