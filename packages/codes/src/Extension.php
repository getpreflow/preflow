<?php

declare(strict_types=1);

namespace Preflow\Codes;

use Preflow\Folio\Extension\Attributes\DeclaresPermission;
use Preflow\Folio\Extension\Attributes\FolioExtension;
use Preflow\Folio\Extension\Extension as FolioExtensionBase;

/** Codes managed in Folio (models/code.json, Types/CodeAdmin); CodesServiceProvider binds the Codes service. */
#[DeclaresPermission('codes.revoke', 'Revoke codes')]
#[FolioExtension(name: 'codes', label: 'Codes', icon: 'ui-key', version: '1.0.0')]
final class Extension extends FolioExtensionBase
{
}
