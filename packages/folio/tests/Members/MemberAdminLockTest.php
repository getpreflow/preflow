<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Members;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Auth\Remember\RememberMe;
use Preflow\Data\DynamicRecord;
use Preflow\Folio\Extension\Customization\Customizations;
use Preflow\Folio\Tests\Members\Support\MembersScaffold;
use Preflow\I18n\Translator;
use Preflow\Members\Members;
use Preflow\Members\Tests\Support\Dom;
use Preflow\Notify\Notifier;
use Preflow\Notify\TransportFactory;
use Preflow\Rbac\RoleList;
use Psr\Http\Message\ResponseInterface;

/** Lock, unlock and resend in the members admin: the Locked badge, the three actions, the panel lines. */
final class MemberAdminLockTest extends TestCase
{
    use MembersScaffold;

    protected function setUp(): void
    {
        $this->scaffold();
        $this->migrateAndApply();
        $this->flow = $this->app();
    }

    protected function tearDown(): void
    {
        $this->removeScaffold();
    }

    #[RunInSeparateProcess]
    public function test_state_column_shows_locked_for_a_locked_member(): void
    {
        $locked = $this->makeMember('locked@example.com', 'rejected');
        $this->makeMember('free@example.com', 'member');
        $this->flow->container()->get(Members::class)->lock($this->makeMember('l2@example.com', 'member'));
        $this->flow->container()->get(Members::class)->lock($locked);
        $this->signIn();

        $states = $this->stateColumn();

        $this->assertSame('Locked', $states['l2@example.com']);
        $this->assertSame('Locked', $states['locked@example.com'], 'never the declined label');
        $this->assertSame('Member', $states['free@example.com']);
    }

    #[RunInSeparateProcess]
    public function test_lock_action_locks_and_ends_sessions(): void
    {
        $m = $this->makeMember('ada@example.com', 'member');
        $cookie = $this->flow->container()->get(RememberMe::class)->issue('members', (string) $m->getId());
        $this->signIn();

        $response = $this->act($m, 'lock');

        $this->assertSame(302, $response->getStatusCode());
        $fresh = $this->reload($m);
        $this->assertNotSame('', (string) $fresh->get('locked_at'));
        $this->assertSame('1', (string) $fresh->get('auth_epoch'));
        $this->assertNull($this->flow->container()->get(RememberMe::class)->resolve('members', $cookie));
        $this->assertStringContainsString('Member locked', $this->flash($m));
    }

    #[RunInSeparateProcess]
    public function test_unlock_action_unlocks(): void
    {
        $m = $this->makeMember('ada@example.com', 'member');
        $this->flow->container()->get(Members::class)->lock($m);
        $this->signIn();

        $this->act($m, 'unlock');

        $this->assertSame('', (string) $this->reload($m)->get('locked_at'));
        $this->assertStringContainsString('Member unlocked', $this->flash($m));
    }

    #[RunInSeparateProcess]
    public function test_resend_action_mails_an_unconfirmed_member(): void
    {
        $m = $this->makeMember('ada@example.com', 'unconfirmed');
        $sent = count($this->mails());
        $this->signIn();

        $this->act($m, 'resend_confirmation');

        $this->assertCount($sent + 1, $this->mails());
        $this->assertSame(['ada@example.com'], (array) $this->mails()[$sent]->to);
        $this->assertStringContainsString('Confirmation mail sent', $this->flash($m));
    }

    #[RunInSeparateProcess]
    public function test_resend_action_refuses_a_confirmed_member(): void
    {
        $m = $this->makeMember('ada@example.com', 'member');
        $this->signIn();

        $this->act($m, 'resend_confirmation');

        $this->assertSame([], $this->mails());
        $this->assertStringContainsString('only an unconfirmed member', $this->flash($m));
    }

