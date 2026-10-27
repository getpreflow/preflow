<?php

declare(strict_types=1);

namespace Preflow\Codes;

use Preflow\Core\Application;
use Preflow\Core\Config\Config;
use Preflow\Core\Container\Container;
use Preflow\Core\Container\ServiceProvider;
use Preflow\Core\Throttle\Throttle;
use Preflow\Data\DataManager;
use Preflow\Data\Locales;
use Preflow\Data\Migration\MigrationRegistry;
use Preflow\I18n\Translator;
use Preflow\Notify\Notifier;
use Preflow\View\TemplateEngineInterface;

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
        $container->singleton(CodeMailer::class, static function (Container $c): CodeMailer {
            $url = $c->get(Config::class)->get('app.url');

            return new CodeMailer(
                $c->get(Notifier::class),
                $c->get(TemplateEngineInterface::class),
                $c->get(Translator::class),
                $c->get(Locales::class),
                is_string($url) && $url !== '' ? $url : 'http://localhost',
                self::config($c),
            );
        });
    }

    public function boot(Container $container): void
    {
        // Declared in composer.json (extra.preflow.translations); this covers apps whose Composer metadata doesn't list the package.
        if ($container->has(Translator::class)) {
            $container->get(Translator::class)->addDefaultsIfMissing('codes-mail', dirname(__DIR__) . '/lang', 'public');
        }
        if ($container->has(TemplateEngineInterface::class)) {
            $container->get(TemplateEngineInterface::class)->addNamespace('codes', dirname(__DIR__) . '/templates');
        }
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
