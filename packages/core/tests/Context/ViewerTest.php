<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\Context;

use PHPUnit\Framework\TestCase;
use Preflow\Core\Context\RoleView;
use Preflow\Core\Context\SyntheticIdentity;
use Preflow\Core\Context\Viewer;
use Preflow\Core\Identity;

final class ViewerTest extends TestCase
{
    public function test_synthetic_identity_id_is_reserved_and_stable(): void
    {
        $view = new RoleView('staff', ['editor', 'admin', 'editor']);

        $this->assertSame(['admin', 'editor'], $view->roles, 'sorted, each role once');
        $this->assertInstanceOf(Identity::class, $view->identity());
        $this->assertInstanceOf(SyntheticIdentity::class, $view->identity());
        $this->assertSame('preflow:synthetic:staff:admin,editor', $view->identity()->getAuthId());
        $this->assertSame(SyntheticIdentity::PREFIX, 'preflow:synthetic:');
        $this->assertSame(
            $view->identity()->getAuthId(),
            (new RoleView('staff', ['admin', 'editor']))->identity()->getAuthId(),
            'the same roles in another order give the same id',
        );
    }

    public function test_role_view_refuses_names_that_would_make_the_id_ambiguous(): void
    {
        foreach ([['', ['a']], ['st:aff', ['a']], ['staff', ['a,b']], ['staff', ['']]] as [$population, $roles]) {
            try {
                new RoleView($population, $roles);
                $this->fail('accepted ' . $population . ' / ' . implode('|', $roles));
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_viewer_key(): void
    {
        $this->assertSame('anon', Viewer::anonymous()->key());
        $this->assertTrue(Viewer::anonymous()->isAnonymous());
        $this->assertFalse(Viewer::anonymous()->isSynthetic());
        $this->assertNull(Viewer::anonymous()->primary());

        $user = self::identity('42');
        $viewer = new Viewer(['web' => $user], 'web');
        $this->assertSame('id:web:42', $viewer->key());
        $this->assertSame($user, $viewer->primary());
        $this->assertSame($user, $viewer->for('web'));
        $this->assertNull($viewer->for('members'));
        $this->assertFalse($viewer->isAnonymous());

        $member = self::identity('7');
        $both = new Viewer(['web' => $user, 'members' => $member], 'web');
        $this->assertSame($user, $both->primary());
        $this->assertSame($member, $both->for('members'));
        $this->assertNotSame($viewer->key(), $both->key(), 'a second identity changes the key');
        $this->assertSame(
            $both->key(),
            (new Viewer(['members' => $member, 'web' => $user], 'web'))->key(),
            'the order identities were given in does not matter',
        );

        $role = Viewer::synthetic(new RoleView('member', ['silver', 'gold']));
        $this->assertSame('role:member:gold,silver', $role->key());
        $this->assertTrue($role->isSynthetic());
        $this->assertFalse($role->isAnonymous());
        $this->assertSame('preflow:synthetic:member:gold,silver', $role->primary()?->getAuthId());
        $this->assertSame($role->primary(), $role->for('member'));
    }

    public function test_key_names_the_primary_guard_when_there_are_several(): void
    {
        $user = self::identity('42');
        $member = self::identity('7');
        $web = new Viewer(['web' => $user, 'members' => $member], 'web');
        $members = new Viewer(['web' => $user, 'members' => $member], 'members');

        $this->assertNotSame($web->key(), $members->key(), 'another primary guard, another key');
        $this->assertSame('id:members:7;id:web:42@web', $web->key());

        $given = new Viewer(['web' => $user, 'members' => $member]);
        $reversed = new Viewer(['members' => $member, 'web' => $user]);
        $this->assertSame($member, $given->primary(), 'no primary guard: the first guard by name');
        $this->assertSame($member, $reversed->primary());
        $this->assertSame($given->key(), $reversed->key(), 'the order given does not change primary(), nor the key');
        $this->assertSame($members->key(), $given->key(), 'the same primary, the same key');
    }

    public function test_a_synthetic_identity_needs_its_role_view(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Viewer(['member' => (new RoleView('member', ['gold']))->identity()], 'member');
    }

    public function test_a_reserved_id_needs_a_role_view(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Viewer(['web' => self::identity(SyntheticIdentity::PREFIX . 'member:gold')], 'web');
    }

    public function test_a_role_view_needs_its_synthetic_identity(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Viewer(['web' => self::identity('42')], 'web', new RoleView('member', ['gold']));
    }

    public function test_a_role_view_needs_the_identity_of_the_same_roles(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Viewer(['member' => (new RoleView('member', ['silver']))->identity()], 'member', new RoleView('member', ['gold']));
    }

    public function test_primary_guard_must_name_an_identity(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Viewer(['web' => self::identity('1')], 'members');
    }

    private static function identity(string $id): Identity
    {
        return new class ($id) implements Identity {
            public function __construct(private string $id) {}

            public function getAuthId(): string
            {
                return $this->id;
            }
        };
    }
}
