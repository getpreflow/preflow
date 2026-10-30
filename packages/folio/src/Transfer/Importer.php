<?php

declare(strict_types=1);

namespace Preflow\Folio\Transfer;

use Preflow\Data\DataManager;
use Preflow\Data\Driver\PdoDriver;
use Preflow\Data\Query;
use Preflow\Data\StorageDriver;

/**
 * Reads a content archive into a temporary directory, validating every entry
 * (manifest first, Folio tables only, content paths only), then diffs it
 * against the local content. Nothing local changes until apply().
 */
final class Importer
{
    /** Record ids an archive can carry (file names; no leading dot, so globbing sees them; ":" for "{locale}:{key}" of the translation overrides). */
    public const ID = '/^[A-Za-z0-9_-][A-Za-z0-9._:-]*$/';

    /** TransferException code: the import failed while writing records and changed none. */
    public const RECORDS_UNCHANGED = 1;

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
            [$manifest, $notices] = $this->extract($stream, $dir);

            // Only what both the export carried and this import asked for is compared:
            // a data-only export never removes media, and the reverse.
            $carried = in_array($manifest['only'] ?? 'all', ['all', 'data', 'media'], true) ? ($manifest['only'] ?? 'all') : 'all';
            $withData = $only !== 'media' && $carried !== 'media';
            $withFiles = $only !== 'data' && $carried !== 'data';

            $tables = $withData ? $this->diffTables($dir, $manifest, $notices) : []; // adds a notice per row it leaves out

