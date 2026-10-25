<?php

declare(strict_types=1);

namespace Preflow\Media\Tests\Color;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\RequiresFunction;
use PHPUnit\Framework\TestCase;
use Preflow\Media\Asset;
use Preflow\Media\MediaLibrary;
use Preflow\Media\Tests\Support\MediaApp;
use Preflow\View\AssetCollector;
use Preflow\View\TemplateEngineInterface;

/**
 * media_colors() and media_color_class(): an asset's colours as data and as CSS
 * variables on a class, whose rule goes once per page into the nonce'd head style
 * (CSP forbids inline styles). media_image(…, {placeholder: true}) uses the same class.
 */
#[RequiresFunction('imagewebp')]
final class ColorTemplateTest extends TestCase
{
    use MediaApp;

    private const array COLORS = [
        'color' => '#1976d2',
        'palette' => ['#1976d2', '#ffc107', '#0d47a1', '#ffe082', '#bbdefb'],
        'text_color' => '#ffffff',
    ];

    protected function setUp(): void
    {
        $this->scaffoldMediaApp();
    }

    protected function tearDown(): void
    {
        $this->removeMediaApp();
    }

    /** An uploaded image with the given colours (or none). */
    private function asset(?array $colors = self::COLORS): Asset
    {
        $image = imagecreatetruecolor(600, 400);
        ob_start();
        imagepng($image);
        $png = (string) ob_get_clean();
        $f = new Psr17Factory();
        $asset = $this->media()->upload($f->createUploadedFile($f->createStream($png), strlen($png), UPLOAD_ERR_OK, 'a.png'), null);
        (new \ReflectionMethod(MediaLibrary::class, 'save'))->invoke($this->media(), [...$asset->toData(), ...($colors ?? ['color' => '', 'palette' => [], 'text_color' => ''])]);

        return $this->media()->find($asset->id);
    }

    private function render(string $template, array $context): string
    {
        $this->writeFile('page.twig', $template);

        return $this->mediaApp()->container()->get(TemplateEngineInterface::class)->render('page.twig', $context);
    }

    private function head(): string
    {
        return $this->mediaApp()->container()->get(AssetCollector::class)->renderHead();
    }

    /** The head's <style> blocks (the rest is the collector's scripts). */
    private static function styles(string $head): string
    {
        preg_match_all('#<style[^>]*>.*?</style>#s', $head, $m);

        return implode('', $m[0]);
    }

    public function test_media_color_class_adds_one_nonced_rule_per_page(): void
    {
        $asset = $this->asset();

        $out = $this->render("{% for i in 1..5 %}<div class=\"{{ media_color_class(id) }}\"></div>{% endfor %}", ['id' => $asset->id]);

        preg_match_all('/class="(media-c-[a-z0-9]+)"/', $out, $m);
        $this->assertCount(5, $m[1]);
        $this->assertCount(1, array_unique($m[1]));
        $class = $m[1][0];
        $head = $this->head();
        $nonce = $this->mediaApp()->container()->get(AssetCollector::class)->getNonce();
        $this->assertSame(1, substr_count($head, '.' . $class . '{'));
        $this->assertSame(1, substr_count($head, '<style nonce="' . $nonce . '">'));
        $this->assertMatchesRegularExpression('/<style nonce="' . preg_quote($nonce, '/') . '">[^<]*\.' . $class . '\{[^}]*\}/', $head);
        preg_match('/\.' . $class . '\{([^}]*)\}/', $head, $rule);
        $this->assertStringContainsString('--media-color:#1976d2', $rule[1]);
        $this->assertStringContainsString('--media-color-rgb:25,118,210', $rule[1]);
        $this->assertStringContainsString('--media-text:#ffffff', $rule[1]);
        $this->assertStringContainsString('--media-palette-1:#1976d2', $rule[1]);
        $this->assertStringContainsString('--media-palette-5:#bbdefb', $rule[1]);
        $this->assertStringNotContainsString('--media-palette-6', $rule[1]);
        $this->assertStringNotContainsString('style=', $out . self::styles($head));
    }

