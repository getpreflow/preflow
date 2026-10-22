<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\Fixtures;

use Preflow\Auth\ArrayUser;
use Preflow\Auth\Authenticatable;
use Preflow\Auth\UserProviderInterface;

/** A custom provider for boot tests: knows the ids listed in its config entry. */
final class ConfigEntryUserProvider implements UserProviderInterface
{
    /** @param array<string, mixed> $config */
    public function __construct(public readonly array $config) {}

    public function findById(string $id): ?Authenticatable
    {
        return in_array($id, $this->config['ids'] ?? [], true) ? new ArrayUser($id, $id . '@site.test', '', []) : null;
    }

    public function findByCredentials(array $credentials): ?Authenticatable
    {
        return null;
    }
}
