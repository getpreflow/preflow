<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Preflow\Folio\Tests\Support\PublishingScaffold;

/**
 * A page with component blocks, data-only blocks, nested blocks and rich text renders
 * byte for byte as it did before blocks became their own package (0.70): public and
 * preview, English and German, through folio_blocks() and folio_block(). The expected
 * pages were rendered by 0.69's Folio (tests/Fixtures/blocks-render/*.html).
 */
final class BlocksRenderUnchangedTest extends TestCase
{
    use PublishingScaffold;

    private const string EVIL = '<p onclick="steal()">kept</p><script>steal()</script>';

    protected function setUp(): void
    {
        $this->scaffoldPublishing();
        $this->write('config/folio.php', "<?php\nreturn ['path' => '/folio', 'blocks' => ['classes' => ["
            . '\\Preflow\\Folio\\Tests\\Fixtures\\Blocks\\HeroBlock\\HeroBlock::class, \\Preflow\\Folio\\Tests\\Fixtures\\Blocks\\TradeoffNoteBlock::class, '
            . '\\Preflow\\Folio\\Tests\\Fixtures\\Blocks\\SectionBlock\\SectionBlock::class, \\Preflow\\Folio\\Tests\\Fixtures\\Blocks\\ProseBlock\\ProseBlock::class, '
            . "\\Preflow\\Folio\\Tests\\Fixtures\\Blocks\\QuoteBlock::class]]];\n");
        $this->write('resources/folio/frontend/post.twig', '<html><body>'
            . '<main class="public">{{ folio_blocks(record.sections|default([])) }}</main>'
            . '<main class="preview">{{ folio_blocks(record.sections|default([]), true) }}</main>'
            . '<main class="json">{{ folio_blocks(record.sections|default([])|json_encode) }}</main>'
            . '<main class="one">{{ folio_block(record.sections[1]) }}</main>'
            . '</body></html>');
    }

    protected function tearDown(): void
    {
        $this->removeScaffold();
    }

    public function test_a_blocks_page_renders_as_before(): void
    {
        $this->publisher()->publish($this->working('post', 'p1', [
            'title' => ['en' => 'T', 'de' => 'T'],
            'slug' => ['en' => 'hello', 'de' => 'hallo'],
            '_locales' => ['en', 'de'],
            'sections' => [
                [
                    '_uid' => 'sec1', '_block' => 'section', 'heading' => 'Work', 'body' => self::EVIL,
                    'items' => [[
                        '_uid' => 'hero2', '_block' => 'hero', 'headline' => ['en' => 'Inner', 'de' => 'Innen'],
                        'tradeoff' => [['_uid' => 'note1', '_block' => 'tradeoff_note', 'chose' => 'speed']],
                    ]],
                ],
                [
                    '_uid' => 'prose1', '_block' => 'prose',
                    'body' => ['en' => self::EVIL, 'de' => '<p>Deutsch</p>'],
                    'caption' => ['en' => '<b>plain</b>', 'de' => 'Bildtext'],
                    'quotes' => [['_uid' => 'q1', '_block' => 'quote', 'text' => ['en' => self::EVIL, 'de' => '<em>Zitat</em>']]],
                ],
                ['_uid' => 'hero1', '_block' => 'hero', 'headline' => ['en' => 'Hello', 'de' => 'Hallo'], 'intro' => ['en' => 'i']],
                ['_uid' => 'gone', '_block' => 'no_such_block', 'x' => 'y'],
                ['_uid' => 'q2', '_block' => 'quote', 'text' => 'data-only at the top is skipped'],
            ],
        ]), null);

        foreach (['en' => '/hello', 'de' => '/de/hallo'] as $locale => $path) {
            // The page without the debug toolbar (timings and a nonce change per request).
            $html = (string) $this->get($path)->getBody();
            $html = explode('<div id="preflow-debug-toolbar">', $html)[0];
            $expected = dirname(__DIR__) . "/Fixtures/blocks-render/page-{$locale}.html";
            if (getenv('BLOCKS_RENDER_RECORD') === '1') {
                @mkdir(dirname($expected), 0777, true);
                file_put_contents($expected, $html);
            }

            $this->assertStringContainsString('data-folio-block="hero2" data-folio-label="Hero"', $html, $locale);
            $this->assertStringNotContainsString('steal()', $html, $locale);
            $this->assertSame((string) file_get_contents($expected), $html, $locale);
        }
    }
}
