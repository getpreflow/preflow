<?php

declare(strict_types=1);

namespace Preflow\BlockLibrary\Tests;

use PHPUnit\Framework\TestCase;

/**
 * "^0.92.0 || @dev" in require matches any version (@dev is anything), so only "conflict" keeps
 * Composer from pairing the block library with a release that lacks the APIs it calls:
 * Since 0.92.0 (the package's first release): blocks 0.92.0 (AreaLayoutSets, AreasValue and the
 * areas field the columns block's columns are). Components, core, i18n and view 0.91.0: the
 * release before it, raised with the package (BlockComponent's base, the service provider and
 * Application::configFile(), Translator::addDefaultsIfMissing() with a scope, AssetCollector).
 * It also names in composer.json extra.preflow.blocks the class BlocksServiceProvider registers.
 */
final class ComposerConstraintsTest extends TestCase
{
    public function test_conflicts_with_releases_before_the_apis_it_calls(): void
    {
        $composer = json_decode((string) file_get_contents(dirname(__DIR__) . '/composer.json'), true);

        $this->assertSame([
            'preflow/blocks' => '<0.92.0',
            'preflow/components' => '<0.91.0',
            'preflow/core' => '<0.91.0',
            'preflow/i18n' => '<0.91.0',
            'preflow/view' => '<0.91.0',
        ], $composer['conflict'] ?? null);
        foreach ($composer['conflict'] as $package => $below) {
            $this->assertSame('^' . substr($below, 1) . ' || @dev', $composer['require'][$package] ?? null, $package);
        }
    }

    public function test_installing_the_package_registers_its_blocks_and_texts(): void
    {
        $composer = json_decode((string) file_get_contents(dirname(__DIR__) . '/composer.json'), true);

        $this->assertSame(['Preflow\\BlockLibrary\\Columns\\Columns'], $composer['extra']['preflow']['blocks'] ?? null);
        $this->assertSame(['block-library' => ['path' => 'lang', 'scope' => 'public']], $composer['extra']['preflow']['translations'] ?? null);
        foreach ($composer['extra']['preflow']['blocks'] as $class) {
            $this->assertTrue(class_exists($class), $class);
        }
    }
}
