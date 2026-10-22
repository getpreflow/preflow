<?php

declare(strict_types=1);

namespace Preflow\Rbac;

use Preflow\Core\Application;
use Preflow\Core\Container\Container;
use Preflow\Data\DataManager;
use Preflow\Folio\Extension\Attributes\FolioExtension;
use Preflow\Folio\Extension\Extension as FolioExtensionBase;

/** Roles managed in Folio (models/rbac_role.json); RbacServiceProvider answers can() from them. */
#[FolioExtension(name: 'rbac', label: 'Roles', icon: 'ui-users', version: '1.0.0')]
final class Extension extends FolioExtensionBase
{
    /**
     * Seeds the roles, never changing existing ones: the staff role "admin" (everything) when
     * there is no staff role, then every role of config/roles.php (preflow/roles' format,
     * population => name => patterns) that does not exist yet.
     */
    public function install(Container $c): void
    {
        $store = new RoleStore($c->get(DataManager::class));
        if ($store->rolesFor('staff') === []) {
            $store->ensure('staff', 'admin', 'Administrator', ['*']);
        }

        $file = $c->get(Application::class)->basePath('config/roles.php');
        $config = is_file($file) ? require $file : [];
        foreach (is_array($config) ? $config : [] as $population => $roles) {
            foreach (is_array($roles) ? $roles : [] as $name => $patterns) {
                $store->ensure((string) $population, (string) $name, ucfirst(str_replace('_', ' ', (string) $name)), RoleList::normalize($patterns));
            }
        }
    }
}