    public function test_assets_with_the_same_colours_share_the_class_and_others_get_their_own(): void
    {
        $a = $this->asset();
        $b = $this->asset();
        $c = $this->asset(['color' => '#ffc107', 'palette' => ['#ffc107', '#1976d2', '#000000', '#ffffff', '#ff0000'], 'text_color' => '#000000']);

        $out = $this->render('{{ media_color_class(a) }} {{ media_color_class(b) }} {{ media_color_class(c) }}', ['a' => $a->id, 'b' => $b->id, 'c' => $c->id]);

        [$ca, $cb, $cc] = explode(' ', $out);
        $this->assertSame($ca, $cb);
        $this->assertNotSame($ca, $cc);
        $this->assertSame(2, substr_count($this->head(), '.media-c-'));
    }

    public function test_media_colors_returns_the_data(): void
    {
        $asset = $this->asset();

        $out = $this->render('{{ media_colors(id)|json_encode|raw }}|{{ media_colors(none) is null ? "null" }}|{{ media_colors(bare) is null ? "null" }}', ['id' => $asset->id, 'none' => null, 'bare' => $this->asset(null)->id]);

        [$json, $none, $bare] = explode('|', $out);
        $this->assertSame([
            'color' => '#1976d2',
            'rgb' => '25,118,210',
            'palette' => self::COLORS['palette'],
            'palette_rgb' => ['25,118,210', '255,193,7', '13,71,161', '255,224,130', '187,222,251'],
            'text' => '#ffffff',
        ], json_decode($json, true));
        $this->assertSame('null', $none);
        $this->assertSame('null', $bare);
        $this->assertStringNotContainsString('.media-', $this->head(), 'media_colors() adds no CSS');
    }

    public function test_media_color_class_is_empty_without_colours(): void
    {
        $out = $this->render('[{{ media_color_class(bare) }}][{{ media_color_class(null) }}][{{ media_color_class("0123456789abcdef0123456789abcdef") }}]', ['bare' => $this->asset(null)->id]);

        $this->assertSame('[][][]', $out);
        $this->assertStringNotContainsString('.media-', $this->head());
    }

    public function test_media_image_placeholder_puts_the_colour_behind_the_image(): void
    {
        $asset = $this->asset();

        $out = $this->render("{{ media_image(id, null, {placeholder: true}) }}{{ media_image(id, null, {placeholder: true}) }}{{ media_color_class(id) }}", ['id' => $asset->id]);

        preg_match('/<img [^>]*class="([^"]*)"/', $out, $m);
        $classes = explode(' ', $m[1]);
        $this->assertContains('media-placeholder', $classes);
        $color = array_values(array_filter($classes, static fn (string $c) => str_starts_with($c, 'media-c-')));
        $this->assertCount(1, $color);
        $this->assertStringEndsWith($color[0], $out, 'media_color_class() gives the same class');
        $head = $this->head();
        $this->assertSame(1, substr_count($head, '.media-placeholder{background-color:var(--media-color)}'));
        $this->assertSame(1, substr_count($head, '.' . $color[0] . '{'));
        $this->assertStringNotContainsString('style=', $out . self::styles($head));
    }

    public function test_media_image_without_placeholder_or_colours_is_unchanged(): void
    {
        $asset = $this->asset();
        $bare = $this->asset(null);

        $plain = $this->render('{{ media_image(id) }}', ['id' => $asset->id]);
        $without = $this->render('{{ media_image(id, null, {placeholder: true}) }}', ['id' => $bare->id]);

        $this->assertStringNotContainsString('media-', preg_replace('#/media/[^" ]+#', '', $plain . $without));
        $this->assertStringNotContainsString('.media-', $this->head());
    }
}
