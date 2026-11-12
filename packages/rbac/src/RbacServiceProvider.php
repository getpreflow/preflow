<?php

declare(strict_types=1);

namespace Preflow\Rbac;

use Preflow\Auth\Access\Access;
use Preflow\Auth\Access\PermissionRegistry;
use Preflow\Auth\AuthManager;
use Preflow\Auth\ChainUserProvider;
use Preflow\Auth\LazyUserProvider;
use Preflow\Auth\PasswordHasherInterface;
use Preflow\Auth\Record\AccountLinks;
use Preflow\Auth\Record\RecordUserProvider;
use Preflow\Auth\Remember\RememberMe;
use Preflow\Auth\Tokens\Tokens;
use Preflow\Auth\UserProviderInterface;
use Preflow\Core\Application;
use Preflow\Core\Container\Container;
use Preflow\Core\Container\ServiceProvider;
use Preflow\Data\DataManager;
use Preflow\Data\Locales;
use Preflow\Folio\Content\DisplayLabels;
use Preflow\Folio\Content\TypeCatalog;
use Preflow\Folio\Field\FieldTypeRegistry;
use Preflow\Folio\Http\AdminAssets;
use Preflow\Rbac\Fields\PermissionsFieldType;
use Preflow\Rbac\Fields\RolesFieldType;
use Preflow\Rbac\Staff\StaffAccounts;
use Preflow\Rbac\Staff\StaffLinks;
use Preflow\Rbac\Staff\StaffLocales;
use Preflow\Rbac\Staff\StaffMail;
use Preflow\Rbac\Staff\StaffPopulation;
use Preflow\Rbac\Staff\StaffType;
use Preflow\Notify\Mail\Mailer;

/**
 * Answers can() from the rbac_role records (RbacResolver; until `php preflow rbac:install`
 * staff may do everything) whenever this provider runs and the site has a data config. In
 * boot(), so it wins over preflow/roles, which sets its resolver in register(). Defines the
 * role type (RoleType) unless Folio's rbac admin did. The role store, the resolver, the
 * delegation check (PermissionCoverage), the rbac strings (Messages) and the staff accounts
 * (StaffAccounts; the type "staff" when config/auth.php signs staff in with it, StaffType) are
 * container instances. With Folio it also registers the field types rbac_permissions (its editor's
 * assets/rbac.css and rbac.js served by Folio) and rbac_roles.
 */
final class RbacServiceProvider extends ServiceProvider
{
    public function register(Container $container): void
    {
        $container->singleton(RoleStore::class, static fn (Container $c): RoleStore => new RoleStore($c->get(DataManager::class)));
    }

    public function boot(Container $container): void
    {
        // The DataManager is bound only with config/data.php (Container::has() is true for any class).
        if (!$container->bound(DataManager::class)) {
            return;
        }
        RoleType::define($container);
        $debug = (bool) $container->get(Application::class)->config()->get('app.debug', false);
        $resolver = new RbacResolver($container->get(RoleStore::class), $debug);
        $access = $container->get(Access::class);
        $access->setResolver($resolver);
        $container->instance(RbacResolver::class, $resolver);
        $coverage = new PermissionCoverage($access, $resolver);
        $container->instance(PermissionCoverage::class, $coverage);
        $messages = Messages::boot($container);
        $container->instance(Messages::class, $messages);
        StaffType::define($container);
        $this->staff($container);

        if ($container->bound(FieldTypeRegistry::class)) {
            $this->folio($container, $coverage, $messages);
        }
    }

