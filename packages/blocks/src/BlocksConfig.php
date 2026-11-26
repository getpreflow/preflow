<?php

declare(strict_types=1);

namespace Preflow\Blocks;

use Preflow\Core\Application;

/**
 * config/blocks.php (a missing file means the defaults):
 *
 *   'classes'    block classes to register besides app/Components and app/Blocks
 *   'layouts'    page layout classes (#[Layout]) to register besides app/Layouts
 *   'max_depth'  how many levels of blocks there are in all (a field's blocks are level 1, the blocks
 *                in their blocks fields level 2, …), default 4; the editor enforces it, rendering notes it
 *   'cache_path' where blocks.cache and layouts.cache is kept when debug is off: storage/cache, or a path
 *                (absolute, or relative to the project root)
 */
final readonly class BlocksConfig
{
    public const int DEFAULT_MAX_DEPTH = 4;

    /**
     * @param list<class-string> $classes
     * @param list<class-string> $layouts page layout classes (since 0.92)
     */
    public function __construct(
        public array $classes = [],
        public int $maxDepth = self::DEFAULT_MAX_DEPTH,
        public string $cacheFile = '',
        public array $layouts = [],
        public string $layoutsCacheFile = '',
    ) {}

    public static function fromApp(Application $app): self
    {
        $config = $app->configFile('blocks');
        $config = is_array($config) ? $config : [];
        $cachePath = $config['cache_path'] ?? null;

        $dir = self::cacheDir($app, is_string($cachePath) ? $cachePath : null);

        return new self(
            array_values(array_unique((array) ($config['classes'] ?? []))),
            (int) ($config['max_depth'] ?? self::DEFAULT_MAX_DEPTH),
            $dir . '/blocks.cache',
            array_values(array_unique((array) ($config['layouts'] ?? []))),
            $dir . '/layouts.cache',
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
