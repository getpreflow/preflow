<?php

declare(strict_types=1);

namespace Preflow\Folio\Transfer;

use Preflow\Data\DataManager;
use Preflow\Data\Query;

/**
 * Reads a content archive into a temporary directory, validating every entry
 * (manifest first, Folio tables only, content paths only), then diffs it
 * against the local content. Nothing local changes until apply().
 */
final class Importer
{
    /** Record ids an archive can carry (file names; no leading dot, so globbing sees them). */
    public const ID = '/^[A-Za-z0-9_-][A-Za-z0-9._-]*$/';

    public function __construct(
        private readonly ContentSet $set,
        private readonly DataManager $dm,
    ) {}

    /**
     * @param resource $stream
     * @param 'all'|'data'|'media' $only
     * @throws TransferException
     */
    public function read($stream, string $only = 'all'): ImportPlan
    {
        $dir = sys_get_temp_dir() . '/folio-import-' . bin2hex(random_bytes(6));
        mkdir($dir, 0700, true);

        try {
            $manifest = $this->extract($stream, $dir);

            // Only what both the export carried and this import asked for is compared:
            // a data-only export never removes media, and the reverse.
            $carried = in_array($manifest['only'] ?? 'all', ['all', 'data', 'media'], true) ? ($manifest['only'] ?? 'all') : 'all';
            $withData = $only !== 'media' && $carried !== 'media';
            $withFiles = $only !== 'data' && $carried !== 'data';

            return new ImportPlan(
                $dir,
                $withData ? $this->diffTables($dir, $manifest) : [],
                $withFiles ? $this->diffFiles($dir) : [],
            );
        } catch (\Throwable $e) {
            self::removeDir($dir);
            throw $e instanceof TransferException ? $e : new TransferException($e->getMessage(), 0, $e);
        }
    }

    public function apply(ImportPlan $plan): void
    {
        $tables = $this->set->tables();
        foreach ($plan->tables as $table => $diff) {
            $def = $tables[$table];
            $driver = $this->dm->driver($def->storage);
            foreach ([...$diff['added'], ...$diff['changed']] as $id) {
                $row = json_decode((string) file_get_contents("{$plan->dir}/data/{$table}/{$id}.json"), true, 512, JSON_THROW_ON_ERROR);
                $driver->save($table, $id, $row, $def->idField);
            }
            foreach ($diff['removed'] as $id) {
                $driver->delete($table, $id, $def->idField);
            }
        }

        $roots = $this->set->fileRoots();
        foreach ($plan->files as $root => $diff) {
            $target = $roots[$root];
            foreach ([...$diff['added'], ...$diff['changed']] as $rel) {
                $to = $target . '/' . $rel;
                if (!is_dir(dirname($to)) && !@mkdir(dirname($to), 0775, true) && !is_dir(dirname($to))) {
                    throw new TransferException('Could not create ' . dirname($to));
                }
                $tmp = $to . '.tmp-' . bin2hex(random_bytes(4));
                if (!@copy("{$plan->dir}/{$root}/{$rel}", $tmp) || !@rename($tmp, $to)) {
                    @unlink($tmp);
                    throw new TransferException("Could not write {$to}");
                }
            }
            foreach ($diff['removed'] as $rel) {
                @unlink($target . '/' . $rel);
            }
            self::pruneEmptyDirs($target);
        }
    }

    /** @return array{tables: array<string, int>} */
    private function extract($stream, string $dir): array
    {
        $manifest = null;
        $tables = $this->set->tables();
        foreach ((new TarReader($stream))->entries() as [$name, $size, $copyTo]) {
            if ($manifest === null) {
                if ($name !== 'folio-export.json') {
                    throw new TransferException('Not a Folio export: the manifest must come first');
                }
                $buf = fopen('php://temp', 'w+b');
                $copyTo($buf);
                rewind($buf);
                $manifest = json_decode((string) stream_get_contents($buf), true);
                if (!is_array($manifest) || ($manifest['format'] ?? null) !== 1 || !is_array($manifest['tables'] ?? null)) {
                    throw new TransferException('Unsupported export format');
                }
                foreach (array_keys($manifest['tables']) as $table) {
                    if (!isset($tables[$table])) {
                        throw new TransferException("{$table} is not transferred (user data or not a Folio type)");
                    }
                }
                continue;
            }

            if (preg_match('#^data/([A-Za-z0-9_]+)/([A-Za-z0-9_-][A-Za-z0-9._-]*)\.json$#', $name, $m) === 1) {
                if (!array_key_exists($m[1], $manifest['tables'])) {
                    throw new TransferException("Record for a table outside the manifest: {$name}");
                }
            } elseif (preg_match('#^(media|uploads|private-media)/.+$#', $name, $m) !== 1 || !isset($this->set->fileRoots()[$m[1]])) {
                throw new TransferException("Unexpected archive entry: {$name}");
            } else {
                self::assertServableFile($name);
            }

            $to = $dir . '/' . $name;
            if (!is_dir(dirname($to))) {
                mkdir(dirname($to), 0700, true);
            }
            $copyTo($to);
        }
        if ($manifest === null) {
            throw new TransferException('Empty archive');
        }

        return $manifest;
    }

