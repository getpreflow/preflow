<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Content;

use PHPUnit\Framework\TestCase;
use Preflow\Folio\Content\TypeCatalog;

final class TypeCatalogTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/folio_models_' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0777, true);
    }

    public function test_discovers_valid_types_with_label(): void
    {
        file_put_contents($this->dir . '/page.json', json_encode([
            'key' => 'page', 'table' => 'page', 'label' => 'Pages', 'fields' => [],
        ]));
        file_put_contents($this->dir . '/author.json', json_encode([
            'key' => 'author', 'table' => 'author', 'fields' => [],
        ]));

        $catalog = new TypeCatalog($this->dir);
        $all = $catalog->all();

        $keys = array_map(fn ($t) => $t->key, $all);
        sort($keys);
        $this->assertSame(['author', 'page'], $keys);

        $byKey = [];
        foreach ($all as $t) {
            $byKey[$t->key] = $t->label;
        }
        $this->assertSame('Pages', $byKey['page']);     // explicit label
        $this->assertSame('Author', $byKey['author']);  // derived from key (ucfirst)
    }

    public function test_ignores_malformed_json(): void
    {
        file_put_contents($this->dir . '/good.json', json_encode(['key' => 'good', 'table' => 'good', 'fields' => []]));
        file_put_contents($this->dir . '/broken.json', '{ not valid json');

        $catalog = new TypeCatalog($this->dir);

        $this->assertCount(1, $catalog->all());
        $this->assertTrue($catalog->has('good'));
        $this->assertFalse($catalog->has('broken'));
    }

    public function test_missing_dir_yields_empty(): void
    {
        $catalog = new TypeCatalog($this->dir . '/nope');
        $this->assertSame([], $catalog->all());
    }
}
