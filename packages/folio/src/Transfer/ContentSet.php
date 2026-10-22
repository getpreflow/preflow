<?php

declare(strict_types=1);

namespace Preflow\Folio\Transfer;

use Preflow\Core\Application;
use Preflow\Data\DataManager;
use Preflow\Data\TypeDefinition;
use Preflow\Folio\Assets\AssetLibrary;
use Preflow\Folio\Content\TypeCatalog;
use Preflow\Folio\Publishing\ShadowTypes;

/**
 * Everything Folio owns at runtime: its types' tables (with the published and
 * revision shadows of publishing types), the asset records, the media
 * directory, the private media directory (its files travel with their asset
 * records, archive root "private-media") and legacy uploads. Nothing else (code, .env, caches, sessions,
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
        $assets = $c->get(AssetLibrary::class)->definition();
        $tables[$assets->table] = $assets;
        ksort($tables);

        $cfg = is_file($app->basePath('config/folio.php')) ? (require $app->basePath('config/folio.php')) : [];
        $cfg = is_array($cfg) ? $cfg : [];
        $media = $cfg['assets']['dir'] ?? $app->basePath('public/media');
        $uploads = $cfg['uploads_path'] ?? $app->basePath('storage/uploads');
        $private = $cfg['assets']['private_dir'] ?? $app->basePath('storage/private-media');

        return new self(
            $tables,
            is_string($media) ? rtrim($media, '/') : null,
            is_string($uploads) ? rtrim($uploads, '/') : null,
            is_string($private) ? rtrim($private, '/') : null,
        );
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
        return array_filter(['media' => $this->mediaDir, 'uploads' => $this->uploadsDir, 'private-media' => $this->privateDir], 'is_string');
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
