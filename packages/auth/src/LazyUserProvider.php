<?php

declare(strict_types=1);

namespace Preflow\Auth;

/**
 * A user provider built on first use. Providers that need services other packages register
 * (Folio's type catalog) cannot be built while auth boots, before the service providers.
 */
final class LazyUserProvider implements UserProviderInterface
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
     * Build the provider during boot. One whose own setup fails (Folio's user type is gone because an
     * extension was disabled) then resolves nobody instead of breaking every request; the failure is
     * returned for logging. A class that is not a provider still throws.
     */
    public function bootResolve(): ?\RuntimeException
    {
        try {
            $built = ($this->factory)();
        } catch (\RuntimeException $e) {
            $this->unavailable = true;

            return $e;
        }
        $this->provider = $this->check($built);

        return null;
    }

    /** Build the provider once (a class that is not a provider throws here). */
    public function resolve(): UserProviderInterface
    {
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

    private function check(object $provider): UserProviderInterface
    {
        if (!$provider instanceof UserProviderInterface) {
            throw new \RuntimeException("Provider \"{$this->name}\" ({$this->class}) does not implement " . UserProviderInterface::class . '.');
        }

        return $provider;
    }
}
