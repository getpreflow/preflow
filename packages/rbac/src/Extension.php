<?php

declare(strict_types=1);

namespace Preflow\Rbac;

use Preflow\Folio\Extension\Attributes\FolioExtension;
use Preflow\Folio\Extension\Extension as FolioExtensionBase;

/** Roles managed in Folio (models/rbac_role.json); RbacServiceProvider answers can() from them. */
#[FolioExtension(name: 'rbac', label: 'Roles', icon: 'ui-users', version: '1.0.0')]
final class Extension extends FolioExtensionBase
{
}
