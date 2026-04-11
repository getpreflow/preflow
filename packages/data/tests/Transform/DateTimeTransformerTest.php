<?php

declare(strict_types=1);

namespace Preflow\Data\Tests\Transform;

use PHPUnit\Framework\TestCase;
use Preflow\Data\Transform\DateTimeTransformer;

final class DateTimeTransformerTest extends TestCase
{
    private DateTimeTransformer $transformer;

    protected function setUp(): void
    {
        $this->transformer = new DateTimeTransformer();
    }

    public function test_to_storage_formats_datetime(): void
    {
        $dt = new \DateTimeImmutable('2026-04-11 14:30:00');
        $result = $this->transformer->toStorage($dt);
        $this->assertSame('2026-04-11 14:30:00', $result);
    }

    public function test_from_storage_parses_datetime_string(): void
    {
        $result = $this->transformer->fromStorage('2026-04-11 14:30:00');
        $this->assertInstanceOf(\DateTimeImmutable::class, $result);
        $this->assertSame('2026-04-11 14:30:00', $result->format('Y-m-d H:i:s'));
    }

    public function test_from_storage_parses_iso8601(): void
    {
        $result = $this->transformer->fromStorage('2026-04-11T14:30:00+00:00');
        $this->assertInstanceOf(\DateTimeImmutable::class, $result);
        $this->assertSame('2026-04-11', $result->format('Y-m-d'));
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

    public function test_to_storage_passes_through_non_datetime(): void
    {
        $this->assertSame('2026-04-11', $this->transformer->toStorage('2026-04-11'));
    }

    public function test_roundtrip(): void
    {
        $dt = new \DateTimeImmutable('2026-12-25 08:00:00');
        $stored = $this->transformer->toStorage($dt);
        $restored = $this->transformer->fromStorage($stored);
        $this->assertSame($dt->format('Y-m-d H:i:s'), $restored->format('Y-m-d H:i:s'));
    }
}
