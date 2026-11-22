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
        $w('config/app.php', "<?php\nreturn ['name' => 'Folio Test', 'debug' => 0, 'engine' => 'twig', 'key' => 'folio-canonical-test-key-0123456789abcdef'];\n");
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
                'flag' => ['type' => 'boolean'],
            ],
            'list' => ['columns' => ['title', 'count', 'flag'], 'filters' => ['flag']],
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
            'flag' => '0', 'note' => 'n/a', 'tags' => '["a","Übersetzung"]', 'uuid' => 'cli', 'count' => '2', 'title' => 'Grüße',
        ]));

        // 3. A hand-written file (keys in any order, a comma string, a number as text), saved through RecordWriter.
        file_put_contents($this->dir . '/storage/data/item/hand.json', '{"count":"2","uuid":"hand","flag":0,"tags":"a, Übersetzung","note":"n/a","title":"Grüße"}');
        $app = $this->app();
        $dm = $app->container()->get(DataManager::class);
        $app->container()->get(RecordWriter::class)->save($dm->findType('item', 'hand') ?? $this->fail('hand-written file read'));

        $this->assertStringEndsWith("\n", $folio);
        $this->assertStringContainsString('"Übersetzung"', $folio, 'umlauts readable');
        $this->assertSame(['title', 'count', 'tags', 'note', 'flag', 'uuid', '_updated_at'], array_keys(json_decode($folio, true)), 'the fields, then the system keys (the change date, 0.84)');
        // The three saves ran at their own moments: the change date (0.84) may differ by a second.
        $undated = static fn (string $json): string => (string) preg_replace('/"_updated_at": "[^"]+"/', '"_updated_at": "…"', $json);
        $this->assertSame($undated($folio), $undated(str_replace('"cli"', '"' . $folioId . '"', $this->file('cli'))), 'a CLI save');
        $this->assertSame($undated($folio), $undated(str_replace('"hand"', '"' . $folioId . '"', $this->file('hand'))), 'a hand-written file after its next save');
    }

    public function test_a_filter_on_a_number_and_a_yes_no_field_finds_a_record_saved_canonically(): void
    {
        // Written before 0.78: the number and the yes/no value as text. The save stores 2 and true.
        @mkdir($this->dir . '/storage/data/item', 0777, true);
        file_put_contents($this->dir . '/storage/data/item/r1.json', '{"uuid":"r1","title":"Quokkaone","count":"2","flag":"1"}');
        file_put_contents($this->dir . '/storage/data/item/r2.json', '{"uuid":"r2","title":"Zebratwo","count":"3","flag":"0"}');
        $app = $this->app();
        $dm = $app->container()->get(DataManager::class);
        foreach (['r1', 'r2'] as $id) {
            $dm->saveType($dm->findType('item', $id) ?? $this->fail('hand-written file read'), false);
        }
        $saved = json_decode($this->file('r1'), true);
        $this->assertSame([2, true], [$saved['count'], $saved['flag']], 'stored canonically');

        $get = fn (string $uri): string => (string) $this->app()->handle((new Psr17Factory())->createServerRequest('GET', $uri))->getBody();
        // Folio's list filters take yes/no, select, relation and date fields; a number is filtered by
        // callers (an extension, a site's page) that pass the value as text.
        $byNumber = $dm->queryType('item')->where('count', '2')->get()->items();
        $this->assertSame(['r1'], array_map(static fn ($r): string => (string) $r->getId(), $byNumber));
        $this->assertCount(1, $dm->queryType('item')->where('flag', '1')->get()->items());
        $yes = $get('/folio/item?f%5Bflag%5D=1');
        $this->assertStringContainsString('Quokkaone', $yes);
        $this->assertStringNotContainsString('Zebratwo', $yes);
        $no = $get('/folio/item?f%5Bflag%5D=0');
        $this->assertStringContainsString('Zebratwo', $no);
        $this->assertStringNotContainsString('Quokkaone', $no);
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
