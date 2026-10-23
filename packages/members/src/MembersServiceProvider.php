<?php

declare(strict_types=1);

namespace Preflow\Members;

use Preflow\Auth\PasswordHasherInterface;
use Preflow\Core\Application;
use Preflow\Core\Config\Config;
use Preflow\Core\Container\Container;
use Preflow\Core\Container\ServiceProvider;
use Preflow\Data\DataManager;
use Preflow\Data\Locales;
use Preflow\Folio\Extension\ExtensionRegistry;
use Preflow\Members\Admission\Admission;
use Preflow\Notify\Notifier;

/**
 * Binds the members services: MembersConfig (config/members.php; a missing file means the
 * defaults), Members, Links, MemberMailer and the configured Admission. When the Folio
 * extension "members" is enabled, boot checks that rbac (the member roles) is enabled too
 * and that the admission class is one.
 */
final class MembersServiceProvider extends ServiceProvider
{
    public function register(Container $container): void
    {
        $container->singleton(MembersConfig::class, static fn (Container $c): MembersConfig => MembersConfig::fromFile(
            $c->get(Application::class)->basePath('config/members.php'),
        ));
        $container->singleton(Members::class, static fn (Container $c): Members => new Members(
            $c->get(DataManager::class),
            $c->get(PasswordHasherInterface::class),
        ));
        $container->singleton(Links::class, static fn (Container $c): Links => self::links($c));
        $container->singleton(MemberMailer::class, static fn (Container $c): MemberMailer => new MemberMailer(
            $c->get(Notifier::class),
            $c->get(Locales::class),
        ));
        $container->singleton(Admission::class, static function (Container $c): Admission {
            $class = $c->get(MembersConfig::class)->admission();
            if (!class_exists($class) || !is_subclass_of($class, Admission::class)) {
                throw new \RuntimeException("config/members.php admission: {$class} does not implement " . Admission::class . '.');
            }

            return $c->get($class);
        });
    }

    public function boot(Container $container): void
    {
        $extensions = $container->get(ExtensionRegistry::class);
        if (!$extensions->isEnabled('members')) {
            return;
        }
        if (!$extensions->isEnabled('rbac')) {
            throw new \RuntimeException('The members extension needs the rbac extension for the member roles: run preflow folio:extensions:enable rbac.');
        }
        $container->get(Admission::class);
    }

    private static function links(Container $c): Links
    {
        $app = $c->get(Application::class);
        $url = $c->get(Config::class)->get('app.url');
        $i18n = self::config($app->basePath('config/i18n.php'));
        $folio = self::config($app->basePath('config/folio.php'));

        return new Links(
            is_string($url) && $url !== '' ? $url : 'http://localhost',
            $c->get(MembersConfig::class)->path(),
            $c->get(Locales::class),
            ($i18n['url_strategy'] ?? 'prefix') === 'prefix',
            '/' . trim((string) ($folio['path'] ?? '/folio'), '/'),
        );
    }

    /** @return array<string, mixed> */
    private static function config(string $file): array
    {
        $config = is_file($file) ? require $file : [];

        return is_array($config) ? $config : [];
    }
}
