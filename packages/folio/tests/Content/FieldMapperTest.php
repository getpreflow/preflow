<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Content;

use PHPUnit\Framework\TestCase;
use Preflow\Folio\Content\FieldMapper;

final class FieldMapperTest extends TestCase
{
    public function test_maps_known_types(): void
    {
        $this->assertSame('text', FieldMapper::inputFor('string'));
        $this->assertSame('textarea', FieldMapper::inputFor('text'));
        $this->assertSame('number', FieldMapper::inputFor('integer'));
    }

    public function test_unknown_defaults_to_text(): void
    {
        $this->assertSame('text', FieldMapper::inputFor('whatever'));
    }
}
