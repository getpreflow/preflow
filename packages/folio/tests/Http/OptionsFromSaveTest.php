<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Http;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Container\Container;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\JsonFileDriver;
use Preflow\Data\DynamicRecord;
use Preflow\Data\TypeRegistry;
use Preflow\Folio\Content\FrontendResolver;
use Preflow\Folio\Content\RecordLabeler;
use Preflow\Folio\Content\TypeCatalog;
use Preflow\Folio\Field\FieldTypeRegistry;
use Preflow\Folio\Field\Types\SelectFieldType;
use Preflow\Folio\Field\Types\StringFieldType;
use Preflow\Folio\Field\Types\TagsFieldType;
use Preflow\Folio\Http\AdminController;
use Preflow\Folio\Override\ActionResolver;
use Preflow\Folio\Tests\Support\RecordWriters;
use Preflow\Folio\Tests\Support\TempRoots;
use Preflow\Validation\RuleFactory;
use Preflow\Validation\ValidatorFactory;
use Preflow\View\TemplateEngineInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * A select with "options_from" (0.89) in the editor: the form shows the record's values of the
 * source field as options, and a save accepts only a value among the source's values as submitted
 * in the same save (the source may be edited in the same form).
 */
final class OptionsFromSaveTest extends TestCase
{
    private string $base;
    private DataManager $dm;
    private TypeRegistry $registry;
    /** @var array<string, mixed> the last rendered template's context */
    private array $rendered = [];

    protected function setUp(): void
    {
        $this->base = TempRoots::path('folio_optfrom_');
        mkdir($this->base . '/m', 0777, true);
        mkdir($this->base . '/s', 0777, true);
        file_put_contents($this->base . '/m/question.json', json_encode([
            'key' => 'question', 'table' => 'question', 'storage' => 'json', 'id_field' => 'uuid', 'label' => 'Questions',
            'fields' => [
                'title' => ['type' => 'string', 'validate' => ['required']],
                'answers' => ['type' => 'tags'],
                'answer' => ['type' => 'select', 'options_from' => 'answers'],
                'fallback' => ['type' => 'select', 'options_from' => 'answers', 'display' => 'radio', 'validate' => ['required']],
                'sizes' => ['type' => 'tags', 'hidden' => true],
                'size' => ['type' => 'select', 'options_from' => 'sizes'],
                'colours' => ['type' => 'tags', 'readonly' => true],
                'colour' => ['type' => 'select', 'options_from' => 'colours'],
            ],
        ]));
        $this->registry = new TypeRegistry($this->base . '/m');
        $this->dm = new DataManager(['json' => new JsonFileDriver($this->base . '/s')], 'json', $this->registry, new ValidatorFactory(new RuleFactory()));
    }

    private function controller(): AdminController
    {
        $fields = new FieldTypeRegistry();
        $fields->register(new StringFieldType());
        $fields->register(new TagsFieldType());
        $fields->register(new SelectFieldType());
        $test = $this;
        $engine = new class ($test) implements TemplateEngineInterface {
            public function __construct(private readonly OptionsFromSaveTest $test) {}
            public function render(string $template, array $context = []): string { $this->test->capture($context); return $template; }
            public function exists(string $template): bool { return true; }
            public function addFunction(\Preflow\View\TemplateFunctionDefinition $f): void {}
            public function addGlobal(string $n, mixed $v): void {}
            public function getTemplateExtension(): string { return 'twig'; }
            public function addNamespace(string $n, string $p): void {}
        };
        $catalog = new TypeCatalog($this->base . '/m');

        return new AdminController(
            $catalog, $this->registry, $this->dm, $engine,
            new ActionResolver(new Container(), 'Preflow\\Folio\\Tests\\Overrides\\'),
            $fields, '/folio', new RecordLabeler(), new FrontendResolver($this->dm, 'page'),
            ...RecordWriters::for($this->dm, $catalog, $fields, new FrontendResolver($this->dm, 'page')),
        );
    }

    /** @param array<string, mixed> $context */
    public function capture(array $context): void
    {
        if (isset($context['fields'])) {
            $this->rendered = $context;
        }
    }

