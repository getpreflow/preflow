<?php

declare(strict_types=1);

namespace Preflow\Fields\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Fields\CodeOptions;
use Preflow\Fields\ColorOptions;
use Preflow\Fields\FieldOptions;
use Preflow\Fields\Rules\ValidCode;
use Preflow\Fields\Values\Code;
use Preflow\Fields\Values\Color;
use Preflow\Fields\Values\Email;
use Preflow\Fields\Values\JsonText;
use Preflow\Validation\Message;
use Preflow\Validation\ValidationContext;

/** The values and options of the email, colour, code and JSON fields (0.91). */
final class ColorCodeJsonEmailTest extends TestCase
{
    public function test_an_email_is_text_with_a_safe_mailto(): void
    {
        $this->assertSame('mailto:team@example.org', Email::fromStored(' team@example.org ')?->href());
        $this->assertSame('team@example.org', Email::fromStored(' team@example.org ')?->toStored());
        $this->assertSame('mailto:a%3Fbcc%3Dx@example.org', Email::fromStored('a?bcc=x@example.org')?->href());
        $this->assertNull(Email::fromStored('not an address')?->href());
        foreach ([null, '', '  ', 5, []] as $empty) {
            $this->assertNull(Email::fromStored($empty));
        }
    }

    public function test_a_colour_is_read_as_people_type_it_and_stored_lowercase(): void
    {
        foreach (['#C00000' => '#c00000', 'c00000' => '#c00000', '#C00' => '#cc0000', '#abcd' => '#aabbccdd', ' #1A2B3C80 ' => '#1a2b3c80'] as $typed => $stored) {
            $this->assertSame($stored, Color::parse((string) $typed)?->toStored(), (string) $typed);
        }
        foreach ([null, '', 'red', '#12', '#12345', '#1234567', 5] as $none) {
            $this->assertNull(Color::fromStored($none), json_encode($none) ?: '');
        }
        $this->assertSame(1.0, Color::parse('#c00000')?->alpha());
        $this->assertSame(0.502, Color::parse('#c0000080')?->alpha());
        $this->assertSame('#c00000', Color::parse('#c0000080')?->opaque());
    }

    public function test_a_palette_is_a_list_or_a_map_of_names(): void
    {
        $this->assertSame([['value' => '#c00000', 'label' => null], ['value' => '#0a5ca8', 'label' => null]], ColorOptions::fromConfig(['palette' => ['#C00000', '#0a5ca8']])->palette);
        $options = ColorOptions::fromConfig(['palette' => ['Brand red' => '#C00000'], 'palette_only' => true]);
        $this->assertSame([['value' => '#c00000', 'label' => 'Brand red']], $options->palette);
        $this->assertTrue($options->paletteOnly);
        $this->assertFalse(ColorOptions::fromConfig(['palette_only' => true])->paletteOnly, 'no palette: nothing to restrict to');
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function badConfigs(): iterable
    {
        yield 'palette as text' => [['palette' => 'red'], '"palette" must be a list of colours such as ["#c00000", "#0a5ca8"] or a map such as {"Brand red": "#c00000"}.'];
        yield 'a palette colour' => [['palette' => ['#c00000', 'red']], '"palette" lists "red"; write each colour as #rrggbb (or #rrggbbaa).'];
        yield 'palette_only text' => [['palette' => ['#c00000'], 'palette_only' => 'yes'], '"palette_only" must be true or false.'];
        yield 'palette_only alone' => [['palette_only' => true], '"palette_only" needs a "palette" naming the colours that are allowed.'];
    }

    /** @param array<string, mixed> $config */
    #[\PHPUnit\Framework\Attributes\DataProvider('badConfigs')]
    public function test_a_colour_config_mistake_names_what_to_write(array $config, string $message): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage($message);
        FieldOptions::check('color', $config);
    }

