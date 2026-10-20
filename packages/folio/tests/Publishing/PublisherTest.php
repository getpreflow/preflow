<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Publishing;

use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\JsonFileDriver;
use Preflow\Data\DynamicRecord;
use Preflow\Data\TypeRegistry;
use Preflow\Folio\Content\RecordValidator;
use Preflow\Folio\Content\TypeCatalog;
use Preflow\Folio\Field\FieldTypeRegistry;
use Preflow\Folio\Field\Types\StringFieldType;
use Preflow\Folio\Field\Types\TextFieldType;
use Preflow\Folio\Publishing\PublishState;
use Preflow\Folio\Publishing\Publisher;
use Preflow\Folio\Publishing\VersionConflict;
use Preflow\Validation\RuleFactory;
use Preflow\Validation\ValidationException;
use Preflow\Validation\ValidatorFactory;

final class PublisherTest extends TestCase
{
    private string $dir;
    private DataManager $dm;
    private TypeRegistry $registry;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/pub_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/models', 0777, true);
        $fields = [
            'title' => ['type' => 'string', 'validate' => ['required']],
            'body' => ['type' => 'text'],
        ];
        $this->model('post', ['frontend' => ['route' => '/{slug}'], 'fields' => $fields]);
        $this->model('memo', ['fields' => $fields]);
        $this->model('memo2', ['publishing' => true, 'fields' => $fields]);
        $this->model('post2', ['frontend' => ['route' => '/x/{slug}'], 'publishing' => false, 'fields' => $fields]);
        $this->model('site', ['singleton' => true, 'fields' => $fields]);

