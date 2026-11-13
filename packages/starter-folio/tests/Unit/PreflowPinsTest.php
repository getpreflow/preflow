<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/** Preflow's packages are released together: every preflow/* requirement names the same version. */
final class PreflowPinsTest extends TestCase
{
    public function test_every_preflow_package_is_pinned_to_the_same_release(): void
    {
        $composer = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        $pins = array_filter($composer['require'], static fn (string $name): bool => str_starts_with($name, 'preflow/'), ARRAY_FILTER_USE_KEY);

        $this->assertNotSame([], $pins);
        $this->assertSame([$pins['preflow/core']], array_values(array_unique($pins)), 'all preflow/* pins: ' . json_encode($pins));
    }
}
