<?php

declare(strict_types=1);

namespace Preflow\Codes;

use Preflow\Codes\Fields\PurposeFieldType;
use Preflow\Core\Application;
use Preflow\Core\Container\Container;
use Preflow\Core\Container\ServiceProvider;
use Preflow\Core\Throttle\Throttle;
use Preflow\Data\DataManager;
use Preflow\Data\Migration\MigrationRegistry;
use Preflow\Folio\Extension\ExtensionRegistry;
use Preflow\Folio\Field\FieldTypeRegistry;

/**
 * Binds Codes (purposes and throttle from config/codes.php; a missing file means no purposes)
 * and registers the code_redemption migration. When the Folio extension "codes" is enabled
 * (config/folio/extensions.json), it requires the storage alias "codes" at boot and registers
 * the field type codes_purpose.
 */
final class CodesServiceProvider extends ServiceProvider
{
    public function register(Container $container): void
    {
        // The admin labels (lang/{locale}/codes.php): declared in composer.json (extra.preflow.translations);
        // this covers apps whose Composer metadata doesn't list the package (test apps).
        if ($container->bound(\Preflow\I18n\Translator::class)) {
            $container->get(\Preflow\I18n\Translator::class)->addDefaultsIfMissing('codes', dirname(__DIR__) . '/lang', 'admin');
        }
        $container->get(MigrationRegistry::class)->addPath(dirname(__DIR__) . '/migrations');
        $container->singleton(Codes::class, static fn (Container $c): Codes => new Codes(
            $c->get(DataManager::class),
            $c->get(Throttle::class),
            self::config($c),
            lockDir: $c->get(Application::class)->basePath('storage/locks'),
        ));
    }

    public function boot(Container $container): void
    {
        if (!$container->get(ExtensionRegistry::class)->isEnabled('codes')) {
            return;
        }
        $dm = is_file($container->get(Application::class)->basePath('config/data.php')) ? $container->get(DataManager::class) : null;
        if ($dm === null || !in_array(Codes::STORAGE, $dm->driverNames(), true)) {
            throw new \RuntimeException("The codes extension stores codes in the storage alias [codes], which is not configured: add 'aliases' => ['codes' => '<driver>'] to config/data.php (a SQL driver such as 'sqlite').");
        }

        $container->get(FieldTypeRegistry::class)->register(new PurposeFieldType($container->get(Codes::class)->purposes()));
    }

    /** @return array<string, mixed> config/codes.php */
    private static function config(Container $c): array
    {
        $file = $c->get(Application::class)->basePath('config/codes.php');
        $config = is_file($file) ? require $file : [];

        return is_array($config) ? $config : [];
    }
}
