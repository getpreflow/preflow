<?php

declare(strict_types=1);

namespace Preflow\Rbac\Tests\Fixtures;

use Preflow\Core\Container\Container;
use Preflow\Core\Container\ServiceProvider;
use Preflow\Rbac\Staff\StaffLinks;

/** An app's own set-password page (https://site.test/set-password/{token}), as an admin package binds one. */
final class PasswordPageProvider extends ServiceProvider
{
    public function register(Container $container): void
    {
        $container->instance(StaffLinks::class, new class implements StaffLinks {
            public function setPasswordUrl(string $token): string
            {
                return 'https://site.test/set-password/' . $token;
            }
        });
    }
}
