<?php

declare(strict_types=1);

namespace Preflow\Members\Tests;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Auth\Remember\RememberMe;
use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;
use Preflow\Members\Console\PruneMembersCommand;
use Preflow\Members\DeletionResult;
use Preflow\Members\MemberEvents;
use Preflow\Members\Members;
use Preflow\Members\Tests\Support\MemberFlows;
use Preflow\Members\Tests\Support\MembersScaffold;
use Preflow\Notify\Notifier;
use Preflow\Notify\TransportFactory;

/**
 * A member deletes their own account: through the data delete plan with the self rules
 * (onSelfDelete), so records pointing at the member are detached, deleted with it, or keep the
 * account (restrict) — then nothing changes and the member is told honestly whether the site knows.
 */
final class SelfDeletionTest extends TestCase
{
    use MemberFlows;
    use MembersScaffold;

    private const string TOLD = 'The site has been told and will get in touch with you.';
    private const string CONTACT = 'Please contact us.';

    protected function tearDown(): void
    {
        $this->removeScaffold();
    }

    /** A site with an order type pointing at members: staff deletes are restricted, self deletes follow $self. @param array<string, mixed> $members */
    private function orderSite(string $self, array $members = []): void
    {
        $this->flowSite($members, files: ['config/models/order.json' => (string) json_encode([
            'key' => 'order', 'table' => 'order', 'storage' => 'json', 'id_field' => 'uuid', 'label' => 'Orders',
            'fields' => [
                'title' => ['type' => 'string'],
                'member' => ['type' => 'relation', 'relation' => ['to' => 'member', 'onDelete' => 'restrict', 'onSelfDelete' => $self]],
            ],
        ])]);
    }

    private function order(string $id, DynamicRecord $member): void
    {
        $dm = $this->flow->container()->get(DataManager::class);
        $dm->saveType(DynamicRecord::fromArray($dm->getTypeRegistry()->get('order'), ['uuid' => $id, 'title' => 'Order ' . $id, 'member' => (string) $member->getId()]), false);
    }

    private function findOrder(string $id): ?DynamicRecord
    {
        return $this->flow->container()->get(DataManager::class)->findType('order', $id);
    }

    private function deleteOwnAccount(): \Psr\Http\Message\ResponseInterface
    {
        $this->post('/members/login', ['email' => 'ada@example.com', 'password' => self::PASSWORD]);

        return $this->post('/members/account/delete', ['current' => self::PASSWORD, 'confirm' => '1']);
    }

    #[RunInSeparateProcess]
    public function test_detach_deletes_the_member_and_clears_the_orders_member(): void
    {
        $this->orderSite('detach');
        $m = $this->makeMember();
        $this->order('o1', $m);

        $response = $this->deleteOwnAccount();

        $this->assertSame(303, $response->getStatusCode());
        $this->assertSame('/members/register', $response->getHeaderLine('Location'));
        $this->assertNull($this->flow->container()->get(Members::class)->find((string) $m->getId()));
        $order = $this->findOrder('o1');
        $this->assertNotNull($order, 'the order stays');
        $this->assertSame('', (string) $order->get('member'), 'and points at nobody');
    }

    #[RunInSeparateProcess]
    public function test_cascade_deletes_the_orders_with_the_member(): void
    {
        $this->orderSite('cascade');
        $m = $this->makeMember();
        $this->order('o1', $m);
        $this->order('o2', $m);

        $this->assertSame(303, $this->deleteOwnAccount()->getStatusCode());

        $this->assertNull($this->flow->container()->get(Members::class)->find((string) $m->getId()));
        $this->assertNull($this->findOrder('o1'));
        $this->assertNull($this->findOrder('o2'));
    }