        $this->registry = new TypeRegistry($this->dir . '/models');
        $this->dm = new DataManager(
            drivers: ['json' => new JsonFileDriver($this->dir . '/store')],
            defaultDriver: 'json',
            typeRegistry: $this->registry,
            validatorFactory: new ValidatorFactory(new RuleFactory()),
        );
        $this->now = new \DateTimeImmutable('2026-10-20T10:00:00+00:00');
    }

    protected function tearDown(): void
    {
        $rii = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($rii as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($this->dir);
    }

    public function test_publishing_defaults(): void
    {
        $p = $this->publisher();

        $this->assertTrue($p->enabled('post'));
        $this->assertFalse($p->enabled('memo'));
        $this->assertTrue($p->enabled('memo2'));
        $this->assertTrue($p->enabled('site'));
        $this->assertFalse($p->enabled('post2'));
    }

    public function test_states_follow_content(): void
    {
        $p = $this->publisher();
        $post = $this->working('post', ['title' => 'A']);

        $this->assertSame(PublishState::Draft, $p->state($post));

        $p->publish($post, null);
        $this->assertSame(PublishState::Published, $p->state($this->reload('post')));

        $changed = $this->reload('post');
        $changed->set('title', 'B');
        $p->saveDraft($changed, null);
        $this->assertSame(PublishState::Changed, $p->state($this->reload('post')));

        $p->discard('post', 'r1');
        $this->assertSame(PublishState::Published, $p->state($this->reload('post')));
        $this->assertSame('A', $this->reload('post')->get('title'));
    }

    public function test_meta_keys_do_not_change_state(): void
    {
        $p = $this->publisher();
        $p->publish($this->working('post', ['title' => 'A']), null);

        $post = $this->reload('post');
        $post->set('_publish_at', '2026-11-01T09:00');
        $post->set('_order', 30);
        $post->set('_share_token', str_repeat('a', 32));
        $p->saveDraft($post, null);

        $this->assertSame(PublishState::Published, $p->state($this->reload('post')));
    }

    public function test_missing_and_empty_values_are_the_same_content(): void
    {
        $p = $this->publisher();
        $p->publish($this->working('post', ['title' => 'A']), null); // no body key, as seeded
        $post = $this->reload('post');
        $post->set('body', ''); // what a form save stores for an untouched empty field
        $p->saveDraft($post, null);

        $this->assertSame(PublishState::Published, $p->state($this->reload('post')));

        $post = $this->reload('post');
        $post->set('title', 'B');
        $p->publish($post, null);
        $this->assertSame(['title'], $p->revisions('post', 'r1')[0]->changed);
    }

    public function test_cleared_schedule_survives_null_dropping_storage(): void
    {
        // PdoDriver drops null values, and MySQL's upsert keeps the old column value.
        $inner = new JsonFileDriver($this->dir . '/store');
        $this->dm = new DataManager(
            drivers: ['json' => new NullDroppingDriver($inner)],
            defaultDriver: 'json',
            typeRegistry: $this->registry,
            validatorFactory: new ValidatorFactory(new RuleFactory()),
        );
        $p = $this->publisher();
        $this->dm->saveType($this->working('post', ['title' => 'A', '_publish_at' => '2026-10-20T09:00']), false);

        $p->publish($this->reload('post'), 'scheduler');

        $due = (new \Preflow\Folio\Publishing\Scheduler($p, new TypeCatalog($this->dir . '/models'), $this->dm))
            ->runDue(new \DateTimeImmutable('2026-10-20T10:00'));
        $this->assertSame([], $due['published']);
    }

    public function test_publish_stamps_snapshot(): void
    {
        $p = $this->publisher();
        $p->publish($this->working('post', ['title' => 'A', '_publish_at' => '2026-10-21T09:00']), 'u@x');

        $snapshot = $p->snapshot('post', 'r1');
        $this->assertSame($this->now->format(DATE_ATOM), $snapshot?->get('_published_at'));
        $this->assertSame('u@x', $snapshot?->get('_published_by'));
        $this->assertEmpty($this->reload('post')->get('_publish_at'));
    }

    public function test_publish_validates_fully_but_draft_does_not(): void
    {
        $p = $this->publisher();
        $post = $this->working('post', ['title' => '']);
        $p->saveDraft($post, null);
        $this->assertSame('', $this->reload('post')->get('title'));

        try {
            $p->publish($this->reload('post'), null);
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('title', $e->errors());
        }
        $this->assertNull($p->snapshot('post', 'r1'));
    }

    public function test_unpublish_removes_snapshot(): void
    {
        $p = $this->publisher();
        $p->publish($this->working('post', ['title' => 'A']), null);
        $p->unpublish('post', 'r1');

        $this->assertNull($p->snapshot('post', 'r1'));
        $this->assertSame(PublishState::Draft, $p->state($this->reload('post')));
    }

    public function test_version_conflict(): void
    {
        $p = $this->publisher();
        $this->dm->saveType($this->working('post', ['title' => 'A', '_version' => 3]), false);

        try {
            $p->saveDraft($this->reload('post'), 2);
            $this->fail('expected VersionConflict');
        } catch (VersionConflict $e) {
            $this->assertSame(3, $e->current);
        }

        $p->saveDraft($this->reload('post'), 3);
        $this->assertSame(4, $this->reload('post')->get('_version'));

        $p->saveDraft($this->reload('post'), null);
        $this->assertSame(5, $this->reload('post')->get('_version'));
    }

    public function test_live_and_query_read_snapshots_only_when_enabled(): void
    {
        $p = $this->publisher();
        $p->saveDraft($this->working('post', ['title' => 'Draft']), null);
        $p->saveDraft($this->working('memo', ['title' => 'Memo']), null);

        $this->assertNull($p->live('post', 'r1'));
        $this->assertSame(0, $p->query('post')->get()->total());
        $this->assertSame('Memo', $p->live('memo', 'r1')?->get('title'));
        $this->assertSame(1, $p->query('memo')->get()->total());

        $p->publish($this->reload('post'), null);
        $this->assertSame('Draft', $p->live('post', 'r1')?->get('title'));
        $this->assertSame(1, $p->query('post')->get()->total());
    }

    public function test_publish_appends_revision_with_changed_fields(): void
    {
        $p = $this->publisher();
        $p->publish($this->working('post', ['title' => 'A']), 'u@x');
        $post = $this->reload('post');
        $post->set('body', 'More');
        $p->publish($post, 'u@x');

        $revisions = $p->revisions('post', 'r1');
        $this->assertCount(2, $revisions);
        $this->assertSame(['body'], $revisions[0]->changed);
        $this->assertSame(['title'], $revisions[1]->changed);
        $this->assertSame('u@x', $revisions[0]->createdBy);
        $this->assertSame('More', $revisions[0]->data['body']);
    }

    public function test_revisions_are_capped(): void
    {
        $p = $this->publisher(keep: 3);
        for ($i = 1; $i <= 5; $i++) {
            $post = $i === 1 ? $this->working('post', ['title' => 'T1']) : $this->reload('post');
            $post->set('title', 'T' . $i);
            $p->publish($post, null);
        }

        $this->assertSame([5, 4, 3], array_map(static fn ($r) => $r->seq, $p->revisions('post', 'r1')));
    }

    public function test_restore_copies_into_working_copy_only(): void
    {
        $p = $this->publisher();
        $p->publish($this->working('post', ['title' => 'A']), null);
        $post = $this->reload('post');
        $post->set('title', 'B');
        $p->publish($post, null);

        $first = $p->revisions('post', 'r1')[1];
        $p->restore('post', 'r1', $first->id);

        $this->assertSame('A', $this->reload('post')->get('title'));
        $this->assertSame(PublishState::Changed, $p->state($this->reload('post')));
        $this->assertSame('B', $p->snapshot('post', 'r1')?->get('title'));
    }

    private function publisher(int $keep = 20): Publisher
    {
        $fieldTypes = new FieldTypeRegistry();
        $fieldTypes->register(new StringFieldType());
        $fieldTypes->register(new TextFieldType());

        return new Publisher(
            $this->dm,
            new TypeCatalog($this->dir . '/models'),
            new RecordValidator($fieldTypes, $this->dm),
            $keep,
            fn (): \DateTimeImmutable => $this->now,
        );
    }

    /** @param array<string, mixed> $data */
    private function working(string $type, array $data): DynamicRecord
    {
        return DynamicRecord::fromArray($this->registry->get($type), ['uuid' => 'r1'] + $data);
    }

    private function reload(string $type): DynamicRecord
    {
        return $this->dm->findType($type, 'r1') ?? throw new \RuntimeException('missing');
    }

    /** @param array<string, mixed> $config */
    private function model(string $key, array $config): void
    {
        file_put_contents($this->dir . "/models/{$key}.json", json_encode(
            ['key' => $key, 'table' => $key, 'storage' => 'json', 'id_field' => 'uuid'] + $config,
        ));
    }
}
