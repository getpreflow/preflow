<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Field;

use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\JsonFileDriver;
use Preflow\Data\DynamicRecord;
use Preflow\Data\TypeRegistry;
use Preflow\Folio\Content\RecordLabeler;
use Preflow\Folio\Content\RecordRenderer;
use Preflow\Folio\Content\TypeCatalog;
use Preflow\Folio\Field\FieldContext;
use Preflow\Folio\Field\FieldTypeRegistry;
use Preflow\Folio\Field\Types\MatrixFieldType;
use Preflow\Folio\Field\Types\StringFieldType;
use Preflow\View\TemplateEngineInterface;
use Preflow\View\TemplateFunctionDefinition;

final class MatrixFieldTypeTest extends TestCase
{
    private string $base;
    private TypeRegistry $registry;
    private DataManager $dm;
    private TypeCatalog $catalog;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/folio_mx_' . bin2hex(random_bytes(4));
        mkdir($this->base . '/m', 0777, true);
        mkdir($this->base . '/s', 0777, true);
        // matrixable type
        file_put_contents($this->base . '/m/note.json', json_encode([
            'key' => 'note', 'table' => 'note', 'storage' => 'json', 'id_field' => 'uuid', 'label' => 'Notes', 'matrixable' => true,
            'fields' => ['name' => ['type' => 'string']],
        ]));
        // non-matrixable type
        file_put_contents($this->base . '/m/secret.json', json_encode([
            'key' => 'secret', 'table' => 'secret', 'storage' => 'json', 'id_field' => 'uuid', 'label' => 'Secrets',
            'fields' => ['name' => ['type' => 'string']],
        ]));
        $this->registry = new TypeRegistry($this->base . '/m');
        $this->dm = new DataManager(['json' => new JsonFileDriver($this->base . '/s')], 'json', $this->registry);
        $this->catalog = new TypeCatalog($this->base . '/m');
        $this->dm->saveType(DynamicRecord::fromArray($this->registry->get('note'), ['uuid' => 'n1', 'name' => 'First note']));
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

    private function recordRenderer(): RecordRenderer
    {
        $fr = new FieldTypeRegistry();
        $fr->register(new StringFieldType());
        $engine = new class implements TemplateEngineInterface {
            public function render(string $template, array $context = []): string
            {
                return '[' . ($context['type'] ?? '') . ':' . ($context['rendered']['name'] ?? '') . ']';
            }
            public function exists(string $template): bool { return false; }
            public function addFunction(TemplateFunctionDefinition $f): void {}
            public function addGlobal(string $n, mixed $v): void {}
            public function getTemplateExtension(): string { return 'twig'; }
            public function addNamespace(string $n, string $p): void {}
        };
        return new RecordRenderer($fr, $engine);
    }

    private function type(): MatrixFieldType
    {
        return new MatrixFieldType(
            $this->catalog,
            $this->registry,
            $this->dm,
            $this->recordRenderer(),
            new RecordLabeler(),
            '/folio',
        );
    }

    private function config(array $allowed = []): array
    {
        return ['matrix' => ['allowed' => $allowed]];
    }

    public function test_key_and_assets(): void
    {
        $t = $this->type();
        $this->assertSame('matrix', $t->key());
        $this->assertSame(['admin.js'], $t->assets());
    }

    public function test_render_editor_has_hook_options_and_allowed_types(): void
    {
        $html = $this->type()->renderEditor(new FieldContext(name: 'blocks', config: $this->config()));
        $this->assertStringContainsString('data-folio-matrix', $html);
        $this->assertStringContainsString('data-field="blocks"', $html);
        $this->assertStringContainsString('data-matrix-options', $html);
        $this->assertStringContainsString('First note', $html); // note record in the options blob
        $this->assertStringContainsString('value="note"', $html); // note type addable
        $this->assertStringNotContainsString('value="secret"', $html); // not matrixable -> excluded
    }

    public function test_render_editor_existing_rows(): void
    {
        $html = $this->type()->renderEditor(new FieldContext(
            name: 'blocks', value: [['_type' => 'note', 'id' => 'n1']], config: $this->config(),
        ));
        $this->assertStringContainsString('name="blocks[0][_type]" value="note"', $html);
        $this->assertStringContainsString('name="blocks[0][id]" value="n1"', $html);
        $this->assertStringContainsString('First note', $html); // resolved label
    }

    public function test_normalize_filters_disallowed_and_reindexes(): void
    {
        $raw = [
            5 => ['_type' => 'note', 'id' => 'n1'],
            2 => ['_type' => 'secret', 'id' => 's1'], // not matrixable -> dropped
            7 => ['_type' => 'note', 'id' => ''],      // empty id -> dropped
        ];
        $out = $this->type()->normalizeInput($raw, $this->config());
        $this->assertSame([['_type' => 'note', 'id' => 'n1']], $out);
    }

    public function test_storage_roundtrip(): void
    {
        $t = $this->type();
        $json = $t->toStorage([['_type' => 'note', 'id' => 'n1']]);
        $this->assertSame([['_type' => 'note', 'id' => 'n1']], $t->fromStorage($json));
    }

    public function test_render_frontend_resolves_via_type_template(): void
    {
        $out = $this->type()->renderFrontend([['_type' => 'note', 'id' => 'n1']], $this->config());
        $this->assertStringContainsString('[note:First note]', $out); // per-type template output
    }

    public function test_render_frontend_skips_unknown_type(): void
    {
        $out = $this->type()->renderFrontend([['_type' => 'ghost', 'id' => 'x']], $this->config());
        $this->assertSame('', $out);
    }

    public function test_render_editor_embeds_prefix_for_admin_js(): void
    {
        $html = $this->type()->renderEditor(new FieldContext(name: 'blocks', config: $this->config()));
        $this->assertStringContainsString('"prefix":"/folio"', $html);
    }
}
