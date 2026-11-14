<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Field;

use PHPUnit\Framework\TestCase;
use Preflow\Data\Schema\ColumnKind;
use Preflow\Folio\Content\FrontendConfigException;
use Preflow\Folio\Content\TypeCatalog;
use Preflow\Folio\Field\DisplaysInAdmin;
use Preflow\Folio\Field\FieldContext;
use Preflow\Folio\Field\HasColumnKind;
use Preflow\Folio\Field\SearchableText;
use Preflow\Folio\Field\Types\TagsFieldType;
use Preflow\Validation\ValidationContext;
use Preflow\Validation\ValidationRule;

/** The tag field (0.77): a list of short texts, stored as a JSON list in a text column. */
final class TagsFieldTypeTest extends TestCase
{
    private TagsFieldType $type;

    protected function setUp(): void
    {
        $this->type = new TagsFieldType();
    }

    public function test_key_and_capabilities(): void
    {
        $this->assertSame('tags', $this->type->key());
        $this->assertInstanceOf(SearchableText::class, $this->type);
        $this->assertInstanceOf(HasColumnKind::class, $this->type);
        $this->assertInstanceOf(DisplaysInAdmin::class, $this->type);
        $this->assertSame(ColumnKind::Text, $this->type->columnKind([]));
        $this->assertSame(['fields.js'], $this->type->assets());
    }

    public function test_normalises_a_posted_list(): void
    {
        $this->assertSame(['Folio', 'members', 'AI translation'], $this->type->normalizeInput([' Folio ', 'members', '', '  ', 'AI translation'], []));
    }

    public function test_normalises_a_comma_string(): void
    {
        $this->assertSame(['Folio', 'members', 'AI translation'], $this->type->normalizeInput('Folio, members,, AI translation ,', []));
        $this->assertSame([], $this->type->normalizeInput('', []));
        $this->assertSame([], $this->type->normalizeInput(null, []));
    }

    public function test_drops_duplicates_ignoring_case_keeping_the_first(): void
    {
        $this->assertSame(['Folio', 'Über'], $this->type->normalizeInput(['Folio', 'folio', 'Über', 'ÜBER', 'FOLIO'], []));
    }

    public function test_tags_are_nfc_so_a_decomposed_umlaut_is_the_same_tag(): void
    {
        if (!class_exists(\Normalizer::class)) {
            $this->markTestSkipped('needs the intl extension');
        }
        $decomposed = "U\u{0308}bersetzung";
        $this->assertSame(['Übersetzung'], $this->type->normalizeInput([$decomposed, 'übersetzung'], []));
    }

    public function test_strips_control_characters(): void
    {
        $this->assertSame(['ab', 'cd'], $this->type->normalizeInput(["a\x00b", "c\td\n", "\x7F"], []));
    }

    public function test_ignores_what_is_not_text(): void
    {
        $this->assertSame(['a', '3'], $this->type->normalizeInput(['a', ['nested'], 3, null, true], []));
    }

    public function test_keeps_what_is_over_the_limits_so_the_rule_refuses_it(): void
    {
        $many = array_map(static fn (int $i): string => 't' . $i, range(1, 25));
        $this->assertCount(25, $this->type->normalizeInput($many, []));
    }

    public function test_the_limits_refuse_too_many_and_too_long(): void
    {
        $rule = $this->rule([]);
        $ctx = new ValidationContext('uses', []);
        $this->assertTrue($rule->validate($this->type->toStorage(['a', 'b']), $ctx));
        $this->assertTrue($rule->validate(null, $ctx));
        $this->assertTrue($rule->validate([], $ctx));

        $many = array_map(static fn (int $i): string => 't' . $i, range(1, 21));
        $this->assertSame('At most 20 tags.', (string) $rule->validate($this->type->toStorage($many), $ctx));
        $this->assertSame('A tag can have at most 40 characters.', (string) $rule->validate([str_repeat('ä', 41)], $ctx));
        $this->assertTrue($rule->validate([str_repeat('ä', 40)], $ctx), 'characters, not bytes');

        $small = $this->rule(['max' => 2, 'maxLength' => 3]);
        $this->assertSame('At most 2 tags.', (string) $small->validate(['a', 'b', 'c'], $ctx));
        $this->assertSame('A tag can have at most 3 characters.', (string) $small->validate(['abcd'], $ctx));
    }

