<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Integration;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Activity\Activity;
use Preflow\Core\Activity\ActivityLog;
use Preflow\Data\DynamicRecord;
use Preflow\Folio\Tests\Support\StaffScaffold;
use Preflow\Publishing\Publisher;

/** The admin's autosave, unpublish and delete tell the activity log who did it. */
final class ActivityByTest extends TestCase
{
    use StaffScaffold;

    protected function setUp(): void
    {
        $this->staffSite();
        $this->write('config/models/post.json', (string) json_encode([
            'key' => 'post', 'table' => 'post', 'storage' => 'json', 'id_field' => 'uuid', 'label' => 'Posts', 'publishing' => true,
            'fields' => ['title' => ['type' => 'string']],
        ]));
        $this->flow = $this->app();
    }

    protected function tearDown(): void
    {
        $this->removeSite();
    }

    #[RunInSeparateProcess]
    public function test_autosave_unpublish_and_delete_name_the_person(): void
    {
        $dm = $this->dm();
        $record = DynamicRecord::fromArray($dm->getTypeRegistry()->get('post'), ['uuid' => 'p1', 'title' => 'First']);
        $dm->saveType($record, false);
        $this->flow->container()->get(Publisher::class)->publish($record, null);
        $this->post('/folio/login', ['email' => self::CONFIG_EMAIL, 'password' => self::PASSWORD]);

        $this->post('/folio/post/p1/autosave', ['title' => 'Second', '_version' => (string) $dm->findType('post', 'p1')?->get('_version')]);
        $this->post('/folio/post/p1/unpublish', []);
        $this->post('/folio/post/p1/delete', []);

        $rows = array_map(
            static fn (Activity $a): string => $a->kind->value . ' ' . ($a->by ?? '-'),
            $this->flow->container()->get(ActivityLog::class)->recent(10, ['post'])->items,
        );
        $this->assertSame(['deleted ' . self::CONFIG_EMAIL, 'unpublished ' . self::CONFIG_EMAIL, 'saved ' . self::CONFIG_EMAIL, 'published -'], $rows);
    }
}