    /** The rendered editor HTML of one field. */
    private function fieldHtml(string $name): string
    {
        foreach ((array) ($this->rendered['fields'] ?? []) as $field) {
            if (is_array($field) && ($field['name'] ?? null) === $name) {
                return (string) $field['html'];
            }
        }
        $this->fail("no field {$name} rendered");
    }

    /** @param array<string, mixed> $stored */
    private function store(array $stored): void
    {
        $this->dm->saveType(DynamicRecord::fromArray($this->registry->get('question'), ['uuid' => 'q1'] + $stored), false);
    }

    /** @param array<string, mixed> $post */
    private function update(array $post): ResponseInterface
    {
        $req = (new Psr17Factory())->createServerRequest('POST', '/folio/question/q1')
            ->withAttribute('type', 'question')->withAttribute('id', 'q1')->withParsedBody($post);

        return $this->controller()->update($req);
    }

    private function stored(string $field): mixed
    {
        return $this->dm->findType('question', 'q1')?->get($field);
    }

    public function test_the_edit_form_shows_the_records_source_values_as_options(): void
    {
        $this->store(['title' => 'Lunch?', 'answers' => ['Pizza', 'Salad'], 'answer' => 'Salad', 'fallback' => 'Pizza']);
        $req = (new Psr17Factory())->createServerRequest('GET', '/folio/question/q1/edit')
            ->withAttribute('type', 'question')->withAttribute('id', 'q1');
        $this->controller()->editForm($req);

        $select = $this->fieldHtml('answer');
        $this->assertStringContainsString('<option value="Pizza">Pizza</option>', $select);
        $this->assertStringContainsString('<option value="Salad" selected>Salad</option>', $select);
        $radio = $this->fieldHtml('fallback');
        $this->assertStringContainsString('<input type="radio" name="fallback" value="Pizza" checked required>', $radio);
        $this->assertStringContainsString('<input type="radio" name="fallback" value="Salad" required>', $radio);
    }

    public function test_a_new_record_shows_a_note_naming_the_source_instead_of_options(): void
    {
        $req = (new Psr17Factory())->createServerRequest('GET', '/folio/question/new')->withAttribute('type', 'question');
        $this->controller()->createForm($req);

        foreach (['answer', 'fallback'] as $name) {
            $html = $this->fieldHtml($name);
            $this->assertStringContainsString('Add values to Answers and save; they show up here as choices.', $html, $name);
            $this->assertStringNotContainsString('<option', $html, $name);
            $this->assertStringNotContainsString('type="radio"', $html, $name);
        }
    }

    public function test_a_source_the_form_does_not_post_counts_with_its_stored_values(): void
    {
        // A hidden and a read-only source are not in the form: the save keeps their stored values, and those are the options.
        $this->store(['title' => 'Shirt', 'answers' => ['Pizza'], 'fallback' => 'Pizza', 'sizes' => ['S', 'M'], 'colours' => ['Red']]);
        $req = (new Psr17Factory())->createServerRequest('GET', '/folio/question/q1/edit')
            ->withAttribute('type', 'question')->withAttribute('id', 'q1');
        $this->controller()->editForm($req);
        $this->assertStringContainsString('<option value="M">M</option>', $this->fieldHtml('size'));
        $this->assertStringContainsString('<option value="Red">Red</option>', $this->fieldHtml('colour'));

        $ok = $this->update(['title' => 'Shirt', 'answers' => '["Pizza"]', 'fallback' => 'Pizza', 'size' => 'M', 'colour' => 'Red', 'sizes' => '["XL"]', 'colours' => '["Blue"]']);
        $this->assertSame(302, $ok->getStatusCode());
        $this->assertSame('M', $this->stored('size'));
        $this->assertSame(['S', 'M'], $this->stored('sizes'), 'not taken from the post');

        $this->assertSame(422, $this->update(['title' => 'Shirt', 'answers' => '["Pizza"]', 'fallback' => 'Pizza', 'size' => 'XL', 'sizes' => '["XL"]'])->getStatusCode());
        $this->assertSame(422, $this->update(['title' => 'Shirt', 'answers' => '["Pizza"]', 'fallback' => 'Pizza', 'colour' => 'Blue', 'colours' => '["Blue"]'])->getStatusCode());
    }