    #[RunInSeparateProcess]
    public function test_resend_action_reports_a_transport_failure(): void
    {
        $m = $this->makeMember('ada@example.com', 'unconfirmed');
        $c = $this->flow->container();
        $c->instance(Notifier::class, new Notifier($c->get(TransportFactory::class), []));
        $this->signIn();

        $response = $this->act($m, 'resend_confirmation');

        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringContainsString('could not be sent', $this->flash($m));
    }

    #[RunInSeparateProcess]
    public function test_lock_and_unlock_refuse_where_they_do_not_apply(): void
    {
        $a = $this->makeMember('a@example.com', 'member');
        $b = $this->makeMember('b@example.com', 'member');
        $this->flow->container()->get(Members::class)->lock($b);
        $lockedAt = (string) $this->reload($b)->get('locked_at');
        $this->signIn();

        $this->act($a, 'unlock');
        $this->assertSame('', (string) $this->reload($a)->get('locked_at'));
        $this->act($b, 'lock');
        $this->assertSame($lockedAt, (string) $this->reload($b)->get('locked_at'));
        $this->assertSame('1', (string) $this->reload($b)->get('auth_epoch'), 'no second session end');
    }

    #[RunInSeparateProcess]
    public function test_actions_need_members_decide(): void
    {
        $this->signIn();
        $custom = $this->flow->container()->get(Customizations::class);
        foreach (['lock', 'unlock', 'resend_confirmation'] as $name) {
            $this->assertSame('members.decide', $custom->action('member', $name)['permission'] ?? null, $name);
        }
        // A staff user without it gets 403: the controller checks the action's permission.
        $c = $this->flow->container();
        $c->get(\Preflow\Rbac\RoleStore::class)->ensure('staff', 'editor', 'Editor', ['member.edit', 'member.view']);
        $resolver = new \Preflow\Rbac\RbacResolver($c->get(\Preflow\Rbac\RoleStore::class), $c->get(\Preflow\Folio\Extension\ExtensionState::class));
        $this->assertFalse($resolver->allows(new \Preflow\Auth\ArrayUser('e', 'e@site.test', '', ['editor']), 'staff', 'members.decide', null));
    }

    #[RunInSeparateProcess]
    public function test_panel_offers_the_buttons_that_apply(): void
    {
        $this->signIn();
        $free = $this->makeMember('free@example.com', 'member');
        $locked = $this->makeMember('locked@example.com', 'member');
        $this->flow->container()->get(Members::class)->lock($locked);
        $unconfirmed = $this->makeMember('u@example.com', 'unconfirmed');

        $has = fn (DynamicRecord $m, string $a): bool => Dom::first($this->admin('/folio/member/' . $m->getId() . '/edit'), '//form[contains(@action, "/_action/' . $a . '")]') !== null;

        $this->assertSame([true, false, false], [$has($free, 'lock'), $has($free, 'unlock'), $has($free, 'resend_confirmation')]);
        $this->assertSame([false, true, false], [$has($locked, 'lock'), $has($locked, 'unlock'), $has($locked, 'resend_confirmation')]);
        $this->assertSame([true, false, true], [$has($unconfirmed, 'lock'), $has($unconfirmed, 'unlock'), $has($unconfirmed, 'resend_confirmation')]);
    }

    #[RunInSeparateProcess]
    public function test_panel_shows_locked_since_and_last_sign_in(): void
    {
        $m = $this->makeMember('ada@example.com', 'member');
        $members = $this->flow->container()->get(Members::class);
        $this->signIn();
        $plain = $this->admin('/folio/member/' . $m->getId() . '/edit');
        $this->assertStringNotContainsString('Locked since', $plain);
        $this->assertStringNotContainsString('Last sign-in', preg_replace('~<label.*?</label>~s', '', $plain));

        $members->lock($m);
        $members->noteSignIn($this->reload($m));
        $fresh = $this->reload($m);
        $fmt = static fn (string $v): string => (new \DateTimeImmutable($v))->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('M j, Y H:i');
        $html = html_entity_decode($this->admin('/folio/member/' . $m->getId() . '/edit'), ENT_QUOTES);

        $this->assertStringContainsString('Locked since ' . $fmt((string) $fresh->get('locked_at')), $html);
        $this->assertStringContainsString('Last sign-in ' . $fmt((string) $fresh->get('last_login_at')), $html);
    }

