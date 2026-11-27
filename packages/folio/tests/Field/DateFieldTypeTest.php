<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Field;

use PHPUnit\Framework\TestCase;
use Preflow\Folio\Field\Types\DateFieldType;
use Preflow\Folio\FolioText;
use Preflow\I18n\Translator;

final class DateFieldTypeTest extends TestCase
{
    use \Preflow\Folio\Tests\Support\AdminTexts;

    private string $tz;

    protected function setUp(): void
    {
        $this->tz = date_default_timezone_get();
        date_default_timezone_set('Europe/Zurich');
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->tz);
    }

    public function test_the_editor_has_the_calendar_icon_a_clear_button_and_the_hint(): void
    {
        $html = (new DateFieldType())->renderEditor(new \Preflow\Folio\Field\FieldContext(name: 'publish', label: 'Publish at', value: '2026-11-14T08:00', config: ['time' => true]));

        $this->assertMatchesRegularExpression(
            '~<div class="folio-date-input" data-folio-date><svg class="folio-icon"[^>]*>.*?</svg>'
            . '<input type="datetime-local" name="publish" value="2026-11-14T08:00" id="publish" aria-describedby="publish-hint">'
            . '<button type="button" class="btn btn-ghost btn-icon btn-sm" data-folio-date-clear aria-label="Clear Publish at" title="Clear Publish at"><svg class="folio-icon"[^>]*>.*?</svg></button></div>'
            . '<p class="folio-date-hint" id="publish-hint" data-folio-date-hint data-zone="Europe/Zurich">Europe/Zurich</p>~s',
            $html,
        );
        $this->assertStringContainsString('class="form-group folio-date-field"', $html);
    }

    public function test_a_date_without_time_has_no_zone_and_an_empty_one_no_clear_button(): void
    {
        $html = (new DateFieldType())->renderEditor(new \Preflow\Folio\Field\FieldContext(name: 'day', value: ''));
        $this->assertStringContainsString('<input type="date" name="day" value="" id="day" aria-describedby="day-hint">', $html);
        $this->assertStringContainsString('data-folio-date-clear aria-label="Clear Day" title="Clear Day" hidden>', $html);
        $this->assertStringContainsString('<p class="folio-date-hint" id="day-hint" data-folio-date-hint></p>', $html);
    }

    public function test_date_display_formats(): void
    {
        $type = new DateFieldType();

        $this->assertSame('Oct 22, 2026', $type->renderAdmin('2026-10-22', []));
        $this->assertSame('Oct 22, 2026 23:15', $type->renderAdmin('2026-10-22T23:15', ['time' => true]));
        $this->assertSame('Oct 22, 2026', $type->renderAdmin('2026-10-22T23:15', []));
        // ISO 8601 with an offset is shown in the app timezone (Zurich is +02:00 in October).
        $this->assertSame('Oct 23, 2026 01:15', $type->renderAdmin('2026-10-22T23:15:12+00:00', ['time' => true]));
        $this->assertSame('', $type->renderAdmin(null, []));
    }

    public function test_date_display_garbage_is_escaped(): void
    {
        $this->assertSame('&lt;b&gt;soon&lt;/b&gt;', (new DateFieldType())->renderAdmin('<b>soon</b>', []));
    }

    public function test_frontend_output_is_unchanged(): void
    {
        $type = new DateFieldType();

        $this->assertSame('2026-10-22', $type->renderFrontend('2026-10-22', []));
        $this->assertSame('2026-10-22T23:15:12+00:00', $type->renderFrontend('2026-10-22T23:15:12+00:00', ['time' => true]));
    }

    public function test_only_the_listed_shapes_parse(): void
    {
        $type = new DateFieldType();

        foreach (['tomorrow', 'now', 'next monday', '2026-10-22 23:15', '22.10.2026', '2026-02-30'] as $raw) {
            $this->assertSame($raw, $type->renderAdmin($raw, []), $raw);
        }
        $this->assertSame('Oct 23, 2026 01:15', $type->renderAdmin('2026-10-22T23:15+00:00', ['time' => true]));
        $this->assertSame('Oct 23, 2026 01:15', $type->renderAdmin('2026-10-22T23:15:12Z', ['time' => true]));
    }

    public function test_date_display_follows_the_interface_language(): void
    {
        $type = new DateFieldType();
        foreach (['de' => ['22.10.2026', '23.10.2026 01:15'], 'fr' => ['22/10/2026', '23/10/2026 01:15']] as $locale => [$date, $datetime]) {
            $translator = new Translator(dirname(__DIR__, 2) . '/lang/.none', $locale, 'en');
            $translator->addDefaults('folio', dirname(__DIR__, 2) . '/lang');
            self::inAdmin($translator, function () use ($type, $date, $datetime, $locale): void {
                $this->assertSame($date, $type->renderAdmin('2026-10-22', []), $locale);
                $this->assertSame($datetime, $type->renderAdmin('2026-10-22T23:15:12+00:00', ['time' => true]), $locale);
            });
        }
    }

    public function test_seconds_from_the_datetime_input_are_read(): void
    {
        $this->assertSame('Oct 22, 2026 23:15', (new DateFieldType())->renderAdmin('2026-10-22T23:15:12', ['time' => true]));
        $this->assertSame('2026-10-22T23:15:12+02:00', DateFieldType::read('2026-10-22T23:15:12')?->format(DATE_ATOM), 'in the app timezone');
    }

    public function test_the_stored_rule_takes_exactly_what_the_type_reads(): void
    {
        $type = new DateFieldType();
        $rule = $type->storedRules([])[0];
        $this->assertEquals($type->storedRules([]), $type->storedRules(['time' => true]), 'with or without time');
        $context = new \Preflow\Validation\ValidationContext('d', []);

        foreach (['2026-10-22', '2026-10-22T23:15', '2026-10-22T23:15:12', '2026-10-22T23:15:12+00:00', '2026-10-22T23:15:12.5+00:00', '2026-10-22T23:15+00:00', '2026-10-22T23:15:12Z', gmdate(DATE_ATOM), '', null] as $value) {
            $this->assertTrue($rule->validate($value, $context), var_export($value, true));
            if (is_string($value) && $value !== '') {
                $this->assertNotNull(DateFieldType::read($value), $value . ' is read');
            }
        }
        foreach (['tomorrow', 'now', '2026-10-22 23:15', '22.10.2026', '2026-02-30', '2026-10-22T24:15', ['2026-10-22'], 20261022,
            '2026-02-30T05:00:00+00:00', '2026-11-27T25:00:00+00:00', '2026-11-27T05:61+01:00', '2026-11-31T05:00+01:00'] as $value) {
            $this->assertNotTrue($rule->validate($value, $context), var_export($value, true));
            if (is_string($value)) {
                $this->assertSame($value, $type->renderAdmin($value, []), $value . ' is not read either');
            }
        }
    }

    public function test_a_padded_value_is_not_stored_but_still_shown(): void
    {
        $type = new DateFieldType();
        $context = new \Preflow\Validation\ValidationContext('d', []);

        $this->assertNotTrue($type->storedRules([])[0]->validate(' 2026-11-27 ', $context), 'stored as it is, it would print padded');
        $this->assertNull(DateFieldType::read(' 2026-11-27 '));
        $this->assertSame('Nov 27, 2026', $type->renderAdmin(' 2026-11-27 ', []), 'the admin still shows an old padded value');
    }
}
