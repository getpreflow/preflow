<?php

declare(strict_types=1);

namespace Preflow\Fields;

use Preflow\Blocks\BlockFieldChecks;
use Preflow\Core\Application;
use Preflow\Core\Container\Container;
use Preflow\Core\Container\ServiceProvider;
use Preflow\Data\DataManager;
use Preflow\Data\FieldKinds;
use Preflow\Data\Locales;
use Preflow\Data\Schema\ColumnKind;
use Preflow\Data\TypeRegistry;
use Preflow\Fields\Rules\ValidDateRange;
use Preflow\Fields\Rules\ValidDateTime;
use Preflow\Fields\Rules\ValidEmbed;
use Preflow\Fields\Rules\ValidLink;
use Preflow\Fields\Rules\ValidLocation;
use Preflow\Fields\Rules\ValidPhone;
use Preflow\Fields\Rules\ValidTable;
use Preflow\Fields\Rules\ValidTime;
use Preflow\Validation\Rules\Color;
use Preflow\Validation\Rules\Email;
use Preflow\Validation\Rules\Json;
use Preflow\View\AssetCollector;
use Preflow\View\TemplateEngineInterface;

/**
 * The field types of preflow/fields, for the data package and the app's templates:
 *
 * - registerKinds(): each type's column kind and its own check with the data package
 *   (FieldKinds::column(), rules()), so a save validates the value without Folio;
 * - the template functions (FieldsExtensionProvider) with the app's engine.
 *
 * RecordAddresses is optional: bind your own (Folio binds one); without it a record link
 * renders without an href (and an embed's poster is not shown). It binds EmbedProviders (the
 * video hosts: YouTube, Vimeo and those a site adds to it in its own boot()); EmbedPlayer
 * (the embed's markup) and LocationPicker (a map in an editor) are the site's to bind, both
 * optional.
 *
 * Its boot also checks the options of these types in every type's fields (kept in the boot
 * manifest), and with preflow/blocks it adds the same check for block fields (BlockFieldChecks),
 * so a misspelt option stops the boot with or without Folio.
 *
 * Listed in config/providers.php by a site without Folio; Folio registers it itself.
 *
 * @since 0.91.0
 */
final class FieldsServiceProvider extends ServiceProvider
{
    public function register(Container $container): void
    {
        if (!$container->bound(EmbedProviders::class)) {
            $container->singleton(EmbedProviders::class, static fn (): EmbedProviders => new EmbedProviders());
        }
        // With preflow/blocks: every block's fields are checked once, when the block registry is
        // built (the video hosts are asked then, so a site's own register() has bound them).
        if (class_exists(BlockFieldChecks::class)) {
            if (!$container->bound(BlockFieldChecks::class)) {
                $container->instance(BlockFieldChecks::class, new BlockFieldChecks());
            }
            $container->get(BlockFieldChecks::class)->add(static function (array $fields) use ($container): void {
                FieldOptions::checkFields($fields, $container->get(EmbedProviders::class));
            });
        }
    }

    public function boot(Container $container): void
    {
        // Without config/data.php there is no storage to register with. With it, an error building
        // the DataManager is the site's to see, so it is not caught here.
        $app = $container->get(Application::class);
        if ($app->hasConfigFile('data')) {
            $data = $container->get(DataManager::class);
            self::registerKinds(
                $data->fieldKinds(),
                static fn (): EmbedProviders => $container->get(EmbedProviders::class),
                // A poster is checked against the library when the bound RecordAddresses can tell (Folio's can).
                static function () use ($container): ?AssetImages {
                    $addresses = $container->bound(RecordAddresses::class) ? $container->get(RecordAddresses::class) : null;

                    return $addresses instanceof AssetImages ? $addresses : null;
                },
            );
            $types = $data->getTypeRegistry();
            if ($types !== null) {
                // The options of every type's fields, with or without Folio: checked when the boot
                // manifest is built (and live while none is loaded), as the data package's own checks.
                $manifest = $app->bootManifest();
                $manifest->remember('fields.options', static function () use ($types, $container): bool {
                    self::checkTypes($types, $container->get(EmbedProviders::class));

                    return true;
                }, $manifest->isRecording() ? $types->sources() : []);
            }
        }

        if ($container->has(TemplateEngineInterface::class)) {
            $engine = $container->get(TemplateEngineInterface::class);
            $provider = new FieldsExtensionProvider(
                static fn (): ?RecordAddresses => $container->bound(RecordAddresses::class) ? $container->get(RecordAddresses::class) : null,
                $container->bound(Locales::class) ? $container->get(Locales::class) : null,
                static fn (): EmbedProviders => $container->get(EmbedProviders::class),
                static fn (): EmbedPlayer => $container->bound(EmbedPlayer::class) ? $container->get(EmbedPlayer::class) : new PrivacyEmbedPlayer(),
                static fn (): ?AssetCollector => $container->has(AssetCollector::class) ? $container->get(AssetCollector::class) : null,
            );
            foreach ($provider->getTemplateFunctions() as $function) {
                $engine->addFunction($function);
            }
        }
    }

