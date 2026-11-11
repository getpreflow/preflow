<?php

declare(strict_types=1);

namespace Preflow\Folio\Users;

use Preflow\Auth\AuthManager;
use Preflow\Auth\UserProviderInterface;

/**
 * Who Folio's staff are: the user provider of the staff guard (StaffGuard: config/folio.php
 * 'guard', else the default guard). A container service, for packages that manage or look up staff
 * accounts (preflow/rbac's import and its config-user check), so they follow 'guard' as Folio does.
 */
final class StaffUsers
{
    /**
     * @param \Closure(): ?AuthManager            $auth            the app's AuthManager; null without config/auth.php
     * @param \Closure(): ?UserProviderInterface  $defaultProvider the default guard's provider (UserProviderInterface)
     */
    public function __construct(
        private readonly StaffGuard $guard,
        private readonly \Closure $auth,
        private readonly \Closure $defaultProvider,
    ) {}

    /** The staff guard's name. */
    public function guard(): string
    {
        return $this->guard->name;
    }

    /** The staff guard's user provider; null without auth (no config/auth.php). */
    public function provider(): ?UserProviderInterface
    {
        if ($this->guard->isDefault) {
            return ($this->defaultProvider)(); // the binding, as before folio.guard
        }
        $auth = ($this->auth)();

        return $auth === null ? null : $this->guard->provider($auth, fn (): ?UserProviderInterface => ($this->defaultProvider)());
    }
}
