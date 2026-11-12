<?php

declare(strict_types=1);

namespace Preflow\Rbac\Tests\Fixtures;

use Preflow\Core\Container\Container;
use Preflow\Core\Container\ServiceProvider;
use Preflow\Rbac\Staff\StaffLinks;

/** A set-password page that can't build links now (as Folio's without app.url). */
final class UnavailablePasswordPageProvider extends ServiceProvider
{
    public function register(Container $container): void
    {
        $container->instance(StaffLinks::class, new class implements StaffLinks {
            public function available(): bool
            {
                return false;
            }

            public function setPasswordUrl(string $token): string
            {
                throw new \LogicException('asked although not available');
            }
        });
    }
}
