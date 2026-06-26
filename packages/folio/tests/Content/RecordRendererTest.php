<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Content;

use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\JsonFileDriver;
use Preflow\Data\DynamicRecord;
use Preflow\Data\TypeRegistry;
use Preflow\Folio\Content\RecordRenderer;
use Preflow\Folio\Field\FieldTypeRegistry;
use Preflow\Folio\Field\Types\StringFieldType;
use Preflow\Folio\Field\Types\TextFieldType;
use Preflow\View\TemplateEngineInterface;
use Preflow\View\TemplateFunctionDefinition;

final class RecordRendererTest extends TestCase
{
    private function fieldRegistry(): FieldTypeRegistry
    {
        $r = new FieldTypeRegistry();
        $r->register(new StringFieldType());
        $r->register(new TextFieldType());
        return $r;
    }

    private function engine(): TemplateEngineInterface
    {
        // Minimal fake: note.twig and note_card.twig exist; everything else falls back to _default.
        return new class implements TemplateEngineInterface {
            public function render(string $template, array $context = []): string
            {
                return $template . '|title=' . ($context['record']['title'] ?? '')
                    . '|body=' . ($context['rendered']['body'] ?? '')
                    . '|view=' . ($context['view'] ?? '');
            }
            public function exists(string $template): bool
            {
                return in_array($template, [
                    '@folio/frontend/types/note.twig',
                    '@folio/frontend/types/note_card.twig',
                ], true);
            }
            public function addFunction(TemplateFunctionDefinition $f): void {}
            public function addGlobal(string $n, mixed $v): void {}
            public function getTemplateExtension(): string { return 'twig'; }
            public function addNamespace(string $n, string $p): void {}
        };
    }

    private function record(): DynamicRecord
    {
        $dir = sys_get_temp_dir() . '/rr_' . bin2hex(random_bytes(4));
        mkdir($dir . '/m', 0777, true);
        mkdir($dir . '/s', 0777, true);
        file_put_contents($dir . '/m/note.json', json_encode([
            'key' => 'note', 'table' => 'note', 'storage' => 'json', 'id_field' => 'uuid',
            'fields' => ['title' => ['type' => 'string'], 'body' => ['type' => 'text']],
        ]));
        $reg = new TypeRegistry($dir . '/m');
        return DynamicRecord::fromArray($reg->get('note'), ['uuid' => 'n1', 'title' => 'Hi', 'body' => 'B']);
    }

    public function test_rendered_map_escapes_scalar_fields(): void
    {
        $rr = new RecordRenderer($this->fieldRegistry(), $this->engine());
        $map = $rr->renderedMap($this->record());
        $this->assertSame('Hi', $map['title']);
        $this->assertSame('B', $map['body']);
    }

    public function test_render_type_template_uses_specific_then_default(): void
    {
        $rr = new RecordRenderer($this->fieldRegistry(), $this->engine());
        $out = $rr->renderTypeTemplate($this->record());
        // engine fake "exists" only for note.twig, so it should be chosen
        $this->assertStringContainsString('@folio/frontend/types/note.twig', $out);
        $this->assertStringContainsString('title=Hi', $out);
    }

    public function test_render_type_template_uses_view_variant_when_present(): void
    {
        $rr = new RecordRenderer($this->fieldRegistry(), $this->engine());
        $out = $rr->renderTypeTemplate($this->record(), 'card');
        $this->assertStringContainsString('@folio/frontend/types/note_card.twig', $out);
        $this->assertStringContainsString('view=card', $out);
    }

    public function test_render_type_template_view_falls_back_to_type_then_default(): void
    {
        $rr = new RecordRenderer($this->fieldRegistry(), $this->engine());
        // 'inline' variant does not exist -> falls back to note.twig (which does)
        $out = $rr->renderTypeTemplate($this->record(), 'inline');
        $this->assertStringContainsString('@folio/frontend/types/note.twig', $out);
    }

    public function test_render_type_template_rejects_traversal_view(): void
    {
        $rr = new RecordRenderer($this->fieldRegistry(), $this->engine());
        // a view with illegal chars is ignored -> normal chain (note.twig exists)
        $out = $rr->renderTypeTemplate($this->record(), '../secret');
        $this->assertStringContainsString('@folio/frontend/types/note.twig', $out);
        $this->assertStringNotContainsString('secret', $out);
    }
}
