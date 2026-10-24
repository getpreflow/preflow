<?php

declare(strict_types=1);

namespace Preflow\Rbac;

use Preflow\Auth\ChainUserProvider;
use Preflow\Auth\ConfigUserProvider;
use Preflow\Auth\LazyUserProvider;
use Preflow\Auth\UserProviderInterface;

/** The config users (ConfigUserProvider entries) behind a guard's provider: directly, in a chain, built lazy ones. */
final class ConfigUsers
{
    /** @return list<array<string, mixed>> the entries as written (email, password_hash, roles) */
    public static function of(?UserProviderInterface $provider): array
    {
        if ($provider instanceof LazyUserProvider) {
            $provider = $provider->inner();
        }
        if ($provider instanceof ConfigUserProvider) {
            return $provider->entries();
        }
        $found = [];
        if ($provider instanceof ChainUserProvider) {
            foreach ($provider->providers() as $member) {
                array_push($found, ...self::of($member));
            }
        }

        return $found;
    }

    /** Whether one of them has this e-mail (compared trimmed and lowercased). */
    public static function hasEmail(?UserProviderInterface $provider, string $email): bool
    {
        $email = mb_strtolower(trim($email));
        foreach (self::of($provider) as $user) {
            if ($email !== '' && mb_strtolower(trim((string) ($user['email'] ?? ''))) === $email) {
                return true;
            }
        }

        return false;
    }
}
