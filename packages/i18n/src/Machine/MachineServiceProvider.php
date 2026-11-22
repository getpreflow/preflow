<?php

declare(strict_types=1);

namespace Preflow\I18n\Machine;

use Preflow\Ai\Ai;
use Preflow\Ai\AiServiceProvider;
use Preflow\Ai\Limits\AiLimits;
use Preflow\Ai\Settings\AiSettingsStore;
use Preflow\Core\Application;
use Preflow\Core\Container\Container;
use Preflow\Core\Container\ServiceProvider;

/**
 * Binds machine translation from config/ai.php. Everything resolves with AI off; only using the
 * translator throws TranslatorUnavailable, so admin screens can depend on it unconditionally. The
 * translator is AiTranslator unless `translation.translator` names a class of the app's own.
 *
 * preflow/ai is only suggested (since 0.80.0); only the default AiTranslator needs it. Without it
 * (AiPackage::installed() is false) a translator named in `translation.translator` works as
 * before; with none named the translator is MissingAiTranslator (never available; it says what is
 * missing). MachineTranslation says which (Folio asks it, not this class). The jobs, sources,
 * review index and worker resolve either way; TranslationSettings, which holds preflow/ai's tuning
 * and glossary, is bound only with the package.
 */
final class MachineServiceProvider extends ServiceProvider
{
    private const DEFAULT_BATCH_CHARS = 12000;

    public function register(Container $container): void
    {
        $ai = AiPackage::installed();
        if ($ai) {
            // The AI core binds Ai and the settings store this builds on.
            if (!$container->hasProvider(AiServiceProvider::class)) {
                $container->registerProvider(new AiServiceProvider());
            }
            $container->singleton(TranslationSettings::class, static fn (Container $c): TranslationSettings => new TranslationSettings(
                self::config($c),
                $c->get(AiSettingsStore::class),
            ));
        }
        // config/ai.php translation.translator: the class of a MachineTranslator of the app's own, built
        // by the container (so it may take services), with or without preflow/ai; without one,
        // AiTranslator through the AI provider, or MissingAiTranslator when preflow/ai is not installed.
        $container->singleton(MachineTranslator::class, static function (Container $c) use ($ai): MachineTranslator {
            $class = self::translatorClass($c);
            if ($class !== null) {
                return $c->make($class, ['batchChars' => self::batchChars($c)]);
            }

            return $ai
                ? new AiTranslator($c->get(Ai::class), $c->get(TranslationSettings::class), self::batchChars($c))
                : new MissingAiTranslator();
        });
        // Whether there is a translator to offer: asked on every boot (Folio's permissions), so from the
        // config alone, without building the translator. A provider binding a translator of its own binds this too.
        $container->singleton(MachineTranslation::class, static fn (Container $c): MachineTranslation => new ConfiguredMachineTranslation(
            $ai || self::translatorClass($c) !== null,
        ));
        $container->singleton(Estimator::class, static fn (Container $c): Estimator => new Estimator(
            $c->get(MachineTranslator::class),
            self::batchChars($c),
        ));
        $container->singleton(TextSources::class, static fn (): TextSources => new TextSources());
        $container->singleton(JobStore::class, static fn (Container $c): JobStore => new FileJobStore(self::storage($c) . '/jobs'));
        $container->singleton(RecordLease::class, static fn (Container $c): RecordLease => new FileRecordLease(self::storage($c) . '/leases'));
        $container->singleton(ReviewIndex::class, static fn (Container $c): ReviewIndex => new FileReviewIndex(self::storage($c) . '/review.json'));
        $container->singleton(JobRunner::class, static fn (Container $c): JobRunner => new JobRunner(
            $c->get(TextSources::class),
            $c->get(MachineTranslator::class),
            $c->get(Estimator::class),
            $c->get(JobStore::class),
            $c->get(RecordLease::class),
            null,
            // Per-person AI limits (bound with a data layer): a job someone queued counts against them.
            $c->bound(AiLimits::class) ? $c->get(AiLimits::class) : null,
        ));
        $container->singleton(TranslationWorker::class, static fn (Container $c): TranslationWorker => new TranslationWorker(
            $c->get(JobRunner::class),
            $c->get(JobStore::class),
            $c->get(MachineTranslator::class),
            self::storage($c) . '/worker.json',
        ));
    }

    /** A translator class config/ai.php can't mean stops the boot. */
    public function boot(Container $container): void
    {
        self::translatorClass($container);
    }

    /**
     * config/ai.php translation.translator, or null for the default AiTranslator.
     *
     * @return class-string<MachineTranslator>|null
     * @throws \InvalidArgumentException when it names no MachineTranslator class
     */
    private static function translatorClass(Container $c): ?string
    {
        $translation = self::config($c)['translation'] ?? [];
        $class = is_array($translation) ? ($translation['translator'] ?? null) : null;
        if ($class === null || $class === '') {
            return null;
        }
        $class = is_string($class) ? ltrim($class, '\\') : '';
        if ($class === '' || !class_exists($class) || !is_subclass_of($class, MachineTranslator::class)) {
            throw new \InvalidArgumentException('config/ai.php translation.translator: ' . ($class !== '' ? $class : 'the value') . ' is not the class of a ' . MachineTranslator::class . '.');
        }

        return $class;
    }

    /** Same location rule as the AI core: relative to the app, default storage/ai. */
    private static function storage(Container $c): string
    {
        $storage = self::config($c)['storage'] ?? 'storage/ai';
        $storage = is_string($storage) && $storage !== '' ? $storage : 'storage/ai';

        return str_starts_with($storage, '/') ? $storage : $c->get(Application::class)->basePath($storage);
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
