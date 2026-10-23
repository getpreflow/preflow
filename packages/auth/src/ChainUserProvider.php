<?php

declare(strict_types=1);

namespace Preflow\Auth;

/**
 * Tries its providers in order; the first one that finds a user wins. Lets a guard move from one
 * user source to another (config users to Folio records) without a window where nobody can sign in.
 */
final class ChainUserProvider implements UserProviderInterface
{
    /** @var list<UserProviderInterface> */
    private array $providers;

    /** @param iterable<UserProviderInterface> $providers in lookup order */
    public function __construct(iterable $providers)
    {
        $this->providers = [...$providers];
    }

    public function findById(string $id): ?Authenticatable
    {
        foreach ($this->providers as $provider) {
            $user = $provider->findById($id);
            if ($user !== null) {
                return $user;
            }
        }

        return null;
    }

    public function findByCredentials(array $credentials): ?Authenticatable
    {
        foreach ($this->providers as $provider) {
            $user = $provider->findByCredentials($credentials);
            if ($user !== null) {
                return $user;
            }
        }

        return null;
    }
}
