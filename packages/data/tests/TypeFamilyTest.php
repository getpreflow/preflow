<?php

declare(strict_types=1);

namespace Preflow\Data\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Data\TypeFamily;

/** The vocabulary types and blocks share: its values are what schemas write, so they never change. */
final class TypeFamilyTest extends TestCase
{
    public function test_the_six_families_and_their_values(): void
    {
        $this->assertSame(
            ['page', 'reference', 'asset', 'member', 'shop', 'live'],
            array_map(static fn (TypeFamily $family): string => $family->value, TypeFamily::cases()),
        );
        $this->assertSame(TypeFamily::Live, TypeFamily::from('live'));
        $this->assertNull(TypeFamily::tryFrom('Page'), 'values are lower case');
    }
}
