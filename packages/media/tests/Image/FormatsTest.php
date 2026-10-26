<?php

declare(strict_types=1);

namespace Preflow\Media\Tests\Image;

use PHPUnit\Framework\TestCase;
use Preflow\Media\Image\Format;
use Preflow\Media\Image\Formats;

final class FormatsTest extends TestCase
{
    public function test_a_cover_preset_is_a_box(): void
    {
        $formats = Formats::fromConfig(['cover-thumb' => ['width' => 200, 'height' => 200, 'fit' => 'cover', 'widths' => [200, 400]]]);

        $format = $formats->get('cover-thumb');
        $this->assertInstanceOf(Format::class, $format);
        $this->assertTrue($format->isBox());
        $this->assertSame(1.0, $format->ratio());
        $this->assertSame([200, 400], $format->widths);
        $this->assertSame(['cover-thumb'], array_keys($formats->boxes()));
    }

    public function test_a_width_only_preset_is_max_and_not_a_box(): void
    {
        $formats = Formats::fromConfig(['step-side' => ['width' => 500]]);

        $format = $formats->get('step-side');
        $this->assertSame('max', $format->fit);
        $this->assertFalse($format->isBox());
        $this->assertNull($format->ratio());
        $this->assertSame([], $formats->boxes());
        $this->assertNull($formats->get('nope'));
    }

    public function test_ratio_of_a_contain_box(): void
    {
        $format = Formats::fromConfig(['wide' => ['width' => 300, 'height' => 100, 'fit' => 'contain']])->get('wide');
        $this->assertSame(3.0, $format->ratio());
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('bad')]
    public function test_a_bad_preset_names_config_and_preset(string $name, array $preset): void
    {
        try {
            Formats::fromConfig([$name => $preset]);
            $this->fail('accepted');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('config/image.php', $e->getMessage());
            $this->assertStringContainsString($name, $e->getMessage());
        }
    }

    /** @return array<string, array{string, array<string, mixed>}> */
    public static function bad(): array
    {
        $box = ['width' => 200, 'height' => 200, 'fit' => 'cover'];

        return [
            'unknown fit' => ['fill-it', ['width' => 200, 'fit' => 'fill']],
            'cover without height' => ['no-height', ['width' => 200, 'fit' => 'cover']],
            'contain without width' => ['no-width', ['height' => 200, 'fit' => 'contain']],
            'reserved edit' => ['edit', $box],
            'reserved preview' => ['preview', $box],
            'reserved oriented' => ['oriented', $box],
            'uppercase' => ['Cover', $box],
            'underscore' => ['a_b', $box],
        ];
    }

    public function test_key_is_eight_hex_characters_of_the_input_hash(): void
    {
        $inputs = [1, 0, false, null];
        $this->assertSame(substr(hash('sha256', json_encode($inputs)), 0, 8), Formats::key($inputs));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{8}$/', Formats::key($inputs));
    }
}