    /**
     * Refuses at boot a field option of this package's types that cannot work, in every type of
     * the registry (FieldOptions::checkFields()), the message naming the type and the field. Types
     * a provider defines at runtime after this boot are not seen here (Folio's type catalog checks
     * the ones it shows).
     *
     * @throws \LogicException
     */
    public static function checkTypes(TypeRegistry $types, ?EmbedProviders $embedProviders = null): void
    {
        foreach ($types->keys() as $key) {
            $schema = $types->schema($key);
            $fields = array_filter(is_array($schema['fields'] ?? null) ? $schema['fields'] : [], 'is_array');
            try {
                FieldOptions::checkFields($fields, $embedProviders);
            } catch (\LogicException $e) {
                throw new \LogicException("Type \"{$key}\": " . $e->getMessage(), 0, $e);
            }
        }
    }

    /**
     * The field types' storage and checks, with the data package. Each type of this package is
     * one entry here: its column kind and its rules for a field's config.
     *
     * @param (\Closure(): EmbedProviders)|null $embedProviders the app's video hosts, asked when a value is checked (default: YouTube and Vimeo)
     * @param (\Closure(): ?AssetImages)|null $images whether a poster is an image of the library (null: shape only)
     */
    public static function registerKinds(FieldKinds $kinds, ?\Closure $embedProviders = null, ?\Closure $images = null): void
    {
        $embedProviders ??= EmbedProviders::lazyBuiltIn();

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

        // datetime: UTC ISO text ("2026-03-29T12:30:00Z"), which sorts as text: a date column.
        $kinds->column('datetime', ColumnKind::Date);
        $kinds->rules('datetime', static fn (array $config): array => [new ValidDateTime(DateOptions::fromConfig($config))]);

        // time: "HH:MM" text.
        $kinds->column('time', ColumnKind::Short);
        $kinds->rules('time', static fn (array $config): array => [new ValidTime()]);

        // daterange: an object ({start, end}), JSON text in an SQL column; not searchable, like a link.
        $kinds->column('daterange', ColumnKind::Text);
        $kinds->rules('daterange', static fn (array $config): array => [new ValidDateRange(DateOptions::fromConfig($config))]);

        // embed: an object ({provider, id, url, poster}), JSON text in an SQL column; not searchable.
        $kinds->column('embed', ColumnKind::Text);
        $kinds->rules('embed', static fn (array $config): array => [new ValidEmbed(EmbedOptions::fromConfig($config), $embedProviders(), $images !== null ? $images() : null)]);

        // table: an object ({head, rows}, a cell a text or a text per language), JSON text in an SQL
        // column; not searchable in 0.91 (a structured value, like a link's label or a block).
        $kinds->column('table', ColumnKind::Text);
        $kinds->rules('table', static fn (array $config): array => [new ValidTable(TableOptions::fromConfig($config))]);

        // location: an object ({lat, lng, address}), JSON text in an SQL column; not searchable.
        $kinds->column('location', ColumnKind::Text);
        $kinds->rules('location', static fn (array $config): array => [new ValidLocation()]);
    }
}
