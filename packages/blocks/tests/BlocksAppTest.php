<?php

declare(strict_types=1);

namespace Preflow\Blocks\Tests;

use Preflow\Blocks\BlockLocale;
use Preflow\Blocks\BlockRegistry;
use Preflow\Blocks\BlockRenderer;
use Preflow\Blocks\BlocksConfig;
use Preflow\Blocks\BlockTextCleaner;
use Preflow\Blocks\DefaultBlockLocale;
use Preflow\Blocks\DefaultBlockTextCleaner;
use Preflow\Core\Cache\CacheRegistry;
use Preflow\Core\Cache\FileCache;

/** preflow/blocks on its own: discovery, rendering with blocks(), preview, depth, the defaults. */
final class BlocksAppTest extends BlocksAppTestCase
{
    private const array PAGE = [
        ['_uid' => 'b1', '_block' => 'banner', 'title' => ['en' => 'Hello', 'de' => 'Hallo'], 'body' => '<em>as stored</em>',
            'quotes' => [['_uid' => 'q1', '_block' => 'quote', 'text' => ['en' => 'Quoted', 'de' => 'Zitiert']]],
            'children' => [['_uid' => 'b2', '_block' => 'banner', 'title' => 'Inner']]],
        ['_uid' => 'q2', '_block' => 'quote', 'text' => 'a data-only block at the top is skipped'],
        ['_uid' => 'x1', '_block' => 'unknown', 'title' => 'skipped too'],
    ];

    public function test_discovers_component_blocks_data_only_blocks_and_configured_classes(): void
    {
        $this->write('config/blocks.php', "<?php\nreturn ['classes' => [\\Preflow\\Blocks\\Tests\\Fixtures\\ConfigCard::class]];\n");

        $blocks = $this->app()->container()->get(BlockRegistry::class);

        $this->assertSame(['banner', 'quote', 'config_card'], array_keys($blocks->all()), 'app/Components, app/Blocks, config/blocks.php; Plain has no #[Block]');
        $this->assertTrue($blocks->get('banner')->isComponent);
        $this->assertFalse($blocks->get('quote')->isComponent);
        $this->assertSame(['allowed' => ['banner']], $blocks->get('banner')->fields['children']->config);
        $this->assertTrue($blocks->get('banner')->fields['title']->translatable);
        $this->assertSame(['required'], $blocks->get('config_card')->fields['heading']->validate);
        $this->assertSame(['options' => ['a' => 'A']], $blocks->get('config_card')->fields['style']->config);
        $this->assertSame(['Sections' => [$blocks->get('banner')], 'Blocks' => [$blocks->get('quote')]], $blocks->grouped(['quote', 'banner']));
    }

    public function test_an_installed_package_s_blocks_are_registered(): void
    {
        $this->write('vendor/composer/installed.json', (string) json_encode(['packages' => [[
            'name' => 'acme/notes',
            'extra' => ['preflow' => ['blocks' => [\Preflow\Blocks\Tests\Fixtures\Package\PackageNote::class]]],
        ]]]));

        $this->assertNotNull($this->app()->container()->get(BlockRegistry::class)->get('package_note'));
    }

    public function test_a_package_listing_a_class_without_block_fails_with_its_name(): void
    {
        $this->write('vendor/composer/installed.json', (string) json_encode(['packages' => [[
            'name' => 'acme/notes',
            'extra' => ['preflow' => ['blocks' => [\Preflow\Blocks\Tests\Fixtures\Package\NotABlock::class]]],
        ]]]));

        $this->expectExceptionMessage('Package acme/notes: extra.preflow.blocks lists Preflow\Blocks\Tests\Fixtures\Package\NotABlock, which has no #[Preflow\Blocks\Block].');
        $this->app()->container()->get(BlockRegistry::class);
    }

    public function test_blocks_renders_components_with_their_data_only_and_nested_blocks(): void
    {
        $html = $this->render('<main>{{ blocks(page) }}</main>', ['page' => self::PAGE]);

        $this->assertMatchesRegularExpression('#^<main><div id="Banner-[0-9a-f]+-b1"><div class="banner"><h2>Hello</h2><div class="body"><em>as stored</em></div><q>Quoted</q>'
            . '<div id="Banner-[0-9a-f]+-b2"><div class="banner"><h2>Inner</h2><div class="body"></div></div>\s*</div></div>\s*</div></main>$#', $html);
        $this->assertStringNotContainsString('skipped', $html);
        $this->assertStringNotContainsString('data-block', $html);
    }

    public function test_blocks_takes_stored_json_too(): void
    {
        $this->assertSame(
            $this->render('{{ blocks(page) }}', ['page' => self::PAGE]),
            $this->render('{{ blocks(page|json_encode) }}', ['page' => self::PAGE]),
        );
        $this->assertSame('', $this->render('{{ blocks(page) }}', ['page' => null]));
    }

    public function test_preview_marks_each_block_root_nested_ones_included(): void
    {
        $html = $this->render('{{ blocks(page, true) }}', ['page' => self::PAGE]);

        $this->assertMatchesRegularExpression('#<div id="Banner-[0-9a-f]+-b1" data-block="b1" data-block-label="Banner">#', $html);
        $this->assertMatchesRegularExpression('#<div id="Banner-[0-9a-f]+-b2" data-block="b2" data-block-label="Banner">#', $html, 'the child is rendered without the flag, and previewed');
        $this->assertFalse($this->app()->container()->get(BlockRenderer::class)->inPreview(), 'preview ends with the render');
    }

