<?php

declare(strict_types=1);

namespace Preflow\Members\Tests;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Auth\Tokens\Tokens;
use Preflow\Data\DataManager;
use Preflow\Members\Admission\AcceptOnConfirm;
use Preflow\Members\Members;
use Preflow\Members\Tests\Fixtures\CompanyAdmission;
use Preflow\Members\Tests\Support\Dom;
use Preflow\Members\Tests\Support\MemberFlows;
use Preflow\Notify\Notifier;
use Preflow\Notify\TransportFactory;
use Preflow\Rbac\RoleList;
use Psr\Http\Message\ResponseInterface;

/** Registration and the double opt-in: the register page, the confirm link, the admission's decision. */
final class MemberRegistrationTest extends TestCase
{
    use MemberFlows;

    protected function setUp(): void
    {
        $this->flowSite();
    }

    protected function tearDown(): void
    {
        $this->removeScaffold();
    }

    #[RunInSeparateProcess]
    public function test_register_page_shows_the_form_with_csrf_in_each_locale(): void
    {
        foreach (['/members/register' => ['/members/register', 'Name', 'Create account'], '/de/members/register' => ['/de/members/register', 'Passwort', 'Konto erstellen']] as $uri => [$action, $label, $submit]) {
            $response = $this->send('GET', $uri);
            $html = (string) $response->getBody();

            $this->assertSame(200, $response->getStatusCode(), $uri);
            $this->assertSame('private, no-store', $response->getHeaderLine('Cache-Control'), $uri);
            $form = Dom::first($html, '//form[@action="' . $action . '"]');
            $this->assertNotNull($form, $uri);
            $this->assertSame('post', strtolower($form->getAttribute('method')));
            $this->assertNotNull(Dom::first($html, '//form[@action="' . $action . '"]//input[@name="_csrf_token"]'), $uri);
            $this->assertStringContainsString($label, $html, $uri);
            $this->assertStringContainsString($submit, $html, $uri);
        }
    }

    #[RunInSeparateProcess]
    public function test_register_sends_confirm_mail_and_stores_member(): void
    {
        $response = $this->register();
        $html = (string) $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Check your inbox', $html);
        $this->assertStringContainsString('ada@example.com', $html);
        $mails = $this->mails();
        $this->assertCount(1, $mails);
        $this->assertSame(['ada@example.com'], $mails[0]->to);
        $this->assertMatchesRegularExpression('~https://site\.test/members/confirm/[A-Za-z0-9_-]+~', $mails[0]->text);
        $m = $this->member('ada@example.com');
        $this->assertSame([], RoleList::normalize($m->get('roles')));
        $this->assertSame('en', $m->get('language'));
        $this->assertSame('Ada Lovelace', $m->get('name'));
        $this->assertSame('0', (string) $m->get('auth_epoch'));
    }

    #[RunInSeparateProcess]
    public function test_register_in_german_stores_the_language_and_mails_german(): void
    {
        $this->register([], '/de/members');

        $this->assertSame('de', $this->member('ada@example.com')->get('language'));
        $this->assertStringContainsString('https://site.test/de/members/confirm/', $this->mails()[0]->text);
        $this->assertSame('Bestätigen Sie Ihre E-Mail-Adresse', $this->mails()[0]->subject);
    }

    #[RunInSeparateProcess]
    public function test_register_normalises_the_email(): void
    {
        $this->register(['email' => ' Ada@Example.COM ', 'name' => '  Ada Lovelace ']);

        $this->assertSame('Ada Lovelace', $this->member('ada@example.com')->get('name'));
    }

    #[RunInSeparateProcess]
    public function test_register_requires_password_min_10(): void
    {
        $response = $this->register(['password' => '123456789']);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('at least 10 characters', (string) $response->getBody());
        $this->assertSame([], $this->mails());
    }

    #[RunInSeparateProcess]
    public function test_register_requires_consent_name_and_valid_email_and_keeps_the_input(): void
    {
        foreach ([['consent' => ''], ['name' => ' '], ['email' => 'not-an-email']] as $bad) {
            $response = $this->register($bad);
            $this->assertSame(422, $response->getStatusCode(), (string) json_encode($bad));
        }
        $html = (string) $this->register(['consent' => ''])->getBody();
        $this->assertSame('Ada Lovelace', Dom::first($html, '//input[@name="name"]')?->getAttribute('value'));
        $this->assertSame('ada@example.com', Dom::first($html, '//input[@name="email"]')?->getAttribute('value'));
        $this->assertNull(Dom::first($html, '//input[@name="password"]')?->getAttribute('value') ?: null, 'the password is never echoed');
        $this->assertStringContainsString('Please agree so your account can be created.', $html);
        $this->assertSame([], $this->mails());
        $this->assertNull($this->flow->container()->get(Members::class)->findByEmail('ada@example.com'));
    }

