<?php

declare(strict_types=1);

namespace Preflow\I18n\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\I18n\Completeness;

final class CompletenessTest extends TestCase
{
    public function test_the_values(): void
    {
        $this->assertSame(['complete', 'partial', 'missing'], array_map(static fn (Completeness $c): string => $c->value, Completeness::cases()));
    }

    public function test_not_visible_is_missing_whatever_the_counts(): void
    {
        $this->assertSame(Completeness::Missing, Completeness::of(false, 4, 0));
        $this->assertSame(Completeness::Missing, Completeness::of(false, 0, 0));
    }

    public function test_nothing_to_translate_is_complete(): void
    {
        $this->assertSame(Completeness::Complete, Completeness::of(true, 0, 0));
    }

    public function test_nothing_missing_is_complete(): void
    {
        $this->assertSame(Completeness::Complete, Completeness::of(true, 5, 0));
    }

    public function test_everything_missing_is_missing(): void
    {
        $this->assertSame(Completeness::Missing, Completeness::of(true, 3, 3));
        $this->assertSame(Completeness::Missing, Completeness::of(true, 3, 4), 'more missing than counted is still missing');
    }

    public function test_some_missing_is_partial(): void
    {
        $this->assertSame(Completeness::Partial, Completeness::of(true, 3, 1));
        $this->assertSame(Completeness::Partial, Completeness::of(true, 3, 2));
    }
}
