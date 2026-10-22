<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\Fixtures;

use Preflow\Auth\Authenticatable;
use Preflow\Auth\UserProviderInterface;
use Preflow\Core\Container\Container;
use Preflow\Core\Container\ServiceProvider;

/** A service only a service provider registers (like Folio's TypeCatalog). */
final class LateMarker
{
    public function __construct(public readonly string $value) {}
}

final class LateServiceProvider extends ServiceProvider
{
    public function register(Container $container): void
    {
        $container->instance(LateMarker::class, new LateMarker('late'));
    }
}

/** A provider whose constructor needs a service registered after auth boots. */
final class LateServiceUserProvider implements UserProviderInterface
{
    /** @param array<string, mixed> $config */
    public function __construct(public readonly LateMarker $marker, public readonly array $config = []) {}

    public function findById(string $id): ?Authenticatable
    {
        return null;
    }

    public function findByCredentials(array $credentials): ?Authenticatable
    {
        return null;
    }
}
