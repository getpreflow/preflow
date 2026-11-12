<?php

declare(strict_types=1);

namespace Preflow\Rbac\Staff;

use Preflow\Data\DynamicRecord;

/**
 * The language a staff member reads their mails in. Optional: without a binding, or for null,
 * the site's default language. An admin package binds one (Folio: the user's interface language).
 */
interface StaffLocales
{
    public function locale(DynamicRecord $staff): ?string;
}
