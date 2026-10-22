<?php

declare(strict_types=1);

namespace Preflow\Folio\Extension;

use Preflow\Folio\Extension\Attributes\FolioExtension;

/**
 * Discovered extensions (project folders app/Folio/{Dir}/Extension.php and Composer packages
 * naming one in extra.folio.extension) and which of them, and of their parts,
 * config/folio/extensions.json enables.
 */
final class ExtensionRegistry
{
    /** Part attributes, matched by name (their classes may not be loaded or may not exist yet). */
    private const SCREEN = 'Preflow\\Folio\\Extension\\Attributes\\FolioScreen';
    private const TYPE = 'Preflow\\Folio\\Extension\\Attributes\\FolioType';

    /** @var array<string, ExtensionDefinition> by name, sorted */
    private array $extensions = [];

    /**
     * @param list<ExtensionDefinition>  $extensions
     * @param array<string, list<string>> $enabled disabled part ids by enabled extension name (validated)
     */
    public function __construct(
        array $extensions = [],
        private readonly array $enabled = [],
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
     */
    public static function discover(string $basePath, bool $debug, ?string $cacheFile = null): self
    {
        $file = new ExtensionsFile($basePath . '/config/folio/extensions.json');
        $projectDir = $basePath . '/app/Folio';
        $installed = $basePath . '/vendor/composer/installed.json';

        // Keyed by the project extension files, installed.json (Composer rewrites it whenever a
        // package changes) and the extensions file, so edits rebuild it (no manual clearing).
        $signature = !$debug && $cacheFile !== null ? self::signature($projectDir, $installed, $file) : null;
        if ($signature !== null && is_file($cacheFile)) {
            $cached = @unserialize((string) file_get_contents($cacheFile), ['allowed_classes' => [ExtensionDefinition::class]]);
            if (is_array($cached) && ($cached['signature'] ?? null) === $signature
                && is_array($cached['extensions'] ?? null) && is_array($cached['enabled'] ?? null)) {
                return new self(array_values(array_filter($cached['extensions'], fn ($d) => $d instanceof ExtensionDefinition)), $cached['enabled']);
            }
        }

        $definitions = [];
        foreach (self::projectClasses($projectDir) as $class => $path) {
            $definitions[] = self::define($class, $path);
        }
        foreach (self::packageClasses($installed) as $package => $class) {
            if (!class_exists($class)) {
                throw new ExtensionException("Package {$package} names extension class {$class} (extra.folio.extension), which does not exist.");
            }
            $definitions[] = self::define($class, (string) (new \ReflectionClass($class))->getFileName());
        }
        $registry = new self($definitions);
        $registry = new self($definitions, $registry->validate($file, $debug));

        if ($signature !== null) {
            if ($definitions === []) {
                @unlink($cacheFile);
            } else {
                @mkdir(dirname($cacheFile), 0755, true);
                @file_put_contents($cacheFile, serialize([
                    'signature' => $signature,
                    'extensions' => array_values($registry->extensions),
                    'enabled' => $registry->enabled,
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
        return $this->isEnabled($name)
            && $this->extensions[$name]->hasPart($partId)
            && !in_array($partId, $this->enabled[$name], true);
    }

    public function get(string $name): ExtensionDefinition
    {
        return $this->extensions[$name] ?? throw new ExtensionException("Unknown extension \"{$name}\".");
    }

    /**
     * Model JSON schemas of the enabled model parts, by extension name then key.
     *
     * @return list<array<string, mixed>>
     */
    public function enabledModels(): array
    {
        $schemas = [];
        foreach ($this->enabled() as $definition) {
            foreach ($definition->parts as $part) {
                if ($part['kind'] === 'model' && $this->partEnabled($definition->name, $part['id'])) {
                    $schemas[] = self::model($definition->folder . '/models/' . substr($part['id'], 6) . '.json');
                }
            }
        }

        return $schemas;
    }

    /** @return array<string, list<string>> disabled part ids by enabled name; unknown entries throw (debug) or are logged and dropped */
    private function validate(ExtensionsFile $file, bool $debug): array
    {
        $enabled = [];
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
        }

        return $enabled;
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

        $folder = dirname($path);
        $parts = [];
        foreach (glob($folder . '/models/*.json') ?: [] as $model) {
            $schema = self::model($model);
            $parts[] = ['id' => 'model:' . $schema['key'], 'kind' => 'model', 'label' => is_string($schema['label'] ?? null) ? $schema['label'] : $schema['key']];
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

    private static function signature(string $projectDir, string $installed, ExtensionsFile $file): string
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
