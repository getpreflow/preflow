<?php

declare(strict_types=1);

namespace Preflow\Auth;

/** A user provider that can store a user's password hash anew (a rehash on sign-in, {@see PasswordRehash}). */
interface UpdatesPasswordHash
{
    /**
     * Stores $newHash for $user only when the stored hash still equals $oldHash (compare and set):
     * a password changed meanwhile stays. Changes nothing else (no session epoch bump).
     *
     * @return bool whether it was stored; false for a user this provider does not hold
     */
    public function updatePasswordHash(Authenticatable $user, string $oldHash, string $newHash): bool;
}
