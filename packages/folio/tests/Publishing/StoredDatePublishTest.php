<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Publishing;

use PHPUnit\Framework\TestCase;
use Preflow\Data\DynamicRecord;
use Preflow\Folio\Publishing\FolioRecordChecks;
use Preflow\Folio\Tests\Support\PublishingScaffold;
use Preflow\Publishing\RecordChecks;
use Preflow\Publishing\RecordWriter;
use Preflow\Validation\ValidationException;

/**
 * Since 0.93 Folio's RecordChecks (publishing, RecordWriter's saves) run the core's stored rules
 * plus Folio's address rules: a date server code stored with an offset (gmdate(DATE_ATOM))
 * publishes and saves, as saveType() takes it. Only Folio's own form keeps its input rule
 * (FolioFormChecks): "Y-m-d", or "Y-m-d\TH:i[:s]" with "time", as in 0.92.1.
 */
final class StoredDatePublishTest extends TestCase
{
    use PublishingScaffold;

    private const string OFFSET = '2026-11-27T05:00:00+00:00';

    protected function setUp(): void
    {
        $this->scaffoldPublishing();
        $this->writeModel('event', [
            'label' => 'Events',
            'publishing' => true,
            'frontend' => ['route' => '/events/{slug}'],
            'fields' => [
                'name' => ['type' => 'string', 'validate' => ['required']],
                'slug' => ['type' => 'string', 'validate' => ['required']],
                'starts' => ['type' => 'date', 'time' => true],
            ],
        ]);
        $this->writeModel('memo', [
            'label' => 'Memos',
            'fields' => [
                'name' => ['type' => 'string', 'validate' => ['required']],
                'when' => ['type' => 'date'],
            ],
        ]);
    }

    protected function tearDown(): void
    {
        $this->removeScaffold();
    }

    /** @return array<string, list<string>> */
    private static function errorsOf(\Closure $run): array
    {
        try {
            $run();
        } catch (ValidationException $e) {
            return $e->errors();
        }
        self::fail('Expected ValidationException');
    }

    public function test_publish_accepts_a_stored_date_with_an_offset(): void
    {
        $this->assertInstanceOf(FolioRecordChecks::class, $this->app()->container()->get(RecordChecks::class));
        $working = $this->working('event', 'e1', ['name' => 'Launch', 'slug' => 'launch', 'starts' => self::OFFSET]);

        $this->publisher()->publish($working, 'admin');

        $this->assertSame(self::OFFSET, $this->publisher()->snapshot('event', 'e1')?->get('starts'));
        $bad = $this->working('event', 'e2', ['name' => 'Launch', 'slug' => 'launch-2', 'starts' => '26.11.2026']);
        $this->assertArrayHasKey('starts', self::errorsOf(fn () => $this->publisher()->publish($bad, 'admin')), 'a date the type does not read is still refused');
    }

    public function test_record_writer_save_accepts_it(): void
    {
        $writer = $this->app()->container()->get(RecordWriter::class);
        $def = $this->dm()->getTypeRegistry()->get('memo');

        $writer->save(DynamicRecord::fromArray($def, ['uuid' => 'm1', 'name' => 'Note', 'when' => self::OFFSET]));

        $this->assertSame(self::OFFSET, $this->stored('memo', 'm1')->get('when'));
        $this->assertArrayHasKey('when', self::errorsOf(fn () => $writer->save(DynamicRecord::fromArray($def, ['uuid' => 'm2', 'name' => 'Note', 'when' => '26.11.2026']))));
        $this->assertNull($this->dm()->findType('memo', 'm2'));
    }

    public function test_folio_form_still_takes_only_its_input_shape(): void
    {
        foreach (['26.11.2026', self::OFFSET] as $posted) {
            $res = $this->post('/folio/memo', ['_locale' => 'en', 'name' => 'Note', 'when' => $posted]);
            $this->assertSame(422, $res->getStatusCode(), $posted . ' is refused by the form');
        }
        $this->assertSame([], $this->dm()->queryType('memo')->get()->items(), 'nothing stored');

        $res = $this->post('/folio/memo', ['_locale' => 'en', 'name' => 'Note', 'when' => '2026-11-27']);
        $this->assertSame(302, $res->getStatusCode(), (string) $res->getBody());
        $items = $this->dm()->queryType('memo')->get()->items();
        $this->assertCount(1, $items);
        $this->assertSame('2026-11-27', $items[0]->get('when'));
    }

    public function test_address_rule_still_applies_on_publish(): void
    {
        $working = $this->working('event', 'e1', ['name' => 'Launch', 'slug' => 'Not A Slug', 'starts' => self::OFFSET]);

        $errors = self::errorsOf(fn () => $this->publisher()->publish($working, 'admin'));

        $this->assertSame(['slug'], array_keys($errors));
        $this->assertNull($this->publisher()->snapshot('event', 'e1'));
    }
}
