<?php

declare(strict_types=1);

namespace Preflow\Codes;

use Preflow\Core\Application;
use Preflow\Core\Container\Container;
use Preflow\Core\Container\ServiceProvider;
use Preflow\Core\Throttle\Throttle;
use Preflow\Data\DataManager;
use Preflow\Data\Migration\MigrationRegistry;

/**
 * Binds Codes (purposes and throttle from config/codes.php; a missing file means no purposes)
 * and registers the code_redemption migration. At boot it defines the type "code" (CodeType)
 * unless something did already (Folio's codes admin defines its own, richer one).
 */
final class CodesServiceProvider extends ServiceProvider
{
    public function register(Container $container): void
    {
        $container->get(MigrationRegistry::class)->addPath(dirname(__DIR__) . '/migrations');
        $container->singleton(CodeEvents::class, static fn (): CodeEvents => new CodeEvents());
        $container->singleton(Codes::class, static fn (Container $c): Codes => new Codes(
            $c->get(DataManager::class),
            $c->get(Throttle::class),
            self::config($c),
            lockDir: $c->get(Application::class)->basePath('storage/locks'),
            events: $c->get(CodeEvents::class),
        ));
    }

    public function boot(Container $container): void
    {
        CodeType::define($container);
    }

    /** @return array<string, mixed> config/codes.php */
    private static function config(Container $c): array
    {
        $file = $c->get(Application::class)->basePath('config/codes.php');
        $config = is_file($file) ? require $file : [];

        return is_array($config) ? $config : [];
    }
}