    #[RunInSeparateProcess]
    public function test_restrict_with_a_notice_address_keeps_everything_and_tells_the_site(): void
    {
        $this->orderSite('restrict', ['notice_email' => 'owner@site.test']);
        $m = $this->makeMember();
        $this->order('o1', $m);
        $cookie = $this->cookieValue($this->post('/members/login', ['email' => 'ada@example.com', 'password' => self::PASSWORD]), 'site_member');

        $response = $this->post('/members/account/delete', ['current' => self::PASSWORD, 'confirm' => '1']);

        $this->assertSame(409, $response->getStatusCode());
        $html = html_entity_decode((string) $response->getBody(), ENT_QUOTES);
        $this->assertStringContainsString("Your account can't be deleted right now.", $html);
        $this->assertStringContainsString(self::TOLD, $html);
        $this->assertStringNotContainsString(self::CONTACT, $html);
        $this->assertNotNull($this->flow->container()->get(Members::class)->find((string) $m->getId()), 'the member stays');
        $this->assertSame((string) $m->getId(), (string) $this->findOrder('o1')?->get('member'), 'the order is untouched');
        $this->assertSame(200, $this->send('GET', '/members/account')->getStatusCode(), 'still signed in');
        $this->assertNotNull($this->flow->container()->get(RememberMe::class)->resolve('members', $cookie), 'the remember cookie still works');

        $mails = array_values(array_filter($this->mails(), static fn ($mail) => $mail->to === ['owner@site.test']));
        $this->assertCount(1, $mails, 'one notice to the owner');
        $this->assertStringContainsString('Ada Lovelace', $mails[0]->subject);
        $this->assertStringContainsString('ada@example.com', (string) $mails[0]->text);
        $this->assertStringContainsString('order: 1', (string) $mails[0]->text);
    }

    #[RunInSeparateProcess]
    public function test_restrict_without_address_or_listener_asks_the_member_to_get_in_touch(): void
    {
        $this->orderSite('restrict');
        $m = $this->makeMember();
        $this->order('o1', $m);

        $response = $this->deleteOwnAccount();

        $this->assertSame(409, $response->getStatusCode());
        $html = html_entity_decode((string) $response->getBody(), ENT_QUOTES);
        $this->assertStringContainsString(self::CONTACT, $html);
        $this->assertStringNotContainsString(self::TOLD, $html);
        $this->assertSame([], $this->mails(), 'no mail went out');
        $this->assertNotNull($this->flow->container()->get(Members::class)->find((string) $m->getId()));
    }

    #[RunInSeparateProcess]
    public function test_a_failed_notice_mail_is_not_telling_the_site(): void
    {
        $this->orderSite('restrict', ['notice_email' => 'owner@site.test']);
        $c = $this->flow->container();
        $c->instance(Notifier::class, new Notifier($c->get(TransportFactory::class), [])); // every send fails
        $m = $this->makeMember();
        $this->order('o1', $m);

        $response = $this->deleteOwnAccount();

        $this->assertSame(409, $response->getStatusCode());
        $html = html_entity_decode((string) $response->getBody(), ENT_QUOTES);
        $this->assertStringContainsString(self::CONTACT, $html);
        $this->assertStringNotContainsString(self::TOLD, $html);
        $this->assertNotNull($c->get(Members::class)->find((string) $m->getId()));
        $log = (string) file_get_contents($this->dir . '/error.log');
        $this->assertStringContainsString('members: deletion refused notice failed for member ' . $m->getId(), $log);
        $this->assertStringNotContainsString('owner@site.test', $log, 'no address in the log');
    }

    #[RunInSeparateProcess]
    public function test_restrict_without_address_but_a_listener_that_tells_the_site(): void
    {
        $this->orderSite('restrict');
        $m = $this->makeMember();
        $this->order('o1', $m);
        $seen = [];
        $this->flow->container()->get(MemberEvents::class)->on(MemberEvents::DELETION_REFUSED, static function (array $payload) use (&$seen): bool {
            $seen[] = $payload;

            return true; // the project told the site itself
        });

        $html = (string) $this->deleteOwnAccount()->getBody();

        $this->assertStringContainsString(self::TOLD, $html);
        $this->assertCount(1, $seen);
        $this->assertSame((string) $m->getId(), (string) $seen[0]['member']->getId());
        $this->assertSame('restricted', $seen[0]['reason']);
        $this->assertFalse($seen[0]['notice_sent']);
        $this->assertSame(['order' => 1], $seen[0]['plan']->restrictions());
    }

    #[RunInSeparateProcess]
    public function test_german_refusal_says_sie(): void
    {
        $this->orderSite('restrict');
        $m = $this->makeMember('ada@example.com', 'member', 'de');
        $this->order('o1', $m);
        $this->post('/de/members/login', ['email' => 'ada@example.com', 'password' => self::PASSWORD]);

        $html = (string) $this->post('/de/members/account/delete', ['current' => self::PASSWORD, 'confirm' => '1'])->getBody();

        $this->assertStringContainsString('Ihr Konto kann im Moment nicht gelöscht werden.', $html);
        $this->assertStringContainsString('Bitte kontaktieren Sie uns.', $html);
    }