    public function test_storage_is_a_json_list_with_readable_umlauts(): void
    {
        $this->assertSame('["Folio","Übersetzung","a/b"]', $this->type->toStorage(['Folio', 'Übersetzung', 'a/b']));
        $this->assertNull($this->type->toStorage([]), 'no tags is no value, so "required" refuses it');
        $this->assertSame(['Folio', 'Übersetzung'], $this->type->fromStorage('["Folio","Übersetzung"]'));
        $this->assertSame(['Folio'], $this->type->fromStorage(['Folio']), 'a list as the JSON storage or a class model holds it');
        $this->assertSame([], $this->type->fromStorage(null));
        $this->assertSame([], $this->type->fromStorage(''));
    }

    public function test_reads_a_legacy_comma_string(): void
    {
        $this->assertSame(['Folio', 'members', 'AI translation'], $this->type->fromStorage('Folio, members, AI translation'));
        $this->assertSame(['Folio'], $this->type->fromStorage('Folio'));
    }

    public function test_frontend_is_an_escaped_list(): void
    {
        $this->assertSame('<ul class="tags"><li>Folio</li><li>&lt;b&gt; &amp; co</li></ul>', $this->type->renderFrontend('["Folio","<b> & co"]', []));
        $this->assertSame('', $this->type->renderFrontend('[]', []));
        $this->assertSame('', $this->type->renderFrontend(null, []));
    }

    public function test_admin_shows_the_first_tags_and_how_many_more(): void
    {
        $this->assertSame('a, &lt;b&gt;, c +2', $this->type->renderAdmin('["a","<b>","c","d","e"]', []));
        $this->assertSame('a, b', $this->type->renderAdmin('["a","b"]', []));
        $this->assertSame('', $this->type->renderAdmin('[]', []));
    }

    public function test_the_editor_without_script_is_one_comma_separated_input(): void
    {
        $html = $this->type->renderEditor(new FieldContext('uses', 'Uses', null, '["Folio","members"]'));
        $this->assertMatchesRegularExpression('~<input type="text" id="uses" name="uses" value="Folio, members"[^>]*class="folio-tags-plain"~', $html);
        $this->assertStringContainsString('<label for="uses">Uses</label>', $html);
    }

    public function test_the_editor_with_script_has_chips_and_an_add_input(): void
    {
        $html = $this->type->renderEditor(new FieldContext('uses', 'Uses', 'Shown on the card.', '["Folio","<i>"]', ['Too many.'], ['options' => ['Folio', 'AI']]));
        $this->assertStringContainsString('data-folio-tags', $html);
        $this->assertStringContainsString('data-folio-tags-name="uses[]"', $html);
        // Each chip: the text, ✕ named "Remove …", and its hidden input, nameless until the script names it.
        $this->assertStringContainsString('<span class="folio-tag-text">&lt;i&gt;</span>', $html);
        $this->assertStringContainsString('aria-label="Remove &lt;i&gt;"', $html);
        $this->assertStringContainsString('<input type="hidden" value="Folio" data-folio-tag-value>', $html);
        $this->assertStringNotContainsString(' name="uses[]"', $html, 'without script only the plain input posts');
        $this->assertStringContainsString('placeholder="Add…"', $html);
        $this->assertStringContainsString('id="uses-tags-add"', $html);
        $this->assertStringContainsString('aria-label="Add to Uses"', $html);
        $this->assertStringContainsString('data-folio-no-edit', $html);
        $this->assertStringContainsString('Enter or comma adds one.', $html);
        $this->assertStringContainsString('Separate them with commas.', $html);
        $this->assertStringContainsString('Shown on the card.', $html);
        $this->assertStringContainsString('<datalist id="uses-tag-options"><option value="Folio"></option><option value="AI"></option></datalist>', $html);
        $this->assertStringContainsString('list="uses-tag-options"', $html);
        $this->assertStringContainsString('has-error', $html);
        $this->assertStringContainsString('<div class="form-error">Too many.</div>', $html);
        $this->assertStringContainsString('data-max="20"', $html);
        $this->assertStringContainsString('data-max-length="40"', $html);
        $this->assertStringNotContainsString(' style=', $html);
    }

