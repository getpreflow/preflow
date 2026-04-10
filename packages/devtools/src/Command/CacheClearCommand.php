<?php

declare(strict_types=1);

namespace Preflow\DevTools\Command;

final class CacheClearCommand implements CommandInterface
{
    public function getName(): string { return 'cache:clear'; }
    public function getDescription(): string { return 'Clear all framework caches'; }

    public function execute(array $args): int
    {
        $cacheDir = getcwd() . '/storage/cache';

        if (!is_dir($cacheDir)) {
            echo "No cache directory found.\n";
            return 0;
        }

        $count = 0;
        $this->clearDir($cacheDir, $count);
        echo "Cleared {$count} cached file(s).\n";
        return 0;
    }

    private function clearDir(string $dir, int &$count): void
    {
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->clearDir($path, $count);
            } else {
                unlink($path);
                $count++;
            }
        }
    }
}
