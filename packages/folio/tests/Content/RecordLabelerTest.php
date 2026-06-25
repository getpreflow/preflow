<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Content;

use PHPUnit\Framework\TestCase;
use Preflow\Data\DynamicRecord;
use Preflow\Data\TypeRegistry;
use Preflow\Folio\Content\RecordLabeler;

final class RecordLabelerTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/rl_' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        @rmdir($this->dir);
    }

    /** @param array<string,array<string,mixed>> $fields */
    private function reg(array $fields): TypeRegistry
    {
        file_put_contents($this->dir . '/thing.json', json_encode([
            'key' => 'thing', 'table' => 'thing', 'storage' => 'json', 'id_field' => 'uuid',
            'fields' => $fields,
        ]));
        return new TypeRegistry($this->dir);
    }

    public function test_uses_first_string_field_value(): void
    {
        $reg = $this->reg(['name' => ['type' => 'string'], 'note' => ['type' => 'string']]);
        $rec = DynamicRecord::fromArray($reg->get('thing'), ['uuid' => 't1', 'name' => 'Hello', 'note' => 'X']);
        $this->assertSame('Hello', (new RecordLabeler())->label($rec));
    }

    public function test_falls_back_to_id_when_first_string_empty(): void
    {
        $reg = $this->reg(['name' => ['type' => 'string']]);
        $rec = DynamicRecord::fromArray($reg->get('thing'), ['uuid' => 't2', 'name' => '']);
        $this->assertSame('t2', (new RecordLabeler())->label($rec));
    }

    public function test_only_first_string_field_considered(): void
    {
        // First string field empty -> id (the later non-empty string is NOT used).
        $reg = $this->reg(['name' => ['type' => 'string'], 'alt' => ['type' => 'string']]);
        $rec = DynamicRecord::fromArray($reg->get('thing'), ['uuid' => 't4', 'name' => '', 'alt' => 'Second']);
        $this->assertSame('t4', (new RecordLabeler())->label($rec));
    }

    public function test_falls_back_to_id_when_no_string_field(): void
    {
        $reg = $this->reg(['count' => ['type' => 'number']]);
        $rec = DynamicRecord::fromArray($reg->get('thing'), ['uuid' => 't3', 'count' => 5]);
        $this->assertSame('t3', (new RecordLabeler())->label($rec));
    }
}
