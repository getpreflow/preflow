<?php

declare(strict_types=1);

namespace Preflow\Folio\Transfer;

use Preflow\Core\Application;
use Preflow\Data\DataManager;
use Preflow\Data\TypeDefinition;
use Preflow\Folio\Content\TypeCatalog;
use Preflow\Folio\Publishing\ShadowTypes;
use Preflow\Folio\Translations\StoredOverrides;
use Preflow\Media\MediaConfig;
use Preflow\Media\MediaLibrary;

/**
 * Everything Folio owns at runtime: its types' tables (with the published and
 * revision shadows of publishing types), the asset records, the media
 * directory, the private media directory (its files travel with their asset
 * records, archive root "private-media"; assets in the bin stay on the server), the earlier files of replaced assets
 * (MediaConfig versions_dir, archive root "media-versions") and legacy uploads, and the
 * admins' interface text overrides (StoredOverrides). Nothing else (code, .env, caches, sessions,
 * other tables such as user_tokens) is content.
 */
final class ContentSet
{
    /** @param array<string, TypeDefinition> $tables keyed by table */
    public function __construct(
        private readonly array $tables,
        private readonly ?string $mediaDir,
        private readonly ?string $uploadsDir,
        private readonly ?string $privateDir = null,
        private readonly ?string $versionsDir = null,
        /** @var (\Closure(list<string>): void)|null told the tables an import changed */
        private readonly ?\Closure $onImport = null,
        /** @var array<string, \Closure(string, array<string, mixed>): ?string> table => why an imported row can't be stored (null: it can) */
        private readonly array $rowChecks = [],
        /** @var array<string, \Closure(array<string, mixed>): bool> table => whether an export leaves the row out */
        private readonly array $exportSkips = [],
    ) {}

    public static function fromApp(Application $app): self
    {
        $c = $app->container();
        $registry = $c->get(DataManager::class)->getTypeRegistry();
        $tables = [];
        foreach ($c->get(TypeCatalog::class)->all() as $listing) {
            if (!$listing->transfer) {
                continue; // user types and opted-out types stay on the server
            }
            $def = $registry->get($listing->key);
            $tables[$def->table] = $def;
            if ($listing->publishing) {
                foreach ([ShadowTypes::published($def), ShadowTypes::revisions($def)] as $shadow) {
                    $tables[$shadow->table] = $shadow;
                }
            }
        }
        $assets = $c->get(MediaLibrary::class)->definition();
        $tables[$assets->table] = $assets;
        // The admins' text overrides travel with the content (the table is made when missing, so an export always reads it).
        $overrides = $c->get(StoredOverrides::class);
        $overrides->ensureTable();
        $tables[StoredOverrides::TABLE] = $overrides->definition();
        ksort($tables);

        $cfg = is_file($app->basePath('config/folio.php')) ? (require $app->basePath('config/folio.php')) : [];
        $cfg = is_array($cfg) ? $cfg : [];
        $media = $c->get(MediaConfig::class);
        $uploads = $cfg['uploads_path'] ?? $app->basePath('storage/uploads');

        return new self(
            $tables,
            rtrim($media->dir, '/'),
            is_string($uploads) ? rtrim($uploads, '/') : null,
            $media->privateDir !== null ? rtrim($media->privateDir, '/') : null,
            $media->versionsDir !== null ? rtrim($media->versionsDir, '/') : null,
            // Imported overrides replace the compiled ones.
            static function (array $tables) use ($overrides): void {
                if (in_array(StoredOverrides::TABLE, $tables, true)) {
                    $overrides->forget();
                }
            },
            // Override texts are printed unescaped: an archive's rows pass the same checks as the screen's.
            [StoredOverrides::TABLE => StoredOverrides::rowProblem(...)],
            // The bin is local to a server: a binned asset's files are not in the archive, so elsewhere it could never be restored.
            [$assets->table => static fn (array $row): bool => (string) ($row['binned_at'] ?? '') !== ''],
        );
    }

    /**
     * Whether an export leaves the row out (an asset in the bin).
     *
     * @param array<string, mixed> $row
     */
    public function skipsExport(string $table, array $row): bool
    {
        return isset($this->exportSkips[$table]) && ($this->exportSkips[$table])($row);
    }

    /**
     * Why an imported row of the table can't be stored, or null.
     *
     * @param array<string, mixed> $row
     */
    public function rowProblem(string $table, string $id, array $row): ?string
    {
        return isset($this->rowChecks[$table]) ? ($this->rowChecks[$table])($id, $row) : null;
    }

    /**
     * Called by Importer::apply() with the tables it changed.
     *
     * @param list<string> $tables
     */
    public function imported(array $tables): void
    {
        if ($this->onImport !== null) {
            ($this->onImport)($tables);
        }
    }

    /** @return array<string, TypeDefinition> */
    public function tables(): array
    {
        return $this->tables;
    }

    public function mediaDir(): ?string
    {
        return $this->mediaDir;
    }

    public function uploadsDir(): ?string
    {
        return $this->uploadsDir;
    }

    /** @return array<string, string> archive root => local directory */
    public function fileRoots(): array
    {
        return array_filter([
            'media' => $this->mediaDir,
            'uploads' => $this->uploadsDir,
            'private-media' => $this->privateDir,
            'media-versions' => $this->versionsDir,
        ], 'is_string');
    }

    /**
     * Regular files under a directory, relative and sorted; dotfiles and
     * symlinks are not content.
     *
     * @return list<string>
     */
    public static function files(?string $dir): array
    {
        if ($dir === null || !is_dir($dir)) {
            return [];
        }
        $out = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                static fn (\SplFileInfo $f): bool => !str_starts_with($f->getFilename(), '.') && !$f->isLink(),
            ),
        );
        foreach ($it as $f) {
            if ($f->isFile()) {
                $out[] = substr($f->getPathname(), strlen($dir) + 1);
            }
        }
        sort($out);

        return $out;
    }
}
