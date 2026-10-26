<?php

declare(strict_types=1);

namespace Preflow\Members\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Auth\PasswordHasherInterface;
use Preflow\Members\Admission\AcceptOnConfirm;
use Preflow\Members\Admission\Admission;
use Preflow\Members\Admission\Decision;
use Preflow\Members\Admission\OwnerApproval;
use Preflow\Members\MemberState;
use Preflow\Members\Members;
use Preflow\Members\Tests\Support\MembersScaffold;
use Preflow\Members\Viewer;
use Preflow\Auth\AuthManager;
use Preflow\Auth\Record\RecordUser;
use Preflow\Members\MemberRoles;

/** The members domain service on a migrated SQLite site. */
final class MembersServiceTest extends TestCase
{
    use MembersScaffold;

    protected function setUp(): void
    {
        $this->scaffold();
        $this->migrate();
    }

    protected function tearDown(): void
    {
        $this->removeScaffold();
    }

    public function test_register_normalises_email_and_sets_defaults(): void
    {
        $members = $this->members();
        $m = $members->register(' Ada@Example.COM ', 'Ada', 'correct horse battery', 'en', []);

        $stored = $members->find((string) $m->getId());
        $this->assertNotNull($stored);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', (string) $stored->getId());
        $this->assertSame('ada@example.com', $stored->get('email'));
        $this->assertSame('Ada', $stored->get('name'));
        $this->assertSame([], MemberRoles::of($stored));
        $this->assertNotSame('', (string) $stored->get('registered_at'));
        $this->assertSame('', (string) $stored->get('email_confirmed_at'));
        $this->assertSame('', (string) $stored->get('decided_at'));
        $this->assertSame('en', $stored->get('language'));
        $this->assertTrue($this->container()->get(PasswordHasherInterface::class)->verify('correct horse battery', (string) $stored->get('password_hash')));
    }

    public function test_register_stores_extra_values_of_an_extended_type(): void
    {
        $this->write('config/models/member.json', (string) json_encode([
            'key' => 'member', 'extends' => true,
            'fields' => ['company' => ['type' => 'string'], 'screening_answer' => ['type' => 'text', 'readonly' => true]],
        ]));
        $this->flow = null;
        $this->migrate();

        $members = $this->members();
        $m = $members->register('ada@example.com', 'Ada', 'correct horse battery', 'de', ['company' => 'Holz AG', 'screening_answer' => 'I build things']);

        $stored = $members->find((string) $m->getId());
        $this->assertSame('Holz AG', $stored?->get('company'));
        $this->assertSame('I build things', $stored?->get('screening_answer'));
    }

    public function test_register_refuses_an_extra_key_the_type_does_not_have(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('"company" is not a field of the member type');

        $this->members()->register('ada@example.com', 'Ada', 'correct horse battery', 'en', ['company' => 'Holz AG']);
    }

    public function test_register_refuses_an_extra_key_it_sets_itself(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('"roles" is set by Members');

        $this->members()->register('ada@example.com', 'Ada', 'correct horse battery', 'en', ['roles' => ['member']]);
    }

    public function test_find_by_email_ignores_case(): void
    {
        $members = $this->members();
        $m = $members->register('ada@example.com', 'Ada', 'correct horse battery', 'en', []);

        $this->assertSame($m->getId(), $members->findByEmail('  ADA@Example.com')?->getId());
        $this->assertNull($members->findByEmail('nobody@example.com'));
    }

    public function test_email_taken_excludes_the_member_itself(): void
    {
        $members = $this->members();
        $ada = $members->register('ada@example.com', 'Ada', 'correct horse battery', 'en', []);

        $this->assertFalse($members->emailTaken('ADA@example.com', (string) $ada->getId()));
        $this->assertTrue($members->emailTaken(' ada@EXAMPLE.com', 'someone-else'));
        $this->assertFalse($members->emailTaken('eve@example.com', 'someone-else'));
    }

    public function test_state_from_record(): void
    {
        $members = $this->members();
        $m = $members->register('ada@example.com', 'Ada', 'correct horse battery', 'en', []);
        $this->assertSame(MemberState::Unconfirmed, MemberState::fromRecord($m));

        $members->confirm($m);
        $this->assertSame(MemberState::Waiting, MemberState::fromRecord($m));

        $members->decide($m, 'member');
        $this->assertSame(MemberState::Member, MemberState::fromRecord($m));

        $members->decide($m, 'rejected');
        $this->assertSame(MemberState::Declined, MemberState::fromRecord($m), 'rejected beats confirmed');
        $this->assertSame('declined', MemberState::Declined->label());
        $this->assertSame('waiting', MemberState::Waiting->label());
    }

