<?php

declare(strict_types=1);

namespace Preflow\Blocks;

use Preflow\Core\Application;

/**
 * config/blocks.php (a missing file means the defaults):
 *
 *   'classes'    block classes to register besides app/Components and app/Blocks
 *   'max_depth'  how many levels of blocks there are in all (a field's blocks are level 1, the blocks
 *                in their blocks fields level 2, …), default 4; the editor enforces it, rendering notes it
 *   'cache_path' where blocks.cache is kept when debug is off: storage/cache, or a path
 *                (absolute, or relative to the project root)
 */
final readonly class BlocksConfig
{
    public const int DEFAULT_MAX_DEPTH = 4;

    /** @param list<class-string> $classes */
    public function __construct(
        public array $classes = [],
        public int $maxDepth = self::DEFAULT_MAX_DEPTH,
        public string $cacheFile = '',
    ) {}

    public static function fromApp(Application $app): self
    {
        $config = $app->configFile('blocks');
        $config = is_array($config) ? $config : [];
        $cachePath = $config['cache_path'] ?? null;

        return new self(
            array_values(array_unique((array) ($config['classes'] ?? []))),
            (int) ($config['max_depth'] ?? self::DEFAULT_MAX_DEPTH),
            self::cacheDir($app, is_string($cachePath) ? $cachePath : null) . '/blocks.cache',
        );
    }

    private static function cacheDir(Application $app, ?string $path): string
    {
        $path = rtrim(trim((string) $path), '/\\');
        if ($path === '') {
            return $app->basePath('storage/cache');
        }
        $absolute = str_starts_with($path, '/') || preg_match('~^[A-Za-z]:~', $path) === 1;

        return $absolute ? $path : $app->basePath($path);
    }
}