            return new ImportPlan(
                $dir,
                $tables,
                $withFiles ? $this->diffFiles($dir, $manifest) : [],
                $notices,
            );
        } catch (\Throwable $e) {
            self::removeDir($dir);
            throw $e instanceof TransferException ? $e : new TransferException($e->getMessage(), 0, $e);
        }
    }

    /**
     * Records first, then files. The records of all tables are replaced together: each table's
     * removed and replaced rows are deleted before the incoming ones are written, so a unique
     * value (a slug) that moves to another id never collides. SQL storages run it in one
     * transaction per connection; other storages (JSON) get their earlier rows written back.
     * A failure there (reading the archive rows included) throws a TransferException with code
     * RECORDS_UNCHANGED and leaves the local records as they were. The code is 0 when that can't
     * be promised: a connection was already committed, the written-back rows failed, or the
     * import ran inside a transaction the caller opened (the caller then rolls back, or not).
     * Files are written after the records and are not undone.
     */
    public function apply(ImportPlan $plan): void
    {
        $this->applyRecords($plan);

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

    private function applyRecords(ImportPlan $plan): void
    {
        $tables = $this->set->tables();
        $changed = [];
        $work = [];
        try {
            foreach ($plan->tables as $table => $diff) {
                $table = (string) $table;
                if ($diff['added'] === [] && $diff['changed'] === [] && $diff['removed'] === []) {
                    continue;
                }
                $changed[] = $table;
                $def = $tables[$table];
                $rows = [];
                foreach ([...$diff['added'], ...$diff['changed']] as $id) {
                    $row = json_decode((string) file_get_contents("{$plan->dir}/data/{$table}/{$id}.json"), true, 512, JSON_THROW_ON_ERROR);
                    if (!is_array($row)) {
                        throw new TransferException("Broken record in the export: {$table}/{$id}");
                    }
                    // As saveType() writes it: no empty text in number, yes/no or date columns.
                    $rows[$id] = $this->dm->storageValues($def, $row);
                }
                $work[$table] = [$def, $this->dm->driver($def->storage), $diff, $rows];
            }
        } catch (\Throwable $e) {
            throw new TransferException($e->getMessage(), self::RECORDS_UNCHANGED, $e);
        }
        if ($work === []) {
            $this->set->imported([]);

            return;
        }

        /** @var array<int, \PDO> $began */
        $began = [];
        $committed = 0;
        $joined = false; // a transaction the caller opened: its rollback is the caller's
        /** @var list<array{0: StorageDriver, 1: string, 2: string, 3: list<string>, 4: array<string, array<string, mixed>>}> $undo */
        $undo = [];
        try {
            foreach ($work as $table => [$def, $driver, $diff]) {
                if ($driver instanceof PdoDriver) {
                    $pdo = $driver->pdo();
                    if (!isset($began[spl_object_id($pdo)])) {
                        if ($pdo->inTransaction()) {
                            $joined = true;
                        } else {
                            $pdo->beginTransaction();
                            $began[spl_object_id($pdo)] = $pdo;
                        }
                    }
                    continue;
                }
                // No transaction: keep the rows this import replaces or removes, to write them back.
                $before = [];
                foreach ([...$diff['changed'], ...$diff['removed']] as $id) {
                    $row = $driver->findOne($table, $id, $def->idField);
                    if ($row !== null) {
                        $before[$id] = $row;
                    }
                }
                $undo[] = [$driver, $table, $def->idField, $diff['added'], $before];
            }

            foreach ($work as $table => [$def, $driver, $diff, $rows]) {
                // Deleted first: a unique value the archive gives another id is free when it is written.
                foreach ([...$diff['removed'], ...$diff['changed']] as $id) {
                    $driver->delete($table, $id, $def->idField);
                }
                foreach ($rows as $id => $row) {
                    $driver->save($table, (string) $id, $row, $def->idField);
                }
            }

            foreach ($began as $pdo) {
                $pdo->commit();
                $committed++;
            }
        } catch (\Throwable $e) {
            foreach ($began as $pdo) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
            }
            try {
                foreach ($undo as [$driver, $table, $idField, $added, $before]) {
                    foreach ([...$added, ...array_keys($before)] as $id) {
                        $driver->delete($table, (string) $id, $idField);
                    }
                    foreach ($before as $id => $row) {
                        $driver->save($table, (string) $id, $row, $idField);
                    }
                }
            } catch (\Throwable $undoFailed) {
                throw new TransferException('its records could not be put back (' . $undoFailed->getMessage() . '): ' . $e->getMessage(), 0, $e);
            }
            if ($committed > 0) {
                throw new TransferException('another database was already committed: ' . $e->getMessage(), 0, $e);
            }

            // In the caller's transaction the rows are still written until the caller rolls back.
            throw new TransferException($e->getMessage(), $joined ? 0 : self::RECORDS_UNCHANGED, $e);
        }
        $this->set->imported($changed);
    }

    /**
     * The manifest and notices for the output: version files are skipped (not refused)
     * when this site keeps no versions, so a production export still imports locally.
     *
     * @return array{0: array{tables: array<string, int>}, 1: list<string>}
     */
    private function extract($stream, string $dir): array
    {
        $manifest = null;
        $skippedVersions = 0;
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

            if (preg_match('#^data/([A-Za-z0-9_]+)/([A-Za-z0-9_-][A-Za-z0-9._:-]*)\.json$#', $name, $m) === 1) {
                if (!array_key_exists($m[1], $manifest['tables'])) {
                    throw new TransferException("Record for a table outside the manifest: {$name}");
                }
            } elseif (str_starts_with($name, 'media-versions/') && !isset($this->set->fileRoots()['media-versions'])) {
                $skippedVersions++;
                continue; // the reader skips the body
            } elseif (preg_match('#^(media|uploads|private-media|media-versions)/.+$#', $name, $m) !== 1 || !isset($this->set->fileRoots()[$m[1]])) {
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
        $notices = $skippedVersions > 0
            ? ["media-versions/: {$skippedVersions} earlier file" . ($skippedVersions === 1 ? '' : 's') . ' skipped, this site keeps no versions (versions_dir is not set).']
            : [];

        return [$manifest, $notices];
    }

    /**
     * Incoming rows the ContentSet refuses (ContentSet::rowProblem()) are left out with a notice:
     * neither stored nor counted as removed locally. Local rows an export leaves out
     * (ContentSet::skipsExport(), assets in the bin) are not counted as removed either.
     *
     * @param list<string> $notices
     * @return array<string, array{added: list<string>, changed: list<string>, removed: list<string>}>
     */
    private function diffTables(string $dir, array $manifest, array &$notices = []): array
    {
        $out = [];
        foreach (array_keys($manifest['tables']) as $table) {
            $def = $this->set->tables()[$table];
            $incoming = [];
            $skipped = [];
            foreach (glob("{$dir}/data/{$table}/*.json") ?: [] as $file) {
                $row = json_decode((string) file_get_contents($file), true);
                if (!is_array($row)) {
                    throw new TransferException('Broken record in the export: ' . basename($file));
                }
                $problem = $this->set->rowProblem($table, basename($file, '.json'), $row);
                if ($problem !== null) {
                    // Left out: the local record of that id (if any) stays as it is.
                    $notices[] = "{$table}/" . basename($file, '.json') . ": skipped, {$problem}.";
                    $skipped[basename($file, '.json')] = true;
                    continue;
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
            foreach ($local as $id => $row) {
                // A row an export leaves out (an asset in this server's bin) is never in an archive: it stays.
                if (!array_key_exists((string) $id, $incoming) && !isset($skipped[(string) $id]) && !$this->set->skipsExport($table, $row)) {
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

    /**
     * @param array<string, mixed> $manifest
     * @return array<string, array{added: list<string>, changed: list<string>, removed: list<string>}>
     */
    private function diffFiles(string $dir, array $manifest): array
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
            // A root the archive does not name (an export from before private assets or versions) removes nothing.
            $diff['removed'] = array_key_exists($root, $manifest) ? array_values(array_diff($existing, $incoming)) : [];
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