    /** @return array<string, array{added: list<string>, changed: list<string>, removed: list<string>}> */
    private function diffTables(string $dir, array $manifest): array
    {
        $out = [];
        foreach (array_keys($manifest['tables']) as $table) {
            $def = $this->set->tables()[$table];
            $incoming = [];
            foreach (glob("{$dir}/data/{$table}/*.json") ?: [] as $file) {
                $row = json_decode((string) file_get_contents($file), true);
                if (!is_array($row)) {
                    throw new TransferException('Broken record in the export: ' . basename($file));
                }
                $incoming[basename($file, '.json')] = $row;
            }
            $local = [];
            foreach ($this->dm->driver($def->storage)->findMany($table, new Query())->items() as $row) {
                $local[(string) ($row[$def->idField] ?? '')] = $row;
            }
            unset($local['']);

            $diff = ['added' => [], 'changed' => [], 'removed' => []];
            foreach ($incoming as $id => $row) {
                $id = (string) $id;
                if (!array_key_exists($id, $local)) {
                    $diff['added'][] = $id;
                } elseif (self::canonical($row) !== self::canonical($local[$id])) {
                    $diff['changed'][] = $id;
                }
            }
            foreach (array_keys($local) as $id) {
                if (!array_key_exists((string) $id, $incoming)) {
                    $diff['removed'][] = (string) $id;
                }
            }
            foreach ($diff as &$ids) {
                sort($ids);
            }
            unset($ids);
            $out[$table] = $diff;
        }

        return $out;
    }

    /** @return array<string, array{added: list<string>, changed: list<string>, removed: list<string>}> */
    private function diffFiles(string $dir): array
    {
        $out = [];
        foreach ($this->set->fileRoots() as $root => $local) {
            $incoming = ContentSet::files("{$dir}/{$root}");
            $existing = ContentSet::files($local);
            $diff = ['added' => [], 'changed' => [], 'removed' => []];
            foreach ($incoming as $rel) {
                if (!in_array($rel, $existing, true)) {
                    $diff['added'][] = $rel;
                } elseif (filesize("{$dir}/{$root}/{$rel}") !== filesize("{$local}/{$rel}") || sha1_file("{$dir}/{$root}/{$rel}") !== sha1_file("{$local}/{$rel}")) {
                    $diff['changed'][] = $rel;
                }
            }
            $diff['removed'] = array_values(array_diff($existing, $incoming));
            $out[$root] = $diff;
        }

        return $out;
    }

    /** Files that land in the web root: no dotfiles (.htaccess, .user.ini) and nothing a server would execute. */
    private static function assertServableFile(string $name): void
    {
        foreach (array_slice(explode('/', $name), 1) as $segment) {
            if (str_starts_with($segment, '.')) {
                throw new TransferException("Refusing hidden or server config file: {$name}");
            }
        }
        $base = basename($name);
        // Any suffix counts (Apache's AddHandler matches x.php.jpg), and trailing dots or
        // spaces are stripped by some servers; per-directory PHP and IIS config by name.
        if (preg_match('/\\.(php\\d?|phtml|phar|pht|phps|shtml|cgi|pl|py|sh|asp|aspx|jsp)(\\.|$)/i', $base) === 1
            || preg_match('/[.\\s]$/', $base) === 1
            || in_array(strtolower($base), ['php.ini', 'web.config'], true)) {
            throw new TransferException("Refusing executable or server config file: {$name}");
        }
    }

    private static function canonical(mixed $value): string
    {
        $sort = static function (mixed $v) use (&$sort): mixed {
            if (!is_array($v)) {
                return $v;
            }
            if (!array_is_list($v)) {
                ksort($v);
            }

            return array_map($sort, $v);
        };

        return json_encode($sort($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private static function pruneEmptyDirs(string $root): void
    {
        if (!is_dir($root)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            if ($f->isDir() && !$f->isLink() && count(scandir($f->getPathname())) === 2) {
                @rmdir($f->getPathname());
            }
        }
    }

    public static function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() && !$f->isLink() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($dir);
    }
}
