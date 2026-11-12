<?php

declare(strict_types=1);

namespace Preflow\I18n\Tests\Machine;

use PHPUnit\Framework\TestCase;
use Preflow\I18n\Machine\TextSources;
use Preflow\I18n\Tests\Machine\Support\ArrayTextSource;

final class TextSourcesTest extends TestCase
{
    public function test_a_collection_that_is_not_offered_is_not_listed_and_not_built(): void
    {
        $sources = new TextSources();
        $built = 0;
        $factory = static function () use (&$built): ArrayTextSource {
            $built++;

            return new ArrayTextSource([]);
        };
        $asked = 0;
        $sources->add('article', $factory);
        $sources->add('role', $factory, static function () use (&$asked): bool {
            $asked++;

            return false;
        });
        $sources->add('page', $factory, static fn (): bool => true);

        $this->assertSame(0, $asked, 'asked only when looked up');
        $this->assertSame(['article', 'page'], $sources->collections());
        $this->assertFalse($sources->has('role'));
        $this->assertSame(1, $asked, 'answered once');
        $this->assertSame(0, $built);
        $this->expectException(\InvalidArgumentException::class);
        $sources->get('role');
    }
}
