<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Field;

use PHPUnit\Framework\TestCase;
use Preflow\Folio\Field\Types\DateFieldType;

final class DateFieldTypeTest extends TestCase
{
    private string $tz;

    protected function setUp(): void
    {
        $this->tz = date_default_timezone_get();
        date_default_timezone_set('Europe/Zurich');
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->tz);
    }

    public function test_date_display_formats(): void
    {
        $type = new DateFieldType();

        $this->assertSame('22 Oct 2026', $type->renderFrontend('2026-10-22', []));
        $this->assertSame('22 Oct 2026, 23:15', $type->renderFrontend('2026-10-22T23:15', ['time' => true]));
        $this->assertSame('22 Oct 2026', $type->renderFrontend('2026-10-22T23:15', []));
        // ISO 8601 with an offset is shown in the app timezone (Zurich is +02:00 in October).
        $this->assertSame('23 Oct 2026, 01:15', $type->renderFrontend('2026-10-22T23:15:12+00:00', ['time' => true]));
        $this->assertSame('', $type->renderFrontend(null, []));
    }

    public function test_date_display_garbage_is_escaped(): void
    {
        $this->assertSame('&lt;b&gt;soon&lt;/b&gt;', (new DateFieldType())->renderFrontend('<b>soon</b>', []));
    }
}
