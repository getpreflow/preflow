<?php

declare(strict_types=1);

namespace Preflow\Publishing\Tests;

use Preflow\Data\DynamicRecord;
use Preflow\Publishing\CoreRecordChecks;
use Preflow\Publishing\NoRecordChecks;
use Preflow\Publishing\RecordChecks;
use Preflow\Publishing\RecordWriter;
use Preflow\Publishing\SaveOptions;
use Preflow\Validation\ValidationException;
use Preflow\Validation\ValidationResult;

/**
 * Publishing without Folio (0.93): the default RecordChecks is CoreRecordChecks, the core's rules
 * of each field (DataManager::fieldRules()), so a publish refuses what saveType() refuses. A
 * "readonly" field is not checked; a closure adds rules; SaveOptions::$checks replaces the bound
 * checks for one RecordWriter save.
 */
final class CoreRecordChecksTest extends PublishingAppTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $fields = [
            'title' => ['type' => 'string', 'validate' => ['required']],
            'code' => ['type' => 'string', 'readonly' => true, 'validate' => ['required']],
            'when' => ['type' => 'date'],
        ];
        $this->write('config/models/article.json', (string) json_encode(['key' => 'article', 'table' => 'article', 'storage' => 'json', 'publishing' => true, 'fields' => $fields]));
        $this->write('config/models/memo.json', (string) json_encode(['key' => 'memo', 'table' => 'memo', 'storage' => 'json', 'fields' => $fields]));
    }

    /** @param array<string, mixed> $data */
    private function record(string $type, string $id, array $data): DynamicRecord
    {
        return DynamicRecord::fromArray($this->dm()->getTypeRegistry()->get($type), ['uuid' => $id] + $data);
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

    public function test_publish_without_folio_refuses_what_save_type_refuses(): void
    {
        $this->assertInstanceOf(CoreRecordChecks::class, $this->app()->container()->get(RecordChecks::class), 'the default without Folio');

        $saveType = self::errorsOf(fn () => $this->dm()->saveType($this->record('article', 'a0', ['title' => ''])));
        $working = $this->working('article', 'a1', ['title' => '']);
        $publish = self::errorsOf(fn () => $this->publisher()->publish($working, 'ana'));

        $this->assertArrayHasKey('title', $saveType);
        $this->assertSame($saveType['title'], $publish['title'], 'same key, same message');
        $this->assertNull($this->publisher()->snapshot('article', 'a1'), 'nothing published');
    }

    public function test_publish_without_folio_takes_every_stored_date_shape(): void
    {
        $working = $this->working('article', 'a1', ['title' => 'Hello', 'when' => '2026-11-27T05:00:00+00:00']);
        $this->publisher()->publish($working, 'ana');
        $this->assertSame('2026-11-27T05:00:00+00:00', $this->publisher()->snapshot('article', 'a1')?->get('when'));

        $bad = $this->working('article', 'a2', ['title' => 'Hello', 'when' => '26.11.2026']);
        $this->assertArrayHasKey('when', self::errorsOf(fn () => $this->publisher()->publish($bad, 'ana')));
    }

    public function test_readonly_field_is_not_checked(): void
    {
        $checks = new CoreRecordChecks($this->dm());
        $def = $this->dm()->getTypeRegistry()->get('article');

        $rules = $checks->rules($def);

        $this->assertSame([], $rules['code'], 'an empty list: the core runs none of its own for it');
        $this->assertContains('required', $rules['title']);
        $checks->validate($this->record('article', 'a1', ['title' => 'Hello', 'code' => '']));
        $working = $this->working('article', 'a2', ['title' => 'Hello', 'code' => '']);
        $this->publisher()->publish($working, 'ana');
        $this->assertNotNull($this->publisher()->snapshot('article', 'a2'));
    }

    public function test_extra_rules_are_added(): void
    {
        $seen = [];
        $checks = new CoreRecordChecks($this->dm(), static function ($def) use (&$seen): array {
            $seen[] = $def->key;

            return ['title' => ['max:3'], 'code' => ['max:1']];
        });
        $def = $this->dm()->getTypeRegistry()->get('article');

        $rules = $checks->rules($def);

        $this->assertSame(['article'], $seen);
        $this->assertSame('max:3', $rules['title'][array_key_last($rules['title'])], 'after the core rules');
        $this->assertContains('required', $rules['title']);
        $this->assertSame([], $rules['code'], 'a read-only field stays unchecked');
        $errors = self::errorsOf(fn () => $checks->validate($this->record('article', 'a1', ['title' => 'Hello'])));
        $this->assertSame(['title'], array_keys($errors));
    }

    public function test_record_writer_takes_the_options_checks(): void
    {
        $writer = $this->app()->container()->get(RecordWriter::class);
        $refuse = new class implements RecordChecks {
            public int $calls = 0;

            public function validate(DynamicRecord $record): void
            {
                $this->calls++;
                throw new ValidationException(new ValidationResult(['title' => ['Not like this.']]));
            }
        };

        // In place of the bound checks: a record they would take is refused…
        $errors = self::errorsOf(fn () => $writer->save($this->record('memo', 'm1', ['title' => 'Fine']), new SaveOptions(checks: $refuse)));
        $this->assertSame(['title' => ['Not like this.']], $errors);
        $this->assertSame(1, $refuse->calls);
        $this->assertNull($this->dm()->findType('memo', 'm1'), 'nothing stored');

        // …and one they would refuse is stored.
        $writer->save($this->record('memo', 'm2', ['title' => '']), new SaveOptions(checks: new NoRecordChecks()));
        $this->assertNotNull($this->dm()->findType('memo', 'm2'));

        // Without options checks, the bound ones (the core's) run.
        $this->assertArrayHasKey('title', self::errorsOf(fn () => $writer->save($this->record('memo', 'm3', ['title' => '']))));
    }
}
