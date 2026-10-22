<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Support;

use Nyholm\Psr7\Factory\Psr17Factory;
use Preflow\Core\Application;
use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;
use Preflow\Folio\Publishing\Publisher;
use Psr\Http\Message\ResponseInterface;

/**
 * A temp app with a publishing "post" type (frontend "/{slug}", en/de) and a
 * "site" singleton. No auth, so admin POSTs need no session or CSRF token.
 */
trait PublishingScaffold
{
    private string $dir;

    /** The storage the scaffold's models use: 'json' or 'sqlite' (tables via TableSync). */
    private string $storage = 'json';

    private function scaffoldPublishing(string $storage = 'json'): void
    {
        $this->storage = $storage;
        putenv('APP_DEBUG=1');
        $this->dir = sys_get_temp_dir() . '/folio_pub_' . bin2hex(random_bytes(4));

        $this->write('config/app.php', "<?php\nreturn ['name' => 'Pub', 'debug' => 1, 'engine' => 'twig', 'key' => 'folio-integration-test-key'];\n");
        $sqlite = $storage === 'sqlite' ? "'sqlite' => ['path' => __DIR__ . '/../storage/app.sqlite']," : '';
        $this->write('config/data.php', <<<PHP
            <?php
            return [
                'drivers' => [
                    'json' => ['driver' => \\Preflow\\Data\\Driver\\JsonFileDriver::class, 'path' => __DIR__ . '/../storage/data'],
                    {$sqlite}
                ],
                'default' => 'json',
                'models_path' => __DIR__ . '/models',
            ];
            PHP);
        $this->write('config/providers.php', "<?php\nreturn [\\Preflow\\Folio\\FolioServiceProvider::class];\n");
        $this->write('config/folio.php', "<?php\nreturn ['path' => '/folio', 'blocks' => ['classes' => ["
            . "\\Preflow\\Folio\\Tests\\Fixtures\\Blocks\\HeroBlock\\HeroBlock::class, \\Preflow\\Folio\\Tests\\Fixtures\\Blocks\\LinkBlock::class, \\Preflow\\Folio\\Tests\\Fixtures\\Blocks\\TradeoffNoteBlock::class]]];\n");
        $this->write('config/i18n.php', "<?php\nreturn ['default' => 'en', 'available' => ['en', 'de'], 'fallback' => 'en', 'url_strategy' => 'prefix'];\n");
        $this->writeModel('post', [
            'label' => 'Posts',
            'frontend' => ['route' => '/{slug}'],
            'list' => ['orderable' => true],
            'fields' => [
                'title' => ['type' => 'string', 'translatable' => true, 'validate' => ['required']],
                'slug' => ['type' => 'string', 'translatable' => true, 'validate' => ['required']],
                'body' => ['type' => 'text', 'translatable' => true],
                'status' => ['type' => 'string'],
                'cover' => ['type' => 'asset', 'asset' => ['multiple' => false, 'accept' => 'image/*']],
                'related' => ['type' => 'relation', 'relation' => ['to' => 'post', 'multiple' => false, 'labelField' => 'title']],
                'notes' => ['type' => 'matrix', 'matrix' => ['allowed' => ['note']]],
                'sections' => ['type' => 'blocks'],
            ],
        ]);
        $this->writeModel('note', [
            'label' => 'Notes',
            'publishing' => true,
            'matrixable' => true,
            'fields' => ['name' => ['type' => 'string', 'validate' => ['required']]],
        ]);
        $this->write('resources/folio/frontend/types/note.twig', '<aside class="note">{{ record.name }}</aside>');
        $this->writeModel('site', [
            'label' => 'Site',
            'singleton' => true,
            'fields' => ['tagline' => ['type' => 'string', 'translatable' => true]],
        ]);
        $this->write('resources/folio/frontend/post.twig', '<html><head>{{ folio_alternate_links(alternates) }}</head><body><h1>{{ record.title }}</h1><p class="tagline">{{ folio_get("site").tagline|default("") }}</p>'
            . '<div class="rel">{{ rendered.related|default("")|raw }}</div><div class="mx">{{ rendered.notes|default("")|raw }}</div><a class="link">{{ folio_url("post", "p2")|default("none") }}</a></body></html>');
        @mkdir($this->dir . '/storage/data', 0777, true);
    }

    private function removeScaffold(): void
    {
        putenv('APP_DEBUG');
        if (!is_dir($this->dir)) {
            return;
        }
        $rii = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($rii as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($this->dir);
    }

    /** @param array<string, mixed> $config */
    private function writeModel(string $key, array $config): void
    {
        $this->write("config/models/{$key}.json", (string) json_encode(
            ['key' => $key, 'table' => $key, 'storage' => $this->storage, 'id_field' => 'uuid'] + $config,
        ));
    }

    private function write(string $rel, string $content): void
    {
        $path = $this->dir . '/' . $rel;
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, $content);
    }

    private function app(): Application
    {
        $app = Application::create($this->dir);
        $app->boot();

        return $app;
    }

    private function get(string $uri): ResponseInterface
    {
        return $this->app()->handle((new Psr17Factory())->createServerRequest('GET', $uri));
    }

    /** @param array<string, mixed> $body */
    private function post(string $uri, array $body = []): ResponseInterface
    {
        return $this->app()->handle((new Psr17Factory())->createServerRequest('POST', $uri)->withParsedBody($body));
    }

    private function dm(): DataManager
    {
        return $this->app()->container()->get(DataManager::class);
    }

    private function publisher(): Publisher
    {
        return $this->app()->container()->get(Publisher::class);
    }

    /**
     * Store a working copy directly (no rules).
     *
     * @param array<string, mixed> $data
     */
    private function working(string $type, string $id, array $data): DynamicRecord
    {
        $dm = $this->dm();
        $record = DynamicRecord::fromArray($dm->getTypeRegistry()->get($type), ['uuid' => $id] + $data);
        $dm->saveType($record, false);

        return $record;
    }

    private function stored(string $type, string $id): DynamicRecord
    {
        return $this->dm()->findType($type, $id) ?? throw new \RuntimeException("missing {$type}/{$id}");
    }
}
