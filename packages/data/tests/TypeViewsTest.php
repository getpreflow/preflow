<?php

declare(strict_types=1);

namespace Preflow\Data\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Data\TypeRegistry;

final class TypeViewsTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/typeviews_' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0777, true);
        file_put_contents($this->dir . '/note.json', json_encode([
            'key' => 'note', 'table' => 'note', 'storage' => 'json', 'id_field' => 'uuid',
            'views' => ['card', 'inline', 42], // non-strings filtered out
            'fields' => ['name' => ['type' => 'string']],
        ]));
        file_put_contents($this->dir . '/page.json', json_encode([
            'key' => 'page', 'table' => 'page', 'storage' => 'json', 'id_field' => 'uuid',
            'fields' => ['title' => ['type' => 'string']],
        ]));
    }

    protected function tearDown(): void
    {
        @unlink($this->dir . '/note.json');
        @unlink($this->dir . '/page.json');
        @rmdir($this->dir);
    }

    public function test_views_default_empty_and_read_filtered_to_strings(): void
    {
        $reg = new TypeRegistry($this->dir);
        $this->assertSame(['card', 'inline'], $reg->get('note')->views);
        $this->assertSame([], $reg->get('page')->views);
    }
}
