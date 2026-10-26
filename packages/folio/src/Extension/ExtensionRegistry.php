<?php

declare(strict_types=1);

namespace Preflow\Folio\Extension;

use Preflow\Folio\Extension\Attributes\FolioExtension;

/**
 * Discovered extensions (project folders app/Folio/{Dir}/Extension.php, Composer packages
 * naming one in extra.folio.extension, and Folio's built-in ones: BUILT_IN) and which of them, and of their parts,
 * config/folio/extensions.json enables. A part is on with its extension unless listed under
 * "disabled_parts"; a model whose JSON says "opt_in": true (and the extension's type part
 * "type:{key}" for it) is off until listed under "enabled_parts", so a new version of an
 * extension can ship a model existing sites don't get unasked.
 */
final class ExtensionRegistry
{
    /** Part attributes, matched by name (their classes may not be loaded or may not exist yet). */
    private const SCREEN = 'Preflow\\Folio\\Extension\\Attributes\\FolioScreen';
    private const TYPE = 'Preflow\\Folio\\Extension\\Attributes\\FolioType';

    /**
     * Extensions Folio carries itself, each there when the package it adapts is installed
     * (Composer lists it and its class exists), as Folio carries media's library: the members
     * admin with preflow/members. A project's or a package's own extension of the same name
     * takes its place.
     *
     * @var array<class-string, array{package: string, class: string}> extension class => what switches it on
     */
    public const array BUILT_IN = [
        \Preflow\Folio\Members\Extension::class => ['package' => 'preflow/members', 'class' => 'Preflow\\Members\\Members'],
    ];

    /** Extension classes packages declared before Folio carried them; an installed.json of then still names them. */
    private const array MOVED = ['Preflow\\Members\\Extension'];

    /** @var array<string, ExtensionDefinition> by name, sorted */
    private array $extensions = [];

    /**
     * @param list<ExtensionDefinition>  $extensions
     * @param array<string, list<string>> $enabled disabled part ids by enabled extension name (validated)
     * @param array<string, list<string>> $optedIn opt-in part ids switched on, by enabled extension name (validated)
     */
    public function __construct(
        array $extensions = [],
        private readonly array $enabled = [],
        private readonly array $optedIn = [],
    ) {
        foreach ($extensions as $definition) {
            if (isset($this->extensions[$definition->name])) {
                throw new ExtensionException(sprintf(
                    'Extension name "%s" is declared by both %s and %s.',
                    $definition->name,
                    $this->extensions[$definition->name]->class,
                    $definition->class,
                ));
            }
            $this->extensions[$definition->name] = $definition;
        }
        ksort($this->extensions);
    }

    /**
     * @param bool          $debug     file errors (unknown extension or part) throw; otherwise they are logged and ignored
     * @param string|null   $cacheFile used when !$debug, written only when there is an extension
     * @param array<class-string, array{package: string, class: string}>|null $builtIn BUILT_IN when null (tests pass their own)
     */
    public static function discover(string $basePath, bool $debug, ?string $cacheFile = null, ?array $builtIn = null): self
    {
        $file = new ExtensionsFile($basePath . '/config/folio/extensions.json');
        $projectDir = $basePath . '/app/Folio';
        $installed = $basePath . '/vendor/composer/installed.json';
        $builtIn = self::builtIn($builtIn ?? self::BUILT_IN, $installed);

        // Keyed by the project extension files, installed.json (Composer rewrites it whenever a
        // package changes) and the extensions file, so edits rebuild it (no manual clearing).
        $signature = !$debug && $cacheFile !== null ? self::signature($projectDir, $installed, $file, $builtIn) : null;
        if ($signature !== null && is_file($cacheFile)) {
            $cached = @unserialize((string) file_get_contents($cacheFile), ['allowed_classes' => [ExtensionDefinition::class]]);
            if (is_array($cached) && ($cached['signature'] ?? null) === $signature
                && is_array($cached['extensions'] ?? null) && is_array($cached['enabled'] ?? null) && is_array($cached['opted'] ?? null)) {
                return new self(array_values(array_filter($cached['extensions'], fn ($d) => $d instanceof ExtensionDefinition)), $cached['enabled'], $cached['opted']);
            }
        }

        $definitions = [];
        foreach (self::projectClasses($projectDir) as $class => $path) {
            $definitions[] = self::define($class, $path);
        }
        foreach (self::packageClasses($installed) as $package => $class) {
            if (in_array($class, self::MOVED, true)) {
                continue; // Folio carries it now (BUILT_IN)
            }
            if (!class_exists($class)) {
                throw new ExtensionException("Package {$package} names extension class {$class} (extra.folio.extension), which does not exist.");
            }
            $definitions[] = self::define($class, (string) (new \ReflectionClass($class))->getFileName());
        }
        $taken = array_column($definitions, 'name');
        foreach ($builtIn as $class) {
            $definition = self::define($class, (string) (new \ReflectionClass($class))->getFileName());
            if (!in_array($definition->name, $taken, true)) {
                $definitions[] = $definition;
            }
        }
        $registry = new self($definitions);
        [$disabled, $opted] = $registry->validate($file, $debug);
        $registry = new self($definitions, $disabled, $opted);

        if ($signature !== null) {
            if ($definitions === []) {
                @unlink($cacheFile);
            } else {
                @mkdir(dirname($cacheFile), 0755, true);
                @file_put_contents($cacheFile, serialize([
                    'signature' => $signature,
                    'extensions' => array_values($registry->extensions),
                    'enabled' => $registry->enabled,
                    'opted' => $registry->optedIn,
                ]), LOCK_EX);
            }
        }

        return $registry;
    }

