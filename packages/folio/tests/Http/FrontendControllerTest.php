<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Http;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Exceptions\NotFoundHttpException;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\JsonFileDriver;
use Preflow\Data\DynamicRecord;
use Preflow\Data\TypeRegistry;
use Preflow\Folio\Content\FrontendResolver;
use Preflow\Folio\Content\RecordRenderer;
use Preflow\Folio\Field\FieldTypeRegistry;
use Preflow\Folio\Field\Types\StringFieldType;
use Preflow\Folio\Field\Types\TextFieldType;
use Preflow\Folio\Http\FrontendController;
use Preflow\View\TemplateEngineInterface;

final class FrontendControllerTest extends TestCase
{
    private function registry(): FieldTypeRegistry
    {
        $registry = new FieldTypeRegistry();
        $registry->register(new StringFieldType());
        $registry->register(new TextFieldType());
        return $registry;
    }

    private function engine(): TemplateEngineInterface
    {
        return new class implements TemplateEngineInterface {
            public function render(string $template, array $context = []): string
            {
                return $template . '|' . ($context['record']['title'] ?? '');
            }
            public function exists(string $template): bool { return true; }
            public function addFunction(\Preflow\View\TemplateFunctionDefinition $function): void {}
            public function addGlobal(string $name, mixed $value): void {}
            public function getTemplateExtension(): string { return 'twig'; }
            public function addNamespace(string $namespace, string $path): void {}
        };
    }

    private function dm(string $models, string $store): DataManager
    {
        mkdir($models, 0777, true);
        mkdir($store, 0777, true);
        file_put_contents($models . '/page.json', json_encode([
            'key' => 'page', 'table' => 'page', 'storage' => 'json', 'id_field' => 'uuid',
            'fields' => ['title' => ['type' => 'string'], 'slug' => ['type' => 'string'], 'body' => ['type' => 'text'], 'status' => ['type' => 'string']],
        ]));
        $registry = new TypeRegistry($models);
        $dm = new DataManager(['json' => new JsonFileDriver($store)], 'json', $registry);
        $rec = DynamicRecord::fromArray($registry->get('page'), [
            'uuid' => '1', 'title' => 'Home', 'slug' => 'home', 'body' => 'Hi', 'status' => 'published',
        ]);
        $dm->saveType($rec, validate: false);
        return $dm;
    }

    public function test_renders_published_record(): void
    {
        $base = sys_get_temp_dir() . '/folio_fc_' . bin2hex(random_bytes(4));
        $dm = $this->dm($base . '/m', $base . '/s');
        $registry = $this->registry();
        $engine = $this->engine();
        $controller = new FrontendController(new FrontendResolver($dm, 'page'), $engine, new RecordRenderer($registry, $engine));

        $req = (new Psr17Factory())->createServerRequest('GET', '/home')->withAttribute('path', 'home');
        $res = $controller->show($req);

        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame('@folio/frontend/page.twig|Home', (string) $res->getBody());
    }

    public function test_unknown_slug_throws_not_found(): void
    {
        $base = sys_get_temp_dir() . '/folio_fc_' . bin2hex(random_bytes(4));
        $dm = $this->dm($base . '/m', $base . '/s');
        $registry = $this->registry();
        $engine = $this->engine();
        $controller = new FrontendController(new FrontendResolver($dm, 'page'), $engine, new RecordRenderer($registry, $engine));

        $req = (new Psr17Factory())->createServerRequest('GET', '/missing')->withAttribute('path', 'missing');

        $this->expectException(NotFoundHttpException::class);
        $controller->show($req);
    }
}
