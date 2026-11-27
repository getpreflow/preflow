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
use Preflow\Blocks\Layouts\Layout;
use Preflow\Blocks\Layouts\LayoutConfigException;
use Preflow\Blocks\Layouts\LayoutInput;
use Preflow\Blocks\Layouts\LayoutReport;
use Preflow\Blocks\Layouts\LayoutRegistry;
use Preflow\Blocks\Layouts\LayoutResource;
use Preflow\Blocks\Layouts\PageLayouts;
use Preflow\Blocks\Layouts\Rules\AreaContents;
use Preflow\Blocks\Layouts\Rules\LayoutChoice;
use Preflow\Blocks\Layouts\Rules\PinnedSlug;
use Preflow\Core\Container\ServiceProvider;
use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;
use Preflow\Data\FieldKinds;
use Preflow\Data\Locales;
use Preflow\Data\Migration\MigrationRegistry;
use Preflow\Data\TypeDefinition;
use Preflow\Data\TypeFieldDefinition;
use Preflow\Data\TypeRegistry;
use Preflow\Data\Schema\ColumnKind;
use Preflow\Blocks\Layouts\AreasFieldChecks;
use Preflow\Blocks\Layouts\Rules\MessageRule;
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
 * and, with a type registry, PageLayouts for the types with "layouts" — their option checked at
 * boot (kept in the boot manifest), and with a DataManager the layout rules on every validated
 * save of their records (RecordRules): the layout choice, a pinned page's slug (drafts too) and
 * what the areas hold.
 *
 * For APIs (since 0.92): ContentDescription (blocks, layouts, area layout sets and type layouts as
 * data for a schema builder) and, with page layouts, LayoutResource and LayoutInput — a page's
 * layout and areas read and written as JSON under the same core rules as Folio's form.
 *
 * Block values (since 0.92): with a DataManager, BlockValueChecks runs on every validated write of
 * a typed record (a RecordRules check), so a seed, an import or a REST save is refused for a block
 * value Folio's form refuses, with the same keys and messages.
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
            $config = $c->get(BlocksConfig::class);
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
            $config = $c->get(BlocksConfig::class);

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
        if (!$container->bound(BlockTextCleaner::class)) {
            $container->singleton(BlockTextCleaner::class, static fn (): BlockTextCleaner => new DefaultBlockTextCleaner());
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
     * @throws \LogicException when a RecordPreviewMarks gives an attribute name that is not data-…
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
            $html .= ' ' . $name . '="' . htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
        }

        return $html;
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

    /**
     * PageLayouts with the app's type registry (the one Folio binds, else the DataManager's), the
     * boot check of every type's "layouts" and the layout rules with the data package. In boot:
     * Folio binds its registry in its register().
     */
    private static function bootPageLayouts(Container $container): void
    {
        $data = $container->bound(DataManager::class) ? $container->get(DataManager::class) : null;
        $types = $container->bound(TypeRegistry::class) ? $container->get(TypeRegistry::class) : $data?->getTypeRegistry();
        if ($types === null) {
            return;
        }
        $container->singleton(PageLayouts::class, static fn (Container $c): PageLayouts => new PageLayouts(
            $c->get(LayoutRegistry::class),
            $types,
            $c->get(BlockRegistry::class),
        ));

        // A "layouts" option that cannot work stops the boot, with or without Folio: checked when the
        // boot manifest is built (and live while none is loaded), as the data package's own checks.
        $manifest = $container->get(Application::class)->bootManifest();
        $manifest->remember('blocks.type_layouts', static function () use ($container, $types): bool {
            (new PageLayouts($container->get(LayoutRegistry::class), $types))->assertTypes();

            return true;
        }, $manifest->isRecording() ? $types->sources() : []);
        // An areas field of a type names a set some package registers, as a block's does.
        $manifest->remember('blocks.type_areas', static function () use ($container, $types): bool {
            self::assertTypeAreas($types, $container->get(AreaLayoutSets::class));

            return true;
        }, $manifest->isRecording() ? $types->sources() : []);

        if ($data === null) {
            return;
        }
        // `preflow migrate` and `db:check` print what needs a person: layouts without a class, pins without a page, pages on an unavailable layout.
        $container->get(MigrationRegistry::class)->warning('blocks.layouts', static fn (): array => (new LayoutReport(
            $container->get(PageLayouts::class),
            $container->get(LayoutRegistry::class),
            $types,
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
     * What an API controller calls (since 0.92): ContentDescription always, and with page layouts
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
        if (!$container->bound(PageLayouts::class)) {
            return;
        }
        $container->singleton(LayoutResource::class, static fn (Container $c): LayoutResource => new LayoutResource(
            $c->get(PageLayouts::class),
            $c->get(BlockRegistry::class),
            $c->get(AreaLayoutSets::class),
        ));
        $container->singleton(LayoutInput::class, static fn (Container $c): LayoutInput => new LayoutInput(
            $c->get(PageLayouts::class),
            $c->get(BlockRegistry::class),
            $c->bound(ValidatorFactory::class) ? $c->get(ValidatorFactory::class) : new ValidatorFactory(new RuleFactory()),
            $c->get(AreaLayoutSets::class),
        ));
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

        // The areas field (0.92): a layout from a named set and its areas, stored as JSON text on SQL.
        $data->fieldKinds()->column(BlockLists::AREAS, ColumnKind::Text);
        // What the areas of every areas field hold (AreaContents::forField(), against the stored
        // value), keyed by the area that is wrong ("<field>.areas.<area>", nested under the block).
        $data->recordRules()->check(static function (DynamicRecord $record, array $given) use ($container, $data): array {
            $def = $record->getType();
            $values = $record->toArray();
            $layouts = $container->bound(PageLayouts::class) ? $container->get(PageLayouts::class) : null;
            if (!self::holdsAreas($def, $container->get(BlockRegistry::class), $layouts)) {
                return [];
            }
            $id = $values[$def->idField] ?? null;
            $stored = is_scalar($id) && (string) $id !== '' ? $data->findIn($def, (string) $id)?->toArray() : null;

            $checks = new AreasFieldChecks($container->get(AreaLayoutSets::class), $container->get(BlockRegistry::class), $layouts);

            return self::errorsOf($container, $checks->problems($def, $values, $stored, $given));
        });
    }

    /**
     * Whether a record of the type may hold an areas value: a field of its own, a block list (a block
     * with an areas field may be in it), or page areas. Spares the stored read for every other type.
     */
    private static function holdsAreas(TypeDefinition $def, BlockRegistry $blocks, ?PageLayouts $layouts): bool
    {
        $lists = false;
        foreach ($def->fields as $field) {
            if ($field->type === BlockLists::AREAS) {
                return true;
            }
            $lists = $lists || BlockLists::holds($field);
        }
        if (!$lists && $layouts?->forType($def->key) === null) {
            return false;
        }
        foreach ($blocks->all() as $block) {
            foreach ($block->fields as $field) {
                if ($field->type === BlockLists::AREAS) {
                    return true;
                }
            }
        }

        return false;
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
