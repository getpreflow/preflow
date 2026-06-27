<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Http;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\JsonFileDriver;
use Preflow\Data\DynamicRecord;
use Preflow\Data\TypeRegistry;
use Preflow\Folio\Content\RecordRenderer;
use Preflow\Folio\Content\TypeCatalog;
use Preflow\Folio\Field\FieldTypeRegistry;
use Preflow\Folio\Field\Types\StringFieldType;
use Preflow\Folio\Field\Types\TextFieldType;
use Preflow\Folio\Http\PreviewController;
use Preflow\View\TemplateEngineInterface;
use Preflow\View\TemplateFunctionDefinition;

final class PreviewControllerTest extends TestCase
{
    private string $base;
    private TypeRegistry $registry;
    private DataManager $dm;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/folio_prev_' . bin2hex(random_bytes(4));
        mkdir($this->base . '/m', 0777, true);
        mkdir($this->base . '/s', 0777, true);
        file_put_contents($this->base . '/m/page.json', json_encode([
            'key' => 'page', 'table' => 'page', 'storage' => 'json', 'id_field' => 'uuid', 'label' => 'Pages',
            'fields' => [
                'title' => ['type' => 'string', 'validate' => ['required']],
                'body' => ['type' => 'text'],
                'status' => ['type' => 'string'],
            ],
        ]));
        // a second, non-frontend type so the 404 path is exercised
        file_put_contents($this->base . '/m/note.json', json_encode([
            'key' => 'note', 'table' => 'note', 'storage' => 'json', 'id_field' => 'uuid', 'label' => 'Notes',
            'fields' => ['name' => ['type' => 'string']],
        ]));
        $this->registry = new TypeRegistry($this->base . '/m');
        $this->dm = new DataManager(['json' => new JsonFileDriver($this->base . '/s')], 'json', $this->registry);
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->base, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($this->base);
    }

    private function fieldTypes(): FieldTypeRegistry
    {
        $r = new FieldTypeRegistry();
        $r->register(new StringFieldType());
        $r->register(new TextFieldType());
        return $r;
    }

    private function engine(): TemplateEngineInterface
    {
        // Echoes the frontend render inputs so the test can see draft values.
        return new class implements TemplateEngineInterface {
            public function render(string $template, array $context = []): string
            {
                return $template . '|record=' . json_encode($context['record'] ?? [])
                    . '|rendered=' . json_encode($context['rendered'] ?? []);
            }
            public function exists(string $template): bool { return false; }
            public function addFunction(TemplateFunctionDefinition $f): void {}
            public function addGlobal(string $n, mixed $v): void {}
            public function getTemplateExtension(): string { return 'twig'; }
            public function addNamespace(string $n, string $p): void {}
        };
    }

    private function controller(): PreviewController
    {
        $fieldTypes = $this->fieldTypes();
        $engine = $this->engine();
        return new PreviewController(
            new TypeCatalog($this->base . '/m'),
            $this->registry,
            $this->dm,
            $fieldTypes,
            new RecordRenderer($fieldTypes, $engine),
            $engine,
            'page',
        );
    }

    public function test_renders_draft_values_without_saving(): void
    {
        $req = (new Psr17Factory())->createServerRequest('POST', '/folio/page/preview')
            ->withAttribute('type', 'page')
            ->withParsedBody(['title' => 'Draft Title', 'body' => 'Draft body', 'status' => 'draft']);

        $res = $this->controller()->preview($req);

        $this->assertSame(200, $res->getStatusCode());
        $body = (string) $res->getBody();
        $this->assertStringContainsString('@folio/frontend/page.twig', $body);
        $this->assertStringContainsString('Draft Title', $body);  // in record
        $this->assertStringContainsString('Draft body', $body);   // in rendered map
        // Nothing was persisted.
        $this->assertCount(0, $this->dm->queryType('page')->get()->items());
    }

    public function test_existing_record_preview_does_not_mutate_storage(): void
    {
        $this->dm->saveType(DynamicRecord::fromArray($this->registry->get('page'),
            ['uuid' => 'p1', 'title' => 'Original', 'body' => 'b', 'status' => 'published']));

        $req = (new Psr17Factory())->createServerRequest('POST', '/folio/page/p1/preview')
            ->withAttribute('type', 'page')->withAttribute('id', 'p1')
            ->withParsedBody(['title' => 'Edited', 'body' => 'b', 'status' => 'published']);

        $res = $this->controller()->preview($req);

        $this->assertStringContainsString('Edited', (string) $res->getBody());      // draft shown
        $this->assertSame('Original', $this->dm->findType('page', 'p1')->get('title')); // storage unchanged
    }

    public function test_unknown_type_404(): void
    {
        $req = (new Psr17Factory())->createServerRequest('POST', '/folio/ghost/preview')
            ->withAttribute('type', 'ghost')->withParsedBody(['title' => 'x']);
        $this->assertSame(404, $this->controller()->preview($req)->getStatusCode());
    }

    public function test_non_frontend_type_404(): void
    {
        $req = (new Psr17Factory())->createServerRequest('POST', '/folio/note/preview')
            ->withAttribute('type', 'note')->withParsedBody(['name' => 'x']);
        $this->assertSame(404, $this->controller()->preview($req)->getStatusCode());
    }
}
