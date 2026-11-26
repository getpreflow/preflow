<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Content;

use PHPUnit\Framework\TestCase;
use Preflow\Data\DynamicRecord;
use Preflow\Data\FieldTransformer;
use Preflow\Data\Locales;
use Preflow\Data\TypeDefinition;
use Preflow\Data\TypeFieldDefinition;
use Preflow\Folio\Content\ListCells;
use Preflow\Folio\Content\RecordRenderer;
use Preflow\Folio\Content\TranslationStatus;
use Preflow\Folio\Field\FieldTypeRegistry;
use Preflow\Folio\Field\Types\StringFieldType;
use Preflow\View\TemplateEngineInterface;
use Preflow\View\TemplateFunctionDefinition;

/**
 * Show_when costs nothing where it is not used: the output readers do not read a record's stored
 * values (DynamicRecord::toArray(), which runs the type's transformers) to decide visibility for
 * a type without rules, nor for a field without a rule of its own. The default type template
 * prints no wrapper for a hidden field.
 */
final class PlainTypeVisibilityTest extends TestCase
{
    public function test_readers_skip_the_visibility_step_for_a_type_without_show_when(): void
    {
        $counter = new CountingTransformer();
        $type = $this->type($counter, withRule: false);
        $record = DynamicRecord::fromArray($type, ['uuid' => 'n1', 'kind' => 'text', 'title' => ['en' => 'Hi', 'de' => 'Hallo'], 'note' => 'x']);
        $fieldTypes = $this->fieldTypes();

        // Each reader reads the values exactly as often as before show_when: localized() reads
        // them once (renderedMap(), a list cell); the visibility step reads them no more.
        $reads = static function (\Closure $read) use ($counter): int {
            $counter->toStorage = 0;
            $read();

            return $counter->toStorage;
        };
        $this->assertSame(1, $reads(fn () => $this->assertSame('Hi', (new RecordRenderer($fieldTypes, $this->engine()))->renderedMap($record, 'en')['title'])));
        $this->assertSame(0, $reads(fn () => $this->assertCount(1, (new TranslationStatus($fieldTypes))->texts($type, $record))));
        $this->assertSame(0, $reads(fn () => $this->assertFalse(ListCells::hidden($type, 'note', $record))));
        $this->assertSame(1, $reads(fn () => $this->assertSame('x', ListCells::text($fieldTypes, $type, 'note', $record))));

        // With a rule, the same readers decide on the values: one read more each.
        $ruled = $this->type($counter, withRule: true);
        $record = DynamicRecord::fromArray($ruled, ['uuid' => 'n1', 'kind' => 'link', 'title' => ['en' => 'Hi', 'de' => 'Hallo'], 'note' => 'x']);
        $this->assertSame(2, $reads(fn () => (new RecordRenderer($fieldTypes, $this->engine()))->renderedMap($record, 'en')));
        $this->assertSame(1, $reads(fn () => (new TranslationStatus($fieldTypes))->texts($ruled, $record)));
        $this->assertSame(1, $reads(fn () => ListCells::hidden($ruled, 'note', $record)));
    }

    public function test_a_field_without_a_rule_of_its_own_is_never_hidden_without_reading_values(): void
    {
        $counter = new CountingTransformer();
        $type = $this->type($counter, withRule: true);
        $record = DynamicRecord::fromArray($type, ['uuid' => 'n1', 'kind' => 'text', 'title' => ['en' => 'Hi'], 'note' => 'x']);

        $this->assertFalse(ListCells::hidden($type, 'title', $record));
        $this->assertSame(0, $counter->toStorage);

        // The field with the rule is decided on the record's values.
        $this->assertTrue(ListCells::hidden($type, 'note', $record));
        $this->assertGreaterThan(0, $counter->toStorage);
    }

    public function test_the_default_type_template_prints_no_wrapper_for_a_hidden_field(): void
    {
        $twig = new \Twig\Environment(new \Twig\Loader\ArrayLoader([
            'default' => (string) file_get_contents(dirname(__DIR__, 2) . '/templates/frontend/types/_default.twig'),
        ]));

        $html = $twig->render('default', ['type' => 'teaser', 'rendered' => ['title' => 'Hi', 'note' => null, 'empty' => '']]);

        $this->assertStringContainsString('<div class="folio-field folio-field-title">Hi</div>', $html);
        $this->assertStringNotContainsString('folio-field-note', $html);
        $this->assertStringContainsString('<div class="folio-field folio-field-empty"></div>', $html, 'an empty field keeps its wrapper, as before');
    }

    private function type(CountingTransformer $counter, bool $withRule): TypeDefinition
    {
        return new TypeDefinition('note', 'note', 'json', [
            'kind' => new TypeFieldDefinition('kind'),
            'title' => new TypeFieldDefinition('title', translatable: true),
            'note' => new TypeFieldDefinition('note', config: $withRule ? ['show_when' => ['field' => 'kind', '=' => 'link']] : []),
        ], transformers: ['note' => $counter], locales: new Locales(['en', 'de'], 'en'));
    }

    private function fieldTypes(): FieldTypeRegistry
    {
        $registry = new FieldTypeRegistry();
        $registry->register(new StringFieldType());

        return $registry;
    }

    private function engine(): TemplateEngineInterface
    {
        return new class implements TemplateEngineInterface {
            public function render(string $template, array $context = []): string { return ''; }
            public function exists(string $template): bool { return false; }
            public function addFunction(TemplateFunctionDefinition $f): void {}
            public function addGlobal(string $n, mixed $v): void {}
            public function getTemplateExtension(): string { return 'twig'; }
            public function addNamespace(string $n, string $p): void {}
        };
    }
}

final class CountingTransformer implements FieldTransformer
{
    public int $toStorage = 0;

    public function toStorage(mixed $value): mixed
    {
        $this->toStorage++;

        return $value;
    }

    public function fromStorage(mixed $value): mixed
    {
        return $value;
    }
}
