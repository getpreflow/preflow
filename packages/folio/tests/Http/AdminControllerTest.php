<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Http;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Container\Container;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\JsonFileDriver;
use Preflow\Data\TypeRegistry;
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
            'page',
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
            'page',
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
}
