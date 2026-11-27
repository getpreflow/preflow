<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Field;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Preflow\Ai\AiResponse;
use Preflow\Ai\StopReason;
use Preflow\Ai\TokenUsage;
use Preflow\Core\Application;
use Preflow\Data\DataManager;
use Preflow\Data\Html\HtmlCleaner;
use Preflow\Data\Html\SymfonyHtmlCleaner;
use Preflow\Data\Locales;
use Preflow\Data\TypeDefinition;
use Preflow\Data\TypeFieldDefinition;
use Preflow\Data\Values\Core\NumberValue;
use Preflow\Folio\Codes\Fields\PurposeValue;
use Preflow\Folio\Field\BuildContext;
use Preflow\Folio\Rbac\Fields\PermissionsValue;
use Preflow\Folio\Rbac\Fields\RolesValue;
use Preflow\Folio\Field\FieldTypeRegistry;
use Preflow\Folio\Field\StructuredFieldType;
use Preflow\Folio\Field\Values\EnvValue;
use Preflow\Folio\Field\Values\MatrixValue;
use Preflow\Folio\Tests\Support\AiScaffold;
use Preflow\Folio\Tests\Support\PublishingScaffold;

/**
 * Folio's field types on the core values (0.93): the admin form stores what it stored in 0.92.1
 * (the fixture posts of the field-type tests, as the driver gets them), a scalar type reads a
 * stored value as the core's output does, the form and "Write a draft" clean rich text with the
 * site's bound HtmlCleaner, and Folio's own types have a value on the DataManager's kinds.
 */
final class CoreValuesParityTest extends TestCase
{
    use PublishingScaffold;
    use AiScaffold;

    private const UID = 'aaaaaaaaaaaaaaaa';

    protected function setUp(): void
    {
        $this->scaffoldPublishing();
    }

    protected function tearDown(): void
    {
        putenv(self::aiKeyEnv());
        $this->removeScaffold();
    }

