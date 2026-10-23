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
use Preflow\Folio\Field\FieldContext;
use Preflow\Folio\Field\FieldType;
use Preflow\Folio\Field\FieldTypeRegistry;
use Preflow\Folio\Field\Types\DateFieldType;
use Preflow\Folio\Field\Types\RelationFieldType;
use Preflow\Folio\Field\Types\RichTextFieldType;
use Preflow\Folio\Field\Types\SelectFieldType;
use Preflow\Folio\Field\Types\StringFieldType;
use Preflow\Folio\Http\AdminController;
use Preflow\Folio\Override\ActionResolver;
use Preflow\Validation\RuleFactory;
use Preflow\Validation\ValidatorFactory;
use Preflow\View\TemplateEngineInterface;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

final class ReadonlyFieldDisplayTest extends TestCase
{
    private string $base;
    private DataManager $dm;
    private TypeRegistry $registry;
    private string $tz;

    protected function setUp(): void
    {
        $this->tz = date_default_timezone_get();
        date_default_timezone_set('UTC');
        $this->base = sys_get_temp_dir() . '/folio_ro_' . bin2hex(random_bytes(4));
        mkdir($this->base . '/m', 0777, true);
        mkdir($this->base . '/s', 0777, true);
        file_put_contents($this->base . '/m/author.json', json_encode([
            'key' => 'author', 'table' => 'author', 'storage' => 'json', 'id_field' => 'uuid', 'label' => 'Authors',
            'fields' => ['name' => ['type' => 'string']],
        ]));
        file_put_contents($this->base . '/m/member.json', json_encode([
            'key' => 'member', 'table' => 'member', 'storage' => 'json', 'id_field' => 'uuid', 'label' => 'Members',
            'fields' => [
                'language' => ['type' => 'select', 'readonly' => true, 'options' => ['en' => 'English', 'de' => 'Deutsch']],
                'roles' => ['type' => 'rbac_roles', 'readonly' => true],
                'joined' => ['type' => 'date', 'readonly' => true, 'time' => true],
                'writer' => ['type' => 'relation', 'readonly' => true, 'relation' => ['to' => 'author', 'multiple' => false]],
                'bio' => ['type' => 'richtext', 'readonly' => true],
                'note' => ['type' => 'string', 'readonly' => true],
                'cover' => ['type' => 'asset', 'readonly' => true],
                'active' => ['type' => 'boolean', 'readonly' => true],
            ],
        ]));
        $this->registry = new TypeRegistry($this->base . '/m');
        $this->dm = new DataManager(['json' => new JsonFileDriver($this->base . '/s')], 'json', $this->registry, new ValidatorFactory(new RuleFactory()));
        $this->dm->saveType(DynamicRecord::fromArray($this->registry->get('author'), ['uuid' => 'a1', 'name' => 'Ann <i>Author</i>']), false);
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->tz);
    }

    private function engine(): object
    {
        return new class implements TemplateEngineInterface {
            public string $context = '';
            public function render(string $template, array $context = []): string
            {
                $this->context = (string) json_encode($context);
                return '';
            }
            public function exists(string $template): bool { return true; }
            public function addFunction(\Preflow\View\TemplateFunctionDefinition $f): void {}
            public function addGlobal(string $n, mixed $v): void {}
            public function getTemplateExtension(): string { return 'twig'; }
            public function addNamespace(string $n, string $p): void {}
        };
    }

    private function controller(object $engine): AdminController
    {
        $registry = new FieldTypeRegistry();
        $registry->register(new StringFieldType());
        $registry->register(new SelectFieldType());
        $registry->register(new \Preflow\Folio\Field\Types\AssetFieldType($this->base . '/up', '/folio/_uploads'));
        $registry->register(new \Preflow\Folio\Field\Types\BooleanFieldType());
        $registry->register(new DateFieldType());
        $registry->register(new RelationFieldType($this->dm, $this->registry));
        $registry->register(new RichTextFieldType(new HtmlSanitizer((new HtmlSanitizerConfig())->allowSafeElements())));
        $registry->register(new class implements FieldType {
            public function key(): string { return 'rbac_roles'; }
            public function renderEditor(FieldContext $ctx): string { return ''; }
            public function normalizeInput(mixed $raw, array $config): mixed { return (array) $raw; }
            public function toStorage(mixed $value): mixed { return $value; }
            public function fromStorage(mixed $value): mixed { return is_array($value) ? $value : []; }
            public function renderFrontend(mixed $value, array $config): string { return htmlspecialchars(implode(', ', (array) $value)); }
            public function rules(array $config): array { return []; }
            public function assets(): array { return []; }
        });
        return new AdminController(
            new TypeCatalog($this->base . '/m'), $this->registry, $this->dm, $engine,
            new ActionResolver(new Container(), 'Preflow\\Folio\\Tests\\Overrides\\'),
            $registry, '/folio', new RecordLabeler(), new FrontendResolver($this->dm, 'page'),
        );
    }

    /** @param array<string, mixed> $values @return array<string, string> field name => rendered html */
    private function render(array $values): array
    {
        $this->dm->saveType(DynamicRecord::fromArray($this->registry->get('member'), ['uuid' => 'm1'] + $values), false);
        $engine = $this->engine();
        $controller = $this->controller($engine);
        $controller->editForm((new Psr17Factory())->createServerRequest('GET', '/folio/member/m1/edit')->withAttribute('type', 'member')->withAttribute('id', 'm1'));

        $out = [];
        foreach (json_decode($engine->context, true)['fields'] as $field) {
            $out[$field['name']] = $field['html'];
        }

        return $out;
    }

    public function test_readonly_select_shows_label(): void
    {
        $html = $this->render(['language' => 'de'])['language'];
        $this->assertStringContainsString('>Deutsch</div>', $html);
        $this->assertStringNotContainsString('>de<', $html);
    }

    public function test_readonly_roles_show_labels_not_json(): void
    {
        $html = $this->render(['roles' => ['pending', 'editor']])['roles'];
        $this->assertStringContainsString('pending, editor', $html);
        $this->assertStringNotContainsString('[', $html);
        $this->assertStringNotContainsString('&quot;', $html);
    }

    public function test_readonly_relation_shows_labels(): void
    {
        $html = $this->render(['writer' => 'a1'])['writer'];
        $this->assertStringContainsString('Ann &lt;i&gt;Author&lt;/i&gt;', $html);
        $this->assertStringNotContainsString('a1', $html);
        $this->assertStringNotContainsString('folio-relation-item', $html);
    }

    public function test_readonly_richtext_is_sanitised(): void
    {
        $html = $this->render(['bio' => '<p>Hello <b>you</b></p><script>alert(1)</script><a href="/x" onclick="alert(2)">l</a><img src="/i.png" onerror="alert(3)">'])['bio'];
        $this->assertStringContainsString('<p>Hello <b>you</b></p>', $html);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('onclick', $html);
        $this->assertStringNotContainsString('onerror', $html);
    }

    public function test_readonly_plain_text_stays_escaped_and_date_is_formatted(): void
    {
        $fields = $this->render(['note' => '<b>x</b>', 'joined' => '2026-10-22T23:15:12+00:00']);
        $this->assertStringContainsString('&lt;b&gt;x&lt;/b&gt;', $fields['note']);
        $this->assertStringContainsString('22 Oct 2026, 23:15', $fields['joined']);
    }

    public function test_readonly_empty_shows_dash(): void
    {
        $fields = $this->render([]);
        foreach (['language', 'roles', 'joined', 'writer', 'bio', 'note', 'cover'] as $name) {
            $this->assertStringContainsString('>—</div>', $fields[$name], $name);
        }
    }

    public function test_readonly_asset_shows_its_name_not_a_dash(): void
    {
        $html = $this->render(['cover' => '2026/10/<b>photo</b>.png'])['cover'];
        $this->assertStringContainsString('2026/10/&lt;b&gt;photo&lt;/b&gt;.png', $html);
        $this->assertStringNotContainsString('>—</div>', $html);
    }

    public function test_readonly_boolean_without_a_value_shows_dash_and_with_a_value_yes_no(): void
    {
        foreach ([null, ''] as $empty) {
            $this->assertStringContainsString('>—</div>', $this->render(['active' => $empty])['active']);
        }
        $this->assertStringContainsString('>Yes</div>', $this->render(['active' => true])['active']);
        $this->assertStringContainsString('>No</div>', $this->render(['active' => false])['active']);
    }

    public function test_list_cell_formats_dates(): void
    {
        $this->dm->saveType(DynamicRecord::fromArray($this->registry->get('member'), ['uuid' => 'm1', 'joined' => '2026-10-22T23:15:12+00:00']), false);
        $engine = $this->engine();
        $this->controller($engine)->list((new Psr17Factory())->createServerRequest('GET', '/folio/member')->withAttribute('type', 'member'));

        $rows = json_decode($engine->context, true)['rows'];
        $this->assertSame('22 Oct 2026, 23:15', $rows[0]['cells']['joined']);
    }
}