    public function test_code_is_stored_with_its_language_and_output_escaped(): void
    {
        $code = new Code("echo '<b>' . \$x;", 'php');

        $this->assertSame(['code' => "echo '<b>' . \$x;", 'language' => 'php'], $code->toStored());
        $this->assertEquals($code, Code::fromStored($code->toStored()));
        $this->assertEquals($code, Code::fromStored(json_encode($code->toStored())));
        $this->assertSame('<pre><code class="language-php">echo &#039;&lt;b&gt;&#039; . $x;</code></pre>', $code->html());
        $this->assertSame('<pre><code>a &amp; b</code></pre>', (new Code('a & b'))->html());
        $this->assertSame('<pre><code>x</code></pre>', (new Code('x', '"><script>'))->html(), 'a language that is no short name is no class');
        $this->assertSame(['code' => 'plain', 'language' => null], Code::fromStored('plain')?->toStored(), 'plain text is code without a language');
        foreach ([null, '', '{}', ['language' => 'php'], ['code' => ''], ['code' => 5]] as $none) {
            $this->assertNull(Code::fromStored($none), json_encode($none) ?: '');
        }
    }

    /** @param array<string, mixed> $config */
    private function checkCode(mixed $value, array $config = []): true|Message
    {
        return (new ValidCode(CodeOptions::fromConfig($config)))->validate($value, new ValidationContext('snippet', []));
    }

    public function test_the_code_check_looks_at_the_language(): void
    {
        $this->assertTrue($this->checkCode(['code' => 'x', 'language' => 'c++']));
        $this->assertTrue($this->checkCode(['code' => 'x', 'language' => null]));
        $this->assertTrue($this->checkCode('plain code'));
        $this->assertTrue($this->checkCode(['code' => 'x', 'language' => 'php'], ['languages' => ['php', 'html']]));
        $this->assertTrue($this->checkCode(null));

        $this->assertSame('The language must be a short name such as php or javascript (letters, digits and + # . _ -).', (string) $this->checkCode(['code' => 'x', 'language' => 'a b"c']));
        $this->assertSame('Must be one of: php, html.', (string) $this->checkCode(['code' => 'x', 'language' => 'ruby'], ['languages' => ['php', 'html']]));
        $this->assertSame('validation.required', $this->checkCode(['code' => '', 'language' => 'php'])->key ?? null);
        $this->assertSame('validation.code_language', $this->checkCode(['code' => 'x', 'language' => 5])->key ?? null);
    }

    public function test_a_code_config_mistake_names_what_to_write(): void
    {
        foreach ([
            [['languages' => 'php'], '"languages" must be a list such as ["php", "javascript"].'],
            [['languages' => ['php', 'my lang']], '"languages" lists "my lang"; use short names such as php, javascript or c++'],
            [['languages' => ['php'], 'default_language' => 'html'], '"default_language" is "html", which "languages" does not list; add it there or choose one of: php.'],
            [['default_language' => 5], '"default_language" is 5; use a short name such as php.'],
            [['translatable' => true], 'A code field cannot be translatable'],
        ] as [$config, $message]) {
            try {
                FieldOptions::check('code', $config);
                $this->fail('accepted ' . json_encode($config));
            } catch (\LogicException $e) {
                $this->assertStringContainsString($message, $e->getMessage());
            }
        }
        FieldOptions::check('code', ['languages' => ['php', 'html'], 'default_language' => 'html']);
    }

    public function test_json_is_kept_as_written_and_decoded_on_demand(): void
    {
        $this->assertSame('{"b": 1,  "a": [1, 2]}', JsonText::fromStored("  {\"b\": 1,  \"a\": [1, 2]}\n"));
        $this->assertSame(['b' => 1, 'a' => [1, 2]], JsonText::decode('{"b": 1, "a": [1, 2]}'));
        $this->assertSame([1, 2], JsonText::decode(' [1, 2] '));
        $this->assertSame('text', JsonText::decode('"text"'));
        $this->assertSame("{\n    \"a\": 1\n}", JsonText::fromStored(['a' => 1]), 'a seed that stored an array');
        foreach ([null, '', ' ', [], 5] as $none) {
            $this->assertNull(JsonText::fromStored($none), json_encode($none) ?: '');
        }
        $this->assertNull(JsonText::decode('{broken'), 'reading never fails');
    }

    public function test_email_and_json_fields_cannot_be_translatable(): void
    {
        foreach (['email', 'json'] as $type) {
            try {
                FieldOptions::check($type, ['translatable' => true]);
                $this->fail($type);
            } catch (\LogicException $e) {
                $this->assertSame(($type === 'email' ? 'An' : 'A') . " {$type} field cannot be translatable; it holds the same value in every language. Remove \"translatable\".", $e->getMessage());
            }
        }
        FieldOptions::check('string', ['translatable' => true]);
    }
}
