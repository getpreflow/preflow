<?php

declare(strict_types=1);

namespace Preflow\Rbac\Tests\Fixtures;

use Preflow\Auth\Access\PermissionRegistry;
use Preflow\Core\Container\Container;
use Preflow\Core\Container\ServiceProvider;

/** An app's permissions: reports.view, reports.export (family reports.*) and page.edit. */
final class ReportsProvider extends ServiceProvider
{
    public function register(Container $container): void
    {
        $registry = $container->get(PermissionRegistry::class);
        $registry->declare('reports.view', 'View reports', 'Reports');
        $registry->declare('reports.export', 'Export reports', 'Reports');
        $registry->declarePattern('reports.*');
        $registry->declare('page.edit', 'Edit pages', 'Pages');
    }
}