    #[RunInSeparateProcess]
    public function test_a_deleting_listener_refuses(): void
    {
        $this->orderSite('detach', ['notice_email' => 'owner@site.test']);
        $m = $this->makeMember();
        $this->order('o1', $m);
        $events = $this->flow->container()->get(MemberEvents::class);
        $events->on(MemberEvents::DELETING, static fn (array $payload): bool => false);
        $reasons = [];
        $events->on(MemberEvents::DELETION_REFUSED, static function (array $payload) use (&$reasons): void {
            $reasons[] = $payload['reason'];
        });

        $result = $this->flow->container()->get(Members::class)->delete($m);

        $this->assertInstanceOf(DeletionResult::class, $result);
        $this->assertFalse($result->deleted);
        $this->assertTrue($result->refused);
        $this->assertTrue($result->told, 'the notice went to the owner');
        $this->assertSame(['listener'], $reasons);
        $this->assertNotNull($this->flow->container()->get(Members::class)->find((string) $m->getId()));
        $this->assertSame((string) $m->getId(), (string) $this->findOrder('o1')?->get('member'), 'nothing detached');
    }

    #[RunInSeparateProcess]
    public function test_delete_result_of_a_plain_deletion(): void
    {
        $this->orderSite('detach');
        $m = $this->makeMember();

        $result = $this->flow->container()->get(Members::class)->delete($m);

        $this->assertTrue($result->deleted);
        $this->assertFalse($result->refused);
        $this->assertFalse($result->told);
    }

    #[RunInSeparateProcess]
    public function test_prune_follows_the_self_rules(): void
    {
        $this->orderSite('cascade');
        $stale = $this->makeMember('old@example.com', 'unconfirmed');
        $this->order('o1', $stale);

        [$code, $out] = $this->command(new PruneMembersCommand(fn () => $this->flow), ['--now=' . (new \DateTimeImmutable('+10 days'))->format(\DATE_ATOM)]);

        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('deleted 1 unconfirmed', $out);
        $this->assertNull($this->findOrder('o1'), 'the cascade went with it');
    }

    #[RunInSeparateProcess]
    public function test_prune_keeps_going_when_a_deleting_listener_throws(): void
    {
        $this->orderSite('detach');
        $first = $this->makeMember('first@example.com', 'unconfirmed');
        $second = $this->makeMember('second@example.com', 'unconfirmed');
        $this->flow->container()->get(MemberEvents::class)->on(MemberEvents::DELETING, static function (array $p) use ($first): bool {
            if ((string) $p['member']->getId() === (string) $first->getId()) {
                throw new \RuntimeException('project check broke');
            }

            return true;
        });

        [$code, $out] = $this->command(new PruneMembersCommand(fn () => $this->flow), ['--now=' . (new \DateTimeImmutable('+10 days'))->format(\DATE_ATOM)]);

        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('deleted 1 unconfirmed, 0 declined; kept 1 (refused by a self rule or a hook); pruned tokens, remember, throttle', $out);
        $this->assertNotNull($this->flow->container()->get(Members::class)->find((string) $first->getId()));
        $this->assertNull($this->flow->container()->get(Members::class)->find((string) $second->getId()));
        $this->assertStringContainsString('members:prune: deleting member ' . $first->getId() . ' failed (RuntimeException)', (string) file_get_contents($this->dir . '/error.log'));
    }

    #[RunInSeparateProcess]
    public function test_prune_keeps_a_restricted_member_without_telling_anyone(): void
    {
        $this->orderSite('restrict', ['notice_email' => 'owner@site.test']);
        $stale = $this->makeMember('old@example.com', 'unconfirmed');
        $this->order('o1', $stale);

        [$code, $out] = $this->command(new PruneMembersCommand(fn () => $this->flow), ['--now=' . (new \DateTimeImmutable('+10 days'))->format(\DATE_ATOM)]);

        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('deleted 0 unconfirmed, 0 declined', $out);
        $this->assertStringContainsString('kept 1 (refused by a self rule or a hook)', $out);
        $this->assertNotNull($this->flow->container()->get(Members::class)->find((string) $stale->getId()));
        $this->assertSame([], $this->mails(), 'a nightly run sends no notice');
    }
}
