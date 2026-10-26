<?php

declare(strict_types=1);

namespace Preflow\Auth;

/**
 * Upgrades an outdated password hash after a successful sign-in, while the plain password is at
 * hand. Best effort: the sign-in never fails because of it.
 */
final class PasswordRehash
{
    /**
     * Call right after $hasher->verify($password, $user->getPasswordHash()) succeeded. Acts only when
     * the hasher wants a new hash and a provider behind $provider (itself, a chain's members, a lazy
     * provider's built one) implements UpdatesPasswordHash; the first that stores it wins. A user no
     * provider can update (a config user) is left alone. A failure is logged (never the password)
     * and leaves the account as it was.
     */
    public static function afterVerify(UserProviderInterface $provider, Authenticatable $user, string $password, PasswordHasherInterface $hasher): void
    {
        try {
            $old = $user->getPasswordHash();
            if ($old === '' || !$hasher->needsRehash($old)) {
                return;
            }
            $targets = self::targets($provider);
            if ($targets === []) {
                return;
            }
            $new = $hasher->hash($password);
            foreach ($targets as $target) {
                if ($target->updatePasswordHash($user, $old, $new)) {
                    return;
                }
            }
        } catch (\Throwable $e) {
            error_log(sprintf('preflow/auth: password hash not upgraded for account %s (%s)', $user->getAuthId(), $e::class));
        }
    }

    /** @return list<UpdatesPasswordHash> */
    private static function targets(?UserProviderInterface $provider): array
    {
        if ($provider instanceof UpdatesPasswordHash) {
            return [$provider];
        }
        if ($provider instanceof LazyUserProvider) {
            return self::targets($provider->inner());
        }
        if ($provider instanceof ChainUserProvider) {
            $found = [];
            foreach ($provider->providers() as $member) {
                array_push($found, ...self::targets($member));
            }

            return $found;
        }

        return [];
    }
}
