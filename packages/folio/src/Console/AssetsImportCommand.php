<?php

declare(strict_types=1);

namespace Preflow\Folio\Console;

use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;
use Preflow\Data\TypeDefinition;
use Preflow\Data\TypeRegistry;
use Preflow\DevTools\Command\CommandInterface;
use Preflow\Folio\Assets\AssetLibrary;
use Preflow\Folio\Assets\AssetReferences;
use Preflow\Media\AssetUploadException;
use Preflow\Folio\Content\TypeCatalog;
use Preflow\Folio\Field\FieldTypeRegistry;
use Preflow\Folio\Field\Types\AssetFieldType;
use Preflow\Folio\Publishing\ShadowTypes;

/**
 * Move legacy upload paths into the asset library: folio:assets:import [--dry-run].
 * Every referenced file is imported once and every reference to it (working
 * copies, published snapshots, revisions, nested blocks) is rewritten to the
 * asset id, without touching _version. Missing files stay as they are and are
 * listed. Running it again finds nothing left to do.
 */
final class AssetsImportCommand implements CommandInterface
{
    use BootsApplication;

    public function getName(): string { return 'folio:assets:import'; }
    public function getDescription(): string { return 'Import legacy uploads into the asset library [--dry-run]'; }

    public function execute(array $args): int
    {
        $dry = in_array('--dry-run', $args, true);
        $c = $this->app()->container();
        $dm = $c->get(DataManager::class);
        $registry = $c->get(TypeRegistry::class);
        $references = $c->get(AssetReferences::class);
        $library = $c->get(AssetLibrary::class);
        $field = $c->get(FieldTypeRegistry::class)->get('asset');
        if (!$field instanceof AssetFieldType) {
            echo "The asset field type is replaced by the app; nothing to import.\n";
            return 1;
        }

        /** @var array<string, string> $imported legacy path => asset id */
        $imported = [];
        $missing = [];
        $failed = [];
        $rewritten = 0;
        $convert = function (string $value) use ($field, $library, $dry, &$imported, &$missing, &$failed): string {
            if (preg_match('#^\d{4}/\d{2}/[a-f0-9]{32}\.[a-z0-9]{1,8}$#', $value) !== 1) {
                return $value; // an asset id or something else: not a legacy path
            }
            if (isset($imported[$value])) {
                return $imported[$value];
            }
            $file = $field->legacyFile($value);
            if ($file === null) {
                $missing[$value] = true;
                return $value;
            }
            if ($dry) {
                return $imported[$value] = 'dry-run:' . $value;
            }
            try {
                return $imported[$value] = $library->importFile($file, basename($value), 'import')->id;
            } catch (AssetUploadException $e) {
                $failed[$value] = $e->getMessage();
                return $value;
            }
        };

        foreach ($c->get(TypeCatalog::class)->all() as $listing) {
            $def = $registry->get($listing->key);
            $sources = [$def];
            if ($listing->publishing) {
                $sources[] = ShadowTypes::published($def);
            }
            foreach ($sources as $source) {
                foreach ($dm->queryIn($source)->get()->items() as $record) {
                    $data = $record->toArray();
                    $mapped = $references->map($def, $data, $convert);
                    if ($mapped !== $data) {
                        $rewritten++;
                        $dry || $dm->saveType(DynamicRecord::fromArray($source, $mapped), false);
                    }
                }
            }
            if ($listing->publishing) {
                $rewritten += $this->rewriteRevisions($dm, $references, $def, $convert, $dry);
            }
        }

        $files = count($imported);
        $line = $dry
            ? sprintf('Would import %d file%s, rewrite %d record%s, %d missing', $files, $files === 1 ? '' : 's', $rewritten, $rewritten === 1 ? '' : 's', count($missing))
            : sprintf('Imported %d file%s, rewrote %d record%s, %d missing', $files, $files === 1 ? '' : 's', $rewritten, $rewritten === 1 ? '' : 's', count($missing));
        echo $line . ".\n";
        foreach (array_keys($missing) as $path) {
            echo "Missing: {$path}\n";
        }
        foreach ($failed as $path => $reason) {
            echo "Not imported: {$path} ({$reason})\n";
        }

        return $failed === [] ? 0 : 1;
    }

    /** Revisions hold full copies as JSON in "data": rewrite those too, so restoring keeps working. */
    private function rewriteRevisions(DataManager $dm, AssetReferences $references, TypeDefinition $def, \Closure $convert, bool $dry): int
    {
        $count = 0;
        $revisions = ShadowTypes::revisions($def);
        foreach ($dm->queryIn($revisions)->get()->items() as $row) {
            $data = json_decode((string) $row->get('data'), true);
            if (!is_array($data)) {
                continue;
            }
            $mapped = $references->map($def, $data, $convert);
            if ($mapped !== $data) {
                $count++;
                if (!$dry) {
                    $row->set('data', json_encode($mapped));
                    $dm->saveType($row, false);
                }
            }
        }

        return $count;
    }
}
