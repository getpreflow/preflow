<?php

declare(strict_types=1);

namespace Preflow\Folio\Transfer;

/**
 * What an import would change, computed from an extracted, validated archive
 * against the local content. cleanup() removes the extracted copy.
 */
final class ImportPlan
{
    /**
     * @param array<string, array{added: list<string>, changed: list<string>, removed: list<string>}> $tables
     * @param array<string, array{added: list<string>, changed: list<string>, removed: list<string>}> $files keyed media/uploads
     * @param list<string> $notices what the import leaves out and why, for the command output
     */
    public function __construct(
        public readonly string $dir,
        public readonly array $tables,
        public readonly array $files,
        public readonly array $notices = [],
    ) {}

    /**
     * The tables and each file root (media, uploads, private-media, media-versions); a root the plan does not touch is empty.
     *
     * @return array{tables: array<string, array{added: list<string>, changed: list<string>, removed: list<string>}>, media: array{added: list<string>, changed: list<string>, removed: list<string>}, uploads: array{added: list<string>, changed: list<string>, removed: list<string>}, private-media: array{added: list<string>, changed: list<string>, removed: list<string>}, media-versions: array{added: list<string>, changed: list<string>, removed: list<string>}}
     */
    public function summary(): array
    {
        $none = ['added' => [], 'changed' => [], 'removed' => []];

        return [
            'tables' => $this->tables,
            'media' => $this->files['media'] ?? $none,
            'uploads' => $this->files['uploads'] ?? $none,
            'private-media' => $this->files['private-media'] ?? $none,
            'media-versions' => $this->files['media-versions'] ?? $none,
        ];
    }

    public function isEmpty(): bool
    {
        foreach ([...array_values($this->tables), ...array_values($this->files)] as $diff) {
            if ($diff['added'] !== [] || $diff['changed'] !== [] || $diff['removed'] !== []) {
                return false;
            }
        }

        return true;
    }

    /** One line per table/root that changes, for the command output. @return list<string> */
    public function lines(): array
    {
        $out = [];
        foreach ([...$this->tables, ...array_combine(array_map(static fn ($k) => "{$k}/", array_keys($this->files)), array_values($this->files))] as $name => $d) {
            if ($d['added'] !== [] || $d['changed'] !== [] || $d['removed'] !== []) {
                $out[] = sprintf('%-28s +%d  ~%d  -%d', $name, count($d['added']), count($d['changed']), count($d['removed']));
            }
        }

        return $out;
    }

    public function cleanup(): void
    {
        Importer::removeDir($this->dir);
    }
}
