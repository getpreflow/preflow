<?php

declare(strict_types=1);

namespace Preflow\I18n\Machine;

use Preflow\Ai\Ai;
use Preflow\Ai\AiServiceProvider;
use Preflow\Ai\Settings\AiSettingsStore;
use Preflow\Core\Application;
use Preflow\Core\Container\Container;
use Preflow\Core\Container\ServiceProvider;

/**
 * Binds machine translation from config/ai.php. Everything resolves with AI off; only using the
 * translator throws AiUnavailable, so admin screens can depend on it unconditionally.
 */
final class MachineServiceProvider extends ServiceProvider
{
    private const DEFAULT_BATCH_CHARS = 12000;

    public function register(Container $container): void
    {
        // The AI core binds Ai and the settings store this builds on.
        if (!$container->hasProvider(AiServiceProvider::class)) {
            $container->registerProvider(new AiServiceProvider());
        }

        $container->singleton(TranslationSettings::class, static fn (Container $c): TranslationSettings => new TranslationSettings(
            self::config($c),
            $c->get(AiSettingsStore::class),
        ));
        $container->singleton(MachineTranslator::class, static fn (Container $c): MachineTranslator => new AiTranslator(
            $c->get(Ai::class),
            $c->get(TranslationSettings::class),
            self::batchChars($c),
        ));
        $container->singleton(Estimator::class, static fn (Container $c): Estimator => new Estimator(
            $c->get(Ai::class),
            self::batchChars($c),
        ));
    }

    /** @return array<string, mixed> config/ai.php; a missing file means AI is off */
    private static function config(Container $c): array
    {
        $file = $c->get(Application::class)->basePath('config/ai.php');
        $config = is_file($file) ? require $file : [];

        return is_array($config) ? $config : [];
    }

    private static function batchChars(Container $c): int
    {
        $translation = self::config($c)['translation'] ?? [];
        $value = is_array($translation) ? ($translation['batch_chars'] ?? null) : null;

        return is_int($value) && $value > 0 ? $value : self::DEFAULT_BATCH_CHARS;
    }
}
