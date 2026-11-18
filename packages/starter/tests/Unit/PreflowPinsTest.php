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

    /** An undecided plugin stops a non-interactive install on older Composer; the starter says it is off. */
    public function test_the_http_discovery_plugin_is_decided_and_off(): void
    {
        $composer = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);

        $this->assertArrayHasKey('php-http/discovery', $composer['config']['allow-plugins'] ?? []);
        $this->assertFalse($composer['config']['allow-plugins']['php-http/discovery']);
    }
}
