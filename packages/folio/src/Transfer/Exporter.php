<?php

declare(strict_types=1);

namespace Preflow\Folio\Transfer;

use Preflow\Data\DataManager;
use Preflow\Data\Query;

/**
 * Writes a content archive: folio-export.json (manifest), then every record
 * as data/{table}/{id}.json (read through its storage driver, so JSON, SQLite
 * and MySQL types export alike), then media/… and uploads/… files.
 */
final class Exporter
{
    public function __construct(
        private readonly ContentSet $set,
        private readonly DataManager $dm,
    ) {}

    /**
     * @param resource $stream
     * @param 'all'|'data'|'media' $only
     * @return array{tables: array<string, int>, media: int, uploads: int, bytes: int}
     */
    public function export($stream, string $only = 'all'): array
    {
        $rows = [];
        if ($only !== 'media') {
            foreach ($this->set->tables() as $table => $def) {
                $rows[$table] = [];
                foreach ($this->dm->driver($def->storage)->findMany($table, new Query())->items() as $row) {
                    $id = (string) ($row[$def->idField] ?? '');
                    // Never skip a record: a missing one is deleted on the importing side.
                    if (preg_match(Importer::ID, $id) !== 1) {
                        throw new TransferException("Record {$table}/{$id} has an id the export cannot carry (allowed: letters, digits, '.', '_', '-', not starting with '.')");
                    }
                    $rows[$table][$id] = $row;
                }
                ksort($rows[$table]);
            }
        }
        $files = [];
        if ($only !== 'data') {
            foreach ($this->set->fileRoots() as $root => $dir) {
                $files[$root] = ContentSet::files($dir);
            }
        }

        $summary = [
            'tables' => array_map('count', $rows),
            'media' => count($files['media'] ?? []),
            'uploads' => count($files['uploads'] ?? []),
            'bytes' => 0,
        ];
        $writer = new TarWriter($stream);
        $writer->addString('folio-export.json', json_encode([
            'format' => 1,
            'created_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'only' => $only,
            'tables' => $summary['tables'],
            'media' => $summary['media'],
            'uploads' => $summary['uploads'],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

        foreach ($rows as $table => $byId) {
            foreach ($byId as $id => $row) {
                $json = json_encode($row, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                $writer->addString("data/{$table}/{$id}.json", $json);
                $summary['bytes'] += strlen($json);
            }
        }
        foreach ($files as $root => $list) {
            $dir = $this->set->fileRoots()[$root];
            foreach ($list as $rel) {
                $writer->addFile("{$root}/{$rel}", $dir . '/' . $rel);
                $summary['bytes'] += (int) filesize($dir . '/' . $rel);
            }
        }
        $writer->close();

        return $summary;
    }
}