    /** @return list<ExtensionDefinition> */
    public function all(): array
    {
        return array_values($this->extensions);
    }

    /** @return list<ExtensionDefinition> */
    public function enabled(): array
    {
        return array_values(array_filter($this->extensions, fn (ExtensionDefinition $d) => isset($this->enabled[$d->name])));
    }

    public function isEnabled(string $name): bool
    {
        return isset($this->enabled[$name], $this->extensions[$name]);
    }

    public function partEnabled(string $name, string $partId): bool
    {
        if (!$this->isEnabled($name) || !$this->extensions[$name]->hasPart($partId) || in_array($partId, $this->enabled[$name], true)) {
            return false;
        }
        $gate = self::gate($this->extensions[$name], $partId);

        return $gate === null || in_array($gate, $this->optedIn[$name] ?? [], true);
    }

    /**
     * Whether the site has to switch the part on itself ("enabled_parts"): an opt-in model, or
     * the type part of one. Such a part, not switched on, is left out entirely (no type, no
     * permissions), as if the extension did not ship it.
     */
    public function optIn(string $name, string $partId): bool
    {
        return isset($this->extensions[$name]) && self::gate($this->extensions[$name], $partId) !== null;
    }

    /** The opt-in part id that switches $partId on: itself for an opt-in model, model:{key} for its type part; null for a normal part. */
    private static function gate(ExtensionDefinition $definition, string $partId): ?string
    {
        $model = str_starts_with($partId, 'type:') ? 'model:' . substr($partId, 5) : $partId;
        foreach ($definition->parts as $part) {
            if ($part['id'] === $model && ($part['opt_in'] ?? false) === true) {
                return $model;
            }
        }

        return null;
    }

    public function get(string $name): ExtensionDefinition
    {
        return $this->extensions[$name] ?? throw new ExtensionException("Unknown extension \"{$name}\".");
    }

    /**
     * Model JSON schemas of the enabled model parts, by extension name then key; with $extension,
     * that extension's only.
     *
     * @return list<array<string, mixed>>
     */
    public function enabledModels(?string $extension = null): array
    {
        $schemas = [];
        foreach ($this->enabled() as $definition) {
            if ($extension !== null && $definition->name !== $extension) {
                continue;
            }
            foreach ($definition->parts as $part) {
                if ($part['kind'] === 'model' && $this->partEnabled($definition->name, $part['id'])) {
                    $schema = self::model($definition->folder . '/models/' . substr($part['id'], 6) . '.json');
                    unset($schema['opt_in']); // a part setting, not the type's
                    $schemas[] = $schema;
                }
            }
        }

        return $schemas;
    }

    /**
     * Disabled part ids and switched-on opt-in part ids, by enabled name; unknown entries (and
     * "enabled_parts" naming a part that isn't an opt-in model) throw (debug) or are logged and dropped.
     *
     * @return array{0: array<string, list<string>>, 1: array<string, list<string>>}
     */
    private function validate(ExtensionsFile $file, bool $debug): array
    {
        $enabled = [];
        $opted = [];
        foreach ($file->read() as $name => $entry) {
            if (!isset($this->extensions[$name])) {
                self::fileError("{$file->path()} enables unknown extension \"{$name}\"", $debug);
                continue;
            }
            $enabled[$name] = [];
            foreach ($entry['disabled_parts'] as $partId) {
                if (!$this->extensions[$name]->hasPart($partId)) {
                    self::fileError("{$file->path()} disables unknown part \"{$partId}\" of extension \"{$name}\"", $debug);
                    continue;
                }
                $enabled[$name][] = $partId;
            }
            foreach ($entry['enabled_parts'] ?? [] as $partId) {
                if (self::gate($this->extensions[$name], $partId) !== $partId) {
                    self::fileError("{$file->path()} lists \"{$partId}\" of extension \"{$name}\" under enabled_parts, which only takes opt-in models", $debug);
                    continue;
                }
                $opted[$name][] = $partId;
            }
        }

        return [$enabled, $opted];
    }

