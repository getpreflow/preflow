<?php

declare(strict_types=1);

namespace Preflow\Publishing\Tests;

use Preflow\Data\DynamicRecord;
use Preflow\Publishing\PublishState;
use Preflow\Publishing\Publisher;
use Preflow\Publishing\RecordWriter;
use Preflow\Publishing\SaveOptions;
use Preflow\Publishing\SchemaPublishingTypes;
use Preflow\Publishing\Tests\Fixtures\Recorder;
use Preflow\Publishing\WriteKind;

/**
 * RecordWriter::duplicate(): "page" publishes and holds a slug, unique fields of several kinds,
 * blocks and references; "topic" has a translatable slug; "note" (no publishing) is made
 * orderable through the Recorder.
 */
final class RecordWriterDuplicateTest extends PublishingAppTestCase
{
    private Recorder $recorder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->write('config/models/page.json', (string) json_encode(['key' => 'page', 'table' => 'page', 'storage' => 'json', 'publishing' => true, 'fields' => [
            'title' => ['type' => 'string', 'translatable' => true],
            'slug' => ['type' => 'string', 'validate' => ['required', 'slug']],
            'code' => ['type' => 'string', 'unique' => true, 'length' => 6],
            'email' => ['type' => 'string', 'unique' => true, 'validate' => ['email']],
            'number' => ['type' => 'number', 'unique' => true],
            'content' => ['type' => 'blocks'],
            'image' => ['type' => 'asset'],
            'note' => ['type' => 'relation', 'relation' => ['to' => 'note']],
        ]]));
        $this->write('config/models/doc.json', (string) json_encode(['key' => 'doc', 'table' => 'doc', 'storage' => 'json', 'fields' => [
            'handle' => ['type' => 'string'],
            'path' => ['type' => 'string'],
            'slug' => ['type' => 'string', 'validate' => ['slug:path']],
            'site' => ['type' => 'string'],
            'code' => ['type' => 'string', 'unique' => ['site']],
        ]]));
        $this->write('config/models/topic.json', (string) json_encode(['key' => 'topic', 'table' => 'topic', 'storage' => 'json', 'fields' => [
            'name' => ['type' => 'string', 'translatable' => true],
            'slug' => ['type' => 'string', 'translatable' => true],
        ]]));
    }

    private function writer(): RecordWriter
    {
        $this->recorder ??= new Recorder($this->dm());
        $publisher = new Publisher($this->dm(), new SchemaPublishingTypes($this->dm()), $this->recorder, index: $this->recorder, addresses: $this->recorder);

        return new RecordWriter($this->dm(), $publisher, $this->recorder, $this->recorder, $this->recorder, $this->recorder, $this->recorder);
    }

    /** @return array<string, mixed> */
    private static function page(): array
    {
        return [
            'title' => ['en' => 'About', 'de' => 'Über uns'],
            'slug' => 'about',
            'code' => 'ABOUT',
            'email' => 'team@example.com',
            'number' => 7,
            'content' => [['_uid' => 'aaaaaaaaaaaaaaaa', '_block' => 'text', 'body' => ['en' => 'Hello', 'de' => 'Hallo']]],
            'image' => 'asset-1',
            'note' => 'n1',
            '_locales' => ['en', 'de'],
        ];
    }

    public function test_the_copy_has_every_language_its_own_blocks_and_the_same_references(): void
    {
        $writer = $this->writer();
        $this->working('page', 'p1', self::page());

        $copy = $writer->duplicate('page', 'p1', new SaveOptions(by: 'ana', kind: WriteKind::Autosave))->record;
        $id = (string) $copy->getId();

        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $id);
        $stored = $this->stored('page', $id);
        $this->assertSame(['en' => 'About', 'de' => 'Über uns'], $stored->get('title'));
        $this->assertSame(['en', 'de'], $stored->get('_locales'));
        $this->assertSame(self::page()['content'], $stored->get('content'));
        $this->assertSame('asset-1', $stored->get('image'));
        $this->assertSame('n1', $stored->get('note'));
        $this->assertSame(["before:page:{$id}:save", "after:page:{$id}:save"], $this->recorder->kinds, 'the usual save hooks, as a save');

        // The copy's blocks are its own: changing them leaves the record's alone.
        $stored->set('content', [['_uid' => 'aaaaaaaaaaaaaaaa', '_block' => 'text', 'body' => ['en' => 'Changed']]]);
        $writer->save($stored);
        $this->assertSame(self::page()['content'], $this->stored('page', 'p1')->get('content'));
    }

    public function test_the_slug_takes_the_next_free_one(): void
    {
        $writer = $this->writer();
        $this->working('page', 'p1', self::page());

        $first = $writer->duplicate('page', 'p1')->record;
        $second = $writer->duplicate('page', 'p1')->record;

        $this->assertSame('about-2', $this->stored('page', (string) $first->getId())->get('slug'));
        $this->assertSame('about-3', $this->stored('page', (string) $second->getId())->get('slug'));
        $this->assertSame('about', $this->stored('page', 'p1')->get('slug'));
    }

    public function test_unique_text_takes_a_suffix_and_unique_fields_that_cannot_are_left_empty(): void
    {
        $writer = $this->writer();
        $this->working('page', 'p1', self::page());

        $first = $this->stored('page', (string) $writer->duplicate('page', 'p1')->record->getId());
        $second = $this->stored('page', (string) $writer->duplicate('page', 'p1')->record->getId());

        $this->assertSame('ABOU-2', $first->get('code'), 'within its length (6)');
        $this->assertSame('ABOU-3', $second->get('code'));
        $this->assertNull($first->get('email'), 'an email with a suffix is no address');
        $this->assertNull($first->get('number'), 'a number takes no suffix');
    }

    public function test_a_translatable_slug_is_freed_in_each_language(): void
    {
        $writer = $this->writer();
        $this->working('topic', 't1', ['name' => ['en' => 'News', 'de' => 'Neues'], 'slug' => ['en' => 'news', 'de' => 'neues']]);
        $this->working('topic', 't2', ['slug' => ['en' => 'news-2']]);

        $copy = $this->stored('topic', (string) $writer->duplicate('topic', 't1')->record->getId());

        $this->assertSame(['en' => 'news-3', 'de' => 'neues-2'], $copy->get('slug'));
        $this->assertSame(['en' => 'News', 'de' => 'Neues'], $copy->get('name'));
    }

    public function test_overrides_set_a_translatable_title_per_language(): void
    {
        $writer = $this->writer();
        $this->working('topic', 't1', ['name' => ['en' => 'News', 'de' => 'Neues', 'fr' => 'Actualités'], 'slug' => ['en' => 'news', 'de' => 'neues']]);

        $copy = $this->stored('topic', (string) $writer->duplicate('topic', 't1', overrides: ['name' => ['en' => 'News (copy)', 'de' => 'Neues (Kopie)']])->record->getId());

        $this->assertSame(['en' => 'News (copy)', 'de' => 'Neues (Kopie)', 'fr' => 'Actualités'], $copy->get('name'), 'the languages not given stay as copied');
        $this->assertSame(['en' => 'News', 'de' => 'Neues', 'fr' => 'Actualités'], $this->stored('topic', 't1')->get('name'));
    }

    public function test_an_overridden_slug_is_still_freed(): void
    {
        $writer = $this->writer();
        $this->working('page', 'p1', self::page());
        $this->working('page', 'p2', ['title' => ['en' => 'Taken'], 'slug' => 'taken']);

        $copy = $this->stored('page', (string) $writer->duplicate('page', 'p1', overrides: ['slug' => 'taken'])->record->getId());

        $this->assertSame('taken-2', $copy->get('slug'), 'the override goes through the free-value rule');
    }

    public function test_an_override_of_no_field_is_refused_and_nothing_is_written(): void
    {
        $writer = $this->writer();
        $this->working('page', 'p1', self::page());

        try {
            $writer->duplicate('page', 'p1', overrides: ['nope' => 'x']);
            $this->fail('refused');
        } catch (\InvalidArgumentException) {
        }
        $this->assertCount(1, $this->dm()->queryType('page')->get()->items());
    }

    /** @return list<array{0: string, 1: array<string, mixed>}> */
    public static function refusedOverrides(): array
    {
        return [
            'the id' => ['page', ['uuid' => 'x']],
            'a version' => ['page', ['_version' => 3]],
            'a schedule' => ['page', ['_publish_at' => '2099-01-01T10:00']],
            'a share token' => ['page', ['_share_token' => 'secret']],
            'a publish stamp' => ['page', ['_published_at' => '2026-01-01 10:00:00']],
            'who published' => ['page', ['_published_by' => 'ana']],
            'the position' => ['page', ['_order' => 5]],
            'a created stamp' => ['stamped', ['createdAt' => '2020-01-01 00:00:00']],
            'an updated stamp' => ['stamped', ['updatedAt' => '2020-01-01 00:00:00']],
            'a language the type does not have' => ['page', ['title' => ['en' => 'About (copy)', 'xx' => 'Nope']]],
            'a scalar for a translatable field' => ['page', ['title' => 'About (copy)']],
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('refusedOverrides')]
    public function test_an_override_of_a_system_key_or_a_bad_language_value_is_refused_and_nothing_is_written(string $type, array $overrides): void
    {
        $this->write('config/models/page.json', (string) json_encode(['key' => 'page', 'table' => 'page', 'storage' => 'json', 'publishing' => true, 'fields' => [
            'title' => ['type' => 'string', 'translatable' => true],
            'slug' => ['type' => 'string', 'validate' => ['required', 'slug']],
            // System keys a type declares as fields of its own are still the system's.
            '_order' => ['type' => 'number'],
            '_publish_at' => ['type' => 'string'],
        ]]));
        $this->reboot();
        $writer = $this->writer();
        $this->working('page', 'p1', ['title' => ['en' => 'About'], 'slug' => 'about']);
        $this->working('stamped', 's1', ['title' => 'Stamped', 'createdAt' => '2026-01-01 00:00:00', 'updatedAt' => '2026-01-02 00:00:00']);

        try {
            $writer->duplicate($type, $type === 'page' ? 'p1' : 's1', overrides: $overrides);
            $this->fail('refused');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString((string) array_key_first($overrides), $e->getMessage());
            $this->assertMatchesRegularExpression('/system key|has no language|is translatable/', $e->getMessage(), 'refused for what it is, not as an unknown field');
        }
        $this->assertCount(1, $this->dm()->queryType($type)->get()->items());
    }

    public function test_an_override_may_empty_a_translatable_field(): void
    {
        $writer = $this->writer();
        $this->working('topic', 't1', ['name' => ['en' => 'News', 'de' => 'Neues'], 'slug' => ['en' => 'news']]);

        $copy = $this->stored('topic', (string) $writer->duplicate('topic', 't1', overrides: ['name' => null])->record->getId());

        $this->assertEmpty($copy->get('name'));
    }

    public function test_an_orderable_copy_goes_right_after_its_record(): void
    {
        $writer = $this->writer();
        $this->recorder->orderable = ['note'];
        $this->working('note', 'n1', ['title' => ['en' => 'One'], '_order' => 10]);
        $this->working('note', 'n2', ['title' => ['en' => 'Two'], '_order' => '20']);
        $this->working('note', 'n3', ['title' => ['en' => 'Three'], '_order' => 30]);

        $copy = (string) $writer->duplicate('note', 'n1')->record->getId();

        $this->assertSame(15, $this->stored('note', $copy)->get('_order'));
        $this->assertSame(['n1', $copy, 'n2', 'n3'], $this->order());

        $last = (string) $writer->duplicate('note', 'n3')->record->getId();
        $this->assertSame(40, $this->stored('note', $last)->get('_order'), 'after the last: +10');
    }

    public function test_without_room_after_the_record_the_list_is_renumbered(): void
    {
        $writer = $this->writer();
        $this->recorder->orderable = ['note'];
        $this->working('note', 'n1', ['_order' => 10]);
        $this->working('note', 'n2', ['_order' => 11]);
        $this->working('note', 'n3', ['_order' => 12]);

        $copy = (string) $writer->duplicate('note', 'n2')->record->getId();

        $this->assertSame(['n1', 'n2', $copy, 'n3'], $this->order());
        $this->assertSame([10, 20, 30, 40], array_map(fn (string $id): int => (int) $this->stored('note', $id)->get('_order'), $this->order()));
    }

    public function test_a_publishing_copy_is_a_draft_and_its_record_stays_published(): void
    {
        $writer = $this->writer();
        $writer->save(DynamicRecord::fromArray($this->dm()->getTypeRegistry()->get('page'), ['uuid' => 'p1'] + self::page()), new SaveOptions(by: 'ana', publish: true));
        $source = $this->stored('page', 'p1');
        $source->set('_publish_at', '2099-01-01T10:00');
        $source->set('_share_token', 'secret');
        $this->dm()->saveType($source, false);

        $copy = (string) $writer->duplicate('page', 'p1', new SaveOptions(by: 'bo', mayPublish: false))->record->getId();

        $stored = $this->stored('page', $copy);
        $this->assertSame(PublishState::Draft, $this->publisher()->state($stored));
        $this->assertNull($this->publisher()->snapshot('page', $copy));
        $this->assertSame([], $this->publisher()->revisions('page', $copy));
        $this->assertSame(1, $stored->get('_version'), 'a new working copy');
        $this->assertEmpty($stored->get('_publish_at'), 'no schedule');
        $this->assertNull($stored->get('_share_token'), 'no share link');
        $this->assertSame(PublishState::Published, $this->publisher()->state($this->stored('page', 'p1')));
        $this->assertCount(1, $this->publisher()->revisions('page', 'p1'));
    }

    public function test_a_missing_record_or_a_publish_is_refused_and_nothing_is_written(): void
    {
        $writer = $this->writer();
        $this->working('page', 'p1', self::page());

        foreach ([['gone', new SaveOptions()], ['p1', new SaveOptions(publish: true)]] as [$id, $options]) {
            try {
                $writer->duplicate('page', $id, $options);
                $this->fail('Expected InvalidArgumentException');
            } catch (\InvalidArgumentException) {
            }
        }

        $this->assertCount(1, $this->dm()->queryType('page')->get()->items());
        $this->assertSame([], $this->recorder->log);
    }

    public function test_the_fields_of_the_address_are_freed_whatever_their_name(): void
    {
        $writer = $this->writer();
        $this->recorder->addressFields = ['doc' => ['handle' => false, 'path' => true]];
        $this->working('doc', 'd1', ['handle' => 'about', 'path' => 'docs/ueber-uns', 'slug' => 'guides/intro']);

        $first = $this->stored('doc', (string) $writer->duplicate('doc', 'd1')->record->getId());
        $second = $this->stored('doc', (string) $writer->duplicate('doc', 'd1')->record->getId());

        $this->assertSame(['about-2', 'docs/ueber-uns-2'], [$first->get('handle'), $first->get('path')], '"/{handle}" and a catch-all "{...path}"');
        $this->assertSame(['about-3', 'docs/ueber-uns-3'], [$second->get('handle'), $second->get('path')]);
        $this->assertSame('guides/intro-2', $first->get('slug'), 'a slug:path field that is not unique: the suffix on the whole path');
    }

    public function test_without_address_fields_a_field_of_another_name_is_copied_as_it_is(): void
    {
        $this->working('doc', 'd1', ['handle' => 'about']);

        $copy = $this->stored('doc', (string) $this->writer()->duplicate('doc', 'd1')->record->getId());

        $this->assertSame('about', $copy->get('handle'), 'the RecordTypes binding names no address');
    }

    public function test_a_scoped_unique_value_is_freed_across_the_whole_table(): void
    {
        $writer = $this->writer();
        $this->working('doc', 'd1', ['site' => 'a', 'code' => 'X']);
        $this->working('doc', 'd2', ['site' => 'b', 'code' => 'X-2']);

        $copy = $this->stored('doc', (string) $writer->duplicate('doc', 'd1')->record->getId());

        $this->assertSame('X-3', $copy->get('code'), 'X-2 is free within site "a", but held in "b": the scope is not looked at');
    }

    public function test_a_publishing_orderable_copy_renumbers_the_list_and_the_snapshots_follow(): void
    {
        $writer = $this->writer();
        $this->recorder->orderable = ['post'];
        foreach (['p1' => 10, 'p2' => 11] as $id => $order) {
            $writer->save(DynamicRecord::fromArray($this->dm()->getTypeRegistry()->get('post'), ['uuid' => $id, 'title' => ['en' => $id], '_order' => $order]), new SaveOptions(publish: true));
        }

        $copy = (string) $writer->duplicate('post', 'p1')->record->getId();

        $this->assertSame([10, 20, 30], array_map(fn (string $id): int => (int) $this->stored('post', $id)->get('_order'), ['p1', $copy, 'p2']));
        $this->assertSame(30, (int) $this->publisher()->snapshot('post', 'p2')?->get('_order'), 'a position is carried into the snapshot');
        $this->assertNull($this->publisher()->snapshot('post', $copy));
        $this->assertSame(PublishState::Published, $this->publisher()->state($this->stored('post', 'p2')), 'a position is no content change');
    }

    /** @return list<string> the notes' ids in list order */
    private function order(): array
    {
        $items = $this->dm()->queryType('note')->orderBy(RecordWriter::ORDER, numeric: true)->orderBy('uuid')->get()->items();

        return array_values(array_map(static fn (DynamicRecord $r): string => (string) $r->getId(), $items));
    }
}
