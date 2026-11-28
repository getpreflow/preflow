<?php

declare(strict_types=1);

namespace Preflow\Blocks\Tests\Layouts;

use Preflow\Blocks\BlocksConfig;
use Preflow\Blocks\Layouts\AreaLayoutSet;
use Preflow\Blocks\Layouts\AreaLayoutSets;
use Preflow\Blocks\Layouts\LayoutConfigException;
use Preflow\Blocks\Layouts\LayoutRegistry;
use Preflow\Blocks\Tests\BlocksAppTestCase;
use Preflow\Blocks\Tests\Fixtures\Layouts\PackageHome;
use Preflow\Blocks\Tests\Fixtures\Layouts\Standard;
use Preflow\Core\Boot\ManifestBuilder;
use Preflow\Core\Cache\CacheRegistry;
use Preflow\Core\Cache\FileCache;

/** Layouts through a booted app: the container's registry, package discovery, config, the cache entry, the boot manifest. */
final class LayoutsAppTest extends BlocksAppTestCase
{
    private function installPackage(string $declared): void
    {
        $this->write('vendor/composer/installed.json', (string) json_encode(['packages' => [[
            'name' => 'acme/pages',
            'extra' => ['preflow' => ['layouts' => [$declared]]],
        ]]]));
    }

    public function test_container_resolves_app_package_and_config_layouts(): void
    {
        $this->installPackage(PackageHome::class);
        $this->write('config/blocks.php', "<?php\nreturn ['layouts' => [\\Preflow\\Blocks\\Tests\\Fixtures\\Layouts\\Standard::class]];\n");

        $container = $this->app()->container();
        $registry = $container->get(LayoutRegistry::class);

        $this->assertSame(['landing', 'package_home', 'standard'], array_keys($registry->all()), 'app/Layouts, extra.preflow.layouts, config/blocks.php');
        $this->assertSame(PackageHome::class, $registry->get('package_home')->class);
        $this->assertSame([Standard::class], $container->get(BlocksConfig::class)->layouts);
        $this->assertSame($registry, $container->get(LayoutRegistry::class), 'one registry per app');

        $sets = $container->get(AreaLayoutSets::class);
        $this->assertSame($sets, $container->get(AreaLayoutSets::class));
        $sets->add('x', static fn (): AreaLayoutSet => new AreaLayoutSet('x', []));
        $this->assertTrue($sets->has('x'));
    }

    public function test_a_package_listing_a_class_without_layout_fails_with_its_name(): void
    {
        $this->installPackage(\Preflow\Blocks\Tests\Fixtures\Package\NotABlock::class);

        $this->expectException(LayoutConfigException::class);
        $this->expectExceptionMessage('Package acme/pages: extra.preflow.layouts lists Preflow\Blocks\Tests\Fixtures\Package\NotABlock, which has no #[Preflow\Blocks\Layouts\Layout].');
        $this->app()->container()->get(LayoutRegistry::class);
    }

    public function test_a_package_listing_something_that_is_not_a_class_fails(): void
    {
        $this->installPackage('No\\Such\\Layout');

        $this->expectException(LayoutConfigException::class);
        $this->expectExceptionMessage('Package acme/pages: extra.preflow.layouts lists No\Such\Layout, which is not a class.');
        $this->app()->container()->get(LayoutRegistry::class);
    }

    public function test_config_listing_something_that_is_not_a_class_fails(): void
    {
        $this->write('config/blocks.php', "<?php\nreturn ['layouts' => ['No\\\\Such\\\\Layout']];\n");

        $this->expectException(LayoutConfigException::class);
        $this->expectExceptionMessage("config/blocks.php 'layouts' lists No\\Such\\Layout, which is not a class.");
        $this->app()->container()->get(LayoutRegistry::class);
    }

    public function test_layouts_have_their_own_cache_entry(): void
    {
        $cache = $this->app()->container()->get(CacheRegistry::class)->get('layouts');

        $this->assertInstanceOf(FileCache::class, $cache);
        $this->assertSame('Page layouts', $cache->label());
    }

    public function test_the_boot_manifest_holds_layouts_and_sees_config_and_package_changes(): void
    {
        $this->write('config/app.php', "<?php\nreturn ['name' => 'Blocks', 'debug' => 0, 'engine' => 'twig', 'key' => 'blocks-app-test-key-0123456789abcdef'];\n");
        $builder = new ManifestBuilder($this->dir);

        $result = $builder->write();
        $this->assertTrue($result['written']);
        $this->assertContains('blocks.layouts', $result['catalogs']);
        $this->assertSame([], $builder->drift());

        // The same sources as for blocks: config/, installed.json, and the layouts directory.
        $this->write('config/blocks.php', "<?php\nreturn ['layouts' => []];\n");
        $this->assertNotSame([], array_filter($builder->drift() ?? [], static fn (string $line): bool => str_starts_with($line, 'config')));
        $builder->write();
        $this->write('app/Layouts/Landing.php', (string) file_get_contents($this->dir . '/app/Layouts/Landing.php') . "\n// edited\n");
        $this->assertNotSame([], array_filter($builder->drift() ?? [], static fn (string $line): bool => str_contains($line, 'app/Layouts')));
        $builder->write();
        $this->installPackage(PackageHome::class);
        $this->assertNotSame([], array_filter($builder->drift() ?? [], static fn (string $line): bool => str_contains($line, 'installed.json')));
    }

    public function test_a_site_binding_of_page_layouts_is_kept(): void
    {
        $this->write('config/data.php', "<?php\nreturn ['drivers' => ['json' => ['driver' => \\Preflow\\Data\\Driver\\JsonFileDriver::class, 'path' => __DIR__ . '/../storage/data']], 'default' => 'json', 'models_path' => __DIR__ . '/models'];\n");
        $this->write('config/models/page.json', (string) json_encode([
            'key' => 'page', 'table' => 'page', 'storage' => 'json', 'id_field' => 'uuid',
            'fields' => ['title' => ['type' => 'string']],
            'layouts' => ['allowed' => ['landing'], 'default' => 'landing'],
        ]));
        $this->write('config/providers.php', "<?php\nreturn [\\Preflow\\Blocks\\Tests\\Fixtures\\Layouts\\OwnPageLayouts::class, \\Preflow\\Blocks\\BlocksServiceProvider::class];\n");
        \Preflow\Blocks\Tests\Fixtures\Layouts\OwnPageLayouts::$built = null;

        $layouts = $this->app()->container()->get(\Preflow\Blocks\Layouts\PageLayouts::class);

        $this->assertSame(\Preflow\Blocks\Tests\Fixtures\Layouts\OwnPageLayouts::$built, $layouts, 'the site\'s own binding, not replaced in the package\'s boot');
        $this->assertSame('landing', $layouts->forType('page')?->default);
    }
}