    /**
     * Folio type key, field config, the form's post, what 0.92.1 stored (DataManager::storageValues(),
     * what the driver gets).
     *
     * @return iterable<string, array{string, array<string, mixed>, mixed, mixed}>
     */
    public static function posts(): iterable
    {
        $multiple = ['relation' => ['to' => 'post', 'multiple' => true]];
        $single = ['relation' => ['to' => 'post', 'multiple' => false]];

        yield 'string' => ['string', [], 'x', 'x'];
        yield 'string empty' => ['string', [], '', ''];
        yield 'string not posted' => ['string', [], null, ''];
        yield 'text keeps line breaks' => ['text', [], "a\r\nb", "a\r\nb"];
        yield 'number' => ['number', [], '3', 3];
        yield 'number decimal' => ['number', [], '3.50', 3.5];
        yield 'number empty' => ['number', [], '', null];
        yield 'number not a number' => ['number', [], 'abc', 'abc'];
        yield 'boolean checked' => ['boolean', [], '1', true];
        yield 'boolean on' => ['boolean', [], 'on', true];
        yield 'boolean unchecked' => ['boolean', [], null, false];
        yield 'boolean zero' => ['boolean', [], '0', false];
        yield 'date' => ['date', [], '2026-11-27', '2026-11-27'];
        yield 'date with time' => ['date', ['time' => true], '2026-11-27T09:30', '2026-11-27T09:30'];
        yield 'date empty' => ['date', [], '', null];
        yield 'select' => ['select', ['options' => ['a', 'b']], 'a', 'a'];
        yield 'select nothing chosen' => ['select', ['options' => ['a', 'b']], '', null];
        yield 'tags' => ['tags', [], 'Folio, members,, AI translation ,', ['Folio', 'members', 'AI translation']];
        yield 'tags each once' => ['tags', [], ['Folio', 'folio', 'Über', 'ÜBER'], ['Folio', 'Über']];
        yield 'tags none' => ['tags', [], '', null];
        yield 'relation' => ['relation', $single, 'a1', 'a1'];
        yield 'relation none' => ['relation', $single, '', ''];
        yield 'relation multiple' => ['relation', $multiple, ['', 'a2', 'a1'], ['a2', 'a1']];
        yield 'relation multiple none' => ['relation', $multiple, [], null];
        yield 'relation multiple not a list' => ['relation', $multiple, 'x', null];
        yield 'richtext' => ['richtext', [], '<p><strong>ok</strong></p><script>alert(1)</script>', '<p><strong>ok</strong></p>'];
        yield 'richtext not posted' => ['richtext', [], null, ''];
        yield 'email' => ['email', [], "  a@example.org \n", 'a@example.org'];
        yield 'email empty' => ['email', [], '', ''];
        yield 'email not posted' => ['email', [], null, ''];
        yield 'phone' => ['phone', ['default_region' => 'CH'], ' 044 123 45 67 ', ['as_typed' => '044 123 45 67', 'e164' => '+41441234567']];
        yield 'phone no region' => ['phone', [], '044 123 45 67', ['as_typed' => '044 123 45 67', 'e164' => null]];
        yield 'phone empty' => ['phone', [], '  ', null];
        yield 'color' => ['color', [], ' C00000 ', '#c00000'];
        yield 'color short' => ['color', [], '#C00', '#cc0000'];
        yield 'color no colour' => ['color', [], 'red', 'red'];
        yield 'color empty' => ['color', [], '', null];
        yield 'code' => ['code', [], "a\r\nb", "a\nb"];
        yield 'code blank' => ['code', [], " \n ", null];
        yield 'json' => ['json', [], "  {\"a\": 1}\r\n", '{"a": 1}'];
        yield 'json blank' => ['json', [], '  ', null];
        yield 'datetime' => ['datetime', ['zone' => 'Asia/Tokyo'], '2026-07-15T12:30', '2026-07-15T03:30:00Z'];
        yield 'datetime no date' => ['datetime', [], 'soon', 'soon'];
        yield 'datetime empty' => ['datetime', [], '', null];
        yield 'time' => ['time', [], '9:05', '09:05'];
        yield 'time with seconds' => ['time', [], '09:05:30', '09:05'];
        yield 'time out of range' => ['time', [], '25:00', '25:00'];
        yield 'time empty' => ['time', [], '', null];
        yield 'daterange' => ['daterange', [], ['start' => ' 2026-03-01 ', 'end' => '2026-03-07'], ['start' => '2026-03-01', 'end' => '2026-03-07']];
        yield 'daterange one end' => ['daterange', [], ['start' => '2026-03-01', 'end' => ''], ['start' => '2026-03-01', 'end' => null]];
        yield 'daterange none' => ['daterange', [], ['start' => '', 'end' => ''], null];
        yield 'location' => ['location', [], ['lat' => '47,3769', 'lng' => ' 8.5417 ', 'address' => ' Zürich '], ['lat' => 47.3769, 'lng' => 8.5417, 'address' => 'Zürich']];
        yield 'location not a number' => ['location', [], ['lat' => 'north', 'lng' => '8', 'address' => ''], ['lat' => 'north', 'lng' => 8.0, 'address' => null]];
        yield 'location none' => ['location', [], ['lat' => '', 'lng' => '', 'address' => ''], null];
        yield 'link' => ['link', [], ['kind' => 'url', 'url' => 'https://example.org', 'label' => 'Example'], ['kind' => 'url', 'target' => 'https://example.org', 'label' => 'Example', 'new_tab' => false]];
        yield 'link without target' => ['link', [], ['kind' => 'url'], ['kind' => 'url', 'target' => '', 'label' => null, 'new_tab' => false]];
        yield 'link none' => ['link', [], ['kind' => '', 'url' => 'https://example.org'], null];
        yield 'embed' => ['embed', [], ['url' => ' https://youtu.be/aqz-KE-bpKQ '], ['provider' => 'youtube', 'id' => 'aqz-KE-bpKQ', 'url' => 'https://youtu.be/aqz-KE-bpKQ', 'poster' => null]];
        yield 'embed other host' => ['embed', [], ['url' => 'https://example.org/clip'], ['provider' => null, 'id' => null, 'url' => 'https://example.org/clip', 'poster' => null]];
        yield 'embed none' => ['embed', [], ['url' => ''], null];
        yield 'table' => ['table', [], ['head' => ['', ''], 'rows' => [['S', '']]], ['head' => null, 'rows' => [['S']]]];
        yield 'table none' => ['table', [], ['head' => [''], 'rows' => [['']]], null];
        yield 'matrix' => ['matrix', ['matrix' => ['allowed' => ['note']]], [['_type' => 'note', 'id' => 'n1'], ['_type' => 'ghost', 'id' => 'g1'], ['_type' => 'note', 'id' => 'n2', 'view' => 'card']], '[{"_type":"note","id":"n1"},{"_type":"note","id":"n2"}]'];
        yield 'matrix none' => ['matrix', ['matrix' => ['allowed' => ['note']]], null, '[]'];
        yield 'env' => ['env', ['env' => 'SECRET'], 'posted', null];
        yield 'blocks' => ['blocks', [], ['_order' => [self::UID], self::UID => ['_block' => 'link', 'label' => 'L', 'url' => '/x']], [['_uid' => self::UID, '_block' => 'link', 'label' => 'L', 'url' => '/x']]];
        yield 'blocks none' => ['blocks', [], [], []];
    }