    #[RunInSeparateProcess]
    public function test_saving_the_form_keeps_locked_at_and_last_login_at(): void
    {
        $m = $this->makeMember('ada@example.com', 'member');
        $members = $this->flow->container()->get(Members::class);
        $members->lock($m);
        $members->noteSignIn($this->reload($m));
        $before = [(string) $this->reload($m)->get('locked_at'), (string) $this->reload($m)->get('last_login_at')];
        $this->signIn();

        $this->postFrom('/folio/member/' . $m->getId() . '/edit', '/folio/member/' . $m->getId(), ['email' => 'ada@example.com', 'name' => 'Ada L']);

        $this->assertSame($before, [(string) $this->reload($m)->get('locked_at'), (string) $this->reload($m)->get('last_login_at')]);
    }

    public function test_the_new_keys_exist_in_every_language(): void
    {
        $translator = new Translator('/no/such/lang', 'en', 'en');
        $translator->addDefaults('members-admin', dirname(__DIR__, 3) . '/members/lang');
        $keys = ['states.locked', 'lock.lock', 'lock.unlock', 'lock.resend', 'lock.locked_since', 'lock.last_login', 'lock.done_lock', 'lock.done_unlock', 'lock.done_resend', 'lock.resend_failed', 'lock.cant_resend'];
        foreach (['en', 'de', 'fr'] as $locale) {
            $entries = $translator->entries('members-admin', $locale);
            foreach ($keys as $key) {
                $this->assertArrayHasKey('members-admin.' . $key, $entries, $locale . ' ' . $key);
            }
        }
    }

    // --- helpers -------------------------------------------------------------------------------

    /** @return array<string, string> e-mail => state label */
    private function stateColumn(): array
    {
        $html = $this->admin('/folio/member');
        $headings = array_map(static fn (\DOMElement $th): string => trim($th->textContent), Dom::all($html, '//table//thead//th[not(contains(@class, "folio-bulk-cell"))]'));
        $col = array_search('State', $headings, true);
        $this->assertNotFalse($col);
        $states = [];
        foreach (Dom::all($html, '//table//tbody//tr') as $row) {
            $cells = array_values(array_filter(iterator_to_array($row->getElementsByTagName('td')), static fn (\DOMElement $td): bool => !str_contains($td->getAttribute('class'), 'folio-bulk-cell')));
            $states[trim(($cells[0] ?? null)?->textContent ?? '')] = trim(($cells[(int) $col] ?? null)?->textContent ?? '');
        }

        return $states;
    }

    private function flash(DynamicRecord $m): string
    {
        return html_entity_decode($this->admin('/folio/member/' . $m->getId() . '/edit'), ENT_QUOTES);
    }

    private function act(DynamicRecord $m, string $name): ResponseInterface
    {
        return $this->postFrom('/folio/member/' . $m->getId() . '/edit', '/folio/member/' . $m->getId() . '/_action/' . $name, []);
    }

    private function makeMember(string $email, string $state): DynamicRecord
    {
        $members = $this->flow->container()->get(Members::class);
        $m = $members->register($email, 'Ada Lovelace', 'correct horse battery', 'en', []);
        if ($state !== 'unconfirmed') {
            $members->confirm($m);
            $members->decide($m, $state);
        }

        return $m;
    }

    private function reload(DynamicRecord $m): DynamicRecord
    {
        return $this->flow->container()->get(Members::class)->find((string) $m->getId()) ?? $this->fail('gone');
    }

    /** @return list<\Preflow\Notify\Email> */
    private function mails(): array
    {
        $sent = $this->flow->container()->get(TransportFactory::class)->get('mail')->sent();

        return array_values(array_map(static fn ($s) => $s->message, $sent));
    }
}
