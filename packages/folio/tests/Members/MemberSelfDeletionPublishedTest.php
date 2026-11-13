<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Members;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;
use Preflow\Publishing\Publisher;
use Preflow\Folio\Tests\Members\Support\MembersScaffold;
use Preflow\Members\Members;
use Preflow\Members\Tests\Support\MemberFlows;

/**
 * A member's own deletion on a Folio site reaches the published snapshots of publishing types:
 * the members core plans with Folio's snapshot copies (restrict counts snapshot-only pointers),
 * and Folio's DELETED listener clears or forgets what the snapshots hold.
 */
final class MemberSelfDeletionPublishedTest extends TestCase
{
    use MemberFlows;
    use MembersScaffold;

    protected function tearDown(): void
    {
        if ($this->dir !== '') {
            $this->removeScaffold();
        }
    }

    private function site(string $self): void
    {
        $this->flowSite(files: ['config/models/order.json' => (string) json_encode([
            'key' => 'order', 'table' => 'order', 'storage' => 'members', 'id_field' => 'uuid', 'label' => 'Orders',
            'publishing' => true,
            'fields' => [
                'title' => ['type' => 'string'],
                'member' => ['type' => 'relation', 'relation' => ['to' => 'member', 'onDelete' => 'restrict', 'onSelfDelete' => $self]],
            ],
        ])]);
    }

    /** A published order pointing at $member. */
    private function publishedOrder(string $id, DynamicRecord $member): DynamicRecord
    {
        $dm = $this->flow->container()->get(DataManager::class);
        $order = DynamicRecord::fromArray($dm->getTypeRegistry()->get('order'), ['uuid' => $id, 'title' => 'Order ' . $id, 'member' => (string) $member->getId()]);
        $this->publisher()->saveDraft($order, null);
        $this->publisher()->publish($order, null);
        $this->assertSame((string) $member->getId(), (string) $this->publisher()->snapshot('order', $id)?->get('member'));

        return $order;
    }

    private function publisher(): Publisher
    {
        return $this->flow->container()->get(Publisher::class);
    }

    #[RunInSeparateProcess]
    public function test_detach_clears_the_pointer_in_the_snapshot_too(): void
    {
        $this->site('detach');
        $m = $this->makeMember();
        $this->publishedOrder('o1', $m);

        $this->assertTrue($this->flow->container()->get(Members::class)->delete($m)->deleted);

        $dm = $this->flow->container()->get(DataManager::class);
        $this->assertSame('', (string) $dm->findType('order', 'o1')?->get('member'), 'the working copy');
        $snapshot = $this->publisher()->snapshot('order', 'o1');
        $this->assertNotNull($snapshot, 'still published');
        $this->assertSame('', (string) $snapshot->get('member'), 'the published snapshot points at nobody');
    }

    /** A #[BeforeSave] refusing the detached record's save again never keeps a snapshot pointing at the deleted member. */
    #[RunInSeparateProcess]
    public function test_a_refused_save_of_the_detached_record_still_clears_the_snapshot(): void
    {
        $this->site('detach');
        $m = $this->makeMember();
        $this->publishedOrder('o1', $m);
        $c = $this->flow->container();
        $refusing = new class implements \Preflow\Publishing\SaveHooks {
            public function before(DynamicRecord $record): void
            {
                throw new \Preflow\Validation\ValidationException(new \Preflow\Validation\ValidationResult(['member' => ['Orders keep their member.']]));
            }
            public function after(DynamicRecord $record): void {}
            public function beforeDelete(DynamicRecord $record): void {}
            public function afterDelete(DynamicRecord $record): void {}
            public function replacesSave(string $type): bool { return false; }
            public function save(DynamicRecord $record, ?DynamicRecord $previous): void {}
        };
        $c->singleton(\Preflow\Publishing\RecordWriter::class, static fn () => new \Preflow\Publishing\RecordWriter(
            $c->get(DataManager::class), $c->get(Publisher::class), $c->get(\Preflow\Publishing\RecordChecks::class), $refusing,
        ));
        $version = (int) $c->get(DataManager::class)->findType('order', 'o1')?->get('_version');

        $this->assertTrue($c->get(Members::class)->delete($m)->deleted);

        $this->assertSame('', (string) $this->publisher()->snapshot('order', 'o1')?->get('member'), 'scrubbed although the save was refused');
        $working = $c->get(DataManager::class)->findType('order', 'o1');
        $this->assertSame('', (string) $working?->get('member'), 'the working copy is detached');
        $this->assertSame($version, (int) $working?->get('_version'), 'only its version bump was refused');
    }

    #[RunInSeparateProcess]
    public function test_detach_clears_a_snapshot_only_pointer(): void
    {
        $this->site('detach');
        $m = $this->makeMember();
        $order = $this->publishedOrder('o1', $m);
        $order->set('member', '');
        $this->publisher()->saveDraft($order, null); // only the published snapshot still points at the member

        $this->assertTrue($this->flow->container()->get(Members::class)->delete($m)->deleted);

        $this->assertSame('', (string) $this->publisher()->snapshot('order', 'o1')?->get('member'));
    }

    #[RunInSeparateProcess]
    public function test_cascade_forgets_the_published_snapshot(): void
    {
        $this->site('cascade');
        $m = $this->makeMember();
        $this->publishedOrder('o1', $m);

        $this->assertTrue($this->flow->container()->get(Members::class)->delete($m)->deleted);

        $this->assertNull($this->flow->container()->get(DataManager::class)->findType('order', 'o1'));
        $this->assertNull($this->publisher()->snapshot('order', 'o1'), 'unpublished as a staff delete would');
    }

    #[RunInSeparateProcess]
    public function test_a_snapshot_only_pointer_with_restrict_refuses(): void
    {
        $this->site('restrict');
        $m = $this->makeMember();
        $order = $this->publishedOrder('o1', $m);
        // The working copy no longer points at the member; the published snapshot still does.
        $order->set('member', '');
        $this->publisher()->saveDraft($order, null);

        $result = $this->flow->container()->get(Members::class)->delete($m);

        $this->assertTrue($result->refused);
        $this->assertSame(['order' => 1], $result->plan->restrictions());
        $this->assertNotNull($this->flow->container()->get(Members::class)->find((string) $m->getId()));
        $this->assertSame((string) $m->getId(), (string) $this->publisher()->snapshot('order', 'o1')?->get('member'));
    }
}
