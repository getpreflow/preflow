<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Field;

use PHPUnit\Framework\TestCase;
use Preflow\Folio\Field\FieldContext;
use Preflow\Folio\Field\FieldType;
use Preflow\Folio\Field\FieldTypeRegistry;

final class FieldTypeRegistryTest extends TestCase
{
    private function fakeType(string $key): FieldType
    {
        return new class($key) implements FieldType {
            public function __construct(private string $k) {}
            public function key(): string { return $this->k; }
            public function renderEditor(FieldContext $ctx): string { return $this->k; }
            public function normalizeInput(mixed $raw, array $config): mixed { return $raw; }
            public function toStorage(mixed $value): mixed { return $value; }
            public function fromStorage(mixed $value): mixed { return $value; }
            public function renderFrontend(mixed $value, array $config): string { return (string) $value; }
            public function rules(array $config): array { return []; }
            public function assets(): array { return []; }
        };
    }

    public function test_register_get_has(): void
    {
        $r = new FieldTypeRegistry();
        $r->register($this->fakeType('string'));
        $r->register($this->fakeType('text'));

        $this->assertTrue($r->has('text'));
        $this->assertFalse($r->has('nope'));
        $this->assertSame('text', $r->get('text')->key());
    }

    public function test_unknown_falls_back_to_string(): void
    {
        $r = new FieldTypeRegistry();
        $r->register($this->fakeType('string'));
        $this->assertSame('string', $r->get('totally-unknown')->key());
    }

    public function test_alias_resolves(): void
    {
        $r = new FieldTypeRegistry();
        $r->register($this->fakeType('string'));
        $r->register($this->fakeType('number'));
        $r->alias('integer', 'number');

        $this->assertTrue($r->has('integer'));
        $this->assertSame('number', $r->get('integer')->key());
    }
}
