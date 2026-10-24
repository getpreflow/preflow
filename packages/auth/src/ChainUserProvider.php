<?php

declare(strict_types=1);

namespace Preflow\Auth;

/**
 * Tries its providers in order; the first one that finds a user wins.
 * A provider that claims the credentials (ClaimsCredentials) decides alone, even when it finds nobody,
 * and by id too when the id is an e-mail it claims (a config user's id from before an import). Lets
 * a guard move from one user source to another (config users to Folio records) without a window
 * where nobody can sign in, and without config-era sessions outliving the move.
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

    /** @return list<UserProviderInterface> the members, in lookup order */
    public function providers(): array
    {
        return $this->providers;
    }

    public function findById(string $id): ?Authenticatable
    {
        foreach ($this->providers as $provider) {
            $user = $provider->findById($id);
            if ($user !== null) {
                return $user;
            }
            // An id that is an e-mail this provider claims (a session or remember-me row from before
            // the user moved to it) is not rescued by a later provider: it ends there.
            if ($provider instanceof ClaimsCredentials && $provider->claims(['email' => $id])) {
                return null;
            }
        }

        return null;
    }

    public function findByCredentials(array $credentials): ?Authenticatable
    {
        foreach ($this->providers as $provider) {
            if ($provider instanceof ClaimsCredentials && $provider->claims($credentials)) {
                return $provider->findByCredentials($credentials); // it decides, even for a disabled user
            }
            $user = $provider->findByCredentials($credentials);
            if ($user !== null) {
                return $user;
            }
        }

        return null;
    }
}
