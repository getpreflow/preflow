<?php

declare(strict_types=1);

namespace Preflow\Folio\Users;

use Preflow\Auth\AuthManager;
use Preflow\Auth\UserProviderInterface;

/**
 * The guard Folio signs staff in through: config/folio.php 'guard', else the app's default guard
 * (as before 0.65). Only staff may get into the admin, so a guard that declares another
 * population in config/auth.php (members, the starter's employers) stops the boot (assertStaff());
 * a guard without a population is taken as staff, and Folio marks it so.
 */
final readonly class StaffGuard
{
    public function __construct(
        public string $name,
        /** Whether it is the app's default guard. */
        public bool $isDefault,
    ) {}

    /**
     * @param array<string, mixed>|null $folio config/folio.php
     * @param array<string, mixed>|null $auth  config/auth.php
     */
    public static function fromConfig(?array $folio, ?array $auth): self
    {
        $default = (string) ($auth['default_guard'] ?? 'session');
        $named = $folio['guard'] ?? null;
        $name = is_string($named) && $named !== '' ? $named : $default;

        return new self($name, $name === $default);
    }

    /**
     * Refuses a guard config/auth.php doesn't define, and one whose population is not staff.
     *
     * @param array<string, mixed> $auth config/auth.php
     * @throws \RuntimeException
     */
    public function assertStaff(array $auth): void
    {
        $where = $this->isDefault ? "the default guard; config/folio.php 'guard' names another" : "config/folio.php 'guard'";
        $guard = $auth['guards'][$this->name] ?? null;
        if (!is_array($guard)) {
            throw new \RuntimeException("Folio signs staff in through the guard \"{$this->name}\" ({$where}), which config/auth.php does not define.");
        }
        $population = $guard['population'] ?? null;
        if (is_string($population) && $population !== '' && $population !== 'staff') {
            throw new \RuntimeException(
                "Folio signs staff in through the guard \"{$this->name}\" ({$where}), whose population is \"{$population}\": "
                . 'everyone it signs in would get into the admin. Set up a staff guard in config/auth.php (a SessionGuard on your '
                . "staff accounts with 'population' => 'staff', as preflow/starter-folio has) and make it the default guard or "
                . "name it in config/folio.php 'guard'.",
            );
        }
    }

    /**
     * The guard's user provider: the default guard's binding (UserProviderInterface) as before, or
     * the named guard's from the AuthManager.
     *
     * @param \Closure(): UserProviderInterface $defaultProvider
     */
    public function provider(AuthManager $auth, \Closure $defaultProvider): UserProviderInterface
    {
        if ($this->isDefault) {
            return $defaultProvider();
        }

        return $auth->provider($this->name)
            ?? throw new \RuntimeException("Folio's guard \"{$this->name}\" (config/folio.php 'guard') has no user provider.");
    }
}
