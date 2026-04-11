<?php

declare(strict_types=1);

namespace Preflow\Auth;

interface UserProviderInterface
{
    public function findById(string $id): ?Authenticatable;
    public function findByCredentials(array $credentials): ?Authenticatable;
}
