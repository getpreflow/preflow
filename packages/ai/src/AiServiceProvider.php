<?php

declare(strict_types=1);

namespace Preflow\Ai;

use Preflow\Ai\Settings\AiSettingsStore;
use Preflow\Ai\Settings\FileAiSettingsStore;
use Preflow\Ai\Usage\FileUsageLog;
use Preflow\Ai\Usage\Pricing;
use Preflow\Ai\Usage\UsageLog;
use Preflow\Core\Application;
use Preflow\Core\Container\Container;
use Preflow\Core\Container\ServiceProvider;
use Preflow\I18n\Translator;

/**
 * Binds the AI service, usage log, pricing and settings store from config/ai.php. A missing file
 * means AI is off (enabled defaults to false), so listing this provider is harmless. An unknown
 * `provider` stops the boot. The
 * ai:check and ai:usage commands are listed in composer.json (extra.preflow.commands).
 */
final class AiServiceProvider extends ServiceProvider
{
    public function register(Container $container): void
    {
        $container->singleton(Pricing::class, static fn (Container $c): Pricing => Pricing::withOverrides(
            is_array(self::config($c)['pricing'] ?? null) ? self::config($c)['pricing'] : [],
        ));
        $container->singleton(UsageLog::class, static fn (Container $c): UsageLog => new FileUsageLog(self::storage($c)));
        $container->singleton(AiSettingsStore::class, static fn (Container $c): AiSettingsStore => new FileAiSettingsStore(self::storage($c)));
        // The provider's factory (a built-in or config/ai.php's class) is built by the container on first
        // use, after every provider ran, so its constructor may take their services and #[Config] values.
        $container->singleton(Ai::class, static fn (Container $c): Ai => new Ai(
            self::config($c),
            $c->get(UsageLog::class),
            $c->get(Pricing::class),
            make: static fn (string $class): object => $c->get($class),
            // Warnings to the app's PSR-3 logger when one is bound (asked when warning: a provider may
            // bind it later), else the PHP log.
            report: static function (string $message) use ($c): void {
                if ($c->bound(\Psr\Log\LoggerInterface::class)) {
                    $c->get(\Psr\Log\LoggerInterface::class)->warning($message);

                    return;
                }
                error_log($message);
            },
        ));
    }

    public function boot(Container $container): void
    {
        // A provider name config/ai.php can't mean stops the boot, naming the accepted forms.
        $provider = self::config($container)['provider'] ?? 'claude';
        Ai::factoryClass(is_string($provider) && $provider !== '' ? $provider : 'claude');

        // Declared in composer.json (extra.preflow.translations); this covers apps whose Composer metadata doesn't list the package.
        if ($container->has(Translator::class)) {
            $container->get(Translator::class)->addDefaultsIfMissing('ai', dirname(__DIR__) . '/lang', 'admin');
        }
    }

    /** @return array<string, mixed> config/ai.php */
    private static function config(Container $c): array
    {
        $file = $c->get(Application::class)->basePath('config/ai.php');
        $config = is_file($file) ? require $file : [];

        return is_array($config) ? $config : [];
    }

    private static function storage(Container $c): string
    {
        $storage = self::config($c)['storage'] ?? 'storage/ai';
        $storage = is_string($storage) && $storage !== '' ? $storage : 'storage/ai';

        return str_starts_with($storage, '/') ? $storage : $c->get(Application::class)->basePath($storage);
    }
}
