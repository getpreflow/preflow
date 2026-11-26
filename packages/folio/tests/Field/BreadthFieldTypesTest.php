<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Field;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Preflow\Data\Schema\ColumnKind;
use Preflow\Folio\Content\FrontendConfigException;
use Preflow\Folio\Content\TypeCatalog;
use Preflow\Folio\Field\DisplaysInAdmin;
use Preflow\Folio\Field\FieldContext;
use Preflow\Folio\Field\HasColumnKind;
use Preflow\Folio\Field\SearchableText;
use Preflow\Folio\Field\StructuredFieldType;
use Preflow\Folio\Field\Types\CodeFieldType;
use Preflow\Folio\Field\Types\ColorFieldType;
use Preflow\Folio\Field\Types\EmailFieldType;
use Preflow\Folio\Field\Types\JsonFieldType;
use Preflow\Folio\Field\Types\PhoneFieldType;
use Preflow\Folio\Tests\Support\TempRoots;

/**
 * The email, phone, colour, code and JSON inputs (0.91): the values, checks and storage are
 * preflow/fields' (and the core rules); Folio draws the editor, reads what was posted, and shows the value.
 */
final class BreadthFieldTypesTest extends TestCase
{
    public function test_capabilities_and_no_rules_of_their_own(): void
    {
        $this->assertInstanceOf(SearchableText::class, new EmailFieldType(), 'an email is text: loose search covers it');
        foreach ([new PhoneFieldType(), new ColorFieldType(), new CodeFieldType(), new JsonFieldType()] as $type) {
            $this->assertNotInstanceOf(SearchableText::class, $type, $type->key() . ' is not searched');
        }
        foreach ([new EmailFieldType(), new PhoneFieldType(), new ColorFieldType(), new CodeFieldType(), new JsonFieldType()] as $type) {
            $this->assertSame([], $type->rules(['default_region' => 'CH', 'palette_only' => true]), $type->key() . ': the core\'s check applies in Folio, this adds none');
            $this->assertNotInstanceOf(StructuredFieldType::class, $type, 'the posted value is the whole value: no merge with the stored one');
        }
        $this->assertSame(ColumnKind::Short, (new EmailFieldType())->columnKind([]));
        $this->assertSame(ColumnKind::Short, (new ColorFieldType())->columnKind([]));
        $this->assertSame(['email', 'phone', 'color', 'code', 'json'], array_map(static fn ($t): string => $t->key(), [new EmailFieldType(), new PhoneFieldType(), new ColorFieldType(), new CodeFieldType(), new JsonFieldType()]));
    }

    public function test_email_input_and_output(): void
    {
        $type = new EmailFieldType();
        $html = $type->renderEditor(new FieldContext('mail', label: 'Email', value: 'a@example.org'));
        $this->assertStringContainsString('type="email"', $html);
        $this->assertStringContainsString('name="mail"', $html);
        $this->assertStringContainsString('value="a@example.org"', $html);

        $this->assertSame('a@example.org', $type->normalizeInput("  a@example.org \n", []));
        $this->assertSame('', $type->normalizeInput(null, []));
        $this->assertSame('<a href="mailto:a@example.org">a@example.org</a>', $type->renderFrontend('a@example.org', []));
        $this->assertSame('&lt;b&gt;', $type->renderFrontend('<b>', []), 'no address: the text, escaped, no link');
        $this->assertSame('', $type->renderFrontend('', []));
    }

