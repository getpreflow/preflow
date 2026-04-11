<?php

declare(strict_types=1);

namespace Preflow\Core\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Core\DebugLevel;

final class DebugLevelTest extends TestCase
{
    public function test_off_has_value_zero(): void
    {
        $this->assertSame(0, DebugLevel::Off->value);
    }

    public function test_on_has_value_one(): void
    {
        $this->assertSame(1, DebugLevel::On->value);
    }

    public function test_verbose_has_value_two(): void
    {
        $this->assertSame(2, DebugLevel::Verbose->value);
    }

    public function test_from_creates_correct_case(): void
    {
        $this->assertSame(DebugLevel::Off, DebugLevel::from(0));
        $this->assertSame(DebugLevel::On, DebugLevel::from(1));
        $this->assertSame(DebugLevel::Verbose, DebugLevel::from(2));
    }

    public function test_from_throws_on_invalid_value(): void
    {
        $this->expectException(\ValueError::class);
        DebugLevel::from(3);
    }

    public function test_is_debug_returns_false_for_off(): void
    {
        $this->assertFalse(DebugLevel::Off->isDebug());
    }

    public function test_is_debug_returns_true_for_on(): void
    {
        $this->assertTrue(DebugLevel::On->isDebug());
    }

    public function test_is_debug_returns_true_for_verbose(): void
    {
        $this->assertTrue(DebugLevel::Verbose->isDebug());
    }
}
