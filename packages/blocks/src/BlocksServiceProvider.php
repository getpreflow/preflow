<?php

declare(strict_types=1);

namespace Preflow\Blocks;

use Preflow\Components\ComponentMap;
use Preflow\Components\ComponentRenderer;
use Preflow\Core\Application;
use Preflow\Core\Cache\CacheRegistry;
use Preflow\Core\Cache\FileCache;
use Preflow\Core\Composer\InstalledPackages;
use Preflow\Core\Container\Container;
use Preflow\Core\Context\Contexts;
use Preflow\Core\Context\Purpose;
use Preflow\Blocks\Describe\ContentDescription;
use Preflow\Blocks\Layouts\AreaLayoutSets;
use Preflow\Blocks\Layouts\AreasFieldChecks;
use Preflow\Blocks\Layouts\Layout;
use Preflow\Blocks\Layouts\LayoutConfigException;
use Preflow\Blocks\Layouts\LayoutInput;
use Preflow\Blocks\Layouts\LayoutReport;
use Preflow\Blocks\Layouts\LayoutRegistry;
use Preflow\Blocks\Layouts\LayoutResource;
use Preflow\Blocks\Layouts\PageLayouts;
use Preflow\Blocks\Layouts\Rules\AreaContents;
use Preflow\Blocks\Layouts\Rules\LayoutChoice;
use Preflow\Blocks\Layouts\Rules\MessageRule;
use Preflow\Blocks\Layouts\Rules\PinnedSlug;
use Preflow\Blocks\Rules\BlockCount;
use Preflow\Blocks\Values\AreasFieldValue;
use Preflow\Blocks\Values\BlocksFieldValue;
use Preflow\Core\Container\ServiceProvider;
use Preflow\Data\DataManager;
use Preflow\Data\Describe\DescriptionContributors;
use Preflow\Data\DynamicRecord;
use Preflow\Data\FieldKinds;
use Preflow\Data\Html\HtmlCleaner;
use Preflow\Data\Html\SymfonyHtmlCleaner;
use Preflow\Data\Locales;
use Preflow\Data\Migration\MigrationRegistry;
use Preflow\Data\Records\InputPart;
use Preflow\Data\Records\OutputPart;
use Preflow\Data\Records\RecordParts;
use Preflow\Data\TypeDefinition;
use Preflow\Data\TypeFieldDefinition;
use Preflow\Data\TypeRegistry;
use Preflow\Data\Schema\ColumnKind;
use Preflow\Data\Values\CoreValues;
use Preflow\Data\Values\StoredCleaning;
use Preflow\Validation\RuleFactory;
use Preflow\Validation\ValidationContext;
use Preflow\Validation\ValidatorFactory;
use Preflow\View\TemplateEngineInterface;
use Preflow\View\TemplateFunctionDefinition;

/**
 * Blocks: the registry of every class carrying #[Block] — component blocks found under
 * app/Components, data-only blocks under app/Blocks (App\Blocks\…), the classes installed
 * packages list in composer.json extra.preflow.blocks and config/blocks.php 'classes' —
 * and the renderer behind the template function blocks(items, preview = false).
 *
 * The registry is a boot-manifest catalog ("blocks"); without a manifest it is cached in
 * blocks.cache when debug is off (Settings → Cache and cache:clear list it). The renderer
 * asks BlockLocale, BlockTextCleaner and BlockPreviewMarks (preview_mark() asks RecordPreviewMarks):
 * a provider (the app's, Folio) binds its own in register(), and boot() binds the plain defaults for
 * the rest.
 *
 * Page layouts (since 0.92): the layout classes (LayoutRegistry, found and cached like blocks)
 * and, with a type registry, PageLayouts for the types with "layouts" (bound only where a
 * provider bound none, since 0.94) — their option checked at
 * boot (kept in the boot manifest), and with a DataManager the layout rules on every validated
 * save of their records (RecordRules): the layout choice, a pinned page's slug (drafts too) and
 * what the areas hold.
 *
 * For APIs (since 0.92): ContentDescription (blocks, layouts, area layout sets and type layouts as
 * data for a schema builder) and, with page layouts, LayoutResource and LayoutInput — a page's
 * layout and areas read and written as JSON under the same core rules as Folio's form. Since 0.93
 * LayoutResource is the blocks package's output part of the one read class (RecordParts:
 * "_layout", "_areas", "_unplaced" in RecordOutput::toArray()).
 *
 * Block values (since 0.92): with a DataManager, BlockValueChecks runs on every validated write of
 * a typed record (a RecordRules check), so a seed, an import or a REST save is refused for a block
 * value Folio's form refuses, with the same keys and messages. Since 0.94 a blocks field's "min"
 * and "max" are the kind's check (Rules\BlockCount, FieldKinds::rules()), counted at the top and in
 * blocks alike.
 *
 * Field values (since 0.93): with a DataManager, BlocksFieldValue and AreasFieldValue on its
 * FieldKinds (read, written and cleaned through each block field's own value), and a page's
 * "_areas" cleaned on every typed write for the types with layouts (StoredCleaning::systemKey()).
 * The default BlockTextCleaner is CoreBlockTextCleaner: rich text is cleaned on render without
 * Folio too.
 *
 * Listed in config/providers.php by a site without Folio; Folio registers it itself.
 */