    public function test_phone_input_keeps_what_was_typed_and_adds_the_dialled_form(): void
    {
        $type = new PhoneFieldType();

        $this->assertSame(['as_typed' => '044 123 45 67', 'e164' => '+41441234567'], $type->normalizeInput(' 044 123 45 67 ', ['default_region' => 'CH']));
        $this->assertSame(['as_typed' => '044 123 45 67', 'e164' => null], $type->normalizeInput('044 123 45 67', []), 'no region: kept as typed, the check refuses it');
        $this->assertNull($type->normalizeInput('  ', []));
        $this->assertNull($type->normalizeInput(['x'], []));

        $html = $type->renderEditor(new FieldContext('tel', label: 'Phone', value: ['as_typed' => '044 123 45 67', 'e164' => '+41441234567']));
        $this->assertStringContainsString('type="tel"', $html);
        $this->assertStringContainsString('value="044 123 45 67"', $html);
        $this->assertStringContainsString('Dialled as +41441234567.', $html);
        $this->assertStringNotContainsString('Dialled as', $type->renderEditor(new FieldContext('tel', value: ['as_typed' => '044 123 45 67', 'e164' => '+41441234567'], errors: ['Nope'])), 'not beside an error');
        $this->assertStringContainsString('value="+41 44 1"', $type->renderEditor(new FieldContext('tel', value: '+41 44 1')), 'a seed that stored plain text');

        $this->assertSame('<a href="tel:+41441234567">044 123 45 67</a>', $type->renderFrontend('{"as_typed":"044 123 45 67","e164":"+41441234567"}', []));
        $this->assertSame('<a href="tel:112">112</a>', $type->renderFrontend(['as_typed' => '112', 'e164' => null], []));
        $this->assertSame('044 123 45 67', $type->renderAdmin(['as_typed' => '044 123 45 67', 'e164' => '+41441234567'], []));
        $this->assertSame(['as_typed' => 'a', 'e164' => null], $type->fromStorage('{"as_typed":"a"}'));
        $this->assertNull($type->toStorage(null));
    }

    public function test_color_input_reads_leniently_and_keeps_the_rest_for_the_check(): void
    {
        $type = new ColorFieldType();

        $this->assertSame('#c00000', $type->normalizeInput(' C00000 ', []));
        $this->assertSame('#cc0000', $type->normalizeInput('#C00', []));
        $this->assertSame('#c0000080', $type->normalizeInput('#C0000080', []));
        $this->assertSame('red', $type->normalizeInput('red', []), 'kept as typed: the check says it is no colour');
        $this->assertNull($type->normalizeInput('', []));
        $this->assertNull($type->normalizeInput('  ', []));

        $this->assertSame('#c00000', $type->renderFrontend('#C00000', []));
        $this->assertSame('', $type->renderFrontend(null, []));
        $this->assertSame('<svg class="folio-swatch folio-swatch-inline" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><rect width="24" height="24" rx="5" fill="#c00000"/></svg>#c00000', $type->renderAdmin('#c00000', []));
    }

    public function test_color_editor_with_a_palette_offers_swatches_that_fill_the_text(): void
    {
        $html = (new ColorFieldType())->renderEditor(new FieldContext('tint', label: 'Tint', value: '#c0000080', config: ['palette' => ['Brand red' => '#c00000', 'Sky' => '#0A5CA8']]));

        $this->assertStringContainsString('data-folio-color', $html);
        $this->assertStringContainsString('<input type="text" id="folio-color-tint" name="tint" value="#c0000080" data-folio-color-text', $html);
        $this->assertStringContainsString('<input type="color" class="folio-color-picker" data-folio-color-picker value="#c00000"', $html, 'the picker has no name: only the text posts');
        $this->assertStringContainsString('data-folio-swatch="#c00000" aria-label="Brand red #c00000"', $html);
        $this->assertStringContainsString('<rect width="24" height="24" rx="5" fill="#c00000"/>', $html, 'the colour is a fill attribute: no style attribute under a strict CSP');
        $this->assertStringNotContainsString('style=', $html);
        $this->assertStringContainsString('data-folio-swatch="#0a5ca8"', $html, 'palette colours are lowercase');
        $this->assertStringContainsString('data-folio-color-clear', $html);
        $this->assertSame(['fields.js'], (new ColorFieldType())->assets());
    }

