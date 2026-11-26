<?php

declare(strict_types=1);

namespace Preflow\Fields\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Data\Locales;
use Preflow\Fields\FieldOptions;
use Preflow\Fields\Rules\ValidLocation;
use Preflow\Fields\Rules\ValidTable;
use Preflow\Fields\TableOptions;
use Preflow\Fields\Values\Location;
use Preflow\Fields\Values\Table;
use Preflow\Validation\Message;
use Preflow\Validation\ValidationContext;

/** The table and location fields' values, options and checks (0.91). */
final class TableLocationTest extends TestCase
{
    public function test_a_table_reads_its_stored_shape_and_pads_short_rows(): void
    {
        $stored = ['head' => ['Size', 'Price'], 'rows' => [['S', 'CHF 10'], ['M']]];
        $table = Table::fromStored($stored);

        $this->assertNotNull($table);
        $this->assertSame(2, $table->columns());
        $this->assertSame(['head' => ['Size', 'Price'], 'rows' => [['S', 'CHF 10'], ['M', '']]], $table->toStored());
        $this->assertSame($table->toStored(), Table::fromStored((string) json_encode($stored))?->toStored(), 'JSON text as SQL storage gives it');
        $this->assertNull(Table::fromStored(['head' => null, 'rows' => [['S']]])?->head);
        foreach ([null, '', [], ['rows' => []], ['rows' => 'x'], 'text', ['head' => ['', ''], 'rows' => [['', null]]]] as $empty) {
            $this->assertNull(Table::fromStored($empty), (string) json_encode($empty));
        }
    }

    public function test_each_cell_is_translated_on_its_own_along_the_fallback_chain(): void
    {
        $locales = new Locales(['en', 'de', 'fr'], 'en', 'en');
        $table = Table::fromStored([
            'head' => [['en' => 'Size', 'de' => 'Grösse'], ['en' => 'Colour']],
            'rows' => [[['en' => 'Small', 'de' => 'Klein'], 'Red'], ['', ['en' => 'Blue', 'fr' => 'Bleu']]],
        ]);
        $this->assertNotNull($table);

        $this->assertSame(['head' => ['Grösse', 'Colour'], 'rows' => [['Klein', 'Red'], ['', 'Blue']]], $table->texts('de', $locales), 'a cell without German falls back to English');
        $this->assertSame(['head' => ['Size', 'Colour'], 'rows' => [['Small', 'Red'], ['', 'Bleu']]], $table->texts('fr', $locales));
    }

    public function test_a_table_renders_escaped_with_its_header_row(): void
    {
        $table = Table::fromStored(['head' => ['<b>A</b>', 'B'], 'rows' => [['1 & 2', '3']]]);

        $this->assertSame(
            '<table class="preflow-table"><thead><tr><th scope="col">&lt;b&gt;A&lt;/b&gt;</th><th scope="col">B</th></tr></thead><tbody><tr><td>1 &amp; 2</td><td>3</td></tr></tbody></table>',
            $table?->html('en'),
        );
        $this->assertSame('<table class="preflow-table"><tbody><tr><td>x</td></tr></tbody></table>', Table::fromStored(['head' => null, 'rows' => [['x']]])?->html('en'));
    }

    public function test_the_table_check_keeps_to_the_limits_and_names_the_counts(): void
    {
        $rule = new ValidTable(new TableOptions(maxColumns: 2, maxRows: 2));
        $ctx = new ValidationContext('prices', []);

        $this->assertTrue($rule->validate(['head' => ['a', 'b'], 'rows' => [['1', '2'], ['3', '4']]], $ctx));
        $this->assertTrue($rule->validate(null, $ctx));

        $columns = $rule->validate(['head' => null, 'rows' => [['1', '2', '3']]], $ctx);
        $this->assertInstanceOf(Message::class, $columns);
        $this->assertSame('This table has 3 columns; at most 2 are allowed. Remove a column.', (string) $columns);
        $this->assertSame('This table has 3 columns; at most 2 are allowed. Remove a column.', (string) $rule->validate(['head' => ['a', 'b', 'c'], 'rows' => [['1']]], $ctx), 'the header row counts');

        $rows = $rule->validate(['head' => ['a'], 'rows' => [['1'], ['2'], ['3']]], $ctx);
        $this->assertInstanceOf(Message::class, $rows);
        $this->assertSame('This table has 3 rows; at most 2 are allowed. Remove a row.', (string) $rows, 'the header row is not one of them');

        foreach (['text', ['rows' => 'x'], ['rows' => [['a', 5]]], ['rows' => [['a']], 'head' => 'x'], ['rows' => [['a' => 'b']]], ['rows' => [[['en' => 5]]]]] as $bad) {
            $message = $rule->validate($bad, $ctx);
            $this->assertInstanceOf(Message::class, $message, (string) json_encode($bad));
            $this->assertSame('Must be a table: an optional header row and rows of text cells.', (string) $message);
        }
        $this->assertTrue((new ValidTable(new TableOptions()))->validate(['rows' => array_fill(0, 200, array_fill(0, 30, 'x'))], $ctx), 'no limits without options');
        $this->assertTrue($rule->validate(['head' => null, 'rows' => [[['en' => 'A', 'de' => 'B'], null]]], $ctx), 'a cell per language, an empty cell');
    }