    private static function fileError(string $message, bool $debug): void
    {
        if ($debug) {
            throw new ExtensionException($message . '.');
        }
        error_log("preflow/folio: {$message}; ignored");
    }

    /** @param class-string $class */
    private static function define(string $class, string $path): ExtensionDefinition
    {
        $reflection = new \ReflectionClass($class);
        $attribute = $reflection->getAttributes(FolioExtension::class)[0] ?? null;
        if ($attribute === null) {
            throw new ExtensionException("{$class} ({$path}) has no #[FolioExtension] attribute.");
        }
        if (!$reflection->isSubclassOf(Extension::class)) {
            throw new ExtensionException("{$class} must extend " . Extension::class . '.');
        }
        /** @var FolioExtension $meta */
        $meta = $attribute->newInstance();
        if (preg_match('/^[a-z][a-z0-9_]*$/', $meta->name) !== 1) {
            throw new ExtensionException("Extension name \"{$meta->name}\" ({$class}) must match /^[a-z][a-z0-9_]*$/.");
        }

        if ($meta->translations !== null && preg_match('/^[a-z0-9_-]+$/i', $meta->translations) !== 1) {
            throw new ExtensionException("Extension \"{$meta->name}\" ({$class}): translations \"{$meta->translations}\" is no translation group name (letters, digits, _ and -).");
        }
        $folder = dirname($path);
        $parts = [];
        foreach (glob($folder . '/models/*.json') ?: [] as $model) {
            $schema = self::model($model);
            $parts[] = ['id' => 'model:' . $schema['key'], 'kind' => 'model', 'label' => (is_string($schema['label'] ?? null) || is_array($schema['label'] ?? null) ? \Preflow\I18n\Labels::pick($schema['label'], 'en', 'en') : null) ?? $schema['key']]
                + (($schema['opt_in'] ?? false) === true ? ['opt_in' => true] : []);
        }
        foreach (self::partClasses($folder, $reflection->getNamespaceName(), $path) as $partClass) {
            $partReflection = new \ReflectionClass($partClass);
            foreach ([self::SCREEN => 'screen', self::TYPE => 'type'] as $attributeClass => $kind) {
                $partAttribute = $partReflection->getAttributes($attributeClass)[0] ?? null;
                if ($partAttribute === null) {
                    continue;
                }
                $args = $partAttribute->getArguments();
                $key = $args[0] ?? $args[$kind === 'screen' ? 'path' : 'key'] ?? null;
                if (!is_string($key) || $key === '') {
                    throw new ExtensionException(sprintf('#[%s] on %s needs a %s.', substr($attributeClass, strrpos($attributeClass, '\\') + 1), $partClass, $kind === 'screen' ? 'path' : 'type key'));
                }
                $label = $kind === 'screen' && is_string($args['label'] ?? $args[1] ?? null) ? ($args['label'] ?? $args[1]) : $key;
                $parts[] = ['id' => $kind . ':' . $key, 'kind' => $kind, 'class' => $partClass, 'label' => $label];
            }
        }
        // Overriding settings() declares the part; nothing is instantiated here, so a disabled
        // extension's constructor never runs (FolioServiceProvider calls settings() when enabled).
        if ($reflection->getMethod('settings')->getDeclaringClass()->getName() !== Extension::class) {
            $parts[] = ['id' => 'settings', 'kind' => 'settings', 'label' => 'Settings'];
        }

        $ids = array_column($parts, 'id');
        foreach (array_count_values($ids) as $id => $count) {
            if ($count > 1) {
                throw new ExtensionException("Extension \"{$meta->name}\" declares part \"{$id}\" more than once.");
            }
        }

        return new ExtensionDefinition(
            name: $meta->name,
            label: $meta->label,
            description: $meta->description,
            icon: $meta->icon,
            version: $meta->version,
            class: $class,
            folder: $folder,
            parts: $parts,
            translations: $meta->translations,
        );
    }

    /** @return array<string, mixed> a model JSON schema whose key is its file name */
    private static function model(string $path): array
    {
        try {
            $schema = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ExtensionException("{$path} is not valid JSON: {$e->getMessage()}", 0, $e);
        }
        $key = basename($path, '.json');
        if (!is_array($schema) || ($schema['key'] ?? $key) !== $key) {
            throw new ExtensionException("{$path} must be a model schema with \"key\": \"{$key}\" (its file name).");
        }

        return ['key' => $key] + $schema;
    }