    public function test_color_editor_with_palette_only_is_a_choice_of_the_palette(): void
    {
        $type = new ColorFieldType();
        $config = ['palette' => ['Brand red' => '#c00000', 'Sky' => '#0a5ca8'], 'palette_only' => true];
        $html = $type->renderEditor(new FieldContext('accent', label: 'Accent', value: '#0a5ca8', config: $config));

        $this->assertStringContainsString('<fieldset class="form-group folio-color folio-color-palette">', $html);
        $this->assertStringContainsString('<input type="radio" name="accent" value="" >', str_replace('value=""><', 'value="" ><', $html));
        $this->assertStringContainsString('<input type="radio" name="accent" value="#0a5ca8" checked>', $html);
        $this->assertStringContainsString('<input type="radio" name="accent" value="#c00000">', $html);
        $this->assertStringNotContainsString('type="text"', $html, 'nothing else can be typed');

        $outside = $type->renderEditor(new FieldContext('accent', value: '#123456', config: $config));
        $this->assertStringContainsString('<input type="radio" name="accent" value="#123456" checked>', $outside, 'a stored colour outside the palette stays visible');
        $this->assertStringContainsString('#123456 (not in the palette)', $outside);
    }

    public function test_code_input_posts_code_and_language(): void
    {
        $type = new CodeFieldType();
        $this->assertSame(['code' => "a\nb", 'language' => 'php'], $type->normalizeInput(['code' => "a\r\nb", 'language' => ' php '], []));
        $this->assertSame(['code' => 'x', 'language' => null], $type->normalizeInput(['code' => 'x', 'language' => ''], []));
        $this->assertNull($type->normalizeInput(['code' => " \n ", 'language' => 'php'], []), 'a language without code is no value');
        $this->assertNull($type->normalizeInput(null, []));
        $this->assertSame(['code' => 'plain', 'language' => null], $type->normalizeInput('plain', []));

        $free = $type->renderEditor(new FieldContext('snippet', label: 'Snippet', value: ['code' => "<b>\n", 'language' => 'php']));
        $this->assertStringContainsString('name="snippet[language]" value="php"', $free);
        $this->assertStringContainsString('name="snippet[code]" class="folio-input-mono"', $free);
        $this->assertStringContainsString('&lt;b&gt;', $free, 'the code is escaped in the textarea');

        $listed = $type->renderEditor(new FieldContext('snippet', value: null, config: ['languages' => ['php', 'html'], 'default_language' => 'html']));
        $this->assertStringContainsString('<select id="folio-code-snippet-language" name="snippet[language]">', $listed);
        $this->assertStringContainsString('<option value="html" selected>html</option>', $listed, 'a new value starts with the default language');
        $this->assertStringContainsString('<option value="">No language</option>', $listed);

        $legacy = $type->renderEditor(new FieldContext('snippet', value: ['code' => 'x', 'language' => 'ruby'], config: ['languages' => ['php']]));
        $this->assertStringContainsString('<option value="ruby" selected>ruby</option>', $legacy, 'a stored language the field no longer offers stays visible');
    }

    public function test_code_and_json_output_is_escaped(): void
    {
        $code = new CodeFieldType();
        $this->assertSame('<pre><code class="language-html">&lt;script&gt;x&lt;/script&gt;</code></pre>', $code->renderFrontend(['code' => '<script>x</script>', 'language' => 'html'], []));
        $this->assertSame('', $code->renderFrontend(null, []));
        $this->assertSame('php: echo 1; echo 2;', $code->renderAdmin(['code' => "echo 1;\necho 2;", 'language' => 'php'], []), 'one line in a list cell');
        $this->assertSame(['code' => 'x', 'language' => 'php'], $code->fromStorage('{"code":"x","language":"php"}'));

        $json = new JsonFieldType();
        $this->assertSame('<pre><code class="language-json">{&quot;a&quot;: &quot;&lt;b&gt;&quot;}</code></pre>', $json->renderFrontend('{"a": "<b>"}', []));
        $this->assertInstanceOf(DisplaysInAdmin::class, $json);
        $this->assertSame('{ &quot;a&quot;: 1 }', $json->renderAdmin("{\n  \"a\": 1\n}", []));
        $this->assertStringEndsWith('…', $json->renderAdmin(json_encode(array_fill(0, 40, 'word')), []));
    }

