<?php

declare(strict_types=1);

namespace Preflow\Auth;

/**
 * A user provider built on first use. Providers that need services other packages register
 * (Folio's type catalog) cannot be built while auth boots, before the service providers.
 */
final class LazyUserProvider implements UserProviderInterface, ClaimsCredentials
{
    private ?UserProviderInterface $provider = null;

    private bool $unavailable = false;

    /** @param \Closure(): object $factory builds the provider; the result is checked on resolve */
    public function __construct(
        private readonly string $name,
        private readonly string $class,
        private readonly \Closure $factory,
    ) {}

    /**
     * Build the provider during boot. One whose user source is gone (UserProviderUnavailable: Folio's
     * user type after its extension was disabled) then resolves nobody instead of breaking every
     * request; the failure is returned for logging. Any other setup error, and a class that is not a
     * provider, still throws.
     */
    public function bootResolve(): ?UserProviderUnavailable
    {
        try {
            $built = ($this->factory)();
        } catch (UserProviderUnavailable $e) {
            $this->unavailable = true;

            return $e;
        }
        $this->provider = $this->check($built);

        return null;
    }

    /** Build the provider once (a class that is not a provider throws here). */
    public function resolve(): UserProviderInterface
    {
        if ($this->unavailable) {
            throw new UserProviderUnavailable("Provider \"{$this->name}\" ({$this->class}) is unavailable.");
        }

        return $this->provider ??= $this->check(($this->factory)());
    }

    public function findById(string $id): ?Authenticatable
    {
        return $this->unavailable ? null : $this->resolve()->findById($id);
    }

    public function findByCredentials(array $credentials): ?Authenticatable
    {
        return $this->unavailable ? null : $this->resolve()->findByCredentials($credentials);
    }

    public function claims(array $credentials): bool
    {
        if ($this->unavailable) {
            return false;
        }
        $provider = $this->resolve();

        return $provider instanceof ClaimsCredentials && $provider->claims($credentials);
    }

    private function check(object $provider): UserProviderInterface
    {
        if (!$provider instanceof UserProviderInterface) {
            throw new \RuntimeException("Provider \"{$this->name}\" ({$this->class}) does not implement " . UserProviderInterface::class . '.');
        }

        return $provider;
    }
}