    public function test_a_translatable_tags_field_is_a_boot_error(): void
    {
        $this->assertBootError(['type' => 'tags', 'translatable' => true], 'Type "thing": field "uses": A tags field cannot be translatable; keep one list for every language.');
    }

    public function test_translatable_is_read_as_the_type_registry_reads_it(): void
    {
        $this->assertBootError(['type' => 'tags', 'translatable' => 1], 'Type "thing": field "uses": A tags field cannot be translatable; keep one list for every language.');
        $this->assertBootError(['type' => 'tags', 'translatable' => 'yes'], 'Type "thing": field "uses": A tags field cannot be translatable; keep one list for every language.');
    }

    public function test_a_translatable_tags_field_in_a_block_is_refused(): void
    {
        $fields = new \Preflow\Folio\Field\FieldTypeRegistry();
        $fields->register(new \Preflow\Folio\Field\Types\StringFieldType());
        $fields->register($this->type);
        $blocks = new \Preflow\Folio\Field\Types\BlocksFieldType(
            new \Preflow\Blocks\BlockRegistry([\Preflow\Folio\Tests\Fixtures\Blocks\TranslatedTagsBlock::class]),
            $fields,
            4,
            new \Preflow\Validation\ValidatorFactory(new \Preflow\Validation\RuleFactory()),
        );
        $fields->register($blocks);
        $uid = str_repeat('a', 16);

        $this->expectException(FrontendConfigException::class);
        $this->expectExceptionMessage('Block "translated_tags": field "topics": A tags field cannot be translatable; keep one list for every language.');
        $blocks->buildValue(['_order' => [$uid], $uid => ['_block' => 'translated_tags', 'topics' => ['a']]], null, [], new \Preflow\Folio\Field\BuildContext(new \Preflow\Data\Locales(['en', 'de'], 'en'), 'en', false));
    }

    public function test_invalid_limits_and_options_are_boot_errors(): void
    {
        $this->assertBootError(['type' => 'tags', 'max' => 0], 'Type "thing": field "uses": Tags field option "max" must be a whole number of 1 or more.');
        $this->assertBootError(['type' => 'tags', 'maxLength' => 'x'], 'Type "thing": field "uses": Tags field option "maxLength" must be a whole number of 1 or more.');
        $this->assertBootError(['type' => 'tags', 'options' => ['a', '']], 'Type "thing": field "uses": Tags field option "options" must be a list of texts.');
    }

    /** @param array<string, mixed> $config */
    private function rule(array $config): ValidationRule
    {
        $rules = $this->type->rules($config);
        $this->assertCount(1, $rules);
        $this->assertInstanceOf(ValidationRule::class, $rules[0]);

        return $rules[0];
    }

    /** @param array<string, mixed> $field */
    private function assertBootError(array $field, string $message): void
    {
        $dir = sys_get_temp_dir() . '/folio_tags_' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir . '/thing.json', (string) json_encode(['key' => 'thing', 'fields' => ['uses' => $field]]));
        try {
            (new TypeCatalog($dir))->all();
            $this->fail('accepted: ' . json_encode($field));
        } catch (FrontendConfigException $e) {
            $this->assertSame($message, $e->getMessage());
        } finally {
            unlink($dir . '/thing.json');
            rmdir($dir);
        }
    }
}
