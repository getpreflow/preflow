<?php

declare(strict_types=1);

namespace Preflow\Fields;

use Preflow\Core\Application;
use Preflow\Core\Container\Container;
use Preflow\Core\Container\ServiceProvider;
use Preflow\Data\DataManager;
use Preflow\Data\FieldKinds;
use Preflow\Data\Locales;
use Preflow\Data\Schema\ColumnKind;
use Preflow\Fields\Rules\ValidLink;
use Preflow\Fields\Rules\ValidPhone;
use Preflow\Validation\Rules\Color;
use Preflow\Validation\Rules\Email;
use Preflow\Validation\Rules\Json;
use Preflow\View\TemplateEngineInterface;

/**
 * The field types of preflow/fields, for the data package and the app's templates:
 *
 * - registerKinds(): each type's column kind and its own check with the data package
 *   (FieldKinds::column(), rules()), so a save validates the value without Folio;
 * - the template functions (FieldsExtensionProvider) with the app's engine.
 *
 * RecordAddresses is optional: bind your own (Folio binds one); without it a record link
 * renders without an href.
 *
 * Listed in config/providers.php by a site without Folio; Folio registers it itself.
 *
 * @since 0.91.0
 */
final class FieldsServiceProvider extends ServiceProvider
{
    public function register(Container $container): void
    {
    }

    public function boot(Container $container): void
    {
        // Without config/data.php there is no storage to register with. With it, an error building
        // the DataManager is the site's to see, so it is not caught here.
        if ($container->get(Application::class)->hasConfigFile('data')) {
            self::registerKinds($container->get(DataManager::class)->fieldKinds());
        }

        if ($container->has(TemplateEngineInterface::class)) {
            $engine = $container->get(TemplateEngineInterface::class);
            $provider = new FieldsExtensionProvider(
                static fn (): ?RecordAddresses => $container->bound(RecordAddresses::class) ? $container->get(RecordAddresses::class) : null,
                $container->bound(Locales::class) ? $container->get(Locales::class) : null,
            );
            foreach ($provider->getTemplateFunctions() as $function) {
                $engine->addFunction($function);
            }
        }
    }

    /**
     * The field types' storage and checks, with the data package. Each type of this package is
     * one entry here: its column kind and its rules for a field's config.
     */
    public static function registerKinds(FieldKinds $kinds): void
    {
        // link: an object ({kind, target, label, new_tab}), JSON text in an SQL column.
        $kinds->column('link', ColumnKind::Text);
        $kinds->rules('link', static fn (array $config): array => [new ValidLink(LinkOptions::fromConfig($config))]);

        // email: text (loose search covers it), checked as an email address.
        $kinds->column('email', ColumnKind::Short);
        $kinds->text('email');
        $kinds->rules('email', static fn (array $config): array => [new Email()]);

        // phone: an object ({as_typed, e164}), JSON text in an SQL column; not searchable, like a link.
        $kinds->column('phone', ColumnKind::Text);
        $kinds->rules('phone', static fn (array $config): array => [new ValidPhone(PhoneOptions::fromConfig($config))]);

        // color: "#rrggbb" or "#rrggbbaa" text; with "palette_only" only the palette's colours.
        $kinds->column('color', ColumnKind::Short);
        $kinds->rules('color', static function (array $config): array {
            $options = ColorOptions::fromConfig($config);

            return [new Color($options->paletteOnly ? $options->values() : null)];
        });

        // code: plain text (the language is the field's setting), so loose search covers it; nothing to check.
        $kinds->column('code', ColumnKind::Text);
        $kinds->text('code');

        // json: JSON as text, as the editor wrote it; not searchable.
        $kinds->column('json', ColumnKind::Text);
        $kinds->rules('json', static fn (array $config): array => [new Json()]);
    }
}