final class BlocksServiceProvider extends ServiceProvider
{
    public function register(Container $container): void
    {
        $app = $container->get(Application::class);

        $container->singleton(BlocksConfig::class, static fn (): BlocksConfig => BlocksConfig::fromApp($app));
        // Packages add their checks of block fields in register() (preflow/fields does); one instance, whoever registers first.
        if (!$container->bound(BlockFieldChecks::class)) {
            $container->instance(BlockFieldChecks::class, new BlockFieldChecks());
        }

        // Built once per request (discovery reflects over every component); conflicting keys fail when it is built.
        $container->singleton(BlockRegistry::class, static function (Container $c) use ($app): BlockRegistry {
            $config = self::config($c);
            try {
                $components = $c->get(ComponentMap::class);
            } catch (\Throwable) {
                $components = [];
            }

            return BlockRegistry::fromArray($app->bootManifest()->remember('blocks', static fn (): array => BlockRegistry::discover(
                $components,
                $app->basePath('app/Blocks'),
                // Installed packages' blocks (composer.json extra.preflow.blocks), then config/blocks.php's.
                [...self::packageClasses($app), ...$config->classes],
                $config->cacheFile,
                useCache: !$app->config()->get('app.debug', false),
                checks: $c->get(BlockFieldChecks::class)->all(),
            )->toArray(), [$app->basePath('app/Blocks'), $app->basePath('app/Components')]));
        });
        $app->bootManifest()->whenBuilding(static function (Application $app): void {
            $app->container()->get(BlockRegistry::class);
        });

        // Page layouts (since 0.92): found and cached like blocks.
        $container->singleton(LayoutRegistry::class, static function (Container $c) use ($app): LayoutRegistry {
            $config = self::config($c);

            return LayoutRegistry::fromArray($app->bootManifest()->remember('blocks.layouts', static fn (): array => LayoutRegistry::discover(
                $app->basePath('app/Layouts'),
                [...self::packageClasses($app, 'layouts'), ...$config->layouts],
                $config->layoutsCacheFile,
                useCache: !$app->config()->get('app.debug', false),
            )->toArray(), [$app->basePath('app/Layouts')]));
        });
        $app->bootManifest()->whenBuilding(static function (Application $app): void {
            $app->container()->get(LayoutRegistry::class);
        });
        // The area layout sets packages register (the columns block's column layouts), by name: one
        // instance, whoever binds it first (a package listed before this provider may add its set).
        if (!$container->bound(AreaLayoutSets::class)) {
            $container->instance(AreaLayoutSets::class, new AreaLayoutSets());
        }
        // An areas field of a block names a set some package registers (checked when the registry is built).
        $container->get(BlockFieldChecks::class)->add(static function (array $fields) use ($container): void {
            foreach ($fields as $name => $field) {
                if ($field instanceof TypeFieldDefinition && $field->type === BlockLists::AREAS) {
                    try {
                        $container->get(AreaLayoutSets::class)->assertNamed($field->config['layouts'] ?? null);
                    } catch (LayoutConfigException $e) {
                        throw new \LogicException(sprintf('field "%s": %s', $name, $e->getMessage()), 0, $e);
                    }
                }
            }
        });

        // One renderer per app, so nested blocks() calls see the preview state and the depth.
        $container->singleton(BlockRenderer::class, static fn (Container $c): BlockRenderer => new BlockRenderer(
            $c->get(BlockRegistry::class),
            $c->get(ComponentRenderer::class),
            $c,
            $c->get(BlockLocale::class),
            $c->get(BlockTextCleaner::class),
            $c->get(BlockPreviewMarks::class),
            $c->get(BlocksConfig::class)->maxDepth,
            static fn (string $message) => self::note($c, $message),
        ));

        // In register(): Folio adds its field types' block rules to the instance in its boot.
        self::registerValueChecks($container);
    }

