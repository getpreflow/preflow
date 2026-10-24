<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Http;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Container\Container;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\JsonFileDriver;
use Preflow\Data\TypeRegistry;
use Preflow\Folio\Content\FrontendResolver;
use Preflow\Folio\Content\TypeCatalog;
use Preflow\Folio\Content\RecordLabeler;
use Preflow\Folio\Http\AdminController;
use Preflow\Folio\Field\FieldTypeRegistry;
use Preflow\Folio\Field\Types\NumberFieldType;
use Preflow\Folio\Field\Types\StringFieldType;
use Preflow\Folio\Field\Types\TextFieldType;
use Preflow\Folio\Override\ActionResolver;
use Preflow\Validation\RuleFactory;
use Preflow\Validation\ValidatorFactory;
use Preflow\View\TemplateEngineInterface;

final class AdminControllerTest extends TestCase
{
    private string $base;
    private DataManager $dm;
    private TypeRegistry $registry;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/folio_admin_' . bin2hex(random_bytes(4));
        mkdir($this->base . '/m', 0777, true);
        mkdir($this->base . '/s', 0777, true);
        file_put_contents($this->base . '/m/page.json', json_encode([
            'key' => 'page', 'table' => 'page', 'storage' => 'json', 'id_field' => 'uuid', 'label' => 'Pages',
            'fields' => [
                'title' => ['type' => 'string', 'validate' => ['required']],
                'slug' => ['type' => 'string', 'validate' => ['required']],
                'body' => ['type' => 'text'],
                'status' => ['type' => 'string'],
            ],
        ]));
        $this->registry = new TypeRegistry($this->base . '/m');
        $this->dm = new DataManager(
            ['json' => new JsonFileDriver($this->base . '/s')],
            'json',
            $this->registry,
            new ValidatorFactory(new RuleFactory()),
        );
    }

    private function engine(): TemplateEngineInterface
    {
        return new class implements TemplateEngineInterface {
            public function render(string $template, array $context = []): string
            {
                return $template . '|' . json_encode(array_keys($context));
            }
            public function exists(string $template): bool
            {
                return true;
            }
            public function addFunction(\Preflow\View\TemplateFunctionDefinition $f): void {}
            public function addGlobal(string $n, mixed $v): void {}
            public function getTemplateExtension(): string
            {
                return 'twig';
            }
            public function addNamespace(string $n, string $p): void {}
        };
    }

    private function capturingEngine(): TemplateEngineInterface
    {
        return new class implements TemplateEngineInterface {
            public function render(string $template, array $context = []): string
            {
                return $template . '|' . json_encode($context, JSON_UNESCAPED_SLASHES);
            }
            public function exists(string $template): bool { return true; }
            public function addFunction(\Preflow\View\TemplateFunctionDefinition $f): void {}
            public function addGlobal(string $n, mixed $v): void {}
            public function getTemplateExtension(): string { return 'twig'; }
            public function addNamespace(string $n, string $p): void {}
        };
    }

    private function controllerWith(TemplateEngineInterface $engine): AdminController
    {
        return new AdminController(
            new TypeCatalog($this->base . '/m'),
            $this->registry,
            $this->dm,
            $engine,
            new ActionResolver(new Container(), 'Preflow\\Folio\\Tests\\Overrides\\'),
            $this->fieldTypeRegistry(),
            '/folio',
            new RecordLabeler(),
            new FrontendResolver($this->dm, 'page'),
        );
    }

    private function fieldTypeRegistry(): FieldTypeRegistry
    {
        $registry = new FieldTypeRegistry();
        $registry->register(new StringFieldType());
        $registry->register(new TextFieldType());
        $registry->register(new NumberFieldType());
        return $registry;
    }

    private function controller(): AdminController
    {
        return new AdminController(
            new TypeCatalog($this->base . '/m'),
            $this->registry,
            $this->dm,
            $this->engine(),
            new ActionResolver(new Container(), 'Preflow\\Folio\\Tests\\Overrides\\'),
            $this->fieldTypeRegistry(),
            '/folio',
            new RecordLabeler(),
            new FrontendResolver($this->dm, 'page'),
        );
    }

    public function test_index_renders_dashboard(): void
    {
        $req = (new Psr17Factory())->createServerRequest('GET', '/folio');
        $res = $this->controller()->index($req);
        $this->assertSame(200, $res->getStatusCode());
        $this->assertStringContainsString('@folio/admin/dashboard.twig', (string) $res->getBody());
    }

    public function test_store_creates_record_then_redirects(): void
    {
        $req = (new Psr17Factory())->createServerRequest('POST', '/folio/page')
            ->withAttribute('type', 'page')
            ->withParsedBody(['title' => 'Hello', 'slug' => 'hello', 'body' => 'B', 'status' => 'published']);

        $res = $this->controller()->store($req);

        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame('/folio/page', $res->getHeaderLine('Location'));

        $rows = $this->dm->queryType('page')->where('slug', 'hello')->first();
        $this->assertNotNull($rows);
        $this->assertSame('Hello', $rows->get('title'));
    }

    public function test_unknown_type_404(): void
    {
        $req = (new Psr17Factory())->createServerRequest('GET', '/folio/ghost')->withAttribute('type', 'ghost');
        $res = $this->controller()->list($req);
        $this->assertSame(404, $res->getStatusCode());
    }

    public function test_update_missing_record_returns_404(): void
    {
        $req = (new Psr17Factory())->createServerRequest('POST', '/folio/page/does-not-exist')
            ->withAttribute('type', 'page')
            ->withAttribute('id', 'does-not-exist')
            ->withParsedBody(['title' => 'Ghost', 'slug' => 'ghost', 'body' => '', 'status' => 'draft']);

        $res = $this->controller()->update($req);

        $this->assertSame(404, $res->getStatusCode());
    }

    public function test_destroy_missing_record_returns_404(): void
    {
        $req = (new Psr17Factory())->createServerRequest('POST', '/folio/page/does-not-exist')
            ->withAttribute('type', 'page')
            ->withAttribute('id', 'does-not-exist');

        $res = $this->controller()->destroy($req);

        $this->assertSame(404, $res->getStatusCode());
    }

    public function test_record_label_returns_json(): void
    {
        $this->controller()->store((new Psr17Factory())->createServerRequest('POST', '/folio/page')
            ->withAttribute('type', 'page')
            ->withParsedBody(['title' => 'Hello', 'slug' => 'hello', 'body' => 'B', 'status' => 'published']));
        $id = $this->dm->queryType('page')->where('slug', 'hello')->first()->getId();

        $req = (new Psr17Factory())->createServerRequest('GET', '/folio/page/' . $id . '/label')
            ->withAttribute('type', 'page')->withAttribute('id', $id);
        $res = $this->controller()->recordLabel($req);

        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame('application/json; charset=UTF-8', $res->getHeaderLine('Content-Type'));
        $data = json_decode((string) $res->getBody(), true);
        $this->assertSame($id, $data['id']);
        $this->assertSame('Hello', $data['label']);
    }

    public function test_record_label_unknown_type_404(): void
    {
        $req = (new Psr17Factory())->createServerRequest('GET', '/folio/ghost/x/label')
            ->withAttribute('type', 'ghost')->withAttribute('id', 'x');
        $this->assertSame(404, $this->controller()->recordLabel($req)->getStatusCode());
    }

    public function test_record_label_missing_record_404(): void
    {
        $req = (new Psr17Factory())->createServerRequest('GET', '/folio/page/nope/label')
            ->withAttribute('type', 'page')->withAttribute('id', 'nope');
        $this->assertSame(404, $this->controller()->recordLabel($req)->getStatusCode());
    }

    public function test_create_form_drawer_uses_bare_layout_and_keeps_flag(): void
    {
        $req = (new Psr17Factory())->createServerRequest('GET', '/folio/page/new?_drawer=1')
            ->withAttribute('type', 'page');
        $res = $this->controllerWith($this->capturingEngine())->createForm($req);
        $body = (string) $res->getBody();

        $this->assertSame(200, $res->getStatusCode());
        $this->assertStringContainsString('@folio/admin/form.twig', $body);
        $this->assertStringContainsString('"layout":"@folio/admin/_drawer_layout.twig"', $body);
        $this->assertStringContainsString('"action":"/folio/page?_drawer=1"', $body);
    }

    public function test_create_form_normal_uses_full_layout(): void
    {
        $req = (new Psr17Factory())->createServerRequest('GET', '/folio/page/new')
            ->withAttribute('type', 'page');
        $body = (string) $this->controllerWith($this->capturingEngine())->createForm($req)->getBody();

        $this->assertStringContainsString('"layout":"@folio/admin/_layout.twig"', $body);
        $this->assertStringContainsString('"action":"/folio/page"', $body);
    }

    public function test_create_form_prefills_declared_defaults(): void
    {
        file_put_contents($this->base . '/m/note.json', json_encode([
            'key' => 'note', 'table' => 'note', 'storage' => 'json', 'id_field' => 'uuid', 'label' => 'Notes',
            'fields' => ['title' => ['type' => 'string', 'default' => 'Untitled'], 'body' => ['type' => 'text']],
        ]));
        $this->registry = new TypeRegistry($this->base . '/m');
        $this->dm = new DataManager(['json' => new JsonFileDriver($this->base . '/s')], 'json', $this->registry, new ValidatorFactory(new RuleFactory()));
        $req = (new Psr17Factory())->createServerRequest('GET', '/folio/note/new')->withAttribute('type', 'note');
        $body = (string) $this->controllerWith($this->capturingEngine())->createForm($req)->getBody();

        $this->assertStringContainsString('value=\\"Untitled\\"', $body);
    }

    public function test_store_drawer_returns_postmessage_page_not_redirect(): void
    {
        $req = (new Psr17Factory())->createServerRequest('POST', '/folio/page?_drawer=1')
            ->withAttribute('type', 'page')
            ->withParsedBody(['title' => 'Hi', 'slug' => 'hi', 'body' => 'B', 'status' => 'published']);
        $res = $this->controllerWith($this->capturingEngine())->store($req);
        $body = (string) $res->getBody();

        $this->assertSame(200, $res->getStatusCode());
        $this->assertStringContainsString('@folio/admin/drawer_saved.twig', $body);
        $this->assertStringContainsString('"type":"page"', $body);
        $id = $this->dm->queryType('page')->where('slug', 'hi')->first()->getId();
        $this->assertStringContainsString('"id":"' . $id . '"', $body);
    }

    public function test_store_drawer_validation_error_stays_bare_422(): void
    {
        $req = (new Psr17Factory())->createServerRequest('POST', '/folio/page?_drawer=1')
            ->withAttribute('type', 'page')
            ->withParsedBody(['title' => '', 'slug' => 'x', 'body' => 'b', 'status' => 'draft']);
        $res = $this->controllerWith($this->capturingEngine())->store($req);
        $body = (string) $res->getBody();

        $this->assertSame(422, $res->getStatusCode());
        $this->assertStringContainsString('"layout":"@folio/admin/_drawer_layout.twig"', $body);
        $this->assertStringContainsString('"action":"/folio/page?_drawer=1"', $body);
    }
    /** An "account" type with a hidden and a read-only field (written only for the tests using it). */
    private function accountType(): void
    {
        file_put_contents($this->base . '/m/account.json', json_encode([
            'key' => 'account', 'table' => 'account', 'storage' => 'json', 'id_field' => 'uuid', 'label' => 'Accounts',
            'fields' => [
                'email' => ['type' => 'string'],
                'secret' => ['type' => 'string', 'hidden' => true],
                'uses' => ['type' => 'string', 'label' => 'Times used', 'readonly' => true],
                'note' => ['type' => 'string'],
            ],
        ]));
        $this->registry = new TypeRegistry($this->base . '/m');
        $this->dm = new DataManager(['json' => new JsonFileDriver($this->base . '/s')], 'json', $this->registry, new ValidatorFactory(new RuleFactory()));
        $this->dm->saveType(\Preflow\Data\DynamicRecord::fromArray($this->registry->get('account'), [
            'uuid' => 'a1', 'email' => 'a@site.test', 'secret' => 's3cret', 'uses' => '<b>5</b>', 'note' => 'n',
        ]), false);
    }

    public function test_hidden_field_not_rendered_and_kept(): void
    {
        $this->accountType();
        $f = new Psr17Factory();
        $body = (string) $this->controllerWith($this->capturingEngine())->editForm(
            $f->createServerRequest('GET', '/folio/account/a1/edit')->withAttribute('type', 'account')->withAttribute('id', 'a1'),
        )->getBody();
        $this->assertStringContainsString('name=\\"email\\"', $body);
        $this->assertStringNotContainsString('name=\\"secret\\"', $body);
        $this->assertStringNotContainsString('s3cret', $body);

        // A posted value is ignored: the stored one stays.
        $res = $this->controller()->update($f->createServerRequest('POST', '/folio/account/a1')->withAttribute('type', 'account')->withAttribute('id', 'a1')
            ->withParsedBody(['email' => 'b@site.test', 'secret' => 'typed', 'note' => 'n']));
        $this->assertSame(302, $res->getStatusCode());
        $stored = $this->dm->findType('account', 'a1');
        $this->assertSame('b@site.test', $stored?->get('email'));
        $this->assertSame('s3cret', $stored?->get('secret'));

        // On create it starts empty.
        $this->controller()->store($f->createServerRequest('POST', '/folio/account')->withAttribute('type', 'account')
            ->withParsedBody(['email' => 'new@site.test', 'secret' => 'typed', 'uses' => '9']));
        $created = $this->dm->queryType('account')->where('email', 'new@site.test')->first();
        $this->assertNotNull($created);
        $this->assertNull($created->get('secret'));
        $this->assertNull($created->get('uses'));
    }

    public function test_readonly_field_shown_as_text_and_kept(): void
    {
        $this->accountType();
        $f = new Psr17Factory();
        $body = (string) $this->controllerWith($this->capturingEngine())->editForm(
            $f->createServerRequest('GET', '/folio/account/a1/edit')->withAttribute('type', 'account')->withAttribute('id', 'a1'),
        )->getBody();
        $this->assertStringNotContainsString('name=\\"uses\\"', $body);
        $this->assertStringContainsString('Times used', $body);
        $this->assertStringContainsString('&lt;b&gt;5&lt;/b&gt;', $body); // escaped plain text
        $this->assertStringNotContainsString('<b>5', $body);

        $this->controller()->update($f->createServerRequest('POST', '/folio/account/a1')->withAttribute('type', 'account')->withAttribute('id', 'a1')
            ->withParsedBody(['email' => 'a@site.test', 'uses' => '99', 'note' => 'n2']));
        $stored = $this->dm->findType('account', 'a1');
        $this->assertSame('<b>5</b>', $stored?->get('uses'));
        $this->assertSame('n2', $stored?->get('note'));
    }

    public function test_hidden_field_not_in_auto_list_columns(): void
    {
        $this->accountType();
        $body = (string) $this->controllerWith($this->capturingEngine())->list(
            (new Psr17Factory())->createServerRequest('GET', '/folio/account')->withAttribute('type', 'account'),
        )->getBody();

        $this->assertStringContainsString('"columns":{"email":"Email","uses":"Times used","note":"Note"}', $body);
        $this->assertStringNotContainsString('s3cret', $body);
    }

    public function test_list_renders_fifty_rows_and_a_pager(): void
    {
        for ($i = 1; $i <= 51; $i++) {
            $this->dm->saveType(\Preflow\Data\DynamicRecord::fromArray($this->registry->get('page'), ['uuid' => sprintf('p%02d', $i), 'title' => "T{$i}", 'slug' => "s{$i}"]), validate: false);
        }

        $body = (string) $this->controllerWith($this->capturingEngine())->list(
            (new Psr17Factory())->createServerRequest('GET', '/folio/page')->withAttribute('type', 'page'),
        )->getBody();

        $this->assertStringStartsWith('@folio/admin/list.twig|', $body);
        $context = json_decode(substr($body, strlen('@folio/admin/list.twig|')), true);
        $this->assertCount(50, $context['rows']);
        $this->assertSame(51, $context['list']['total']);
        $this->assertSame(2, $context['list']['last_page']);
        $this->assertSame('/folio/page?page=2', $context['list']['next']);
    }

    public function test_list_search_narrows(): void
    {
        foreach (['Alpha' => 'a', 'Beta' => 'b'] as $title => $slug) {
            $this->dm->saveType(\Preflow\Data\DynamicRecord::fromArray($this->registry->get('page'), ['uuid' => $slug, 'title' => $title, 'slug' => $slug]), validate: false);
        }

        $body = (string) $this->controllerWith($this->capturingEngine())->list(
            (new Psr17Factory())->createServerRequest('GET', '/folio/page?q=alp')->withQueryParams(['q' => 'alp'])->withAttribute('type', 'page'),
        )->getBody();

        $context = json_decode(substr($body, strlen('@folio/admin/list.twig|')), true);
        $this->assertSame(['a'], array_column($context['rows'], 'id'));
        $this->assertSame('Alpha', $context['rows'][0]['cells']['title']);
        $this->assertSame('<mark>Alp</mark>ha', $context['rows'][0]['marked']['title']);
    }

    public function test_list_partial_renders_only_the_results_template(): void
    {
        $body = (string) $this->controllerWith($this->capturingEngine())->list(
            (new Psr17Factory())->createServerRequest('GET', '/folio/page?_partial=1')->withQueryParams(['_partial' => '1'])->withAttribute('type', 'page'),
        )->getBody();

        $this->assertStringStartsWith('@folio/admin/_list_results.twig|', $body);
    }

    public function test_partial_render_does_not_load_relation_filter_options(): void
    {
        file_put_contents($this->base . '/m/tag.json', json_encode([
            'key' => 'tag', 'table' => 'tag', 'storage' => 'json', 'id_field' => 'uuid', 'fields' => ['name' => ['type' => 'string']],
        ]));
        file_put_contents($this->base . '/m/post.json', json_encode([
            'key' => 'post', 'table' => 'post', 'storage' => 'json', 'id_field' => 'uuid',
            'fields' => ['title' => ['type' => 'string'], 'tag' => ['type' => 'relation', 'relation' => ['to' => 'tag']]],
            'list' => ['filters' => ['tag']],
        ]));
        $calls = new \ArrayObject();
        $inner = new JsonFileDriver($this->base . '/s');
        $driver = new class ($inner, $calls) implements \Preflow\Data\StorageDriver {
            public function __construct(private \Preflow\Data\StorageDriver $inner, private \ArrayObject $calls) {}
            public function findOne(string $type, string|int $id, string $idField = 'uuid'): ?array { return $this->inner->findOne($type, $id, $idField); }
            public function findMany(string $type, \Preflow\Data\Query $query): \Preflow\Data\ResultSet
            {
                $this->calls[$type] = ($this->calls[$type] ?? 0) + 1;

                return $this->inner->findMany($type, $query);
            }
            public function save(string $type, string|int $id, array $data, string $idField = 'uuid'): void { $this->inner->save($type, $id, $data, $idField); }
            public function delete(string $type, string|int $id, string $idField = 'uuid'): void { $this->inner->delete($type, $id, $idField); }
            public function exists(string $type, string|int $id, string $idField = 'uuid'): bool { return $this->inner->exists($type, $id, $idField); }
            public function lastInsertId(): string|int { return $this->inner->lastInsertId(); }
            public function rawQuery(string $sql, array $bindings = []): array { return $this->inner->rawQuery($sql, $bindings); }
        };
        $this->registry = new TypeRegistry($this->base . '/m');
        $this->dm = new DataManager(['json' => $driver], 'json', $this->registry, new ValidatorFactory(new RuleFactory()));
        $this->dm->saveType(\Preflow\Data\DynamicRecord::fromArray($this->registry->get('post'), ['uuid' => 'p1', 'title' => 'Hello']), validate: false);
        $request = static fn (array $q) => (new Psr17Factory())->createServerRequest('GET', '/folio/post')->withQueryParams($q)->withAttribute('type', 'post');

        $calls->exchangeArray([]);
        $this->controllerWith($this->capturingEngine())->list($request(['sort' => 'title', '_partial' => '1'])); // no q: search itself looks into relation targets
        $this->assertArrayNotHasKey('tag', $calls->getArrayCopy());

        $calls->exchangeArray([]);
        $this->controllerWith($this->capturingEngine())->list($request(['sort' => 'title']));
        $this->assertSame(1, $calls['tag'] ?? 0); // the full page lists the filter's options
    }

    public function test_list_cells_stay_plain_text_and_marked_html_is_separate(): void
    {
        $this->dm->saveType(\Preflow\Data\DynamicRecord::fromArray($this->registry->get('page'), ['uuid' => 'a', 'title' => 'Tom & Jerry', 'slug' => 'a']), validate: false);

        $body = (string) $this->controllerWith($this->capturingEngine())->list(
            (new Psr17Factory())->createServerRequest('GET', '/folio/page')->withQueryParams(['q' => 'jerry'])->withAttribute('type', 'page'),
        )->getBody();

        $context = json_decode(substr($body, strlen('@folio/admin/list.twig|')), true);
        $this->assertSame('Tom & Jerry', $context['rows'][0]['cells']['title']);
        $this->assertSame('Tom &amp; <mark>Jerry</mark>', $context['rows'][0]['marked']['title']);
    }

    private function relationController(): AdminController
    {
        $fieldTypes = $this->fieldTypeRegistry();
        $fieldTypes->register(new \Preflow\Folio\Field\Types\RelationFieldType($this->dm, $this->registry, prefix: '/folio'));

        return new AdminController(
            new TypeCatalog($this->base . '/m'),
            $this->registry,
            $this->dm,
            $this->engine(),
            new ActionResolver(new Container(), 'Preflow\\Folio\\Tests\\Overrides\\'),
            $fieldTypes,
            '/folio',
            new RecordLabeler(),
            new FrontendResolver($this->dm, 'page'),
        );
    }

    private function seedPages(int $n): void
    {
        for ($i = 1; $i <= $n; $i++) {
            $this->dm->saveType(\Preflow\Data\DynamicRecord::fromArray($this->registry->get('page'), [
                'uuid' => 'p' . $i, 'title' => sprintf('Game %03d', $i), 'slug' => 's' . $i,
            ]));
        }
    }

    private function relationRequest(string $type, string $query = ''): \Psr\Http\Message\ServerRequestInterface
    {
        return (new Psr17Factory())->createServerRequest('GET', '/folio/_relation/' . $type . ($query !== '' ? '?' . $query : ''))
            ->withAttribute('type', $type)
            ->withQueryParams($query === '' ? [] : (function () use ($query) { parse_str($query, $q); return $q; })());
    }

    public function test_relation_options_returns_the_first_page_as_json(): void
    {
        $this->seedPages(45);
        $res = $this->relationController()->relationOptions($this->relationRequest('page'));
        $this->assertSame(200, $res->getStatusCode());
        $this->assertStringStartsWith('application/json', $res->getHeaderLine('Content-Type'));
        $data = json_decode((string) $res->getBody(), true);
        $this->assertSame(['items', 'more'], array_keys($data));
        $this->assertCount(20, $data['items']);
        $this->assertTrue($data['more']);
        $this->assertSame('Game 001', $data['items'][0]['label']);
        $this->assertSame('p1', $data['items'][0]['id']);
    }

    public function test_relation_options_searches_and_pages(): void
    {
        $this->seedPages(45);
        $c = $this->relationController();
        $hit = json_decode((string) $c->relationOptions($this->relationRequest('page', 'q=game+04'))->getBody(), true);
        $this->assertCount(7, $hit['items']);
        $this->assertFalse($hit['more']);
        $p3 = json_decode((string) $c->relationOptions($this->relationRequest('page', 'page=3'))->getBody(), true);
        $this->assertCount(5, $p3['items']);
        $this->assertFalse($p3['more']);
    }

    public function test_relation_options_clamps_a_crafted_page(): void
    {
        $this->seedPages(3);
        $c = $this->relationController();
        foreach (['page=-5', 'page=99999999999999999999', 'page=abc', 'page[]=1'] as $q) {
            $res = $c->relationOptions($this->relationRequest('page', $q));
            $this->assertSame(200, $res->getStatusCode(), $q);
        }
        $first = json_decode((string) $c->relationOptions($this->relationRequest('page', 'page=-5'))->getBody(), true);
        $this->assertCount(3, $first['items']);
    }

    public function test_relation_options_unknown_type_404(): void
    {
        $this->assertSame(404, $this->relationController()->relationOptions($this->relationRequest('ghost'))->getStatusCode());
    }

    public function test_relation_options_ignores_an_unknown_label_field(): void
    {
        $this->seedPages(2);
        $res = $this->relationController()->relationOptions($this->relationRequest('page', 'label=nope&order=nope'));
        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame('Game 001', json_decode((string) $res->getBody(), true)['items'][0]['label']);
    }

    /**
     * Pages plus "tag" (pages: multiple relation, detach) and "lock" (page: restrict).
     * Registry and data manager are rebuilt so they see the extra models.
     */
    private function relatedWorld(): void
    {
        file_put_contents($this->base . '/m/tag.json', json_encode([
            'key' => 'tag', 'table' => 'tag', 'storage' => 'json', 'id_field' => 'uuid', 'label' => 'Tags',
            'fields' => ['pages' => ['type' => 'relation', 'relation' => ['to' => 'page', 'multiple' => true]]],
        ]));
        file_put_contents($this->base . '/m/lock.json', json_encode([
            'key' => 'lock', 'table' => 'lock', 'storage' => 'json', 'id_field' => 'uuid', 'label' => 'Locks',
            'fields' => ['page' => ['type' => 'relation', 'relation' => ['to' => 'page', 'onDelete' => 'restrict']]],
        ]));
        file_put_contents($this->base . '/m/site.json', json_encode([
            'key' => 'site', 'table' => 'site', 'storage' => 'json', 'id_field' => 'uuid', 'label' => 'Site', 'singleton' => true,
            'fields' => ['tagline' => ['type' => 'string']],
        ]));
        $this->registry = new TypeRegistry($this->base . '/m');
        $this->dm = new DataManager(
            ['json' => new JsonFileDriver($this->base . '/s')],
            'json',
            $this->registry,
            new ValidatorFactory(new RuleFactory()),
        );
        $this->seedPages(2);
        $this->dm->saveType(\Preflow\Data\DynamicRecord::fromArray($this->registry->get('tag'), ['uuid' => 't1', 'pages' => '["p1","p2"]']), false);
        $this->dm->saveType(\Preflow\Data\DynamicRecord::fromArray($this->registry->get('tag'), ['uuid' => 't2', 'pages' => '["p1"]']), false);
    }

    /** A session that records flashes. */
    private function session(): \Preflow\Core\Http\Session\SessionInterface
    {
        return new class implements \Preflow\Core\Http\Session\SessionInterface {
            /** @var array<string, mixed> */
            public array $flashes = [];
            public function start(): void {}
            public function getId(): string { return 'test'; }
            public function regenerate(): void {}
            public function invalidate(): void {}
            public function get(string $key, mixed $default = null): mixed { return $default; }
            public function set(string $key, mixed $value): void {}
            public function has(string $key): bool { return false; }
            public function remove(string $key): void {}
            public function flash(string $key, mixed $value): void { $this->flashes[$key] = $value; }
            public function getFlash(string $key, mixed $default = null): mixed { return $this->flashes[$key] ?? $default; }
            public function isStarted(): bool { return true; }
            public function ageFlash(): void {}
            public function close(): void {}
        };
    }

    private function destroyRequest(string $type, string $id, ?\Preflow\Core\Http\Session\SessionInterface $session = null): \Psr\Http\Message\ServerRequestInterface
    {
        $req = (new Psr17Factory())->createServerRequest('POST', "/folio/{$type}/{$id}/delete")
            ->withAttribute('type', $type)
            ->withAttribute('id', $id);

        return $session === null ? $req : $req->withAttribute(\Preflow\Core\Http\Session\SessionInterface::class, $session);
    }

    /** @param list<string>|string|null $ids */
    private function planRequest(string $type, array|string|null $ids): \Psr\Http\Message\ServerRequestInterface
    {
        return (new Psr17Factory())->createServerRequest('POST', "/folio/{$type}/_delete-plan")
            ->withAttribute('type', $type)
            ->withParsedBody($ids === null ? [] : ['ids' => $ids]);
    }

    public function test_destroy_deletes_detaches_and_redirects_to_the_list(): void
    {
        $this->relatedWorld();
        $session = $this->session();

        $res = $this->controller()->destroy($this->destroyRequest('page', 'p1', $session));

        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame('/folio/page', $res->getHeaderLine('Location'));
        $this->assertNull($this->dm->findType('page', 'p1'));
        $this->assertSame('["p2"]', $this->dm->findType('tag', 't1')?->get('pages'));
        $this->assertSame('[]', $this->dm->findType('tag', 't2')?->get('pages'));
        $this->assertSame([], $session->flashes);
    }

    public function test_destroy_of_a_restricted_record_flashes_the_reason_and_keeps_it(): void
    {
        $this->relatedWorld();
        $this->dm->saveType(\Preflow\Data\DynamicRecord::fromArray($this->registry->get('lock'), ['uuid' => 'l1', 'page' => 'p1']), false);
        $session = $this->session();

        $res = $this->controller()->destroy($this->destroyRequest('page', 'p1', $session));

        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame('/folio/page', $res->getHeaderLine('Location'));
        $this->assertSame("Can't delete: 1 lock still points here.", $session->flashes['error'] ?? null);
        $this->assertNotNull($this->dm->findType('page', 'p1'));
        $this->assertSame('["p1","p2"]', $this->dm->findType('tag', 't1')?->get('pages'));
    }

    public function test_delete_plan_returns_the_confirmation_as_json(): void
    {
        $this->relatedWorld();

        $res = $this->controller()->deletePlan($this->planRequest('page', ['p1']));

        $this->assertSame(200, $res->getStatusCode());
        $this->assertStringStartsWith('application/json', $res->getHeaderLine('Content-Type'));
        $this->assertSame([
            'restricted' => false,
            'lines' => ['2 tags lose a link.'],
            'confirm' => "This can't be undone.\n2 tags lose a link.",
        ], json_decode((string) $res->getBody(), true));
        $this->assertNotNull($this->dm->findType('page', 'p1')); // only a summary
    }

    public function test_delete_plan_of_a_restricted_record(): void
    {
        $this->relatedWorld();
        $this->dm->saveType(\Preflow\Data\DynamicRecord::fromArray($this->registry->get('lock'), ['uuid' => 'l1', 'page' => 'p2']), false);
        $this->dm->saveType(\Preflow\Data\DynamicRecord::fromArray($this->registry->get('lock'), ['uuid' => 'l2', 'page' => 'p2']), false);

        $data = json_decode((string) $this->controller()->deletePlan($this->planRequest('page', ['p1', 'p2']))->getBody(), true);

        $this->assertTrue($data['restricted']);
        $this->assertSame(["Can't delete: 2 locks still point here."], $data['lines']);
        $this->assertSame("Can't delete: 2 locks still point here.", $data['confirm']);
    }

    public function test_delete_plan_refuses_unknown_types_singletons_and_missing_ids(): void
    {
        $this->relatedWorld();

        $this->assertSame(404, $this->controller()->deletePlan($this->planRequest('ghost', ['x']))->getStatusCode());
        $this->assertSame(404, $this->controller()->deletePlan($this->planRequest('site', ['site']))->getStatusCode());
        $this->assertSame(422, $this->controller()->deletePlan($this->planRequest('page', null))->getStatusCode());
        $this->assertSame(422, $this->controller()->deletePlan($this->planRequest('page', 'p1'))->getStatusCode());
        $this->assertSame(422, $this->controller()->deletePlan($this->planRequest('page', [['nested']]))->getStatusCode());
    }
}
