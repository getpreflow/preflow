<?php

declare(strict_types=1);

namespace Preflow\Data;

use Preflow\Core\Boot\BootManifest;
use Preflow\Data\Attributes\Entity;

/**
 * The model classes that are data types: those marked `#[Entity(type: …)]`, found under the
 * configured model paths (data.model_paths). A file is only autoloaded when a cheap text check
 * sees a `type:` in its Entity attribute, so ordinary models cost a file read and nothing more;
 * the result is remembered in the boot manifest, which makes a boot free of the scan.
 */
final class ModelClasses
{
    /** @var array<string, class-string>|null */
    private ?array $classes = null;

    /** @param list<string> $paths directories holding model classes */
    public function __construct(
        private readonly array $paths,
        private readonly ?BootManifest $manifest = null,
    ) {}

    /** @return array<string, class-string> type key => class */
    public function all(): array
    {
        if ($this->classes !== null) {
            return $this->classes;
        }
        if ($this->manifest === null) {
            return $this->classes = $this->scan();
        }

        return $this->classes = $this->manifest->remember('data.model_classes', fn (): array => $this->scan(), $this->paths);
    }

    /** @return class-string|null */
    public function classFor(string $key): ?string
    {
        return $this->all()[$key] ?? null;
    }

    /** @return array<string, class-string> */
    private function scan(): array
    {
        $classes = [];
        foreach ($this->files() as $file) {
            $source = (string) file_get_contents($file);
            // Cheap pre-check: `type:` inside an #[Entity(…)] attribute, before the class is loaded.
            if (preg_match('/#\[[^\]]*\bEntity\s*\([^\]]*\btype\s*:/s', $source) !== 1) {
                continue;
            }
            $class = self::className($source);
            // A file whose class the autoloader can't find is not a model of this app (or was only a match in a comment).
            if ($class === null || !class_exists($class)) {
                continue;
            }
            $attributes = (new \ReflectionClass($class))->getAttributes(Entity::class);
            $key = $attributes === [] ? null : $attributes[0]->newInstance()->type;
            if ($key === null) {
                continue;
            }
            if (isset($classes[$key])) {
                throw new \RuntimeException("Two model classes use the type \"{$key}\": {$classes[$key]} and {$class}. A type key belongs to one class.");
            }
            $classes[$key] = $class;
        }

        return $classes;
    }

    /** @return list<string> every .php file under the paths, in a stable order */
    private function files(): array
    {
        $files = [];
        foreach ($this->paths as $path) {
            if (!is_dir($path)) {
                continue;
            }
            $found = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS));
            foreach ($found as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }
        sort($files);

        return $files;
    }

    private static function className(string $source): ?string
    {
        if (preg_match('/^\s*(?:(?:final|abstract|readonly)\s+)*class\s+(\w+)/m', $source, $class) !== 1) {
            return null;
        }
        $prefix = preg_match('/^\s*namespace\s+([^\s;{]+)/m', $source, $namespace) === 1 ? $namespace[1] . '\\' : '';

        return $prefix . $class[1];
    }
}
