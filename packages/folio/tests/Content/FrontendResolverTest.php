<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Content;

use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\JsonFileDriver;
use Preflow\Data\DynamicRecord;
use Preflow\Data\TypeRegistry;
use Preflow\Folio\Content\FrontendResolver;

final class FrontendResolverTest extends TestCase
{
    private string $models;
    private string $store;
    private DataManager $dm;

    protected function setUp(): void
    {
        $this->models = sys_get_temp_dir() . '/folio_fr_models_' . bin2hex(random_bytes(4));
        $this->store = sys_get_temp_dir() . '/folio_fr_store_' . bin2hex(random_bytes(4));
        mkdir($this->models, 0777, true);
        mkdir($this->store, 0777, true);

        file_put_contents($this->models . '/page.json', json_encode([
            'key' => 'page',
            'table' => 'page',
            'storage' => 'json',
            'id_field' => 'uuid',
            'fields' => [
                'title' => ['type' => 'string'],
                'slug' => ['type' => 'string', 'searchable' => true],
                'body' => ['type' => 'text'],
                'status' => ['type' => 'string'],
            ],
        ]));

        $registry = new TypeRegistry($this->models);
        $this->dm = new DataManager(
            drivers: ['json' => new JsonFileDriver($this->store)],
            defaultDriver: 'json',
            typeRegistry: $registry,
        );

        $this->seed('1', 'home', 'published');
        $this->seed('2', 'draft-page', 'draft');
    }

    private function seed(string $id, string $slug, string $status): void
    {
        $registry = new TypeRegistry($this->models);
        $rec = DynamicRecord::fromArray($registry->get('page'), [
            'uuid' => $id, 'title' => ucfirst($slug), 'slug' => $slug, 'body' => 'x', 'status' => $status,
        ]);
        $this->dm->saveType($rec, validate: false);
    }

    public function test_resolves_published_by_slug(): void
    {
        $resolver = new FrontendResolver($this->dm, 'page');
        $rec = $resolver->resolve('/home');

        $this->assertNotNull($rec);
        $this->assertSame('home', $rec->get('slug'));
    }

    public function test_unknown_slug_is_null(): void
    {
        $resolver = new FrontendResolver($this->dm, 'page');
        $this->assertNull($resolver->resolve('/missing'));
    }

    public function test_draft_is_not_resolvable(): void
    {
        $resolver = new FrontendResolver($this->dm, 'page');
        $this->assertNull($resolver->resolve('/draft-page'));
    }
}
