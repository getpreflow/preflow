<?php

declare(strict_types=1);

namespace Preflow\Codes;

use Preflow\Folio\Extension\Attributes\FolioExtension;
use Preflow\Folio\Extension\Extension as FolioExtensionBase;

/** Codes managed in Folio (models/code.json); CodesServiceProvider binds the Codes service. */
#[FolioExtension(name: 'codes', label: 'Codes', icon: 'ui-key', version: '1.0.0')]
final class Extension extends FolioExtensionBase
{
}