    public function test_a_value_among_the_submitted_source_values_saves(): void
    {
        $this->store(['title' => 'Lunch?', 'answers' => ['Pizza'], 'fallback' => 'Pizza']);

        // "Soup" is added to the source in the same save, and chosen.
        $res = $this->update(['title' => 'Lunch?', 'answers' => '["Pizza","Soup"]', 'answer' => 'Soup', 'fallback' => 'Pizza']);

        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame('Soup', $this->stored('answer'));
        $this->assertSame(['Pizza', 'Soup'], $this->stored('answers'));
    }

    public function test_a_value_not_among_the_submitted_source_values_fails_and_the_form_keeps_them(): void
    {
        $this->store(['title' => 'Lunch?', 'answers' => ['Pizza', 'Salad'], 'answer' => 'Salad', 'fallback' => 'Pizza']);

        // "Salad" is removed from the source in the same save, but still chosen.
        $res = $this->update(['title' => 'Lunch?', 'answers' => '["Pizza"]', 'answer' => 'Salad', 'fallback' => 'Pizza']);

        $this->assertSame(422, $res->getStatusCode());
        $this->assertSame('Salad', $this->stored('answer'));
        $this->assertSame(['Pizza', 'Salad'], $this->stored('answers'), 'nothing stored');
        $html = $this->fieldHtml('answer');
        $this->assertStringContainsString('Must be one of: Pizza.', $html);
        $this->assertStringContainsString('<option value="Salad" selected>Salad (no longer an option)</option>', $html, 'kept and labelled');
        $this->assertStringContainsString('<option value="Pizza">Pizza</option>', $html, 'the submitted source values');
    }

    public function test_a_translatable_source_counts_with_its_default_language_values(): void
    {
        file_put_contents($this->base . '/m/poll.json', json_encode([
            'key' => 'poll', 'table' => 'poll', 'storage' => 'json', 'id_field' => 'uuid',
            'fields' => [
                'choices' => ['type' => 'string', 'translatable' => true],
                'choice' => ['type' => 'select', 'options_from' => 'choices'],
            ],
        ]));
        $this->registry = new TypeRegistry($this->base . '/m', new \Preflow\Data\Locales(['en', 'de'], 'en'));
        $this->dm = new DataManager(['json' => new JsonFileDriver($this->base . '/s')], 'json', $this->registry, new ValidatorFactory(new RuleFactory()));
        $this->dm->saveType(DynamicRecord::fromArray($this->registry->get('poll'), ['uuid' => 'p1', 'choices' => ['en' => 'Yes', 'de' => 'Ja']]), false);

        $post = static fn (string $choice): array => ['choices' => 'Yes', 'choice' => $choice];
        $request = static fn (string $choice) => (new Psr17Factory())->createServerRequest('POST', '/folio/poll/p1')
            ->withAttribute('type', 'poll')->withAttribute('id', 'p1')->withParsedBody($post($choice));

        $this->assertSame(302, $this->controller()->update($request('Yes'))->getStatusCode(), 'a default-language value');
        $this->assertSame(422, $this->controller()->update($request('Ja'))->getStatusCode(), 'a German value is not an option');
        $this->assertSame('Yes', $this->dm->findType('poll', 'p1')?->get('choice'));
    }

    public function test_empty_passes_unless_required(): void
    {
        $this->store(['title' => 'Lunch?', 'answers' => ['Pizza'], 'answer' => 'Pizza', 'fallback' => 'Pizza']);

        $this->assertSame(302, $this->update(['title' => 'Lunch?', 'answers' => '["Pizza"]', 'answer' => '', 'fallback' => 'Pizza'])->getStatusCode());
        $this->assertNull($this->stored('answer'));

        $this->assertSame(422, $this->update(['title' => 'Lunch?', 'answers' => '["Pizza"]', 'answer' => '', 'fallback' => ''])->getStatusCode());
        $this->assertSame('Pizza', $this->stored('fallback'));
    }
}
