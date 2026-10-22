<?php

declare(strict_types=1);

namespace App\Folio\Demo;

use Preflow\Folio\Extension\Attributes\DeclaresPermission;
use Preflow\Folio\Extension\Attributes\FolioExtension;

/**
 * The demo's one extension: a screen, a customization of the "note" type (a panel and
 * an action) and a setting. It is enabled in config/folio/extensions.json.
 */
#[FolioExtension(name: 'demo', label: 'Demo', description: 'A screen, a note customization and a setting', icon: 'ui-puzzle', version: '1.0.0')]
#[DeclaresPermission('demo.view', 'See the demo screen')]
#[DeclaresPermission('demo.shout', 'Shout a note')]
final class Extension extends \Preflow\Folio\Extension\Extension
{
    /** @return array<string, array<string, mixed>> */
    public function settings(): array
    {
        return [
            'greeting' => ['type' => 'string', 'label' => 'Greeting', 'default' => 'Hello from the demo extension'],
        ];
    }
}
