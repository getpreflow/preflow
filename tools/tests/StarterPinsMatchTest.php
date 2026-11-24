<?php

declare(strict_types=1);

namespace Preflow\Tools\Tests;

use PHPUnit\Framework\TestCase;

/**
 * A release bumps the preflow/* pins of the starters and of the skeleton together. Each one's own
 * pins agree inside it (starter/tests/Unit/PreflowPinsTest); this guard keeps the three equal, so
 * a release cannot forget the skeleton.
 */
final class StarterPinsMatchTest extends TestCase
{
    public function test_skeleton_and_starters_pin_the_same_release(): void
    {
        $root = dirname(__DIR__, 2) . '/packages';
        $core = [];
        foreach (['starter', 'starter-folio', 'skeleton'] as $name) {
            $composer = json_decode((string) file_get_contents($root . '/' . $name . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
            $pins = array_filter(
                $composer['require'] + ($composer['require-dev'] ?? []),
                static fn (string $package): bool => str_starts_with($package, 'preflow/'),
                ARRAY_FILTER_USE_KEY,
            );
            $this->assertNotSame([], $pins, $name);
            $this->assertCount(1, array_unique($pins), $name . ' pins: ' . json_encode($pins));
            $core[$name] = reset($pins);
        }

        $this->assertCount(1, array_unique($core), 'starter, starter-folio and skeleton pin one release: ' . json_encode($core));
    }
}
