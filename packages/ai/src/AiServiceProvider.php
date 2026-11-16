<?php

declare(strict_types=1);

namespace Preflow\Ai;

use Preflow\Ai\Draft\AiDraftWriter;
use Preflow\Ai\Draft\DraftWriter;
use Preflow\Ai\Limits\AiLimits;
use Preflow\Ai\Settings\AiSettingsStore;
use Preflow\Ai\Settings\FileAiSettingsStore;
use Preflow\Ai\Settings\ProjectTuning;
use Preflow\Ai\Usage\FileUsageLog;
use Preflow\Ai\Usage\Pricing;
use Preflow\Ai\Usage\UsageLog;
use Preflow\Core\Application;
use Preflow\Core\Container\Container;
use Preflow\Core\Container\ServiceProvider;
use Preflow\Core\Throttle\Throttle;
use Preflow\I18n\Translator;

/**
 * Binds the AI service, usage log, pricing, settings store and draft writer from config/ai.php. A
 * missing file means AI is off (enabled defaults to false), so listing this provider is harmless.
 * An unknown `provider`, or a `draft.writer` that names no DraftWriter, stops the boot. AiLimits (the
 * per-person limits of `limits`, since 0.78) is bound only when core's Throttle is (an app with
 * config/data.php); a limit that is not a whole number in range stops the boot too. The
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
        $container->singleton(ProjectTuning::class, static fn (Container $c): ProjectTuning => new ProjectTuning(self::config($c), $c->get(AiSettingsStore::class)));
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

        // config/ai.php draft.writer: the class of a DraftWriter of the app's own, built by the container
        // (so it may take services); without it, AiDraftWriter through the AI provider (since 0.77).
        $container->singleton(DraftWriter::class, static function (Container $c): DraftWriter {
            $class = self::draftWriterClass($c);
            if ($class !== null) {
                return $c->get($class);
            }

            return new AiDraftWriter($c->get(Ai::class), static fn (): Tuning => $c->get(ProjectTuning::class)->tuning());
        });

        // Per-person limits count through the Throttle, which needs the data layer: without it nothing is limited.
        // Bound whenever the Throttle is, whoever binds the AI work (config/ai.php or an app's provider).
        // Without an app key they cannot count: the boot stops where config/ai.php lets AI work run
        // (boot()), and take()/check() refuse naming APP_KEY everywhere else; never unlimited. An AI-off
        // site still boots without a key, as before 0.78.
        if ($container->bound(Throttle::class)) {
            $container->singleton(AiLimits::class, static function (Container $c): AiLimits {
                $limits = self::config($c)['limits'] ?? [];

                return new AiLimits($c->get(Throttle::class), is_array($limits) ? $limits : []);
            });
        }
    }

    public function boot(Container $container): void
    {
        self::draftWriterClass($container);
        // A limit config/ai.php can't mean stops the boot, naming the setting; so do limits that are on
        // without an app key where config/ai.php lets AI work run.
        if ($container->bound(AiLimits::class)) {
            $limits = $container->get(AiLimits::class);
            if (self::aiCanWork($container)) {
                $limits->assertKey();
            }
        }

        // A provider name config/ai.php can't mean stops the boot, naming the accepted forms.
        $provider = self::config($container)['provider'] ?? 'claude';
        Ai::factoryClass(is_string($provider) && $provider !== '' ? $provider : 'claude');

        // Declared in composer.json (extra.preflow.translations); this covers apps whose Composer metadata doesn't list the package.
        if ($container->has(Translator::class)) {
            $container->get(Translator::class)->addDefaultsIfMissing('ai', Ai::langPath(), 'admin');
        }
    }

    /**
     * config/ai.php draft.writer, or null for the default AiDraftWriter.
     *
     * @return class-string<DraftWriter>|null
     * @throws \InvalidArgumentException when it names no DraftWriter class
     */
    private static function draftWriterClass(Container $c): ?string
    {
        $draft = self::config($c)['draft'] ?? [];
        $class = is_array($draft) ? ($draft['writer'] ?? null) : null;
        if ($class === null || $class === '') {
            return null;
        }
        $class = is_string($class) ? ltrim($class, '\\') : '';
        if ($class === '' || !class_exists($class) || !is_subclass_of($class, DraftWriter::class)) {
            throw new \InvalidArgumentException('config/ai.php draft.writer: ' . ($class !== '' ? $class : 'the value') . ' is not the class of a ' . DraftWriter::class . '.');
        }

        return $class;
    }

    /**
     * Whether config/ai.php lets any AI work run: the switch is on, or a translator or draft writer
     * of the app's own is named (they need not go through the switch).
     */
    private static function aiCanWork(Container $c): bool
    {
        $config = self::config($c);
        $named = static fn (mixed $section, string $key): bool => is_array($section) && is_string($section[$key] ?? null) && $section[$key] !== '';

        return ($config['enabled'] ?? false) === true
            || $named($config['translation'] ?? null, 'translator')
            || $named($config['draft'] ?? null, 'writer');
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