    /** @param array<string, mixed> $config */
    #[DataProvider('posts')]
    public function test_form_post_stores_what_0_92_stored(string $type, array $config, mixed $post, mixed $stored): void
    {
        $app = $this->app();
        $fieldType = $app->container()->get(FieldTypeRegistry::class)->get($type);
        $value = $fieldType instanceof StructuredFieldType
            ? $fieldType->buildValue($post, null, [], new BuildContext(Locales::single('en'), 'en', true), $config)
            : $fieldType->toStorage($fieldType->normalizeInput($post, $config));
        $def = new TypeDefinition('parity', 'parity', 'json', ['f' => new TypeFieldDefinition('f', $type, config: $config)]);

        $this->assertSame($stored, $app->container()->get(DataManager::class)->storageValues($def, ['f' => $value])['f']);
    }

    public function test_an_int_field_stores_a_number(): void
    {
        // 0.92.1 stored the text "7" (no kind for the alias); since 0.93 "int" is a number on every
        // path (builder's call 2), the form's and an API write's alike.
        $app = $this->app();
        $fieldType = $app->container()->get(FieldTypeRegistry::class)->get('int');
        $def = new TypeDefinition('parity', 'parity', 'json', ['f' => new TypeFieldDefinition('f', 'int')]);

        $this->assertSame(7, $app->container()->get(DataManager::class)->storageValues($def, ['f' => $fieldType->toStorage($fieldType->normalizeInput('7', []))])['f']);
    }

    /**
     * A stored value, as drivers return it (numeric text on SQL included), read by the Folio type
     * (fromStorage()) as the core's value reads it out.
     *
     * @return iterable<string, array{string, array<string, mixed>, mixed}>
     */
    public static function storedScalars(): iterable
    {
        foreach (['x', '', null, 5, 2.5] as $i => $stored) {
            yield "string {$i}" => ['string', [], $stored];
            yield "text {$i}" => ['text', [], $stored];
        }
        foreach ([3, '3', '3.50', '', null, 'abc'] as $i => $stored) {
            yield "number {$i}" => ['number', [], $stored];
        }
        foreach (['2026-11-27', '2026-11-27T09:30', '2026-11-27T05:00:00+00:00', '', null] as $i => $stored) {
            yield "date {$i}" => ['date', [], $stored];
        }
        foreach (['a', '', null, 2] as $i => $stored) {
            yield "select {$i}" => ['select', ['options' => ['a', 'b', '2']], $stored];
        }
        foreach (['["Folio","Über"]', ['Folio'], 'Folio, members', '', null] as $i => $stored) {
            yield "tags {$i}" => ['tags', [], $stored];
        }
        foreach ([true, false, 1, 0, '1', '0'] as $i => $stored) {
            yield "boolean {$i}" => ['boolean', [], $stored];
        }
        foreach (['a1', '["a1","a2"]', ['a1', 'a2'], 7] as $i => $stored) {
            yield "relation {$i}" => ['relation', is_array($stored) || str_starts_with((string) $stored, '[') ? ['relation' => ['to' => 'post', 'multiple' => true]] : [], $stored];
        }
    }

    /** @param array<string, mixed> $config */
    #[DataProvider('storedScalars')]
    public function test_from_storage_equals_core_output_for_scalar_types(string $type, array $config, mixed $stored): void
    {
        $app = $this->app();
        $field = new TypeFieldDefinition('f', $type, config: $config);
        $core = $app->container()->get(DataManager::class)->fieldKinds()->valueOf($field);

        $this->assertSame($core->toOutput($stored, $field), $app->container()->get(FieldTypeRegistry::class)->get($type)->fromStorage($stored));
    }

    public function test_a_boolean_without_a_value_shows_unchecked(): void
    {
        // The core's output keeps "no value" (null); the admin shows it as an unchecked box, as in 0.92.
        $this->assertFalse($this->app()->container()->get(FieldTypeRegistry::class)->get('boolean')->fromStorage(null));
    }

