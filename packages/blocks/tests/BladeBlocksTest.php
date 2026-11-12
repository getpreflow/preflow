<?php

declare(strict_types=1);

namespace Preflow\Blocks\Tests;

/** blocks() is a Blade directive too (@blocks(items, preview)), and component blocks render with their Blade templates. */
final class BladeBlocksTest extends BlocksAppTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->write('config/app.php', "<?php\nreturn ['name' => 'Blocks', 'debug' => 1, 'engine' => 'blade', 'key' => 'blocks-app-test-key'];\n");
    }

    public function test_blocks_renders_in_blade(): void
    {
        $page = [['_uid' => 'b1', '_block' => 'banner', 'title' => ['en' => 'Hello', 'de' => 'Hallo'],
            'children' => [['_uid' => 'b2', '_block' => 'banner', 'title' => 'Inner']]]];

        $this->write('page.blade.php', '<main>@blocks($page, true)</main>');
        $html = $this->app()->container()->get(\Preflow\View\TemplateEngineInterface::class)->render('page.blade.php', ['page' => $page]);

        $this->assertMatchesRegularExpression('#<main><div id="Banner-[0-9a-f]+-b1" data-block="b1" data-block-label="Banner"><div class="banner"><h2>Hello</h2>'
            . '<div id="Banner-[0-9a-f]+-b2" data-block="b2" data-block-label="Banner"><div class="banner"><h2>Inner</h2></div>\s*</div></div>\s*</div></main>#', $html);
    }
}
