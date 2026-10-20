<?php

declare(strict_types=1);

namespace Preflow\Folio\Transfer;

use Preflow\Core\Application;
use Preflow\Data\DataManager;

/** A full local content export written before an import or pull changes anything. */
final class Backups
{
    public static function write(Application $app, string $prefix): string
    {
        $dir = $app->basePath('storage/backups');
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new TransferException("Cannot create {$dir}");
        }
        $path = $dir . '/' . $prefix . '-' . date('Ymd-His') . '.tar.gz';
        $out = fopen('compress.zlib://' . $path, 'wb');
        if ($out === false) {
            throw new TransferException("Cannot write {$path}");
        }
        try {
            (new Exporter(ContentSet::fromApp($app), $app->container()->get(DataManager::class)))->export($out);
            fclose($out);
        } catch (\Throwable $e) {
            fclose($out);
            @unlink($path); // a partial backup must not look like one
            throw $e;
        }

        return $path;
    }

    /** Opens an archive for reading, gzip-aware by file name. @return resource */
    public static function open(string $file)
    {
        if (!is_file($file)) {
            throw new TransferException("No such file: {$file}");
        }
        $in = fopen((str_ends_with($file, '.gz') ? 'compress.zlib://' : '') . $file, 'rb');
        if ($in === false) {
            throw new TransferException("Cannot read {$file}");
        }

        return $in;
    }
}
