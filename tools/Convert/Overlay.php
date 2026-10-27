<?php

declare(strict_types=1);

namespace Preflow\Tools\Convert;

/**
 * The site seen through a temporary directory whose config/data.php points the sqlite storage
 * at another file (--sqlite=<copy>): every other entry of the site and of its config/ is a
 * symlink, so the app boots as the site does but never opens the site's own SQLite database.
 * remove() unlinks only what create() made; it never deletes through a symlink.
 */
final class Overlay
{
    /** @param list<string> $made paths create() made, in the order to remove them */
    private function __construct(
        private readonly string $dir,
        private array $made,
    ) {}

    public static function create(string $site, string $sqlite, string $parent): self
    {
        $site = rtrim($site, '/');
        $dir = rtrim($parent, '/') . '/convert-typed-columns-' . bin2hex(random_bytes(4));
        if (!mkdir($dir . '/config', 0700, true)) {
            throw new \RuntimeException("Cannot create {$dir}.");
        }
        $overlay = new self($dir, []);
        try {
            foreach (self::entries($site) as $entry) {
                if ($entry !== 'config') {
                    $overlay->link("{$site}/{$entry}", "{$dir}/{$entry}");
                }
            }
            foreach (is_dir("{$site}/config") ? self::entries("{$site}/config") : [] as $entry) {
                if ($entry !== 'data.php') {
                    $overlay->link("{$site}/config/{$entry}", "{$dir}/config/{$entry}");
                }
            }
            $real = var_export("{$site}/config/data.php", true);
            $file = var_export($sqlite, true);
            file_put_contents("{$dir}/config/data.php", <<<PHP
                <?php

                // tools/convert-typed-columns.php --sqlite: the site's data config, its sqlite storage on a copy.
                \$config = require {$real};
                if (!isset(\$config['drivers']['sqlite'])) {
                    throw new \\RuntimeException('--sqlite: the site has no sqlite storage in config/data.php.');
                }
                \$config['drivers']['sqlite']['path'] = {$file};

                return \$config;

                PHP);
            $overlay->made[] = "{$dir}/config/data.php";
        } catch (\Throwable $e) {
            $overlay->remove();
            throw $e;
        }

        return $overlay;
    }

    public function basePath(): string
    {
        return $this->dir;
    }

    public function remove(): void
    {
        foreach (array_reverse($this->made) as $path) {
            if (is_link($path) || is_file($path)) {
                unlink($path);
            }
        }
        $this->made = [];
        @rmdir($this->dir . '/config');
        @rmdir($this->dir);
    }

    private function link(string $target, string $link): void
    {
        if (!symlink($target, $link)) {
            throw new \RuntimeException("Cannot link {$link}.");
        }
        $this->made[] = $link;
    }

    /** @return list<string> */
    private static function entries(string $dir): array
    {
        return array_values(array_diff(scandir($dir) ?: [], ['.', '..']));
    }
}
