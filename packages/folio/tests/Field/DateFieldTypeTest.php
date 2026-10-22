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

        $this->assertSame('22 Oct 2026', $type->renderAdmin('2026-10-22', []));
        $this->assertSame('22 Oct 2026, 23:15', $type->renderAdmin('2026-10-22T23:15', ['time' => true]));
        $this->assertSame('22 Oct 2026', $type->renderAdmin('2026-10-22T23:15', []));
        // ISO 8601 with an offset is shown in the app timezone (Zurich is +02:00 in October).
        $this->assertSame('23 Oct 2026, 01:15', $type->renderAdmin('2026-10-22T23:15:12+00:00', ['time' => true]));
        $this->assertSame('', $type->renderAdmin(null, []));
    }

    public function test_date_display_garbage_is_escaped(): void
    {
        $this->assertSame('&lt;b&gt;soon&lt;/b&gt;', (new DateFieldType())->renderAdmin('<b>soon</b>', []));
    }

    public function test_frontend_output_is_unchanged(): void
    {
        $type = new DateFieldType();

        $this->assertSame('2026-10-22', $type->renderFrontend('2026-10-22', []));
        $this->assertSame('2026-10-22T23:15:12+00:00', $type->renderFrontend('2026-10-22T23:15:12+00:00', ['time' => true]));
    }

    public function test_only_the_listed_shapes_parse(): void
    {
        $type = new DateFieldType();

        foreach (['tomorrow', 'now', 'next monday', '2026-10-22 23:15', '22.10.2026', '2026-02-30'] as $raw) {
            $this->assertSame($raw, $type->renderAdmin($raw, []), $raw);
        }
        $this->assertSame('23 Oct 2026, 01:15', $type->renderAdmin('2026-10-22T23:15+00:00', ['time' => true]));
        $this->assertSame('23 Oct 2026, 01:15', $type->renderAdmin('2026-10-22T23:15:12Z', ['time' => true]));
    }
}
