<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Field;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Preflow\Data\Locales;
use Preflow\Fields\EmbedProviders;
use Preflow\Fields\LocationPicker;
use Preflow\Fields\Values\Location;
use Preflow\Folio\Content\FrontendConfigException;
use Preflow\Folio\Content\ListQuery;
use Preflow\Folio\Content\TypeCatalog;
use Preflow\Folio\Field\BuildContext;
use Preflow\Folio\Field\FieldContext;
use Preflow\Folio\Field\Types\EmbedFieldType;
use Preflow\Folio\Field\Types\LocationFieldType;
use Preflow\Folio\Field\Types\TableFieldType;
use Preflow\Folio\Tests\Support\TempRoots;

/**
 * The embed, table and location inputs (0.91): the values, checks and storage are preflow/fields';
 * Folio draws the editor, builds the value from what was posted (a table cell per language) and
 * shows it in a list.
 */
final class EmbedTableLocationFieldTypesTest extends TestCase
{
    public function test_a_pasted_address_becomes_the_cores_embed_and_an_unknown_one_is_kept_for_the_check(): void
    {
        $type = new EmbedFieldType(null, static fn (): EmbedProviders => new EmbedProviders());
        $ctx = new BuildContext(Locales::single('en'), 'en', false);

        $this->assertSame(['provider' => 'youtube', 'id' => 'aqz-KE-bpKQ', 'url' => 'https://youtu.be/aqz-KE-bpKQ', 'poster' => null], $type->buildValue(['url' => ' https://youtu.be/aqz-KE-bpKQ '], null, [], $ctx));
        $this->assertSame(['provider' => null, 'id' => null, 'url' => 'https://example.org/clip', 'poster' => null], $type->buildValue(['url' => 'https://example.org/clip'], null, [], $ctx));
        $this->assertNull($type->buildValue(['url' => ''], null, [], $ctx));
        $this->assertSame('YouTube · aqz-KE-bpKQ', $type->renderAdmin(['provider' => 'youtube', 'id' => 'aqz-KE-bpKQ', 'url' => 'https://youtu.be/aqz-KE-bpKQ', 'poster' => null], []));
    }

    public function test_the_embed_editor_says_which_providers_and_where_a_saved_video_plays_from(): void
    {
        $type = new EmbedFieldType(null, static fn (): EmbedProviders => new EmbedProviders());

        $empty = $type->renderEditor(new FieldContext(name: 'video', label: 'Video'));
        $this->assertStringContainsString('<input type="url" id="folio-embed-video-url" name="video[url]" value=""', $empty);
        $this->assertStringContainsString('Paste the address of a video on YouTube, Vimeo, as the browser shows it.', $empty);

        $saved = $type->renderEditor(new FieldContext(name: 'video', value: ['provider' => 'vimeo', 'id' => '76979871', 'url' => 'https://vimeo.com/76979871', 'poster' => null]));
        $this->assertStringContainsString('value="https://vimeo.com/76979871"', $saved);
        $this->assertStringContainsString('Plays from Vimeo (video 76979871). Nothing loads from Vimeo until a visitor starts it.', $saved);
    }

    public function test_a_new_table_is_two_by_two_and_a_saved_one_has_a_row_and_a_column_more_within_the_limits(): void
    {
        $type = new TableFieldType();

        $new = $type->renderEditor(new FieldContext(name: 'specs'));
        $this->assertSame(2, substr_count($new, 'name="specs[head]['));
        $this->assertSame(4, substr_count($new, 'name="specs[rows]['));

        $value = ['head' => ['A', 'B'], 'rows' => [['1', '2']]];
        $saved = $type->renderEditor(new FieldContext(name: 'specs', value: $value));
        $this->assertStringContainsString('name="specs[head][2]" value=""', $saved, 'a spare column');
        $this->assertStringContainsString('name="specs[rows][1][0]" value=""', $saved, 'a spare row');
        $this->assertStringContainsString('name="specs[rows][0][1]" value="2" aria-label="Row 1, column 2"', $saved);

        $full = $type->renderEditor(new FieldContext(name: 'specs', value: $value, config: ['max_columns' => 2, 'max_rows' => 1]));
        $this->assertStringNotContainsString('specs[head][2]', $full, 'no column past max_columns');
        $this->assertStringNotContainsString('specs[rows][1]', $full, 'no row past max_rows');
    }