    public function test_decide_sets_roles_and_decided_at(): void
    {
        $members = $this->members();
        $m = $members->register('ada@example.com', 'Ada', 'correct horse battery', 'en', []);

        $members->decide($m, 'pending');
        $fresh = $members->find((string) $m->getId());
        $this->assertSame(['pending'], MemberRoles::of($fresh));
        $this->assertSame('', (string) $fresh->get('decided_at'));

        $members->decide($m, 'member');
        $fresh = $members->find((string) $m->getId());
        $this->assertSame(['member'], MemberRoles::of($fresh));
        $this->assertNotSame('', (string) $fresh->get('decided_at'));

        $members->setPassword($m, 'another long password');
        $this->assertTrue($this->container()->get(PasswordHasherInterface::class)
            ->verify('another long password', (string) $members->find((string) $m->getId())->get('password_hash')));

        $members->delete($m);
        $this->assertNull($members->find((string) $m->getId()));
    }

    public function test_pending_count(): void
    {
        $members = $this->members();
        $unconfirmed = $members->register('a@example.com', 'A', 'correct horse battery', 'en', []);
        $waiting = $members->register('b@example.com', 'B', 'correct horse battery', 'en', []);
        $accepted = $members->register('c@example.com', 'C', 'correct horse battery', 'en', []);
        $members->confirm($waiting);
        $members->confirm($accepted);
        $members->decide($accepted, 'member');

        $this->assertSame(1, $members->pendingCount());
        $this->assertSame(MemberState::Unconfirmed, MemberState::fromRecord($unconfirmed));
    }

    public function test_clock_is_injectable_and_stamps_are_utc(): void
    {
        $c = $this->container();
        $members = new Members(
            $c->get(\Preflow\Data\DataManager::class),
            $c->get(PasswordHasherInterface::class),
            static fn (): \DateTimeImmutable => new \DateTimeImmutable('2026-10-23 12:00', new \DateTimeZone('Europe/Zurich')),
        );

        $m = $members->register('ada@example.com', 'Ada', 'correct horse battery', 'en', []);

        $this->assertSame('2026-10-23T10:00:00+00:00', $m->get('registered_at'));
    }

    public function test_shipped_admissions(): void
    {
        $m = $this->members()->register('ada@example.com', 'Ada', 'correct horse battery', 'en', []);
        $request = (new \Nyholm\Psr7\Factory\Psr17Factory())->createServerRequest('POST', '/members/register');

        foreach ([[new AcceptOnConfirm(), Decision::Accept], [new OwnerApproval(), Decision::Pending]] as [$admission, $decision]) {
            $this->assertSame([], $admission->validate(['email' => 'ada@example.com'], $request, []));
            $this->assertSame([], $admission->recordValues(['email' => 'ada@example.com', 'company' => 'x']));
            $this->assertSame($decision, $admission->afterConfirm($m, $request));
            $this->assertSame([], $admission->details($m, Admission::PANEL));
            $this->assertSame([], $admission->details($m, Admission::NOTICE));
        }
    }

    public function test_viewer_reads_the_members_guard(): void
    {
        $c = $this->container();
        $viewer = $c->get(Viewer::class);
        $this->assertNull($viewer->member());
        $this->assertFalse($viewer->isStaff());
        $this->assertSame('', $viewer->name());
        $this->assertSame('en', $viewer->language('en'));

        $m = $this->members()->register('ada@example.com', 'Ada', 'correct horse battery', 'de', []);
        $c->get(AuthManager::class)->addUser(new RecordUser($m), 'members');

        $this->assertSame($m->getId(), $viewer->member()?->getId());
        $this->assertSame('Ada', $viewer->name());
        $this->assertSame('de', $viewer->language('en'));
        $this->assertFalse($viewer->isStaff());

        $c->get(AuthManager::class)->addUser(new \Preflow\Auth\ArrayUser('admin', 'admin@site.test', '', ['admin']), 'session');
        $this->assertTrue($viewer->isStaff(), 'the default guard is staff');
    }

    private function members(): Members
    {
        return $this->container()->get(Members::class);
    }
}