    public function boot(Container $container): void
    {
        // The defaults, where no provider (the app's, Folio) bound its own in register().
        if (!$container->bound(BlockLocale::class)) {
            $container->singleton(BlockLocale::class, static fn (Container $c): BlockLocale => new DefaultBlockLocale($c->get(Locales::class)));
        }
        // The core cleaner (0.93; DefaultBlockTextCleaner before): sites without Folio get rich text
        // cleaned on its way to the template too.
        if (!$container->bound(BlockTextCleaner::class)) {
            $container->singleton(BlockTextCleaner::class, static fn (Container $c): BlockTextCleaner => new CoreBlockTextCleaner(self::cleaningOf($c), $c->get(BlockRegistry::class)));
        }
        if (!$container->bound(BlockPreviewMarks::class)) {
            $container->singleton(BlockPreviewMarks::class, static fn (): BlockPreviewMarks => new DefaultBlockPreviewMarks());
        }
        if (!$container->bound(RecordPreviewMarks::class)) {
            $container->singleton(RecordPreviewMarks::class, static fn (): RecordPreviewMarks => new DefaultRecordPreviewMarks());
        }

        self::bootPageLayouts($container);
        self::bindApi($container);

        // In boot: the cache file follows config that a provider registered after this one may set.
        $container->get(CacheRegistry::class)->register(new FileCache('blocks', 'Blocks', $container->get(BlocksConfig::class)->cacheFile));
        $container->get(CacheRegistry::class)->register(new FileCache('layouts', 'Page layouts', $container->get(BlocksConfig::class)->layoutsCacheFile));

        if ($container->has(TemplateEngineInterface::class)) {
            // blocks(items, preview = false): a blocks field rendered through its blocks' components.
            $container->get(TemplateEngineInterface::class)->addFunction(new TemplateFunctionDefinition(
                name: 'blocks',
                callable: static fn (mixed $blocks, bool $preview = false): string => $container->get(BlockRenderer::class)->render($blocks, $preview),
                isSafe: true,
            ));
            // preview_mark(type, record) (since 0.92): a linked record's element marked for the editor.
            $container->get(TemplateEngineInterface::class)->addFunction(new TemplateFunctionDefinition(
                name: 'preview_mark',
                callable: static fn (string $type, mixed $record): string => self::previewMark($container, $type, $record),
                isSafe: true,
            ));
        }
    }