    #[RunInSeparateProcess]
    public function test_the_admissions_errors_join_the_form_and_its_values_are_stored(): void
    {
        $this->removeScaffold();
        $this->flowSite(['admission' => CompanyAdmission::class], files: [
            'config/models/member.json' => (string) json_encode(['key' => 'member', 'extends' => true, 'fields' => ['company' => ['type' => 'string']]]),
            'resources/members/register.twig' => <<<'TWIG'
{% extends '@members-package/register.twig' %}
{% block admission_fields %}<input name="company" value="{{ old.company|default('') }}">{% if errors.company is defined %}<p class="company-error">{{ errors.company }}</p>{% endif %}{% endblock %}
TWIG,
        ]);

        $missing = $this->register(['name' => '', 'company' => ' ']);
        $html = (string) $missing->getBody();
        $this->assertSame(422, $missing->getStatusCode());
        $this->assertSame('Please name your company.', Dom::text($html, '//p[@class="company-error"]'), 'shown with the form\'s own errors');
        $this->assertStringContainsString('Please enter your name.', $html);
        $this->assertNull($this->flow->container()->get(Members::class)->findByEmail('ada@example.com'));

        $kept = (string) $this->register(['name' => '', 'company' => 'Holz AG'])->getBody();
        $this->assertSame('Holz AG', Dom::first($kept, '//input[@name="company"]')?->getAttribute('value'), 'the admission\'s input is kept');

        $this->assertSame(200, $this->register(['company' => ' Holz AG '])->getStatusCode());
        $this->assertSame('Holz AG', $this->member('ada@example.com')->get('company'));
    }

    #[RunInSeparateProcess]
    public function test_duplicate_email_any_case_answers_the_same(): void
    {
        $fresh = $this->main($this->register());
        $m = $this->member('ada@example.com');
        $this->flow->container()->get(Members::class)->confirm($m);
        $this->flow->container()->get(Members::class)->decide($m, 'member');

        $response = $this->register(['email' => 'ADA@Example.com']);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame($fresh, $this->main($response));
        $mails = $this->mails();
        $this->assertCount(2, $mails);
        $this->assertSame('Your account', $mails[1]->subject);
        $this->assertStringContainsString('https://site.test/members/login', $mails[1]->text);
        $this->assertStringContainsString('https://site.test/members/reset/', $mails[1]->text);
    }

    #[RunInSeparateProcess]
    public function test_duplicate_of_unconfirmed_sends_a_fresh_confirm_link_and_voids_the_old_one(): void
    {
        $this->register();
        $first = $this->linkToken(0, 'confirm');

        $response = $this->register(['email' => 'Ada@example.com']);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertCount(2, $this->mails());
        $this->assertSame('Confirm your email address', $this->mails()[1]->subject);
        $tokens = $this->flow->container()->get(Tokens::class);
        $this->assertNull($tokens->consume($first, 'members.confirm'), 'the old link is void');
        $this->assertNotNull($tokens->consume($this->linkToken(1, 'confirm'), 'members.confirm'));
        $this->assertCount(1, $this->flow->container()->get(DataManager::class)->queryType('member')->get()->items());
    }

    #[RunInSeparateProcess]
    public function test_duplicate_of_waiting_or_declined_sends_no_mail(): void
    {
        $fresh = $this->main($this->register());
        $this->post('/members/confirm', ['token' => $this->linkToken(0, 'confirm')]);
        $this->makeMember('bob@example.com', 'rejected');
        $before = count($this->mails());

        foreach (['ada@example.com', 'bob@example.com'] as $email) {
            $response = $this->register(['email' => $email]);
            $this->assertSame(200, $response->getStatusCode());
            $this->assertSame(str_replace('ada@example.com', $email, $fresh), $this->main($response), 'answers like a fresh registration');
        }
        $this->assertCount($before, $this->mails());
    }

    #[RunInSeparateProcess]
    public function test_register_throttled_after_5_per_hour(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->assertSame(200, $this->register(['email' => "u{$i}@example.com"])->getStatusCode(), "request {$i}");
        }
        $response = $this->register(['email' => 'u6@example.com']);

