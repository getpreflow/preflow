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
use Preflow\Folio\Field\Types\DateFieldType;
use Preflow\Folio\Field\Types\StringFieldType;
use Preflow\Folio\Http\AdminController;
use Preflow\Folio\Override\ActionResolver;
use Preflow\Validation\RuleFactory;
use Preflow\Validation\ValidatorFactory;
use Preflow\View\TemplateEngineInterface;

/** Read-only fields are neither validated nor written from input on admin save. */
final class ReadonlyFieldSaveTest extends TestCase
{
    private const ATOM = '2026-10-22T23:15:12+00:00';

    private string $base;
    private DataManager $dm;
    private TypeRegistry $registry;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/folio_rosave_' . bin2hex(random_bytes(4));
        mkdir($this->base . '/m', 0777, true);
        mkdir($this->base . '/s', 0777, true);
        file_put_contents($this->base . '/m/member.json', json_encode([
            'key' => 'member', 'table' => 'member', 'storage' => 'json', 'id_field' => 'uuid', 'label' => 'Members',
            'fields' => [
                'name' => ['type' => 'string', 'validate' => ['required']],
                'joined' => ['type' => 'date', 'readonly' => true, 'time' => true],
                'note' => ['type' => 'string', 'readonly' => true],
                'ref' => ['type' => 'string', 'readonly' => true, 'validate' => ['required']],
            ],
        ]));
        $this->registry = new TypeRegistry($this->base . '/m');
        $this->dm = new DataManager(['json' => new JsonFileDriver($this->base . '/s')], 'json', $this->registry, new ValidatorFactory(new RuleFactory()));
    }

    private function controller(): AdminController
    {
        $fields = new FieldTypeRegistry();
        $fields->register(new StringFieldType());
        $fields->register(new DateFieldType());
        $engine = new class implements TemplateEngineInterface {
            public function render(string $template, array $context = []): string { return $template; }
            public function exists(string $template): bool { return true; }
            public function addFunction(\Preflow\View\TemplateFunctionDefinition $f): void {}
            public function addGlobal(string $n, mixed $v): void {}
            public function getTemplateExtension(): string { return 'twig'; }
            public function addNamespace(string $n, string $p): void {}
        };

        return new AdminController(
            new TypeCatalog($this->base . '/m'), $this->registry, $this->dm, $engine,
            new ActionResolver(new Container(), 'Preflow\\Folio\\Tests\\Overrides\\'),
            $fields, '/folio', new RecordLabeler(), new FrontendResolver($this->dm, 'page'),
        );
    }

    /** @param array<string, mixed> $stored @param array<string, mixed> $post */
    private function update(array $stored, array $post): \Psr\Http\Message\ResponseInterface
    {
        $this->dm->saveType(DynamicRecord::fromArray($this->registry->get('member'), ['uuid' => 'm1'] + $stored), false);
        $req = (new Psr17Factory())->createServerRequest('POST', '/folio/member/m1')
            ->withAttribute('type', 'member')->withAttribute('id', 'm1')->withParsedBody($post);

        return $this->controller()->update($req);
    }

    private function stored(string $field): mixed
    {
        return $this->dm->findType('member', 'm1')?->get($field);
    }

    public function test_readonly_date_with_atom_value_does_not_block_save(): void
    {
        $res = $this->update(['name' => 'Old', 'joined' => self::ATOM, 'ref' => 'r'], ['name' => 'New']);

        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame('New', $this->stored('name'));
        $this->assertSame(self::ATOM, $this->stored('joined'));
    }

    public function test_readonly_field_cannot_be_overwritten_by_post(): void
    {
        $res = $this->update(['name' => 'Old', 'note' => 'kept', 'joined' => self::ATOM, 'ref' => 'r'], [
            'name' => 'New', 'note' => 'hacked', 'joined' => '2000-01-01T00:00', 'ref' => 'x',
        ]);

        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame('kept', $this->stored('note'));
        $this->assertSame(self::ATOM, $this->stored('joined'));
        $this->assertSame('r', $this->stored('ref'));
    }

    public function test_readonly_required_field_with_empty_value_does_not_block_save(): void
    {
        $res = $this->update(['name' => 'Old'], ['name' => 'New']);

        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame('New', $this->stored('name'));
    }

    public function test_editable_required_field_is_still_validated(): void
    {
        $res = $this->update(['name' => 'Old', 'ref' => 'r'], ['name' => '']);

        $this->assertSame(422, $res->getStatusCode());
        $this->assertSame('Old', $this->stored('name'));
    }

    public function test_store_ignores_posted_readonly_values(): void
    {
        $req = (new Psr17Factory())->createServerRequest('POST', '/folio/member')
            ->withAttribute('type', 'member')->withParsedBody(['name' => 'N', 'note' => 'hacked', 'ref' => 'x']);
        $res = $this->controller()->store($req);

        $this->assertSame(302, $res->getStatusCode());
        $rows = $this->dm->queryType('member')->get()->items();
        $this->assertCount(1, $rows);
        $this->assertNull(array_values($rows)[0]->get('note'));
    }

    public function test_date_with_time_accepts_seconds_from_the_input(): void
    {
        $rules = (new DateFieldType())->rules(['time' => true]);

        $this->assertSame(1, preg_match(substr($rules[0], 6), '2026-10-22T23:15:12'));
        $this->assertSame(1, preg_match(substr($rules[0], 6), '2026-10-22T23:15'));
        $this->assertSame(0, preg_match(substr($rules[0], 6), self::ATOM));
    }
}