    /**
     * Classes of the PHP files in an extension folder (namespace + relative path), Extension.php excluded.
     *
     * @return list<class-string>
     */
    private static function partClasses(string $folder, string $namespace, string $extensionFile): array
    {
        $classes = [];
        foreach (self::files($folder, 'php') as $file) {
            if ($file === $extensionFile) {
                continue;
            }
            $class = $namespace . '\\' . str_replace('/', '\\', substr($file, strlen($folder) + 1, -4));
            if (!class_exists($class) && !interface_exists($class) && !trait_exists($class) && !enum_exists($class)) {
                throw new ExtensionException("{$file} does not declare {$class} (extension classes are named by their namespace and path).");
            }
            if (class_exists($class)) {
                $classes[] = $class;
            }
        }

        return $classes;
    }

    /** @return array<class-string, string> App\Folio\{Dir}\Extension => its file, App\Folio\Overrides skipped ($load: autoload and check the class) */
    private static function projectClasses(string $dir, bool $load = true): array
    {
        $classes = [];
        foreach (glob($dir . '/*/Extension.php') ?: [] as $path) {
            $folder = basename(dirname($path));
            if ($folder === 'Overrides') {
                continue;
            }
            $class = 'App\\Folio\\' . $folder . '\\Extension';
            if ($load && !class_exists($class)) {
                throw new ExtensionException("{$path} must declare class {$class}.");
            }
            $classes[$class] = $path;
        }

        return $classes;
    }

    /**
     * The built-in extensions whose package Composer lists (installed.json) and whose class exists.
     *
     * @param array<class-string, array{package: string, class: string}> $builtIn
     * @return list<class-string>
     */
    private static function builtIn(array $builtIn, string $installed): array
    {
        $json = $builtIn !== [] && is_file($installed) ? (string) file_get_contents($installed) : '';
        $names = [];
        foreach ($builtIn as $requires) {
            // A quick look first (most sites have no such package); json_encode() writes "/" as "\/".
            if (str_contains($json, '"' . $requires['package'] . '"') || str_contains($json, '"' . str_replace('/', '\\/', $requires['package']) . '"')) {
                $data = json_decode($json, true);
                $packages = is_array($data) ? ($data['packages'] ?? $data) : [];
                $names = array_column(is_array($packages) ? array_filter($packages, 'is_array') : [], 'name');
                break;
            }
        }
        $classes = [];
        foreach ($builtIn as $class => $requires) {
            if (in_array($requires['package'], $names, true) && class_exists($requires['class'])) {
                $classes[] = $class;
            }
        }

        return $classes;
    }

    /** @return array<string, string> package name => extension class, from extra.folio.extension */
    private static function packageClasses(string $installed): array
    {
        $json = is_file($installed) ? (string) file_get_contents($installed) : '';
        if (!str_contains($json, '"folio"')) {
            return []; // most sites: no package names a Folio extension, skip decoding
        }
        $data = json_decode($json, true);
        $packages = is_array($data) ? ($data['packages'] ?? $data) : [];

        $classes = [];
        foreach (is_array($packages) ? $packages : [] as $package) {
            $class = is_array($package) ? ($package['extra']['folio']['extension'] ?? null) : null;
            if ($class === null) {
                continue;
            }
            if (!is_string($class) || $class === '') {
                throw new ExtensionException(sprintf('Package %s: extra.folio.extension must be a class name.', $package['name'] ?? '?'));
            }
            $classes[(string) ($package['name'] ?? $class)] = ltrim($class, '\\');
        }

        return $classes;
    }

    /** @param list<class-string> $builtIn the built-in extensions present */
    private static function signature(string $projectDir, string $installed, ExtensionsFile $file, array $builtIn): string
    {
        clearstatcache();
        $parts = [];
        foreach (self::projectClasses($projectDir, load: false) as $path) {
            $folder = dirname($path);
            foreach ([...self::files($folder, 'php'), ...(glob($folder . '/models/*.json') ?: [])] as $f) {
                $parts[] = $f . '@' . filemtime($f);
            }
        }
        $parts[] = $installed . '@' . (is_file($installed) ? filemtime($installed) : 0);
        $parts[] = 'built-in:' . implode(',', $builtIn);
        $parts[] = $file->path() . '@' . ($file->exists() ? filemtime($file->path()) . ':' . hash_file('xxh3', $file->path()) : 0);

        return hash('xxh3', implode('|', $parts));
    }

    /** @return list<string> files with the extension under $dir, sorted */
    private static function files(string $dir, string $extension): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->getExtension() === $extension) {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        return $files;
    }
}
