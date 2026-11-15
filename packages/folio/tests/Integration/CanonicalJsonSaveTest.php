<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Integration;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;
use Preflow\Publishing\RecordWriter;

/**
 * JSON record files in one canonical format (0.78): a record saved through the Folio editor, one
 * saved through DataManager as a CLI script does and a hand-written file saved through
 * RecordWriter end in the same bytes.
 */
final class CanonicalJsonSaveTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/folio_canonical_' . bin2hex(random_bytes(5));
        $w = function (string $rel, string $content): void {
            $path = $this->dir . '/' . $rel;
            @mkdir(dirname($path), 0777, true);
            file_put_contents($path, $content);
        };
        $w('config/app.php', "<?php\nreturn ['name' => 'Folio Test', 'debug' => 0, 'engine' => 'twig', 'key' => 'folio-canonical-test-key'];\n");
        $w('config/data.php', <<<'PHP'
            <?php
            return [
                'drivers' => ['json' => ['driver' => \Preflow\Data\Driver\JsonFileDriver::class, 'path' => __DIR__ . '/../storage/data']],
                'default' => 'json',
                'models_path' => __DIR__ . '/models',
            ];
            PHP);
        $w('config/providers.php', "<?php\nreturn [\\Preflow\\Folio\\FolioServiceProvider::class];\n");
        $w('config/folio.php', "<?php\nreturn ['path' => '/folio'];\n");
        $w('config/models/item.json', (string) json_encode([
            'key' => 'item', 'table' => 'item', 'storage' => 'json', 'id_field' => 'uuid', 'label' => 'Items',
            'fields' => [
                'title' => ['type' => 'string', 'validate' => ['required']],
                'count' => ['type' => 'number'],
                'tags' => ['type' => 'tags'],
                'note' => ['type' => 'text'],
            ],
        ]));
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->dir);
    }

    public function test_folio_save_cli_save_and_a_hand_written_file_end_in_the_same_bytes(): void
    {
        // 1. The Folio editor's POST (the chips post tags[]).
        $app = $this->app();
        $response = $app->handle((new Psr17Factory())->createServerRequest('POST', '/folio/item')->withHeader('Sec-Fetch-Site', 'same-origin')
            ->withParsedBody(['title' => 'Grüße', 'count' => '2', 'tags' => ['a', 'Übersetzung'], 'note' => 'n/a']));
        $this->assertSame(302, $response->getStatusCode());
        $files = glob($this->dir . '/storage/data/item/*.json') ?: [];
        $this->assertCount(1, $files);
        $folioId = basename($files[0], '.json');
        $folio = $this->file($folioId);

        // 2. A CLI script: its own key order, the count as text, the tags as JSON text.
        $app = $this->app();
        $dm = $app->container()->get(DataManager::class);
        $dm->saveType(DynamicRecord::fromArray($dm->getTypeRegistry()->get('item'), [
            'note' => 'n/a', 'tags' => '["a","Übersetzung"]', 'uuid' => 'cli', 'count' => '2', 'title' => 'Grüße',
        ]));

        // 3. A hand-written file (keys in any order, a comma string, a number as text), saved through RecordWriter.
        file_put_contents($this->dir . '/storage/data/item/hand.json', '{"count":"2","uuid":"hand","tags":"a, Übersetzung","note":"n/a","title":"Grüße"}');
        $app = $this->app();
        $dm = $app->container()->get(DataManager::class);
        $app->container()->get(RecordWriter::class)->save($dm->findType('item', 'hand') ?? $this->fail('hand-written file read'));

        $this->assertStringEndsWith("\n", $folio);
        $this->assertStringContainsString('"Übersetzung"', $folio, 'umlauts readable');
        $this->assertSame(['title', 'count', 'tags', 'note', 'uuid'], array_keys(json_decode($folio, true)));
        $this->assertSame($folio, str_replace('"cli"', '"' . $folioId . '"', $this->file('cli')), 'a CLI save');
        $this->assertSame($folio, str_replace('"hand"', '"' . $folioId . '"', $this->file('hand')), 'a hand-written file after its next save');
    }

    private function file(string $id): string
    {
        return (string) file_get_contents($this->dir . '/storage/data/item/' . $id . '.json');
    }

    private function app(): Application
    {
        $app = Application::create($this->dir);
        $app->boot();

        return $app;
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->rrmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
