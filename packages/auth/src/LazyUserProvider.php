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

    /** @param \Closure(): object $factory builds the provider; the result is checked on resolve */
    public function __construct(
        private readonly string $name,
        private readonly string $class,
        private readonly \Closure $factory,
    ) {}

    /** Build the provider once (a class that is not a provider throws here). */
    public function resolve(): UserProviderInterface
    {
        if ($this->provider === null) {
            $provider = ($this->factory)();
            if (!$provider instanceof UserProviderInterface) {
                throw new \RuntimeException("Provider \"{$this->name}\" ({$this->class}) does not implement " . UserProviderInterface::class . '.');
            }
            $this->provider = $provider;
        }

        return $this->provider;
    }

    public function findById(string $id): ?Authenticatable
    {
        return $this->resolve()->findById($id);
    }

    public function findByCredentials(array $credentials): ?Authenticatable
    {
        return $this->resolve()->findByCredentials($credentials);
    }
}
