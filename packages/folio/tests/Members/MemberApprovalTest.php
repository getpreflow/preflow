<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Members;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Auth\Access\Access;
use Preflow\Auth\ArrayUser;
use Preflow\Auth\Remember\RememberMe;
use Preflow\Data\DynamicRecord;
use Preflow\Folio\Extension\ExtensionState;
use Preflow\Members\Members;
use Preflow\Folio\Tests\Members\Fixtures\ListedAdmission;
use Preflow\Folio\Tests\Members\Support\Dom;
use Preflow\Folio\Tests\Members\Support\MembersScaffold;
use Preflow\Notify\Email;
use Preflow\Notify\Notifier;
use Preflow\Notify\TransportFactory;
use Preflow\Rbac\RbacResolver;
use Preflow\Rbac\RoleList;
use Preflow\Rbac\RoleStore;
use Psr\Http\Message\ResponseInterface;

/** The owner's decision in Folio: waiting badge, state column, Decision panel, accept and decline. */
final class MemberApprovalTest extends TestCase
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
    public function test_sidebar_shows_waiting_badge(): void
    {
        $this->makeMember('a@example.com', 'pending');
        $this->makeMember('b@example.com', 'pending');
        $this->makeMember('c@example.com', 'member');
        $this->makeMember('d@example.com', 'unconfirmed');
        $this->signIn();

        $badge = Dom::first($this->admin('/folio'), '//nav[contains(@class, "folio-nav")]//a[contains(@href, "/folio/member")]//*[contains(@class, "folio-nav-badge")]');
        $this->assertSame('2', trim($badge?->textContent ?? ''));
    }

    #[RunInSeparateProcess]
    public function test_no_badge_without_waiting_members(): void
    {
        $this->makeMember('c@example.com', 'member');
        $this->signIn();

        $this->assertNull(Dom::first($this->admin('/folio'), '//*[contains(@class, "folio-nav-badge")]'));
    }

    #[RunInSeparateProcess]
    public function test_member_list_shows_state_column(): void
    {
        $this->makeMember('a@example.com', 'unconfirmed');
        $this->makeMember('b@example.com', 'pending');
        $this->makeMember('c@example.com', 'member');
        $this->makeMember('d@example.com', 'rejected');
        $this->signIn();
        $html = $this->admin('/folio/member');

        // The list's own columns (not the bulk tick column).
        $headings = array_map(static fn (\DOMElement $th): string => trim($th->textContent), Dom::all($html, '//table//thead//th[not(contains(@class, "folio-bulk-cell"))]'));
        $col = array_search('State', $headings, true);
        $this->assertNotFalse($col, 'a State column');
        $states = [];
        foreach (Dom::all($html, '//table//tbody//tr') as $row) {
            $cells = array_values(array_filter(iterator_to_array($row->getElementsByTagName('td')), static fn (\DOMElement $td): bool => !str_contains($td->getAttribute('class'), 'folio-bulk-cell')));
            $states[trim(($cells[0] ?? null)?->textContent ?? '')] = trim(($cells[(int) $col] ?? null)?->textContent ?? '');
        }
        // Rows without a list sort end in the record id (random), so compare by e-mail, not by row order.
        ksort($states);
        $this->assertSame(['a@example.com' => 'Unconfirmed', 'b@example.com' => 'Waiting', 'c@example.com' => 'Member', 'd@example.com' => 'Declined'], $states);
    }

    #[RunInSeparateProcess]
    public function test_decision_panel_prefilled_in_member_language(): void
    {
        $m = $this->makeMember('ada@example.com', 'pending', 'de');
        $this->signIn();
        $html = $this->admin('/folio/member/' . $m->getId() . '/edit');

        $accept = Dom::first($html, '//form[contains(@action, "/_action/accept")]//textarea[@name="message"]')?->textContent ?? '';
        $this->assertStringContainsString("Guten Tag Ada Lovelace\n\nIhr Konto ist bereit.", $accept);
        $this->assertStringContainsString('https://site.test/de/members/login', $accept);
        $this->assertStringNotContainsString('{name}', $accept);
        $decline = Dom::first($html, '//form[contains(@action, "/_action/decline")]//textarea[@name="message"]')?->textContent ?? '';
        $this->assertStringContainsString('Guten Tag Ada Lovelace', $decline);
        $this->assertStringContainsString('Accept and send', $html);
        $this->assertStringContainsString('Decline and send', $html);
        $this->assertStringContainsString('Sent in German.', $html);
    }

    #[RunInSeparateProcess]
    public function test_panel_falls_back_to_the_default_locale(): void
    {
        $m = $this->makeMember('ada@example.com', 'pending', 'fr'); // not a locale of this site
        $this->signIn();
        $html = $this->admin('/folio/member/' . $m->getId() . '/edit');

        $accept = Dom::first($html, '//form[contains(@action, "/_action/accept")]//textarea[@name="message"]')?->textContent ?? '';
        $this->assertStringContainsString("Hi Ada Lovelace,\n\nyour account is ready.", $accept);
        $this->assertStringContainsString('https://site.test/members/login', $accept);
        $this->assertStringContainsString('Sent in English.', $html);
    }

    #[RunInSeparateProcess]
    public function test_panel_defaults_come_from_the_lang_files_a_site_overrides(): void
    {
        $this->write('lang/de/members.php', "<?php\nreturn ['mail' => ['accepted' => ['body' => 'Servus {name}, hier: {login_url}']]];\n");
        $this->flow = $this->app();
        $m = $this->makeMember('ada@example.com', 'pending', 'de');
        $this->signIn();
        $html = $this->admin('/folio/member/' . $m->getId() . '/edit');

        $accept = Dom::first($html, '//form[contains(@action, "/_action/accept")]//textarea[@name="message"]')?->textContent ?? '';
        $this->assertSame('Servus Ada Lovelace, hier: https://site.test/de/members/login', $accept);
        $decline = Dom::first($html, '//form[contains(@action, "/_action/decline")]//textarea[@name="message"]')?->textContent ?? '';
        $this->assertStringContainsString("Guten Tag Ada Lovelace\n\nVielen Dank für Ihr Interesse.", $decline, 'the package copy for keys the site leaves alone');
    }

    #[RunInSeparateProcess]
    public function test_a_stored_message_wins_over_the_lang_default(): void
    {
        $m = $this->makeMember('ada@example.com', 'pending', 'de');
        $this->signIn();
        $saved = $this->postFrom('/folio/members_settings?locale=de', '/folio/members_settings/members_settings?locale=de', [
            'registration_open' => '1', 'accept_message' => 'Gespeichert für {name}',
        ]);
        $this->assertSame(302, $saved->getStatusCode());
        $html = $this->admin('/folio/member/' . $m->getId() . '/edit');

        $accept = Dom::first($html, '//form[contains(@action, "/_action/accept")]//textarea[@name="message"]')?->textContent ?? '';
        $this->assertSame('Gespeichert für Ada Lovelace', $accept);
    }

    #[RunInSeparateProcess]
    public function test_a_message_stored_only_in_another_locale_gives_way_to_the_lang_default(): void
    {
        $this->removeScaffold();
        $this->scaffold(locales: ['en', 'de', 'fr']);
        $this->migrateAndApply();
        $this->flow = $this->app();
        $m = $this->makeMember('ada@example.com', 'pending', 'fr');
        $this->signIn();
        $saved = $this->postFrom('/folio/members_settings?locale=en', '/folio/members_settings/members_settings?locale=en', [
            'registration_open' => '1', 'accept_message' => 'Stored in English for {name}',
        ]);
        $this->assertSame(302, $saved->getStatusCode());
        $html = $this->admin('/folio/member/' . $m->getId() . '/edit');

        $accept = Dom::first($html, '//form[contains(@action, "/_action/accept")]//textarea[@name="message"]')?->textContent ?? '';
        $this->assertStringStartsWith("Bonjour Ada Lovelace,\n\nVotre compte est prêt.", $accept);
        $this->assertStringNotContainsString('Stored in English', $accept);
    }

    #[RunInSeparateProcess]
    public function test_panel_prefill_in_french(): void
    {
        $this->removeScaffold();
        $this->scaffold(locales: ['en', 'de', 'fr']);
        $this->migrateAndApply();
        $this->flow = $this->app();
        $m = $this->makeMember('ada@example.com', 'pending', 'fr');
        $this->signIn();
        $html = $this->admin('/folio/member/' . $m->getId() . '/edit');

        $accept = Dom::first($html, '//form[contains(@action, "/_action/accept")]//textarea[@name="message"]')?->textContent ?? '';
        $this->assertStringStartsWith('Bonjour Ada Lovelace,', $accept);
        $this->assertStringContainsString('https://site.test/fr/members/login', $accept);
        $this->assertStringContainsString('Sent in French.', $html);
    }

    #[RunInSeparateProcess]
    public function test_panel_offers_what_fits_the_state(): void
    {
        $this->signIn();
        $expect = ['unconfirmed' => [false, false], 'pending' => [true, true], 'member' => [false, true], 'rejected' => [true, false]];
        foreach ($expect as $state => [$accept, $decline]) {
            $m = $this->makeMember($state . '@example.com', $state);
            $html = $this->admin('/folio/member/' . $m->getId() . '/edit');
            $this->assertSame($accept, str_contains($html, '/_action/accept"') && str_contains($html, 'Accept and send'), $state . ' accept');
            $this->assertSame($decline, str_contains($html, 'Decline and send'), $state . ' decline');
        }
        $m = $this->makeMember('u@example.com', 'unconfirmed');
        $this->assertStringContainsString('Not confirmed yet — nothing to decide.', $this->admin('/folio/member/' . $m->getId() . '/edit'));
    }

    #[RunInSeparateProcess]
    public function test_panel_shows_the_admission_details(): void
    {
        $this->write('config/members.php', "<?php\nreturn ['admission' => " . ListedAdmission::class . "::class];\n");
        $this->flow = $this->app();
        $m = $this->makeMember('ada@example.com', 'pending');
        $this->signIn();
        $shown = (new \DateTimeImmutable((string) $this->reload($m)->get('registered_at')))->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('M j, Y H:i');

        $html = html_entity_decode($this->admin('/folio/member/' . $m->getId() . '/edit'), ENT_QUOTES);

        $this->assertStringContainsString('Registered ' . $shown . ' · book code: no · email: ada@example.com', $html);
    }

    #[RunInSeparateProcess]
    public function test_panel_without_details_shows_the_registration_only(): void
    {
        $m = $this->makeMember('ada@example.com', 'pending');
        $this->signIn();

        $help = Dom::text($this->admin('/folio/member/' . $m->getId() . '/edit'), '//p[contains(@class, "folio-help") and starts-with(normalize-space(), "Registered")]');

        $this->assertStringNotContainsString('·', $help);
        $this->assertStringNotContainsString('book code', $help);
    }

    #[RunInSeparateProcess]
    public function test_accept_sends_mail_with_message_and_sets_member(): void
    {
        $m = $this->makeMember('ada@example.com', 'pending');
        $this->signIn();

        $response = $this->action($m, 'accept', "Hi Ada,\n\nwelcome aboard.\n\nSteffen");

        $this->assertSame(302, $response->getStatusCode());
        $fresh = $this->reload($m);
        $this->assertSame(['member'], RoleList::normalize($fresh->get('roles')));
        $this->assertNotSame('', (string) $fresh->get('decided_at'));
        $mails = $this->mails();
        $this->assertCount(1, $mails);
        $this->assertSame(['ada@example.com'], (array) $mails[0]->to);
        $this->assertSame("Hi Ada,\n\nwelcome aboard.\n\nSteffen", $mails[0]->text);
        $this->assertStringContainsString('Accept: done', $this->admin('/folio/member/' . $m->getId() . '/edit'));
    }

    #[RunInSeparateProcess]
    public function test_accept_a_declined_member(): void
    {
        $m = $this->makeMember('ada@example.com', 'rejected');
        $this->signIn();

        $this->action($m, 'accept', 'Changed my mind, welcome.');

        $this->assertSame(['member'], RoleList::normalize($this->reload($m)->get('roles')));
        $this->assertSame('Changed my mind, welcome.', $this->mails()[0]->text);
    }

    #[RunInSeparateProcess]
    public function test_decline_sets_rejected_and_revokes_remember(): void
    {
        $m = $this->makeMember('ada@example.com', 'pending');
        $cookie = $this->flow->container()->get(RememberMe::class)->issue('members', (string) $m->getId());
        $this->signIn();

        $this->action($m, 'decline', 'Sorry, not now.');

        $this->assertSame(['rejected'], RoleList::normalize($this->reload($m)->get('roles')));
        $this->assertNull($this->flow->container()->get(RememberMe::class)->resolve('members', $cookie));
        $this->assertSame('Sorry, not now.', $this->mails()[0]->text);
    }

    #[RunInSeparateProcess]
    public function test_decline_an_accepted_member(): void
    {
        $m = $this->makeMember('ada@example.com', 'member');
        $this->signIn();

        $this->action($m, 'decline', 'Your access ends.');

        $this->assertSame(['rejected'], RoleList::normalize($this->reload($m)->get('roles')));
        $this->assertCount(1, $this->mails());
    }

    /** @return iterable<string, array{0: string, 1: string, 2: string}> state, action, flash */
    public static function refusedDecisions(): iterable
    {
        yield 'accept a member' => ['member', 'accept', 'Member: can’t accept — nothing was changed.'];
        yield 'decline a declined member' => ['rejected', 'decline', 'Declined: can’t decline — nothing was changed.'];
        yield 'accept an unconfirmed member' => ['unconfirmed', 'accept', 'Not confirmed yet — nothing to decide.'];
        yield 'decline an unconfirmed member' => ['unconfirmed', 'decline', 'Not confirmed yet — nothing to decide.'];
    }

    /** A crafted POST the panel never offers: refused on the server, no mail, nothing changed. */
    #[RunInSeparateProcess]
    #[DataProvider('refusedDecisions')]
    public function test_decision_state_matrix_is_enforced_on_the_server(string $state, string $action, string $flash): void
    {
        $m = $this->makeMember('ada@example.com', $state);
        $cookie = $this->flow->container()->get(RememberMe::class)->issue('members', (string) $m->getId());
        $before = $this->reload($m);
        $this->signIn();

        $response = $this->action($m, $action, 'A crafted message.');

        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringEndsWith('/folio/member/' . $m->getId() . '/edit', $response->getHeaderLine('Location'));
        $after = $this->reload($m);
        $this->assertSame(RoleList::normalize($before->get('roles')), RoleList::normalize($after->get('roles')));
        $this->assertSame((string) $before->get('decided_at'), (string) $after->get('decided_at'));
        $this->assertSame([], $this->mails());
        $this->assertNotNull($this->flow->container()->get(RememberMe::class)->resolve('members', $cookie), 'sessions untouched');
        $this->assertStringContainsString($flash, html_entity_decode($this->admin('/folio/member/' . $m->getId() . '/edit'), ENT_QUOTES));
    }

    #[RunInSeparateProcess]
    public function test_empty_message_changes_nothing(): void
    {
        $m = $this->makeMember('ada@example.com', 'pending');
        $this->signIn();

        $this->action($m, 'accept', "  \n ");

        $this->assertSame(['pending'], RoleList::normalize($this->reload($m)->get('roles')));
        $this->assertSame([], $this->mails());
        $this->assertStringContainsString('The message is empty.', $this->admin('/folio/member/' . $m->getId() . '/edit'));
    }

    #[RunInSeparateProcess]
    public function test_mail_failure_changes_nothing(): void
    {
        $m = $this->makeMember('ada@example.com', 'pending');
        $c = $this->flow->container();
        // No channel configured: the Notifier throws a TransportException, as a broken transport would.
        $c->instance(Notifier::class, new Notifier($c->get(TransportFactory::class), []));
        $this->signIn();

        $this->action($m, 'accept', 'Welcome.');

        $this->assertSame(['pending'], RoleList::normalize($this->reload($m)->get('roles')));
        $this->assertSame('', (string) $this->reload($m)->get('decided_at'));
        $this->assertStringContainsString('The mail could not be sent — nothing was changed.', html_entity_decode($this->admin('/folio/member/' . $m->getId() . '/edit'), ENT_QUOTES));
    }

    #[RunInSeparateProcess]
    public function test_decline_mail_failure_changes_nothing(): void
    {
        $m = $this->makeMember('ada@example.com', 'member');
        $c = $this->flow->container();
        $cookie = $c->get(RememberMe::class)->issue('members', (string) $m->getId());
        $c->instance(Notifier::class, new Notifier($c->get(TransportFactory::class), []));
        $this->signIn();
        $decided = (string) $this->reload($m)->get('decided_at');

        $this->action($m, 'decline', 'Sorry.');

        $this->assertSame(['member'], RoleList::normalize($this->reload($m)->get('roles')));
        $this->assertSame($decided, (string) $this->reload($m)->get('decided_at'));
        $this->assertNotNull($c->get(RememberMe::class)->resolve('members', $cookie), 'remember-me left alone');
        $this->assertStringContainsString('The mail could not be sent — nothing was changed.', html_entity_decode($this->admin('/folio/member/' . $m->getId() . '/edit'), ENT_QUOTES));
    }

    #[RunInSeparateProcess]
    public function test_actions_are_panel_only_and_need_members_decide(): void
    {
        $m = $this->makeMember('ada@example.com', 'pending');
        $this->signIn();
        $html = $this->admin('/folio/member/' . $m->getId() . '/edit');

        $this->assertNull(Dom::first($html, '//button[normalize-space()="Accept" and not(@data-folio-tab)]'), 'no toolbar button');
        $this->assertNull(Dom::first($html, '//*[@data-confirm]'));
        $custom = $this->flow->container()->get(\Preflow\Folio\Extension\Customization\Customizations::class);
        foreach (['accept', 'decline'] as $name) {
            $this->assertSame('members.decide', $custom->action('member', $name)['permission'] ?? null);
        }
        $this->assertNotNull(Dom::first($html, '//*[@data-folio-tabs]//*[@data-folio-tabpanel="accept"]//form'));
        $this->assertNotNull(Dom::first($html, '//*[@data-folio-tabs]//*[@data-folio-tabpanel="decline"]//form'));
    }

    #[RunInSeparateProcess]
    public function test_saving_the_form_keeps_the_readonly_dates(): void
    {
        $m = $this->makeMember('ada@example.com', 'member');
        $this->flow->container()->get(Members::class)->setPassword($m, 'a brand new password'); // epoch 1
        $before = [(string) $this->reload($m)->get('registered_at'), (string) $this->reload($m)->get('email_confirmed_at'), (string) $this->reload($m)->get('decided_at')];
        $this->signIn();

        $response = $this->saveForm($m, ['email' => 'ada@example.com', 'name' => 'Ada L']);
        $this->assertSame(302, $response->getStatusCode());

        $after = $this->reload($m);
        $this->assertSame('Ada L', $after->get('name'));
        $this->assertSame($before, [(string) $after->get('registered_at'), (string) $after->get('email_confirmed_at'), (string) $after->get('decided_at')]);
        $this->assertSame('1', (string) $after->get('auth_epoch'), 'the hidden session epoch is kept');
        $this->assertStringNotContainsString('auth_epoch', $this->admin('/folio/member/' . $m->getId() . '/edit'), 'and not shown');
    }

    #[RunInSeparateProcess]
    public function test_owner_email_edit_is_trimmed_and_lowercased(): void
    {
        $m = $this->makeMember('ada@example.com', 'member');
        $this->signIn();

        $response = $this->saveForm($m, ['email' => "  Ada.L@Example.COM \n", 'name' => 'Ada L']);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('ada.l@example.com', $this->reload($m)->get('email'));
        $this->assertNotNull($this->flow->container()->get(Members::class)->findByEmail('ADA.L@example.com'), 'login and reset find it');
    }

    #[RunInSeparateProcess]
    public function test_owner_email_edit_to_a_taken_address_is_refused(): void
    {
        $m = $this->makeMember('ada@example.com', 'member');
        $this->makeMember('eve@example.com', 'member');
        $this->signIn();

        $response = $this->saveForm($m, ['email' => ' EVE@example.com', 'name' => 'Ada L']);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('Another member already uses this email address.', html_entity_decode((string) $response->getBody(), ENT_QUOTES));
        $this->assertSame('ada@example.com', $this->reload($m)->get('email'));
        $this->assertSame('Ada Lovelace', $this->reload($m)->get('name'), 'nothing was saved');
    }

    public function test_decide_requires_permission(): void
    {
        // The site has one config staff user (admin); a role without members.decide is checked through the resolver.
        $c = $this->flow->container();
        $c->get(RoleStore::class)->ensure('staff', 'editor', 'Editor', ['member.edit', 'member.view']);
        $resolver = new RbacResolver($c->get(RoleStore::class), $c->get(ExtensionState::class));

        $this->assertTrue($c->get(Access::class)->registry()->isDeclared('members.decide'));
        $this->assertFalse($resolver->allows(new ArrayUser('e', 'e@site.test', '', ['editor']), 'staff', 'members.decide', null));
        $this->assertTrue($resolver->allows(new ArrayUser('a', 'a@site.test', '', ['admin']), 'staff', 'members.decide', null));
    }

    #[RunInSeparateProcess]
    public function test_dates_are_shown_formatted_in_list_form_and_panel(): void
    {
        $m = $this->makeMember('ada@example.com', 'pending');
        $this->signIn();
        $shown = (new \DateTimeImmutable((string) $this->reload($m)->get('registered_at')))->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('M j, Y H:i');

        $this->assertStringContainsString($shown, $this->admin('/folio/member'));
        $form = $this->admin('/folio/member/' . $m->getId() . '/edit');
        $this->assertSame(3, substr_count($form, $shown), 'registered, confirmed, and the panel summary');
        $this->assertStringContainsString('Registered ' . $shown, $form);
    }

    // --- helpers -------------------------------------------------------------------------------

    /** @param array<string, string> $fields */
    private function saveForm(DynamicRecord $m, array $fields): ResponseInterface
    {
        return $this->postFrom('/folio/member/' . $m->getId() . '/edit', '/folio/member/' . $m->getId(), $fields);
    }

    private function makeMember(string $email, string $state, string $lang = 'en'): DynamicRecord
    {
        $members = $this->flow->container()->get(Members::class);
        $m = $members->register($email, 'Ada Lovelace', 'correct horse battery', $lang, []);
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

    /** POST an action the way the panel's form does: with the CSRF token read from the member's page. */
    private function action(DynamicRecord $m, string $name, string $message): ResponseInterface
    {
        return $this->postFrom('/folio/member/' . $m->getId() . '/edit', '/folio/member/' . $m->getId() . '/_action/' . $name, ['message' => $message]);
    }

    /** @return list<Email> */
    private function mails(): array
    {
        $sent = $this->flow->container()->get(TransportFactory::class)->get('mail')->sent();

        return array_values(array_map(static fn ($s) => $s->message, $sent));
    }
}
