<?php

declare(strict_types=1);

namespace Preflow\Auth;

/**
 * The container's PasswordHasherInterface, asked for on each use. Session guards are made while
 * auth boots, before the app's service providers; with this they use the hasher the container has
 * when a password is checked: config/auth.php `password_hasher` (default NativePasswordHasher,
 * built by the container) or the binding an app's provider put in its place.
 *
 * @since 0.66
 */
final class LazyPasswordHasher implements PasswordHasherInterface
{
    /** @param \Closure(): PasswordHasherInterface $hasher */
    public function __construct(private readonly \Closure $hasher) {}

    public function hash(#[\SensitiveParameter] string $password): string
    {
        return ($this->hasher)()->hash($password);
    }

    public function verify(#[\SensitiveParameter] string $password, string $hash): bool
    {
        return ($this->hasher)()->verify($password, $hash);
    }

    public function needsRehash(string $hash): bool
    {
        return ($this->hasher)()->needsRehash($hash);
    }

    /** The hasher in use now. */
    public function inner(): PasswordHasherInterface
    {
        return ($this->hasher)();
    }
}
