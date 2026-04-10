<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\Config;

use PHPUnit\Framework\TestCase;
use Preflow\Core\Config\Config;

final class ConfigTest extends TestCase
{
    public function test_get_top_level_key(): void
    {
        $config = new Config(['name' => 'Preflow']);
        $this->assertSame('Preflow', $config->get('name'));
    }

    public function test_get_nested_key_with_dot_notation(): void
    {
        $config = new Config([
            'app' => [
                'name' => 'Preflow',
                'nested' => ['deep' => 'value'],
            ],
        ]);
        $this->assertSame('Preflow', $config->get('app.name'));
        $this->assertSame('value', $config->get('app.nested.deep'));
    }

    public function test_get_returns_default_for_missing_key(): void
    {
        $config = new Config([]);
        $this->assertNull($config->get('missing'));
        $this->assertSame('fallback', $config->get('missing', 'fallback'));
    }

    public function test_get_returns_default_for_partially_missing_path(): void
    {
        $config = new Config(['app' => ['name' => 'Preflow']]);
        $this->assertSame('nope', $config->get('app.nonexistent.deep', 'nope'));
    }

    public function test_has_returns_true_for_existing_key(): void
    {
        $config = new Config(['app' => ['name' => 'Preflow']]);
        $this->assertTrue($config->has('app'));
        $this->assertTrue($config->has('app.name'));
    }

    public function test_has_returns_false_for_missing_key(): void
    {
        $config = new Config(['app' => ['name' => 'Preflow']]);
        $this->assertFalse($config->has('missing'));
        $this->assertFalse($config->has('app.missing'));
    }

    public function test_set_creates_nested_keys(): void
    {
        $config = new Config([]);
        $config->set('app.database.host', 'localhost');
        $this->assertSame('localhost', $config->get('app.database.host'));
    }

    public function test_set_overwrites_existing_value(): void
    {
        $config = new Config(['app' => ['name' => 'Old']]);
        $config->set('app.name', 'New');
        $this->assertSame('New', $config->get('app.name'));
    }

    public function test_all_returns_full_array(): void
    {
        $items = ['app' => ['name' => 'Preflow']];
        $config = new Config($items);
        $this->assertSame($items, $config->all());
    }

    public function test_get_returns_array_value(): void
    {
        $config = new Config([
            'app' => ['allowed' => ['a', 'b', 'c']],
        ]);
        $this->assertSame(['a', 'b', 'c'], $config->get('app.allowed'));
    }
}