    /**
     * preview_mark(type, record): the RecordPreviewMarks attributes as ` name="value"` (escaped) for
     * the element a template shows a linked record in, only while the render is a preview
     * (Purpose::Preview): a visit, a share-link preview, every other render and code outside every
     * render (no context: nothing is previewed) get ''. $record is the
     * record's id, an array holding the type's id field, or a DynamicRecord; without an id, ''.
     * The template marks the element itself: nothing is marked on its own.
     *
     * @throws \LogicException when a RecordPreviewMarks gives an attribute name that is not data-…, or a value that is no text
     */
    private static function previewMark(Container $container, string $type, mixed $record): string
    {
        if (Contexts::peek()?->purpose !== Purpose::Preview || $type === '') {
            return '';
        }
        $id = self::recordId($container, $type, $record);
        if ($id === null) {
            return '';
        }
        $marks = $container->get(RecordPreviewMarks::class);
        $html = '';
        foreach ($marks->attributes($type, $id) as $name => $value) {
            if (preg_match('/^data-[a-z0-9]+(-[a-z0-9]+)*$/', (string) $name) !== 1) {
                throw new \LogicException(sprintf('%s gave the attribute name "%s"; preview marks are data-… attributes in lower case (data-record-id).', $marks::class, $name));
            }
            $html .= ' ' . $name . '="' . htmlspecialchars(self::markValue($marks, (string) $name, $value), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
        }

        return $html;
    }

    /**
     * A preview mark's value as text: a site's RecordPreviewMarks may return anything (PHP does not
     * check an array's values), so a number or a boolean is printed, anything else refused.
     *
     * @throws \LogicException naming the class and the attribute
     */
    private static function markValue(RecordPreviewMarks $marks, string $name, mixed $value): string
    {
        return match (true) {
            is_string($value) => $value,
            is_int($value), is_float($value) => (string) $value,
            is_bool($value) => $value ? 'true' : 'false',
            default => throw new \LogicException(sprintf('%s gave the attribute "%s" a value of the type %s; preview marks are texts (or numbers).', $marks::class, $name, get_debug_type($value))),
        };
    }

    /** The id preview_mark() is given: a scalar, a DynamicRecord's, or an array's under the type's id field. */
    private static function recordId(Container $container, string $type, mixed $record): ?string
    {
        if ($record instanceof DynamicRecord) {
            $id = $record->getId();
        } elseif (is_array($record)) {
            $types = $container->bound(TypeRegistry::class) ? $container->get(TypeRegistry::class)
                : ($container->bound(DataManager::class) ? $container->get(DataManager::class)->getTypeRegistry() : null);
            if ($types === null || !$types->has($type)) {
                return null;
            }
            $id = $record[$types->get($type)->idField] ?? null;
        } else {
            $id = $record;
        }
        if (is_int($id)) {
            $id = (string) $id;
        }

        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * Classes installed packages list in composer.json extra.preflow.blocks (or, with $kind
     * "layouts", extra.preflow.layouts; read from vendor/composer/installed.json through the boot
     * manifest). Installing the package is enough.
     *
     * @param 'blocks'|'layouts' $kind
     * @return list<class-string>
     */
    public static function packageClasses(Application $app, string $kind = 'blocks'): array
    {
        $layouts = $kind === 'layouts';
        $attribute = $layouts ? Layout::class : Block::class;
        $classes = [];
        foreach ($app->installedPackages() as $package) {
            $declared = InstalledPackages::extra($package, 'preflow.' . $kind);
            if ($declared === null) {
                continue;
            }
            $what = 'extra.preflow.' . $kind;
            $name = (string) ($package['name'] ?? '?');
            $refuse = static fn (string $message): \RuntimeException => $layouts ? new LayoutConfigException($message) : new BlockConfigException($message);
            if (!is_array($declared)) {
                throw $refuse("Package {$name}: {$what} must be a list of class names.");
            }
            foreach ($declared as $class) {
                $class = is_string($class) ? ltrim($class, '\\') : '';
                if ($class === '' || !class_exists($class)) {
                    throw $refuse("Package {$name}: {$what} lists " . ($class !== '' ? "{$class}, which is not a class" : 'a value that is not a class name') . '.');
                }
                if ((new \ReflectionClass($class))->getAttributes($attribute, \ReflectionAttribute::IS_INSTANCEOF) === []) {
                    throw $refuse("Package {$name}: {$what} lists {$class}, which has no #[" . $attribute . '].');
                }
                $classes[] = $class;
            }
        }

        return $classes;
    }

    /** The bound BlocksConfig (register() binds it; a site may bind its own). */
    private static function config(Container $c): BlocksConfig
    {
        $config = $c->get(BlocksConfig::class);
        if (!$config instanceof BlocksConfig) {
            throw new \LogicException('The container binds ' . BlocksConfig::class . ' to ' . get_debug_type($config) . '; bind a ' . BlocksConfig::class . '.');
        }

        return $config;
    }

    /**
     * PageLayouts with the app's type registry (the one Folio binds, else the DataManager's), the
     * boot check of every type's "layouts" and the layout rules with the data package. In boot:
     * Folio binds its registry in its register(). PageLayouts is bound only when no provider bound
     * its own (0.94: a site's binding is kept), its registries resolved when it is first built.
     */
    private static function bootPageLayouts(Container $container): void
    {
        if (!$container->bound(TypeRegistry::class) && !$container->bound(DataManager::class)) {
            return;
        }
        if (!$container->bound(PageLayouts::class)) {
            $container->singleton(PageLayouts::class, static fn (Container $c): PageLayouts => new PageLayouts(
                $c->get(LayoutRegistry::class),
                self::typesOf($c),
                $c->get(BlockRegistry::class),
            ));
        }

        // A "layouts" option that cannot work stops the boot, with or without Folio: checked when the
        // boot manifest is built (and live while none is loaded), as the data package's own checks.
        $manifest = $container->get(Application::class)->bootManifest();
        $manifest->remember('blocks.type_layouts', static function () use ($container): bool {
            (new PageLayouts($container->get(LayoutRegistry::class), self::typesOf($container)))->assertTypes();

            return true;
        }, $manifest->isRecording() ? self::typesOf($container)->sources() : []);
        // An areas field of a type names a set some package registers, as a block's does.
        $manifest->remember('blocks.type_areas', static function () use ($container): bool {
            self::assertTypeAreas(self::typesOf($container), $container->get(AreaLayoutSets::class));

            return true;
        }, $manifest->isRecording() ? self::typesOf($container)->sources() : []);

        if (!$container->bound(DataManager::class)) {
            return;
        }
        $data = $container->get(DataManager::class);
        $types = static fn (): TypeRegistry => self::typesOf($container);
        // A page's areas are cleaned on every typed write (0.93), as its fields: rich text in their blocks.
        $data->cleaning()->systemKey(PageLayouts::AREAS, static function (mixed $value, TypeDefinition $def) use ($container, $data): mixed {
            return $container->get(PageLayouts::class)->forType($def->key) === null
                ? $value
                : self::blocksValue($container, $data->fieldKinds())->cleanAreaMap($value);
        });
        // `preflow migrate` and `db:check` print what needs a person: layouts without a class, pins without a page, pages on an unavailable layout.
        $container->get(MigrationRegistry::class)->warning('blocks.layouts', static fn (): array => (new LayoutReport(
            $container->get(PageLayouts::class),
            $container->get(LayoutRegistry::class),
            $types(),
            $data,
            sets: $container->get(AreaLayoutSets::class),
            blocks: $container->get(BlockRegistry::class),
        ))->lines(), sqlOnly: false);
        $data->recordRules()->add(static function (TypeDefinition $def) use ($container, $data): array {
            $layouts = $container->get(PageLayouts::class);
            if ($layouts->forType($def->key) === null) {
                return [];
            }

            return [PageLayouts::LAYOUT => [new LayoutChoice($layouts, $data, $def)]];
        });
        // What a page's areas hold (AreaContents), keyed by the area that is wrong ("_areas.<area>"), so
        // an editor sees it beside the area and an API client knows which one (a record check: a field
        // rule on "_areas" could only key the whole value). A caller giving "_areas" no rules skips it.
        $data->recordRules()->check(static function (DynamicRecord $record, array $given) use ($container, $data): array {
            $def = $record->getType();
            $layouts = $container->get(PageLayouts::class);
            if ($layouts->forType($def->key) === null || ($given[PageLayouts::AREAS] ?? null) === []) {
                return [];
            }
            $values = $record->toArray();
            $problems = AreaContents::forPage($layouts, $def, $container->get(BlockRegistry::class), $data)
                ->problems($values[PageLayouts::AREAS] ?? null, new ValidationContext(PageLayouts::AREAS, $values));
            $keyed = [];
            foreach ($problems as $area => $message) {
                $keyed[PageLayouts::AREAS . '.' . $area] = $message;
            }

            return self::errorsOf($container, $keyed);
        });
        $data->recordRules()->add(static function (TypeDefinition $def) use ($container, $data): array {
            $layouts = $container->get(PageLayouts::class);
            $type = $layouts->forType($def->key);

            return $type === null || $type->pinned === [] ? [] : [$type->slugField => [new PinnedSlug($layouts, $data, $def)]];
        }, drafts: true);
    }

    /** The app's type registry: the one Folio binds, else the DataManager's. */
    private static function typesOf(Container $c): TypeRegistry
    {
        return $c->bound(TypeRegistry::class) ? $c->get(TypeRegistry::class) : $c->get(DataManager::class)->getTypeRegistry();
    }

    /**
     * Refuses at boot an areas field of a type whose "layouts" names no registered set, naming the
     * type and the field (a type's fields as the registry reads them).
     *
     * @throws LayoutConfigException
     */
    public static function assertTypeAreas(TypeRegistry $types, AreaLayoutSets $sets): void
    {
        foreach ($types->keys() as $key) {
            $schema = $types->schema($key);
            foreach (is_array($schema['fields'] ?? null) ? $schema['fields'] : [] as $name => $field) {
                if (!is_array($field) || ($field['type'] ?? null) !== BlockLists::AREAS) {
                    continue;
                }
                try {
                    $sets->assertNamed($field['layouts'] ?? null);
                } catch (LayoutConfigException $e) {
                    throw new LayoutConfigException(sprintf('Type "%s": field "%s": %s', $key, $name, $e->getMessage()), 0, $e);
                }
            }
        }
    }

    /**
     * What an API controller calls (since 0.92): ContentDescription always (since 0.93 also added to
     * the one Description as its contributor), and with page layouts
     * LayoutResource (a page's layout and areas as JSON) and LayoutInput (a body merged under the
     * core rules). In boot: PageLayouts is bound in boot.
     */
    private static function bindApi(Container $container): void
    {
        $container->singleton(ContentDescription::class, static fn (Container $c): ContentDescription => new ContentDescription(
            $c->get(BlockRegistry::class),
            $c->get(LayoutRegistry::class),
            $c->get(AreaLayoutSets::class),
            $c->bound(DataManager::class) ? $c->get(DataManager::class)->fieldKinds() : new FieldKinds(),
            $c->bound(PageLayouts::class) ? $c->get(PageLayouts::class) : null,
            $c->bound(TypeRegistry::class) ? $c->get(TypeRegistry::class) : ($c->bound(DataManager::class) ? $c->get(DataManager::class)->getTypeRegistry() : null),
        ));
        // Its part of the one description of every type (0.93): blocks, layouts, area layout sets, type layouts.
        if ($container->bound(DescriptionContributors::class)) {
            $container->get(DescriptionContributors::class)->add(static fn (): ContentDescription => $container->get(ContentDescription::class));
        }
        if (!$container->bound(PageLayouts::class)) {
            return;
        }
        $container->singleton(LayoutResource::class, static fn (Container $c): LayoutResource => new LayoutResource(
            $c->get(PageLayouts::class),
            $c->get(BlockRegistry::class),
            $c->get(AreaLayoutSets::class),
            $c->bound(DataManager::class) ? self::blocksValue($c, $c->get(DataManager::class)->fieldKinds()) : null,
        ));
        // Its part of the one read output (0.93): a page's layout and areas beside its fields.
        if ($container->bound(RecordParts::class)) {
            $container->get(RecordParts::class)->output(static fn (): OutputPart => $container->get(LayoutResource::class));
        }
        $container->singleton(LayoutInput::class, static fn (Container $c): LayoutInput => new LayoutInput(
            $c->get(PageLayouts::class),
            $c->get(BlockRegistry::class),
            $c->bound(ValidatorFactory::class) ? $c->get(ValidatorFactory::class) : new ValidatorFactory(new RuleFactory()),
            $c->get(AreaLayoutSets::class),
        ));
        // Its part of the one write class (0.93): a page's layout and areas beside its fields.
        if ($container->bound(RecordParts::class)) {
            $container->get(RecordParts::class)->input(static fn (): InputPart => $container->get(LayoutInput::class));
        }
    }

    /**
     * The block value checks (BlockValueChecks), with the data package run on every validated
     * write of a typed record: the values inside its block lists and, for a type with layouts, its
     * placed areas. Folio adds its field types' block rules to the same instance.
     */
    private static function registerValueChecks(Container $container): void
    {
        if (!$container->bound(DataManager::class)) {
            return;
        }
        $data = $container->get(DataManager::class);
        $container->singleton(BlockValueChecks::class, static fn (Container $c): BlockValueChecks => new BlockValueChecks(
            $c->get(BlockRegistry::class),
            $data->fieldKinds(),
            $c->bound(ValidatorFactory::class) ? $c->get(ValidatorFactory::class) : new ValidatorFactory(new RuleFactory()),
            // Bound in boot (with a type registry): resolved when the checks are first built.
            $c->bound(PageLayouts::class) ? $c->get(PageLayouts::class) : null,
            $data->fieldLabels(...),
            $c->get(AreaLayoutSets::class),
        ));
        // Built on the first validated write, not at boot (the registry reflects over every block).
        $data->recordRules()->check(static fn (DynamicRecord $record, array $given): array => $container->get(BlockValueChecks::class)->errors($record->getType(), $record->toArray(), $given));

        // A blocks field's "min" and "max" (0.94; Folio's check before): the kind's check, so a record's
        // field and one nested in a block (BlockValueChecks reads the kinds) are counted, with or
        // without Folio. Folio's field type gives the same rule in its place (CoreFieldRules).
        $data->fieldKinds()->rules(BlockLists::BLOCKS, static fn (array $config): array => BlockCount::of($config));
        // The areas field (0.92): a layout from a named set and its areas, stored as JSON text on SQL.
        $data->fieldKinds()->column(BlockLists::AREAS, ColumnKind::Text);
        // What a blocks and an areas field's values mean (0.93): read, written and cleaned on every typed write.
        self::registerValues($container, $data->fieldKinds());
        // What the areas of every areas field hold (AreaContents::forField(), against the stored
        // value), keyed by the area that is wrong ("<field>.areas.<area>", nested under the block).
        $data->recordRules()->check(static function (DynamicRecord $record, array $given) use ($container, $data): array {
            $def = $record->getType();
            $values = $record->toArray();
            $layouts = $container->bound(PageLayouts::class) ? $container->get(PageLayouts::class) : null;
            $checks = new AreasFieldChecks($container->get(AreaLayoutSets::class), $container->get(BlockRegistry::class), $layouts);
            // Spares the stored read for a type that cannot hold an areas value.
            if (!$checks->appliesTo($def)) {
                return [];
            }
            $id = $values[$def->idField] ?? null;
            $stored = is_scalar($id) && (string) $id !== '' ? $data->findIn($def, (string) $id)?->toArray() : null;

            return self::errorsOf($container, $checks->problems($def, $values, $stored, $given));
        });
    }

    /**
     * The values of the blocks and areas field types (0.93) on $kinds: BlocksFieldValue (shape
     * "blocks") and AreasFieldValue (shape "areas_value"), each block field through its own value
     * of $kinds. The registry, LayoutResource and LayoutInput are asked on use.
     */
    private static function registerValues(Container $container, FieldKinds $kinds): void
    {
        $blocks = self::blocksValue($container, $kinds);
        $kinds->value(BlockLists::BLOCKS, $blocks);
        $kinds->value(BlockLists::AREAS, new AreasFieldValue(
            $blocks,
            static fn (): LayoutResource => $container->get(LayoutResource::class),
            static fn (): LayoutInput => $container->get(LayoutInput::class),
        ));
    }

    /** A BlocksFieldValue over $kinds, the registry asked on use. */
    private static function blocksValue(Container $container, FieldKinds $kinds): BlocksFieldValue
    {
        $value = $kinds->valueOf(new TypeFieldDefinition(PageLayouts::AREAS, BlockLists::BLOCKS));

        return $value instanceof BlocksFieldValue ? $value : new BlocksFieldValue(
            static fn (): BlockRegistry => $container->get(BlockRegistry::class),
            $kinds,
            static fn (): ValidatorFactory => $container->bound(ValidatorFactory::class) ? $container->get(ValidatorFactory::class) : new ValidatorFactory(new RuleFactory()),
        );
    }

    /**
     * What the core cleaner of blocks cleans with: the DataManager's StoredCleaning (its rich text
     * cleaner, a site's bound HtmlCleaner), or without a DataManager the core values over the
     * bound HtmlCleaner, else the core's.
     */
    private static function cleaningOf(Container $container): StoredCleaning
    {
        if ($container->bound(DataManager::class)) {
            return $container->get(DataManager::class)->cleaning();
        }
        $kinds = new FieldKinds();
        $default = null;
        CoreValues::register($kinds, static function () use ($container, &$default): HtmlCleaner {
            return $container->bound(HtmlCleaner::class) ? $container->get(HtmlCleaner::class) : ($default ??= SymfonyHtmlCleaner::standard());
        });
        self::registerValues($container, $kinds);

        return new StoredCleaning($kinds);
    }

    /**
     * Messages keyed by field through a validator, so they are in its language (the render's), as the
     * rules' are.
     *
     * @param array<string, \Preflow\Validation\Message> $problems
     * @return array<string, list<string>>
     */
    private static function errorsOf(Container $container, array $problems): array
    {
        if ($problems === []) {
            return [];
        }
        $rules = [];
        foreach ($problems as $key => $message) {
            $rules[$key] = [new MessageRule($message)];
        }
        $validators = $container->bound(ValidatorFactory::class) ? $container->get(ValidatorFactory::class) : new ValidatorFactory(new RuleFactory());

        return $validators->make($rules, [])->validate()->errors();
    }

    /** A warning for the site's log (its PSR logger, error_log() without one). */
    private static function note(Container $c, string $message): void
    {
        $logger = 'Psr\Log\LoggerInterface';
        if (interface_exists($logger) && $c->bound($logger)) {
            $c->get($logger)->warning($message);

            return;
        }
        error_log($message);
    }
}
