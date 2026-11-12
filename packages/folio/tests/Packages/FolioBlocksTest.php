<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Packages;

use PHPUnit\Framework\TestCase;
use Preflow\Blocks\BlockLocale;
use Preflow\Blocks\BlockPreviewMarks;
use Preflow\Blocks\BlockRegistry;
use Preflow\Blocks\BlocksConfig;
use Preflow\Blocks\BlockTextCleaner;
use Preflow\Core\Debug\DebugCollector;
use Preflow\Folio\Content\ContentLocale;
use Preflow\Folio\Content\FolioBlockPreviewMarks;
use Preflow\Folio\Content\SafeRichText;
use Preflow\Folio\Tests\Fixtures\Blocks\LinkBlock;
use Preflow\Folio\Tests\Fixtures\Blocks\QuoteBlock;
use Preflow\Folio\Tests\Support\PublishingScaffold;

/**
 * Folio on preflow/blocks (0.70): it answers the blocks' questions (content locale, rich text,
 * preview marks) and reads its old block settings for one release, each with a deprecation note:
 * config/folio.php 'blocks' and the packages' extra.folio.blocks. folio_blocks() is blocks().
 */
final class FolioBlocksTest extends TestCase
{
    use PublishingScaffold;

    protected function setUp(): void
    {
        $this->scaffoldPublishing();
    }

    protected function tearDown(): void
    {
        $this->removeScaffold();
    }

    public function test_folio_binds_its_content_locale_rich_text_and_preview_marks(): void
    {
        $c = $this->app()->container();

        $this->assertSame($c->get(ContentLocale::class), $c->get(BlockLocale::class));
        $this->assertSame($c->get(SafeRichText::class), $c->get(BlockTextCleaner::class));
        $this->assertInstanceOf(FolioBlockPreviewMarks::class, $c->get(BlockPreviewMarks::class));
        $this->assertSame($c->get(BlockRegistry::class), $c->get(\Preflow\Folio\Block\BlockRegistry::class), 'the old name is the same service');
    }

    public function test_the_old_package_key_still_registers_blocks_with_a_deprecation_note(): void
    {
        $this->packages(['acme/old' => ['folio' => ['blocks' => [LinkBlock::class]]], 'acme/new' => ['preflow' => ['blocks' => [QuoteBlock::class]]]]);
        $c = $this->app()->container();

        $blocks = $c->get(BlockRegistry::class);

        $this->assertNotNull($blocks->get('link'), 'extra.folio.blocks');
        $this->assertNotNull($blocks->get('quote'), 'extra.preflow.blocks');
        $this->assertNotNull($blocks->get('hero'), 'config/folio.php blocks');
        $this->assertContains('composer.json extra.folio.blocks (' . LinkBlock::class . ') is now extra.preflow.blocks; the old key is read until the next release.', $this->deprecations());
        $this->assertContains("config/folio.php 'blocks' moved to config/blocks.php (preflow/blocks: 'classes', 'max_depth'); the old key is read until the next release.", $this->deprecations());
    }

    public function test_the_new_places_need_no_note(): void
    {
        $this->packages(['acme/new' => ['preflow' => ['blocks' => [QuoteBlock::class]]]]);
        $this->write('config/folio.php', "<?php\nreturn ['path' => '/folio'];\n");
        $this->write('config/blocks.php', "<?php\nreturn ['classes' => [\\" . LinkBlock::class . "::class], 'max_depth' => 2];\n");
        $c = $this->app()->container();

        $this->assertNotNull($c->get(BlockRegistry::class)->get('quote'));
        $this->assertNotNull($c->get(BlockRegistry::class)->get('link'));
        $this->assertSame(2, $c->get(BlocksConfig::class)->maxDepth);
        $this->assertSame([], array_values(array_filter($this->deprecations(), static fn (string $d): bool => str_contains($d, 'block'))));
    }

    public function test_max_depth_comes_from_config_blocks_then_config_folio(): void
    {
        $this->write('config/folio.php', "<?php\nreturn ['path' => '/folio', 'blocks' => ['max_depth' => 3]];\n");
        $this->assertSame(3, $this->app()->container()->get(BlocksConfig::class)->maxDepth);

        $this->write('config/blocks.php', "<?php\nreturn ['max_depth' => 5];\n");
        $this->assertSame(5, $this->app()->container()->get(BlocksConfig::class)->maxDepth);
    }