    /**
     * Staff accounts (StaffAccounts) on the staff guard: config/rbac.php 'guard', else the guard an
     * admin binds as StaffPopulation, else auth's default guard. Their password links are auth's
     * AccountLinks for that guard: the container's (one for the app: an admin binds it for its
     * staff guard, else rbac binds it here).
     */
    private function staff(Container $container): void
    {
        $app = $container->get(Application::class);
        $config = $app->configFile('rbac');
        $configured = is_array($config) && is_string($config['guard'] ?? null) && $config['guard'] !== '' ? $config['guard'] : null;
        $admin = $container->bound(StaffPopulation::class) ? $container->get(StaffPopulation::class)->guard : null;
        if ($configured !== null && $admin !== null && $configured !== $admin) {
            throw new \RuntimeException("config/rbac.php 'guard' names \"{$configured}\", but the admin signs staff in through the guard \"{$admin}\". Staff accounts have one guard: name \"{$admin}\" or remove 'guard'.");
        }
        $guard = $configured ?? $admin ?? ($container->bound(AuthManager::class) ? $container->get(AuthManager::class)->defaultGuardName() : 'session');
        $users = static fn (): ?UserProviderInterface => $container->bound(AuthManager::class) ? $container->get(AuthManager::class)->provider($guard) : null;
        if (!$container->bound(AccountLinks::class)) {
            $container->singleton(AccountLinks::class, static fn (Container $c): AccountLinks => new AccountLinks(
                $c->get(Tokens::class),
                $c->bound(RememberMe::class) ? $c->get(RememberMe::class) : null,
                $c->get(PasswordHasherInterface::class),
                $c->get(DataManager::class),
                $users() ?? throw new \RuntimeException("The staff guard \"{$guard}\" has no user provider in config/auth.php."),
                self::recordProviders($users()),
                $guard,
            ));
        }
        $min = is_array($config) && is_int($config['password_min'] ?? null) ? max(1, $config['password_min']) : StaffAccounts::PASSWORD_MIN;
        $container->singleton(StaffAccounts::class, static fn (Container $c): StaffAccounts => new StaffAccounts(
            $c->get(DataManager::class),
            $c->get(PermissionCoverage::class),
            $c->get(Messages::class),
            $guard,
            static fn (): AccountLinks => $c->get(AccountLinks::class),
            $users,
            static fn (): ?StaffLinks => $c->bound(StaffLinks::class) ? $c->get(StaffLinks::class) : null,
            static fn (): StaffMail => new StaffMail(
                $c->get(Mailer::class),
                $c->get(Messages::class)->translator(),
                $c->get(Locales::class)->default, // for accounts without a language of their own
                StaffMail::siteName($app->config()->get('app.name'), $app->config()->get('app.url')),
                $c->bound(StaffLocales::class) ? $c->get(StaffLocales::class) : null,
            ),
            $min,
        ));
    }

    /**
     * The record providers behind a guard's provider: itself, the members of a chain (nested too), built lazy ones.
     *
     * @return list<RecordUserProvider>
     */
    private static function recordProviders(?UserProviderInterface $provider): array
    {
        if ($provider instanceof LazyUserProvider) {
            $provider = $provider->inner();
        }
        if ($provider instanceof RecordUserProvider) {
            return [$provider];
        }
        $found = [];
        if ($provider instanceof ChainUserProvider) {
            foreach ($provider->providers() as $member) {
                array_push($found, ...self::recordProviders($member));
            }
        }

        return $found;
    }

    /** Folio's part (when Folio is there): the field types and the permissions editor's assets. */
    private function folio(Container $container, PermissionCoverage $coverage, Messages $messages): void
    {
        $labels = $container->bound(DisplayLabels::class) ? $container->get(DisplayLabels::class) : null;
        $fieldTypes = $container->get(FieldTypeRegistry::class);
        $fieldTypes->register(new PermissionsFieldType($container->get(PermissionRegistry::class), $container->get(TypeCatalog::class), $coverage, $messages, $labels));
        $assets = $container->get(AdminAssets::class);
        $assets->add('rbac.css', dirname(__DIR__) . '/assets/rbac.css');
        $assets->add('rbac.js', dirname(__DIR__) . '/assets/rbac.js');
        $fieldTypes->register(new RolesFieldType($container->get(DataManager::class), $container->get(TypeCatalog::class), $coverage, $messages));
    }
}
