<?php

declare(strict_types=1);

namespace Preflow\Auth;

final class NativePasswordHasher implements PasswordHasherInterface
{
    public function __construct(
        private readonly string|int|null $algorithm = PASSWORD_DEFAULT,
        private readonly array $options = [],
    ) {}

    public function hash(string $password): string
    {
        return password_hash($password, $this->algorithm, $this->options);
    }

    public function verify(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    public function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, $this->algorithm, $this->options);
    }
}