    public function test_a_table_saved_in_the_default_language_drops_empty_rows_and_columns(): void
    {
        $type = new TableFieldType();
        $ctx = new BuildContext(Locales::single('en'), 'en', false);
        $posted = ['head' => ['Size', '', ''], 'rows' => [['S', '', ''], ['', '', ''], ['M', '', 'x']]];

        $this->assertSame(['head' => ['Size', ''], 'rows' => [['S', ''], ['M', 'x']]], $type->buildValue($posted, null, [], $ctx));
        $this->assertSame(['head' => null, 'rows' => [['S']]], $type->buildValue(['head' => ['', ''], 'rows' => [['S', '']]], null, [], $ctx), 'an empty header row is none');
        $this->assertNull($type->buildValue(['head' => [''], 'rows' => [['']]], null, [], $ctx));
    }

    public function test_each_cell_is_translated_and_another_language_keeps_the_rows_and_columns(): void
    {
        $type = new TableFieldType();
        $locales = new Locales(['en', 'de'], 'en', 'en');
        $en = $type->buildValue(['head' => ['Size', 'Price'], 'rows' => [['Small', '10']]], null, [], new BuildContext($locales, 'en', false));
        $this->assertSame(['head' => [['en' => 'Size'], ['en' => 'Price']], 'rows' => [[['en' => 'Small'], ['en' => '10']]]], $en);

        // In German: the inputs show the English as placeholder; no spare row or column.
        $editor = $type->renderEditor(new FieldContext(name: 'specs', value: $en, locale: 'de', locales: $locales));
        $this->assertStringContainsString('name="specs[rows][0][0]" value="" aria-label="Row 1, column 1" placeholder="Small"', $editor);
        $this->assertStringNotContainsString('specs[rows][1]', $editor);
        $this->assertStringNotContainsString('specs[head][2]', $editor);
        $this->assertStringContainsString('Rows and columns follow the EN table; translate each cell here.', $editor);

        // A German save sets the German texts only, and a posted extra row is ignored there.
        $de = $type->buildValue(['head' => ['Grösse', ''], 'rows' => [['Klein', ''], ['Extra', 'row']]], $en, [], new BuildContext($locales, 'de', false));
        $this->assertSame(['head' => [['en' => 'Size', 'de' => 'Grösse'], ['en' => 'Price']], 'rows' => [[['en' => 'Small', 'de' => 'Klein'], ['en' => '10']]]], $de);

        // Its texts for the translation status and machine translation, and writing one back.
        $texts = $type->translatableTexts($de, [], $locales);
        $this->assertSame(['head.0', 'head.1', 'rows.0.0', 'rows.0.1'], array_map(static fn ($t) => $t->path, $texts));
        $this->assertSame(['en' => 'Price', 'de' => null], $texts[1]->values);
        $this->assertSame('Row 1, column 1', $texts[2]->label);
        $this->assertSame([], $type->translatableTexts($de, [], Locales::single('en')));
        $back = $type->withTranslatedTexts($de, ['head.1' => 'Preis', 'rows.9.9' => 'nowhere'], 'de', [], $locales);
        $this->assertSame(['en' => 'Price', 'de' => 'Preis'], $back['head'][1]);
        $this->assertSame($de['rows'], $back['rows']);

        // Emptying a row in the default language removes it with its translations.
        $this->assertSame(['head' => [['en' => 'Size', 'de' => 'Grösse'], ['en' => 'Price']], 'rows' => []], $type->buildValue(['head' => ['Size', 'Price'], 'rows' => [['', '']]], $de, [], new BuildContext($locales, 'en', false)));
    }

