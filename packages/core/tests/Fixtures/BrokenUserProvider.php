<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\Fixtures;

use Preflow\Auth\Authenticatable;
use Preflow\Auth\UserProviderInterface;

/** A provider whose setup fails for a reason other than a missing user type (a driver error, a config typo). */
final class BrokenUserProvider implements UserProviderInterface
{
    /** @param array<string, mixed> $config */
    public function __construct(array $config = [])
    {
        throw new \RuntimeException('could not connect');
    }

    public function findById(string $id): ?Authenticatable
    {
        return null;
    }

    public function findByCredentials(array $credentials): ?Authenticatable
    {
        return null;
    }
}