    public function test_ai_draft_and_rich_text_use_the_bound_cleaner(): void
    {
        $this->writeModel('article', [
            'label' => 'Articles',
            'fields' => ['title' => ['type' => 'string'], 'body' => ['type' => 'richtext']],
        ]);
        $this->aiConfig(true);
        $this->script = [new AiResponse('<h2>Hours</h2><p>We open at nine.</p>', StopReason::Complete, new TokenUsage(1000, 500), 'fake', 'claude-sonnet-5-5')];
        $this->working('article', 'a1', ['title' => 'Hello']);
        $app = $this->aiApp();
        $app->container()->instance(HtmlCleaner::class, new SiteHtmlCleaner());

        $this->assertSame('<p>site: x</p>', $app->container()->get(FieldTypeRegistry::class)->get('richtext')->normalizeInput('<p>x</p>', []));

        $request = (new Psr17Factory())->createServerRequest('POST', '/folio/_ai/draft')
            ->withHeader('Sec-Fetch-Site', 'same-origin')
            ->withParsedBody(['type' => 'article', 'id' => 'a1', 'field' => 'Body', 'brief' => 'Our opening hours', 'length' => 'short', 'language' => 'en', 'title' => 'Hello', '_csrf_token' => $this->token]);
        $res = $app->handle($request);

        $this->assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        $this->assertSame('<p>site: HoursWe open at nine.</p>', json_decode((string) $res->getBody(), true, 512, JSON_THROW_ON_ERROR)['html']);
    }

    public function test_booting_folio_builds_no_core_cleaner(): void
    {
        SymfonyHtmlCleaner::$built = 0;
        $this->app()->container()->get(FieldTypeRegistry::class);

        $this->assertSame(0, SymfonyHtmlCleaner::$built);
    }

    public function test_folio_values_registered(): void
    {
        $kinds = $this->app()->container()->get(DataManager::class)->fieldKinds();

        $this->assertInstanceOf(MatrixValue::class, $kinds->valueOf(new TypeFieldDefinition('notes', 'matrix')));
        $this->assertInstanceOf(EnvValue::class, $kinds->valueOf(new TypeFieldDefinition('secret', 'env')));
        foreach (['int', 'integer', 'float'] as $alias) {
            $this->assertInstanceOf(NumberValue::class, $kinds->valueOf(new TypeFieldDefinition('n', $alias)), $alias);
        }
        $this->assertSame('json', $kinds->valueOf(new TypeFieldDefinition('notes', 'matrix'))->shape(new TypeFieldDefinition('notes', 'matrix')));
    }

    public function test_folio_values_read_and_write_as_the_types(): void
    {
        $matrix = new MatrixValue();
        $field = new TypeFieldDefinition('notes', 'matrix');
        $this->assertSame('[{"_type":"note","id":"n1"}]', $matrix->toStored([['_type' => 'note', 'id' => 'n1'], ['_type' => '', 'id' => 'x']], $field));
        $this->assertSame([['_type' => 'note', 'id' => 'n1', 'view' => 'card']], $matrix->toOutput('[{"_type":"note","id":"n1","view":"card"}]', $field));
        $this->assertSame([], $matrix->toOutput(null, $field));

        $env = new EnvValue();
        $this->assertNull($env->toStored('posted', new TypeFieldDefinition('secret', 'env')));
        $this->assertNull($env->toOutput('stored', new TypeFieldDefinition('secret', 'env')));

        $purpose = new PurposeValue();
        $field = new TypeFieldDefinition('purpose', 'codes_purpose');
        $this->assertSame('signup', $purpose->toStored('signup', $field));
        $this->assertNull($purpose->toStored('', $field));
        $this->assertSame('string', $purpose->shape($field));

        foreach ([new PermissionsValue(), new RolesValue()] as $list) {
            $field = new TypeFieldDefinition('roles', 'rbac_roles');
            $this->assertSame(['editor', 'admin'], $list->toStored([' editor', '', 'admin', 'editor'], $field));
            $this->assertSame(['editor', 'admin'], $list->toOutput('["editor","admin"]', $field));
            $this->assertSame([], $list->toOutput(null, $field));
            $this->assertSame('string_list', $list->shape($field));
        }
    }
}

/** A site's own cleaner: marks what it cleaned. */
final class SiteHtmlCleaner implements HtmlCleaner
{
    public function clean(string $html): string
    {
        return '<p>site: ' . strip_tags($html) . '</p>';
    }
}
