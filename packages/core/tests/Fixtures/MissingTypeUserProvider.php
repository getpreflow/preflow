<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\Fixtures;

use Preflow\Auth\Authenticatable;
use Preflow\Auth\UserProviderInterface;

/** Like auth's RecordUserProvider (as Folio builds it) when its user type is gone (extension disabled): the constructor throws. */
final class MissingTypeUserProvider implements UserProviderInterface
{
    /** @param array<string, mixed> $config */
    public function __construct(array $config = [])
    {
        throw new \Preflow\Auth\UserProviderUnavailable('User provider: type "member" does not exist.');
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
