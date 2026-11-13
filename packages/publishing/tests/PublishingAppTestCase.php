<?php

declare(strict_types=1);

namespace Preflow\Publishing\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;
use Preflow\Publishing\Publisher;

/**
 * A temp app with preflow/publishing and no Folio: JSON storage, content locales en and de, the
 * type "post" with "publishing": true in its model file, "note" without it, and the class-backed
 * type "job" (Fixtures\Job) whose model file turns publishing on.
 */
abstract class PublishingAppTestCase extends TestCase
{
    protected string $dir;
    private ?Application $app = null;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/publishing_app_' . bin2hex(random_bytes(4));
        $this->write('config/app.php', "<?php\nreturn ['name' => 'Publishing', 'debug' => 1, 'key' => 'publishing-app-test-key'];\n");
        $this->write('config/providers.php', "<?php\nreturn [\\Preflow\\Publishing\\PublishingServiceProvider::class];\n");
        $this->write('config/i18n.php', "<?php\nreturn ['default' => 'en', 'available' => ['en', 'de'], 'fallback' => 'en', 'url_strategy' => 'prefix'];\n");
        $fixtures = var_export(__DIR__ . '/Fixtures', true);
        $this->write('config/data.php', <<<PHP
            <?php
            return [
                'drivers' => ['json' => ['driver' => \\Preflow\\Data\\Driver\\JsonFileDriver::class, 'path' => __DIR__ . '/../storage/data']],
                'default' => 'json',
                'models_path' => __DIR__ . '/models',
                'model_paths' => [{$fixtures}],
            ];
            PHP);
        $fields = ['title' => ['type' => 'string', 'translatable' => true], 'body' => ['type' => 'text', 'translatable' => true]];
        $this->write('config/models/post.json', (string) json_encode(['key' => 'post', 'table' => 'post', 'storage' => 'json', 'publishing' => true, 'fields' => $fields]));
        $this->write('config/models/note.json', (string) json_encode(['key' => 'note', 'table' => 'note', 'storage' => 'json', 'fields' => $fields]));
        $this->write('config/models/job.json', (string) json_encode(['key' => 'job', 'publishing' => true]));
    }

    protected function tearDown(): void
    {
        $this->remove($this->dir);
    }

    protected function write(string $rel, string $content): void
    {
        $path = $this->dir . '/' . $rel;
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, $content);
    }

    protected function app(): Application
    {
        if ($this->app === null) {
            $this->app = Application::create($this->dir);
            $this->app->boot();
        }

        return $this->app;
    }

    protected function dm(): DataManager
    {
        return $this->app()->container()->get(DataManager::class);
    }

    protected function publisher(): Publisher
    {
        return $this->app()->container()->get(Publisher::class);
    }

    /**
     * A working copy, stored without rules.
     *
     * @param array<string, mixed> $data
     */
    protected function working(string $type, string $id, array $data): DynamicRecord
    {
        $record = DynamicRecord::fromArray($this->dm()->getTypeRegistry()->get($type), ['uuid' => $id] + $data);
        $this->dm()->saveType($record, false);

        return $record;
    }

    protected function stored(string $type, string $id): DynamicRecord
    {
        return $this->dm()->findType($type, $id) ?? throw new \RuntimeException("missing {$type}/{$id}");
    }

    private function remove(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }
        @rmdir($dir);
    }
}
