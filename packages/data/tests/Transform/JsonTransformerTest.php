<?php

declare(strict_types=1);

namespace Preflow\Data\Tests\Transform;

use PHPUnit\Framework\TestCase;
use Preflow\Data\Transform\JsonTransformer;

final class JsonTransformerTest extends TestCase
{
    private JsonTransformer $transformer;

    protected function setUp(): void
    {
        $this->transformer = new JsonTransformer();
    }

    public function test_to_storage_encodes_array(): void
    {
        $result = $this->transformer->toStorage(['key' => 'value']);
        $this->assertSame('{"key":"value"}', $result);
    }

    public function test_from_storage_decodes_json_string(): void
    {
        $result = $this->transformer->fromStorage('{"key":"value"}');
        $this->assertSame(['key' => 'value'], $result);
    }

    public function test_to_storage_null_returns_null(): void
    {
        $this->assertNull($this->transformer->toStorage(null));
    }

    public function test_from_storage_null_returns_null(): void
    {
        $this->assertNull($this->transformer->fromStorage(null));
    }

    public function test_from_storage_empty_string_returns_null(): void
    {
        $this->assertNull($this->transformer->fromStorage(''));
    }

    public function test_roundtrip(): void
    {
        $data = ['nested' => ['a' => 1, 'b' => [2, 3]]];
        $stored = $this->transformer->toStorage($data);
        $restored = $this->transformer->fromStorage($stored);
        $this->assertSame($data, $restored);
    }
}