    public function test_json_input(): void
    {
        $type = new JsonFieldType();
        $this->assertSame("{\"a\": 1}", $type->normalizeInput("  {\"a\": 1}\r\n", []));
        $this->assertNull($type->normalizeInput('  ', []));
        $html = $type->renderEditor(new FieldContext('settings', label: 'Settings', value: '{"a": "<b>"}'));
        $this->assertStringContainsString('<textarea', $html);
        $this->assertStringContainsString('folio-input-mono', $html);
        $this->assertStringContainsString('&lt;b&gt;', $html);
        $this->assertStringContainsString('&quot;a&quot;: 1', $type->renderEditor(new FieldContext('settings', value: ['a' => 1])), 'a seed that stored an array shows its JSON');
        $this->assertNull($type->toStorage(' '));
        $this->assertSame('{"a":1}', $type->toStorage(' {"a":1} '));
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function bootMistakes(): iterable
    {
        yield 'phone region' => [['type' => 'phone', 'default_region' => 'Atlantis'], 'Type "thing": field "f": "default_region" is "Atlantis"; use one of CH, LI, DE'];
        yield 'phone translatable' => [['type' => 'phone', 'translatable' => true], 'Type "thing": field "f": A phone field cannot be translatable; it holds the same value in every language. Remove "translatable".'];
        yield 'email translatable' => [['type' => 'email', 'translatable' => true], 'Type "thing": field "f": An email field cannot be translatable; it holds the same value in every language. Remove "translatable".'];
        yield 'json translatable' => [['type' => 'json', 'translatable' => true], 'Type "thing": field "f": A json field cannot be translatable; it holds the same value in every language. Remove "translatable".'];
        yield 'palette_only alone' => [['type' => 'color', 'palette_only' => true], 'Type "thing": field "f": "palette_only" needs a "palette" naming the colours that are allowed.'];
        yield 'palette colour' => [['type' => 'color', 'palette' => ['red']], 'Type "thing": field "f": "palette" lists "red"; write each colour as #rrggbb (or #rrggbbaa).'];
        yield 'code languages' => [['type' => 'code', 'languages' => ['php', 'my lang']], 'Type "thing": field "f": "languages" lists "my lang"; use short names such as php, javascript or c++ (letters, digits and + # . _ -).'];
    }

    /** @param array<string, mixed> $field */
    #[DataProvider('bootMistakes')]
    public function test_config_mistakes_are_boot_errors_naming_the_type_and_field(array $field, string $message): void
    {
        $dir = TempRoots::path('folio_breadth_boot_');
        mkdir($dir);
        file_put_contents($dir . '/thing.json', (string) json_encode(['key' => 'thing', 'fields' => ['f' => $field]]));
        try {
            (new TypeCatalog($dir))->all();
            $this->fail('accepted: ' . json_encode($field));
        } catch (FrontendConfigException $e) {
            $this->assertStringStartsWith($message, $e->getMessage());
        } finally {
            unlink($dir . '/thing.json');
            rmdir($dir);
        }
    }

    public function test_a_valid_config_boots(): void
    {
        $dir = TempRoots::path('folio_breadth_ok_');
        mkdir($dir);
        file_put_contents($dir . '/thing.json', (string) json_encode(['key' => 'thing', 'fields' => [
            'a' => ['type' => 'email'], 'b' => ['type' => 'phone', 'default_region' => 'ch'], 'c' => ['type' => 'color', 'palette' => ['#c00000'], 'palette_only' => true],
            'd' => ['type' => 'code', 'languages' => ['php'], 'default_language' => 'php'], 'e' => ['type' => 'json'],
        ]]));
        try {
            $this->assertCount(1, (new TypeCatalog($dir))->all());
        } finally {
            unlink($dir . '/thing.json');
            rmdir($dir);
        }
    }
}
