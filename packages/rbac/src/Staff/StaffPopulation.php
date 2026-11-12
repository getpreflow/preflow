<?php

declare(strict_types=1);

namespace Preflow\Rbac\Staff;

/**
 * The guard staff sign in through, as an admin package decides it (Folio binds its staff guard:
 * config/folio.php 'guard', else the default guard). rbac's staff guard is config/rbac.php
 * 'guard', else this binding's, else auth's default guard.
 */
final readonly class StaffPopulation
{
    public function __construct(public string $guard) {}
}