        $this->assertSame(429, $response->getStatusCode());
        $this->assertStringContainsString('Too many attempts, try again later', (string) $response->getBody());
        $this->assertNull($this->flow->container()->get(Members::class)->findByEmail('u6@example.com'));
    }

    #[RunInSeparateProcess]
    public function test_closed_registration_hides_form_and_refuses_post(): void
    {
        $this->saveRecord('members_settings', ['uuid' => 'members_settings', 'registration_open' => false]);
        $this->flow = $this->app();

        $html = $this->html('/members/register');
        $this->assertSame([], Dom::all($html, '//form[@action="/members/register"]'));
        $this->assertStringContainsString('Registration is closed', $html);
        $this->assertNotNull(Dom::first($html, '//a[@href="/members/login"]'));

        $response = $this->register();
        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringContainsString('Registration is closed', (string) $response->getBody());
        $this->assertNull($this->flow->container()->get(Members::class)->findByEmail('ada@example.com'));
    }

    #[RunInSeparateProcess]
    public function test_confirm_get_does_not_consume_token(): void
    {
        $this->register();
        $token = $this->linkToken(0, 'confirm');

        foreach ([1, 2] as $visit) {
            $response = $this->send('GET', '/members/confirm/' . $token);
            $html = (string) $response->getBody();
            $this->assertSame(200, $response->getStatusCode());
            $this->assertNotNull(Dom::first($html, '//form[@action="/members/confirm"]//input[@name="token"][@value="' . $token . '"]'));
            $this->assertNotNull(Dom::first($html, '//form[@action="/members/confirm"]//input[@name="_csrf_token"]'));
            $this->assertNotNull(Dom::first($html, '//form[@action="/members/confirm"]//button'), 'visible fallback button');
            // One app serves both visits and prints a script once; a real request boots its own app.
            $visit === 1 && $this->assertMatchesRegularExpression('~<script nonce="[^"]+">.*getElementById\(\'confirm-form\'\).*\.submit\(\)~s', $html, 'auto-submit carries the CSP nonce');
        }
        $this->assertSame('', (string) $this->member('ada@example.com')->get('email_confirmed_at'));

        $this->assertSame(200, $this->post('/members/confirm', ['token' => $token])->getStatusCode());
        $this->assertNotSame('', (string) $this->member('ada@example.com')->get('email_confirmed_at'));
    }

    #[RunInSeparateProcess]
    public function test_confirm_with_owner_approval_sets_pending_and_mails_the_owner(): void
    {
        $this->saveRecord('members_settings', ['uuid' => 'members_settings', 'notice_email' => 'owner@example.com']);
        $this->flow = $this->app();
        $this->register();

        $response = $this->post('/members/confirm', ['token' => $this->linkToken(0, 'confirm')]);
        $html = html_entity_decode((string) $response->getBody(), ENT_QUOTES);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Thanks for your interest', $html);
        $m = $this->member('ada@example.com');
        $this->assertSame(['pending'], RoleList::normalize($m->get('roles')));
        $this->assertSame('', (string) $m->get('decided_at'));
        $mails = $this->mails();
        $this->assertCount(2, $mails);
        $this->assertSame(['owner@example.com'], $mails[1]->to);
        $this->assertSame('Ada Lovelace is waiting for approval', $mails[1]->subject);
        $this->assertStringContainsString('https://site.test/folio/member/' . $m->getId() . '/edit', $mails[1]->text);
        $this->assertNull($this->cookie($response, 'site_member'), 'not signed in');
        $this->assertSame(303, $this->send('GET', '/members/account')->getStatusCode());
    }

    #[RunInSeparateProcess]
    public function test_confirm_without_notice_address_sends_no_owner_mail(): void
    {
        $this->register();
        $this->post('/members/confirm', ['token' => $this->linkToken(0, 'confirm')]);

        $this->assertCount(1, $this->mails());
    }

    #[RunInSeparateProcess]
    public function test_accept_on_confirm_makes_a_member_signs_in_and_lands(): void
    {
        $this->removeScaffold();
        $this->flowSite(['admission' => AcceptOnConfirm::class, 'landing' => '/members/start']);
        $this->saveRecord('members_settings', ['uuid' => 'members_settings', 'notice_email' => 'owner@example.com']);
        $this->flow = $this->app();
        $this->register([], '/de/members');

        $response = $this->post('/de/members/confirm', ['token' => $this->linkToken(0, 'confirm')]);

        $this->assertSame(303, $response->getStatusCode());
        $this->assertSame('/de/members/start', $response->getHeaderLine('Location'));
        $this->assertMatchesRegularExpression('~^site_member=[^;]+; Max-Age=2592000~', (string) $this->cookie($response, 'site_member'), 'remembered');
        $m = $this->member('ada@example.com');
        $this->assertSame(['member'], RoleList::normalize($m->get('roles')));
        $this->assertNotSame('', (string) $m->get('decided_at'));
        $this->assertCount(1, $this->mails(), 'no owner notice for an accepted member');
        $this->assertSame(200, $this->send('GET', '/de/members/account')->getStatusCode(), 'signed in');
    }

    #[RunInSeparateProcess]
    public function test_confirm_twice_shows_used_link_page(): void
    {
        $this->register();
        $token = $this->linkToken(0, 'confirm');
        $this->post('/members/confirm', ['token' => $token]);

        $response = $this->post('/members/confirm', ['token' => $token]);
        $html = (string) $response->getBody();

        $this->assertSame(410, $response->getStatusCode());
        $this->assertSame('private, no-store', $response->getHeaderLine('Cache-Control'));
        $this->assertStringContainsString('This link was already used or has expired.', $html);
        $this->assertNotNull(Dom::first($html, '//a[@href="/members/login"]'));
        $this->assertNotNull(Dom::first($html, '//a[@href="/members/register"]'));
        $this->assertSame(410, $this->post('/members/confirm', ['token' => 'garbage'])->getStatusCode());
    }

    #[RunInSeparateProcess]
    public function test_failing_transport_removes_the_new_member_after_the_response_and_the_visitor_still_sees_check_your_inbox(): void
    {
        $c = $this->flow->container();
        // No channel configured: the deferred send throws a TransportException, as a broken transport would.
        $c->instance(Notifier::class, new Notifier($c->get(TransportFactory::class), []));
        $logFile = (string) ini_get('error_log');

        $response = $this->register();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Check your inbox', (string) $response->getBody());
        $this->assertNull($this->flow->container()->get(Members::class)->findByEmail('ada@example.com'), 'removed again once the mail failed');
        $this->assertStringNotContainsString('ada@example.com', (string) @file_get_contents($logFile), 'no address in the PHP error log');
        $this->assertMatchesRegularExpression('~confirm mail failed for member \S+~', (string) @file_get_contents($logFile));
    }

    #[RunInSeparateProcess]
    public function test_the_confirm_mail_goes_out_after_the_response_not_during_handling(): void
    {
        $this->holdTheFlush();

        $response = $this->register();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([], $this->mails(), 'nothing sent while the response is built');
        $this->assertNotNull($this->flow->container()->get(Members::class)->findByEmail('ada@example.com'));
        $this->assertSame(1, $this->flow->container()->get(Notifier::class)->flushDeferred());
        $this->assertCount(1, $this->mails());
    }

    #[RunInSeparateProcess]
    public function test_register_links_follow_the_register_setting(): void
    {
        $this->removeScaffold();
        $this->flowSite(['register' => '/members']);

        $this->assertNotNull(Dom::first($this->html('/members/login'), '//a[@href="/members"]'), 'the login links the register home');
        $this->assertNotNull(Dom::first($this->html('/de/members/login'), '//a[@href="/de/members"]'));
        $this->assertNotNull(Dom::first($this->html('/members/register'), '//form[@action="/members/register"]'), 'the package page still serves');
    }

    #[RunInSeparateProcess]
    public function test_a_signed_in_member_on_the_register_page_goes_to_the_landing(): void
    {
        $this->makeMember();
        $this->post('/members/login', ['email' => 'ada@example.com', 'password' => self::PASSWORD]);

        $this->assertSame('/members', $this->send('GET', '/members/register')->getHeaderLine('Location'));
    }

    // --- helpers -------------------------------------------------------------------------------

    /** @param array<string, string> $over */
    private function register(array $over = [], string $base = '/members'): ResponseInterface
    {
        return $this->post($base . '/register', $over + [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'password' => self::PASSWORD,
            'consent' => '1',
        ]);
    }

    private function main(ResponseInterface $response): string
    {
        $main = Dom::first((string) $response->getBody(), '//main');

        return trim((string) preg_replace('/\s+/', ' ', $main?->textContent ?? ''));
    }
}
