<?php

declare(strict_types=1);

namespace Preflow\Data\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Data\TypeRegistry;

final class TypeFieldConfigTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/typecfg_' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0777, true);
        file_put_contents($this->dir . '/post.json', json_encode([
            'key' => 'post', 'table' => 'post', 'storage' => 'json', 'id_field' => 'uuid',
            'fields' => [
                'title' => ['type' => 'string', 'validate' => ['required'], 'label' => 'Headline', 'help' => 'Shown in lists'],
                'author' => ['type' => 'relation', 'relation' => ['to' => 'user', 'multiple' => false]],
                'plain' => ['type' => 'string'],
            ],
        ]));
    }

    protected function tearDown(): void
    {
        @unlink($this->dir . '/post.json');
        @rmdir($this->dir);
    }

    public function test_parses_label_help_and_config_bag(): void
    {
        $def = (new TypeRegistry($this->dir))->get('post');

        $title = $def->fields['title'];
        $this->assertSame('Headline', $title->label);
        $this->assertSame('Shown in lists', $title->help);
        $this->assertSame([], $title->config); // no non-reserved keys

        $author = $def->fields['author'];
        $this->assertSame(['relation' => ['to' => 'user', 'multiple' => false]], $author->config);

        $plain = $def->fields['plain'];
        $this->assertNull($plain->label);
        $this->assertNull($plain->help);
        $this->assertSame([], $plain->config);
    }
}