    public function test_blocks_nested_deeper_than_max_depth_render_with_one_note(): void
    {
        $this->write('config/blocks.php', "<?php\nreturn ['max_depth' => 2];\n");
        $log = $this->logger();
        $page = [['_uid' => 'l1', '_block' => 'banner', 'title' => 'One', 'children' => [
            ['_uid' => 'l2', '_block' => 'banner', 'title' => 'Two', 'children' => [
                ['_uid' => 'l3', '_block' => 'banner', 'title' => 'Three'],
                ['_uid' => 'l3b', '_block' => 'banner', 'title' => 'Three too'],
            ]],
        ]]];

        $html = $this->render('{{ blocks(page) }}', ['page' => $page]);

        $this->assertSame(2, $this->app()->container()->get(BlocksConfig::class)->maxDepth);
        foreach (['One', 'Two', 'Three', 'Three too'] as $title) {
            $this->assertStringContainsString("<h2>{$title}</h2>", $html, 'every level renders, as before 0.70');
        }
        $this->assertSame(['Blocks are nested 3 levels deep, deeper than max_depth (2): block "banner" (l3). They render; the editor saves at most max_depth levels (config/blocks.php).'], $log->messages, 'once per request');
    }

    public function test_four_levels_need_no_note_with_the_default_max_depth(): void
    {
        $log = $this->logger();
        $level = ['_uid' => 'l4', '_block' => 'banner', 'title' => 'Four'];
        foreach (['Three', 'Two', 'One'] as $i => $title) {
            $level = ['_uid' => 'l' . (3 - $i), '_block' => 'banner', 'title' => $title, 'children' => [$level]];
        }

        $html = $this->render('{{ blocks(page) }}', ['page' => [$level]]);

        $this->assertStringContainsString('<h2>Four</h2>', $html);
        $this->assertSame([], $log->messages);
        $this->assertSame(4, $this->app()->container()->get(BlocksConfig::class)->maxDepth);
    }

    /** The site's logger, bound before the renderer is built. */
    private function logger(): object
    {
        $logger = new class extends \Psr\Log\AbstractLogger {
            /** @var list<string> */
            public array $messages = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->messages[] = (string) $message;
            }
        };
        $this->app()->container()->instance(\Psr\Log\LoggerInterface::class, $logger);

        return $logger;
    }

    public function test_the_default_locale_is_the_request_s_and_the_default_cleaner_keeps_values(): void
    {
        $c = $this->app()->container();
        $this->assertInstanceOf(DefaultBlockLocale::class, $c->get(BlockLocale::class));
        $this->assertInstanceOf(DefaultBlockTextCleaner::class, $c->get(BlockTextCleaner::class));
        $this->assertSame('en', $c->get(BlockLocale::class)->get());

        $c->get(\Preflow\I18n\Translator::class)->setLocale('de');
        $html = $this->render('{{ blocks(page) }}', ['page' => self::PAGE]);

        $this->assertSame('de', $c->get(BlockLocale::class)->get());
        $this->assertStringContainsString('<h2>Hallo</h2>', $html);
        $this->assertStringContainsString('<q>Zitiert</q>', $html);
        $this->assertStringContainsString('<em>as stored</em>', $html, 'no cleaning without a cleaner of the app');

        $c->get(\Preflow\I18n\Translator::class)->setLocale('fr');
        $this->assertSame('en', $c->get(BlockLocale::class)->get(), 'not a content locale: the default');
    }

    public function test_an_app_binds_its_own_locale_and_cleaner(): void
    {
        $c = $this->app()->container();
        $c->instance(BlockTextCleaner::class, new class implements BlockTextCleaner {
            public function block(array $block): array
            {
                return ['body' => strip_tags((string) ($block['body'] ?? ''))] + $block;
            }
        });
        $c->instance(BlockLocale::class, new DefaultBlockLocale($c->get(\Preflow\Data\Locales::class), static fn (): string => 'de'));
        // The renderer is built on first use, with what is bound then.
        $html = $this->render('{{ blocks(page) }}', ['page' => self::PAGE]);

        $this->assertStringContainsString('<div class="body">as stored</div>', $html);
        $this->assertStringContainsString('<h2>Hallo</h2>', $html);
    }

    public function test_the_cache_is_listed_and_kept_where_config_says(): void
    {
        $this->write('config/app.php', "<?php\nreturn ['name' => 'Blocks', 'debug' => 0, 'engine' => 'twig', 'key' => 'blocks-app-test-key'];\n");
        $this->write('config/blocks.php', "<?php\nreturn ['cache_path' => 'var/blocks'];\n");
        $c = $this->app()->container();

        $c->get(BlockRegistry::class);

        $this->assertFileExists($this->dir . '/var/blocks/blocks.cache');
        $sources = [];
        foreach ($c->get(CacheRegistry::class)->all() as $source) {
            $sources[$source->key()] = $source;
        }
        $this->assertInstanceOf(FileCache::class, $sources['blocks'] ?? null);
        $this->assertSame('Blocks', $sources['blocks']->label());
        $this->assertSame(1, $sources['blocks']->stats()->entries);
    }
}
