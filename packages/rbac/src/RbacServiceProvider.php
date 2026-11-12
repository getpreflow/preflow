<?php

declare(strict_types=1);

namespace Preflow\Rbac;

use Preflow\Auth\Access\Access;
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
use Preflow\Notify\Mail\Mailer;
use Preflow\Notify\Mail\MailText;
use Preflow\Rbac\Staff\StaffAccounts;
use Preflow\Rbac\Staff\StaffLinks;
use Preflow\Rbac\Staff\StaffLocales;
use Preflow\Rbac\Staff\StaffMail;
use Preflow\Rbac\Staff\StaffPopulation;
use Preflow\Rbac\Staff\StaffType;

/**
 * Answers can() from the rbac_role records (RbacResolver; until `php preflow rbac:install`
 * staff may do everything) whenever this provider runs and the site has a data config. In
 * boot(), so it wins over preflow/roles, which sets its resolver in register(). Defines the
 * role type (RoleType) unless an admin did. The role store, the resolver, the delegation check
 * (PermissionCoverage) and the rbac strings (Messages) are container singletons from register()
 * on, so an admin booting before this provider (Folio's rbac admin) can use them; the staff
 * accounts (StaffAccounts; the type "staff" when config/auth.php signs staff in with it,
 * StaffType) and their mails (StaffMail) are built on first use.
 */
final class RbacServiceProvider extends ServiceProvider
{
    public function register(Container $container): void
    {
        $container->singleton(RoleStore::class, static fn (Container $c): RoleStore => new RoleStore(
            $c->get(DataManager::class),
            $c->bound(\Psr\Log\LoggerInterface::class) ? $c->get(\Psr\Log\LoggerInterface::class) : null,
        ));
        $container->singleton(RbacResolver::class, static fn (Container $c): RbacResolver => new RbacResolver(
            $c->get(RoleStore::class),
            (bool) $c->get(Application::class)->config()->get('app.debug', false),
        ));
        $container->singleton(PermissionCoverage::class, static fn (Container $c): PermissionCoverage => new PermissionCoverage($c->get(Access::class), $c->get(RbacResolver::class)));
        $container->singleton(Messages::class, static fn (Container $c): Messages => Messages::boot($c));
    }

    public function boot(Container $container): void
    {
        // The DataManager is bound only with config/data.php (Container::has() is true for any class).
        if (!$container->bound(DataManager::class)) {
            return;
        }
        RoleType::define($container);
        $container->get(Access::class)->setResolver($container->get(RbacResolver::class));
        $container->get(Messages::class); // rbac's strings with the translator from now on
        StaffType::define($container);
        $this->staff($container);
    }

    /**
     * Staff accounts (StaffAccounts) on the staff guard: config/rbac.php 'guard', else the guard an
     * admin binds as StaffPopulation, else auth's default guard, worked out on first use. Their
     * password links are auth's AccountLinks for that guard: the container's (one for the app: an
     * admin binds it for its staff guard, else rbac binds it here).
     */
    private function staff(Container $container): void
    {
        $resolved = null;
        $guard = static function () use ($container, &$resolved): string {
            return $resolved ??= self::guard($container);
        };
        $users = static fn (): ?UserProviderInterface => $container->bound(AuthManager::class) ? $container->get(AuthManager::class)->provider($guard()) : null;
        if (!$container->bound(AccountLinks::class)) {
            $container->singleton(AccountLinks::class, static function (Container $c) use ($guard, $users): AccountLinks {
                $provider = $users() ?? throw new \RuntimeException("The staff guard \"{$guard()}\" has no user provider in config/auth.php.");

                return new AccountLinks(
                    $c->get(Tokens::class),
                    $c->bound(RememberMe::class) ? $c->get(RememberMe::class) : null,
                    $c->get(PasswordHasherInterface::class),
                    $c->get(DataManager::class),
                    $provider,
                    self::recordProviders($provider),
                    $guard(),
                );
            });
        }
        $container->singleton(StaffMail::class, static function (Container $c): StaffMail {
            $config = $c->get(Application::class)->config();

            return new StaffMail(
                $c->get(Mailer::class),
                $c->get(Messages::class)->translator(),
                $c->get(Locales::class)->default, // for accounts without a language of their own
                MailText::siteName($config->get('app.name'), $config->get('app.url')),
                $c->bound(StaffLocales::class) ? $c->get(StaffLocales::class) : null,
            );
        });
        $container->singleton(StaffAccounts::class, static function (Container $c) use ($guard, $users): StaffAccounts {
            $config = $c->get(Application::class)->configFile('rbac');

            return new StaffAccounts(
                $c->get(DataManager::class),
                $c->get(PermissionCoverage::class),
                $c->get(Messages::class),
                $guard(),
                static fn (): AccountLinks => $c->get(AccountLinks::class),
                $users,
                static fn (): ?StaffLinks => $c->bound(StaffLinks::class) ? $c->get(StaffLinks::class) : null,
                static fn (): StaffMail => $c->get(StaffMail::class),
                is_array($config) && is_int($config['password_min'] ?? null) ? max(1, $config['password_min']) : StaffAccounts::PASSWORD_MIN,
            );
        });
    }

    /** The staff guard: config/rbac.php 'guard', else an admin's StaffPopulation, else auth's default guard; one guard, or this throws. */
    private static function guard(Container $container): string
    {
        $config = $container->get(Application::class)->configFile('rbac');
        $configured = is_array($config) && is_string($config['guard'] ?? null) && $config['guard'] !== '' ? $config['guard'] : null;
        $admin = $container->bound(StaffPopulation::class) ? $container->get(StaffPopulation::class)->guard : null;
        if ($configured !== null && $admin !== null && $configured !== $admin) {
            throw new \RuntimeException("config/rbac.php 'guard' names \"{$configured}\", but the admin signs staff in through the guard \"{$admin}\". Staff accounts have one guard: name \"{$admin}\" or remove 'guard'.");
        }

        return $configured ?? $admin ?? ($container->bound(AuthManager::class) ? $container->get(AuthManager::class)->defaultGuardName() : 'session');
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
}
