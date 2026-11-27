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
use Preflow\Folio\Codes\Fields\PurposeFieldType;
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
        // A block's date and number went through the field types unconverted in 0.92 (storageValues()
        // never goes inside blocks): "" stays "", "3" stays "3".
        yield 'blocks with an empty date and a number' => ['blocks', [], ['_order' => [self::UID], self::UID => ['_block' => 'checked_fields', 'title' => 'T', 'day' => '', 'count' => '3', 'size' => '']], [['_uid' => self::UID, '_block' => 'checked_fields', 'title' => 'T', 'day' => '', 'count' => '3', 'labels' => null, 'size' => null]]];
        yield 'blocks with an empty number' => ['blocks', [], ['_order' => [self::UID], self::UID => ['_block' => 'checked_fields', 'title' => 'T', 'day' => '2026-11-27', 'count' => '', 'size' => 's']], [['_uid' => self::UID, '_block' => 'checked_fields', 'title' => 'T', 'day' => '2026-11-27', 'count' => '', 'labels' => null, 'size' => 's']]];
        // A translatable field's map is stored as the form gives it (storageValues() skips it).
        yield 'translatable date empty' => ['date', [], '', ['en' => ''], true];
        yield 'translatable number' => ['number', [], '3', ['en' => '3'], true];
        yield 'translatable number empty' => ['number', [], '', ['en' => ''], true];
        yield 'translatable string' => ['string', [], 'x', ['en' => 'x'], true];
        // The aliases have no column kind: their posted text was stored as it is.
        yield 'int alias' => ['int', [], '7', '7'];
        yield 'integer alias' => ['integer', [], '7', '7'];
        yield 'float alias' => ['float', [], '2.50', '2.50'];
        yield 'int alias empty' => ['int', [], '', ''];
        yield 'asset' => ['asset', [], 'a/b.png', 'a/b.png'];
        yield 'asset none' => ['asset', [], '', ''];
        yield 'asset multiple' => ['asset', ['asset' => ['multiple' => true]], ['x.png', 'y.png'], ['x.png', 'y.png']];
        yield 'areas none' => ['areas', ['layouts' => 'grid'], [], ['layout' => null, 'areas' => []]];
        yield 'codes_purpose' => ['codes_purpose', [], 'signup', 'signup'];
        yield 'codes_purpose none' => ['codes_purpose', [], '', null];
        yield 'rbac_roles' => ['rbac_roles', [], [' editor', '', 'admin', 'editor'], ['editor', 'admin']];
        yield 'rbac_permissions' => ['rbac_permissions', [], ['', 'folio.dashboard', 'ghost.view'], ['folio.dashboard']];
        yield 'matrix not a list' => ['matrix', ['matrix' => ['allowed' => ['note']]], 'x', '[]'];
    }

    /** The Folio type for $key: the app's registry's, or the codes and rbac admins' types, built as Folio builds them. */
    private static function folioType(Application $app, string $key): \Preflow\Folio\Field\FieldType
    {
        $c = $app->container();
        $type = match ($key) {
            'codes_purpose' => new PurposeFieldType([]),
            'rbac_roles' => new \Preflow\Folio\Rbac\Fields\RolesFieldType($c->get(DataManager::class), $c->get(\Preflow\Folio\Content\TypeCatalog::class)),
            'rbac_permissions' => (static function () use ($c): \Preflow\Folio\Rbac\Fields\PermissionsFieldType {
                $registry = new \Preflow\Auth\Access\PermissionRegistry();
                $registry->declare('folio.dashboard', 'See the dashboard', 'Folio');

                return new \Preflow\Folio\Rbac\Fields\PermissionsFieldType($registry, $c->get(\Preflow\Folio\Content\TypeCatalog::class));
            })(),
            default => null,
        };
        if ($type === null) {
            return $c->get(FieldTypeRegistry::class)->get($key);
        }
        if ($type instanceof \Preflow\Folio\Field\UsesFieldKinds) {
            $type->useFieldKinds($c->get(DataManager::class)->fieldKinds());
        }

        return $type;
    }

    /** @param array<string, mixed> $config */
    #[DataProvider('posts')]
    public function test_form_post_stores_what_0_92_stored(string $type, array $config, mixed $post, mixed $stored, bool $translatable = false): void
    {
        $this->write('config/blocks.php', "<?php\nreturn ['classes' => [\\Preflow\\Folio\\Tests\\Fixtures\\Blocks\\LinkBlock::class, \\Preflow\\Folio\\Tests\\Fixtures\\Blocks\\CheckedFieldsBlock::class]];\n");
        if ($type === 'areas') {
            $this->write('config/providers.php', "<?php\nreturn [\\Preflow\\Folio\\FolioServiceProvider::class, \\Preflow\\Folio\\Tests\\Fixtures\\Blocks\\GridSetProvider::class];\n");
        }
        $app = $this->app();
        $fieldType = self::folioType($app, $type);
        $value = $fieldType instanceof StructuredFieldType
            ? $fieldType->buildValue($post, null, [], new BuildContext(Locales::single('en'), 'en', true), $config)
            : $fieldType->toStorage($fieldType->normalizeInput($post, $config));
        $def = new TypeDefinition('parity', 'parity', 'json', ['f' => new TypeFieldDefinition('f', $type, config: $config, translatable: $translatable)], locales: new Locales(['en', 'de'], 'en'));

        $this->assertSame($stored, $app->container()->get(DataManager::class)->storageValues($def, ['f' => $translatable ? ['en' => $value] : $value])['f']);
    }

    /**
     * A record as Folio 0.92 stored it (empty and numeric text in a block and in translatable
     * fields, an int field's text), saved again unchanged through the edit form: the same bytes,
     * the same change date.
     */
    public function test_a_0_92_record_saved_again_unchanged_keeps_its_bytes_and_change_date(): void
    {
        $this->write('config/blocks.php', "<?php\nreturn ['classes' => [\\Preflow\\Folio\\Tests\\Fixtures\\Blocks\\CheckedFieldsBlock::class]];\n");
        $this->writeModel('keeper', [
            'label' => 'Keepers',
            'fields' => [
                'title' => ['type' => 'string'],
                'day' => ['type' => 'date', 'translatable' => true],
                'amount' => ['type' => 'number', 'translatable' => true],
                'qty' => ['type' => 'int'],
                'sections' => ['type' => 'blocks', 'blocks' => ['allowed' => ['checked_fields']]],
            ],
        ]);
        $block = ['_uid' => self::UID, '_block' => 'checked_fields', 'title' => 'T', 'day' => '', 'count' => '5', 'labels' => null, 'size' => null];
        $stored = ['title' => 'Keep', 'day' => ['en' => ''], 'amount' => ['en' => '3'], 'qty' => '7', 'sections' => [$block], 'uuid' => 'k1', '_locales' => ['en'], '_updated_at' => '2026-01-02T03:04:05Z'];
        $this->dm()->driver('json')->save('keeper', 'k1', $stored, 'uuid');
        $file = $this->dir . '/storage/data/keeper/k1.json';
        $before = (string) file_get_contents($file);

        $response = $this->post('/folio/keeper/k1', [
            'title' => 'Keep', 'day' => '', 'amount' => '3', 'qty' => '7', '_locale' => 'en',
            'sections' => ['_order' => [self::UID], self::UID => ['_block' => 'checked_fields', 'title' => 'T', 'day' => '', 'count' => '5', 'size' => '']],
        ]);

        $this->assertSame(302, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame($before, (string) file_get_contents($file), 'the same bytes, the same change date');
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

    public function test_a_value_a_site_registered_first_is_kept(): void
    {
        $this->write('config/providers.php', "<?php\nreturn [\\Preflow\\Folio\\Tests\\Fixtures\\OwnMatrixValueProvider::class, \\Preflow\\Folio\\FolioServiceProvider::class];\n");
        $kinds = $this->app()->container()->get(DataManager::class)->fieldKinds();

        $this->assertNotInstanceOf(MatrixValue::class, $kinds->valueOf(new TypeFieldDefinition('notes', 'matrix')));
        $this->assertInstanceOf(EnvValue::class, $kinds->valueOf(new TypeFieldDefinition('secret', 'env')));
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