    public function test_table_options_are_checked_at_boot(): void
    {
        FieldOptions::check('table', ['max_columns' => 4, 'max_rows' => 20]);
        foreach ([
            [['translatable' => true], 'A table field cannot be translatable; its cells are translated in the table itself (a text per language). Remove "translatable".'],
            [['table' => ['max_rows' => 3]], '"table" holds options, but a table field takes them at its top level, next to "type": move "max_columns", "max_rows" out of "table".'],
            [['max_rows' => 0], '"max_rows" must be a whole number of 1 or more, such as 20; leave it out for no limit.'],
            [['max_columns' => '4'], '"max_columns" must be a whole number of 1 or more, such as 20; leave it out for no limit.'],
        ] as [$config, $message]) {
            try {
                FieldOptions::check('table', $config);
                $this->fail('accepted ' . json_encode($config));
            } catch (\LogicException $e) {
                $this->assertSame($message, $e->getMessage());
            }
        }
    }

    public function test_a_location_reads_numbers_and_keeps_the_address(): void
    {
        $location = Location::fromStored(['lat' => 47.3769, 'lng' => 8.5417, 'address' => 'Bahnhofplatz, Zürich']);

        $this->assertSame(['lat' => 47.3769, 'lng' => 8.5417, 'address' => 'Bahnhofplatz, Zürich'], $location?->toStored());
        $this->assertSame('geo:47.3769,8.5417', $location?->geoHref());
        $this->assertSame(['lat' => -33.8568, 'lng' => 151.2153, 'address' => null], Location::fromStored('{"lat": "-33.8568", "lng": 151.2153}')?->toStored(), 'JSON text, a number as text');
        $this->assertSame(['lat' => 90.0, 'lng' => -180.0, 'address' => null], Location::fromStored(['lat' => 90, 'lng' => -180])?->toStored(), 'the bounds are places too');
        foreach ([null, '', [], ['lat' => 47.1], ['lat' => 91, 'lng' => 0], ['lat' => 0, 'lng' => 180.5], ['lat' => 'north', 'lng' => 1], ['address' => 'Zürich']] as $none) {
            $this->assertNull(Location::fromStored($none), (string) json_encode($none));
        }
    }

    public function test_the_location_check_names_the_bound_that_was_crossed(): void
    {
        $rule = new ValidLocation();
        $ctx = new ValidationContext('place', []);

        $this->assertTrue($rule->validate(['lat' => -90, 'lng' => 180, 'address' => null], $ctx));
        $this->assertTrue($rule->validate(null, $ctx));
        $this->assertTrue($rule->validate(['lat' => null, 'lng' => null, 'address' => null], $ctx), 'nothing filled is no value');
        foreach ([
            [['lat' => 90.0001, 'lng' => 8], 'The latitude must be a number from -90 to 90 (north is positive), such as 47.3769.'],
            [['lat' => -91, 'lng' => 8], 'The latitude must be a number from -90 to 90 (north is positive), such as 47.3769.'],
            [['lat' => 47, 'lng' => 180.1], 'The longitude must be a number from -180 to 180 (east is positive), such as 8.5417.'],
            [['lat' => 47, 'lng' => -181], 'The longitude must be a number from -180 to 180 (east is positive), such as 8.5417.'],
            [['lat' => 47, 'lng' => null], 'Must be a place with a latitude and a longitude, such as 47.3769, 8.5417.'],
            [['lat' => 'north', 'lng' => 8], 'Must be a place with a latitude and a longitude, such as 47.3769, 8.5417.'],
            [['address' => 'Zürich'], 'Must be a place with a latitude and a longitude, such as 47.3769, 8.5417.'],
            ['47.3769, 8.5417', 'Must be a place with a latitude and a longitude, such as 47.3769, 8.5417.'],
        ] as [$value, $message]) {
            $this->assertSame($message, (string) $rule->validate($value, $ctx), (string) json_encode($value));
        }
    }

    public function test_location_options_are_checked_at_boot(): void
    {
        FieldOptions::check('location', []);
        foreach ([
            [['translatable' => true], 'A location field cannot be translatable; it holds the same value in every language. Remove "translatable".'],
            [['location' => ['zoom' => 3]], '"location" holds options, but a location field takes none; remove "location". A map comes from a LocationPicker the site binds.'],
        ] as [$config, $message]) {
            try {
                FieldOptions::check('location', $config);
                $this->fail('accepted ' . json_encode($config));
            } catch (\LogicException $e) {
                $this->assertSame($message, $e->getMessage());
            }
        }
    }
}