    public function test_the_blocks_cache_follows_folio_s_cache_path_until_config_blocks_names_one(): void
    {
        $this->write('config/folio.php', "<?php\nreturn ['path' => '/folio', 'cache_path' => 'var/folio'];\n");
        $this->assertSame($this->dir . '/var/folio/blocks.cache', $this->app()->container()->get(BlocksConfig::class)->cacheFile);
        $this->assertContains("config/folio.php 'cache_path' moves blocks.cache until config/blocks.php sets its own 'cache_path' (from the next release only that does).", $this->deprecations());

        $this->write('config/blocks.php', "<?php\nreturn ['cache_path' => 'var/blocks'];\n");
        $this->assertSame($this->dir . '/var/blocks/blocks.cache', $this->app()->container()->get(BlocksConfig::class)->cacheFile);
    }

    public function test_folio_blocks_is_blocks_with_a_deprecation_note(): void
    {
        $this->write('resources/folio/frontend/post.twig', '<html><body><main class="old">{{ folio_blocks(record.sections) }}</main><main class="new">{{ blocks(record.sections) }}</main></body></html>');
        $this->publisher()->publish($this->working('post', 'p1', [
            'title' => ['en' => 'T'],
            'slug' => ['en' => 'hello'],
            'sections' => [['_uid' => 'h1', '_block' => 'hero', 'headline' => ['en' => 'Hello']]],
        ]), null);
        $app = $this->app();

        $html = (string) $app->handle((new \Nyholm\Psr7\Factory\Psr17Factory())->createServerRequest('GET', '/hello'))->getBody();

        preg_match('#<main class="old">(.*?)</main><main class="new">(.*?)</main>#s', $html, $m);
        $this->assertStringContainsString('<h1>Hello</h1>', $m[1] ?? '');
        $this->assertSame($m[1] ?? null, $m[2] ?? '');
        $this->assertContains('folio_blocks() is now blocks() (preflow/blocks); the old name is removed in the next release.', $app->container()->get(DebugCollector::class)->getDeprecations());
    }

    public function test_an_app_s_own_binding_is_kept(): void
    {
        $this->write('config/providers.php', "<?php\nreturn [\\Preflow\\Folio\\Tests\\Fixtures\\Blocks\\OwnBlockCleanerProvider::class, \\Preflow\\Folio\\FolioServiceProvider::class];\n");
        $c = $this->app()->container();

        $this->assertNotInstanceOf(SafeRichText::class, $c->get(BlockTextCleaner::class));
        $this->assertSame($c->get(ContentLocale::class), $c->get(BlockLocale::class), 'what the app did not bind is still Folio\'s');
    }

    public function test_cache_clear_of_the_old_key_clears_the_blocks_cache_with_a_note(): void
    {
        $this->write('storage/cache/blocks.cache', 'blocks');
        $app = $this->app();

        ob_start();
        $exit = (new \Preflow\DevTools\Command\CacheClearCommand(static fn () => $app))->execute(['folio.blocks']);
        $out = (string) ob_get_clean();

        $this->assertSame(0, $exit, $out);
        $this->assertStringContainsString('Note: the cache folio.blocks is now blocks; the old key is deprecated, use the new one.', $out);
        $this->assertStringContainsString('Cleared blocks: 1 file(s)', $out);
        $this->assertFileDoesNotExist($this->dir . '/storage/cache/blocks.cache');
        $keys = array_map(static fn ($s): string => $s->key(), $app->container()->get(\Preflow\Core\Cache\CacheRegistry::class)->all());
        $this->assertContains('blocks', $keys);
        $this->assertNotContains('folio.blocks', $keys, 'Settings → Cache lists it once');
    }

    /** @param array<string, array<string, mixed>> $extras package name => composer extra */
    private function packages(array $extras): void
    {
        $packages = [];
        foreach ($extras as $name => $extra) {
            $packages[] = ['name' => $name, 'extra' => $extra];
        }
        $this->write('vendor/composer/installed.json', (string) json_encode(['packages' => $packages]));
    }

    /** @return list<string> the deprecations booting and building the block registry logged */
    private function deprecations(): array
    {
        $c = $this->app()->container();
        $c->get(BlockRegistry::class);

        return $c->get(DebugCollector::class)->getDeprecations();
    }
}
