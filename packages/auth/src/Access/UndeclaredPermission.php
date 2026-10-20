<?php

declare(strict_types=1);

namespace Preflow\Auth\Access;

final class UndeclaredPermission extends \LogicException
{
    public static function named(string $permission): self
    {
        return new self("Permission \"{$permission}\" is not declared. Declare it (PermissionRegistry::declare) or fix the name.");
    }
}