    public function test_the_location_inputs_read_a_decimal_comma_and_keep_what_is_no_number(): void
    {
        $type = new LocationFieldType();

        $this->assertSame(['lat' => 47.3769, 'lng' => 8.5417, 'address' => 'Zürich'], $type->normalizeInput(['lat' => '47,3769', 'lng' => ' 8.5417 ', 'address' => ' Zürich '], []));
        $this->assertSame(['lat' => 'north', 'lng' => 8.0, 'address' => null], $type->normalizeInput(['lat' => 'north', 'lng' => '8', 'address' => ''], []));
        $this->assertNull($type->normalizeInput(['lat' => '', 'lng' => '', 'address' => ''], []));
        $this->assertSame('47.3769, 8.5417 · Zürich', $type->renderAdmin(['lat' => 47.3769, 'lng' => 8.5417, 'address' => 'Zürich'], []));
        $this->assertSame('<a href="geo:47.3769,8.5417">Zürich</a>', $type->renderFrontend(['lat' => 47.3769, 'lng' => 8.5417, 'address' => 'Zürich'], []));

        $editor = $type->renderEditor(new FieldContext(name: 'place', value: ['lat' => 47.3769, 'lng' => 8.5417, 'address' => null]));
        $this->assertStringContainsString('<input type="number" id="folio-location-place-lat" name="place[lat]" value="47.3769" step="any" min="-90" max="90"', $editor);
        $this->assertStringContainsString('name="place[lng]" value="8.5417" step="any" min="-180" max="180"', $editor);
        $this->assertStringContainsString('name="place[address]"', $editor);
        $this->assertStringNotContainsString('folio-location-picker', $editor, 'no map without a LocationPicker');
        $this->assertSame([], $type->assets());
    }

    public function test_a_bound_location_picker_adds_its_markup_and_files(): void
    {
        $picker = new class implements LocationPicker {
            /** @var list<array<string, string>> */
            public array $asked = [];

            public function editorHtml(array $inputIds, ?Location $value, array $config): string
            {
                $this->asked[] = $inputIds;

                return '<div class="map" data-lat-input="' . $inputIds['lat'] . '" data-at="' . ($value?->lat ?? '') . '"></div>';
            }

            public function editorAssets(): array
            {
                return ['map-picker.js'];
            }
        };
        $type = new LocationFieldType(static fn (): LocationPicker => $picker);

        $editor = $type->renderEditor(new FieldContext(name: 'place', value: ['lat' => 47.5, 'lng' => 8.0, 'address' => null]));
        $this->assertStringContainsString('<div class="folio-location-picker"><div class="map" data-lat-input="folio-location-place-lat" data-at="47.5"></div></div>', $editor);
        $this->assertSame([['lat' => 'folio-location-place-lat', 'lng' => 'folio-location-place-lng', 'address' => 'folio-location-place-address']], $picker->asked);
        $this->assertSame(['map-picker.js'], $type->assets());
    }

    public function test_the_structured_columns_have_no_sort(): void
    {
        foreach (['embed', 'table', 'location'] as $type) {
            $this->assertFalse(ListQuery::sortable($type), $type);
        }
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function bootMistakes(): iterable
    {
        yield 'embed translatable' => [['type' => 'embed', 'translatable' => true], 'Type "thing": field "f": An embed field cannot be translatable'];
        yield 'embed nested' => [['type' => 'embed', 'embed' => ['providers' => ['x']]], 'Type "thing": field "f": "embed" holds options, but an embed field takes them at its top level, next to "type": move "providers" out of "embed".'];
        yield 'embed built-in listed' => [['type' => 'embed', 'providers' => ['vimeo']], 'Type "thing": field "f": "providers" lists "vimeo", which is always offered'];
        yield 'table translatable' => [['type' => 'table', 'translatable' => true], 'Type "thing": field "f": A table field cannot be translatable; its cells are translated in the table itself'];
        yield 'table limit' => [['type' => 'table', 'max_rows' => -1], 'Type "thing": field "f": "max_rows" must be a whole number of 1 or more'];
        yield 'table nested' => [['type' => 'table', 'table' => ['max_rows' => 2]], 'Type "thing": field "f": "table" holds options, but a table field takes them at its top level'];
        yield 'location translatable' => [['type' => 'location', 'translatable' => true], 'Type "thing": field "f": A location field cannot be translatable'];
        yield 'location nested' => [['type' => 'location', 'location' => ['zoom' => 3]], 'Type "thing": field "f": "location" holds options, but a location field takes none'];
    }

    /** @param array<string, mixed> $field */
    #[DataProvider('bootMistakes')]
    public function test_config_mistakes_are_boot_errors_naming_the_type_and_field(array $field, string $message): void
    {
        $dir = TempRoots::path('folio_etl_boot_');
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
}
