<?php

declare(strict_types=1);

namespace Preflow\Members\Tests;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Members\Admission\AcceptOnConfirm;
use Preflow\Members\Tests\Support\Dom;
use Preflow\Members\Tests\Support\MemberFlows;
use Preflow\Members\Tests\Support\MembersScaffold;
use Preflow\Members\MemberRoles;

/**
 * A second site, not smart-stack: the members under /konto, German (default) and French, everyone
 * accepted on confirmation. Register, confirm from the mail, signed in, account: French copy and
 * /fr/konto URLs throughout.
 */
final class KontoSiteTest extends TestCase
{
    use MemberFlows;
    use MembersScaffold;

    protected function setUp(): void
    {
        $this->flowSite(['path' => '/konto', 'admission' => AcceptOnConfirm::class], ['de', 'fr']);
    }

    protected function tearDown(): void
    {
        $this->removeScaffold();
    }

    #[RunInSeparateProcess]
    public function test_register_confirm_sign_in_and_account_in_french(): void
    {
        $page = $this->html('/fr/konto/register');
        $this->assertNotNull(Dom::first($page, '//form[@action="/fr/konto/register"]//input[@name="_csrf_token"]'));
        $this->assertStringContainsString('Créer un compte', $page);
        $this->assertNotNull(Dom::first($page, '//a[@href="/fr/konto/login"]'));
        $this->assertSame('fr', Dom::first($page, '//html')?->getAttribute('lang'));

        $sent = $this->post('/fr/konto/register', ['name' => 'Ada Lovelace', 'email' => 'ada@example.com', 'password' => self::PASSWORD, 'consent' => '1']);
        $this->assertSame(200, $sent->getStatusCode());
        $this->assertStringContainsString('Consultez votre boîte de réception', html_entity_decode((string) $sent->getBody(), ENT_QUOTES));
        $mail = $this->mails()[0];
        $this->assertSame('Confirmez votre adresse e-mail', $mail->subject);
        $this->assertMatchesRegularExpression('~https://site\.test/fr/konto/confirm/[A-Za-z0-9_-]+~', $mail->text);
        $this->assertSame('fr', $this->member('ada@example.com')->get('language'));

        $token = $this->linkToken(0, 'confirm');
        $confirm = $this->html('/fr/konto/confirm/' . $token);
        $this->assertNotNull(Dom::first($confirm, '//form[@action="/fr/konto/confirm"]//input[@name="token"][@value="' . $token . '"]'));
        $this->assertStringContainsString('Confirmez votre e-mail', html_entity_decode($confirm, ENT_QUOTES));

        $in = $this->post('/fr/konto/confirm', ['token' => $token]);
        $this->assertSame(303, $in->getStatusCode());
        $this->assertSame('/fr/konto', $in->getHeaderLine('Location'), 'the landing, in French');
        $this->assertNotNull($this->cookie($in, 'site_member'), 'remembered');
        $this->assertSame(['member'], MemberRoles::of($this->member('ada@example.com')));

        $account = $this->send('GET', '/fr/konto/account');
        $html = html_entity_decode((string) $account->getBody(), ENT_QUOTES);
        $this->assertSame(200, $account->getStatusCode(), 'signed in');
        $this->assertStringContainsString('Votre compte', $html);
        $this->assertStringContainsString('ada@example.com', $html);
        $this->assertNotNull(Dom::first($html, '//form[@action="/fr/konto/account/password"]'));
        $this->assertNotNull(Dom::first($html, '//form[@action="/fr/konto/account/delete"]'));
        $this->assertNotNull(Dom::first($html, '//a[@href="/fr/konto"]'));

        $changed = $this->post('/fr/konto/account/password', ['current' => self::PASSWORD, 'password' => 'un tout nouveau mot de passe', 'repeat' => 'un tout nouveau mot de passe']);
        $this->assertSame('/fr/konto/account', $changed->getHeaderLine('Location'));
        $this->assertStringContainsString('Mot de passe modifié.', html_entity_decode($this->html('/fr/konto/account'), ENT_QUOTES));

        $this->assertSame('/fr/konto', $this->post('/fr/konto/logout', [])->getHeaderLine('Location'));
        $this->assertSame('/fr/konto/login?next=/fr/konto/account', $this->send('GET', '/fr/konto/account')->getHeaderLine('Location'));
        $this->assertSame(303, $this->post('/fr/konto/login', ['email' => 'ada@example.com', 'password' => 'un tout nouveau mot de passe', 'next' => '/fr/konto/account'])->getStatusCode());
        $this->assertSame(200, $this->send('GET', '/fr/konto/account')->getStatusCode());
    }

    #[RunInSeparateProcess]
    public function test_the_default_locale_is_unprefixed_and_german(): void
    {
        $page = $this->html('/konto/register');
        $this->assertNotNull(Dom::first($page, '//form[@action="/konto/register"]'));
        $this->assertStringContainsString('Konto erstellen', $page);

        $this->post('/konto/register', ['name' => 'Ada', 'email' => 'ada@example.com', 'password' => self::PASSWORD, 'consent' => '1']);
        $this->assertMatchesRegularExpression('~https://site\.test/konto/confirm/~', $this->mails()[0]->text);
        $this->assertSame('/konto', $this->post('/konto/confirm', ['token' => $this->linkToken(0, 'confirm')])->getHeaderLine('Location'));
        $this->assertStringContainsString('Ihr Konto', $this->html('/konto/account'));
    }

    #[RunInSeparateProcess]
    public function test_forgot_and_reset_in_french(): void
    {
        $this->makeMember('ada@example.com', 'member', 'fr');

        $forgot = $this->post('/fr/konto/forgot', ['email' => 'ada@example.com']);
        $this->assertSame(200, $forgot->getStatusCode());
        $this->assertSame('Réinitialisez votre mot de passe', $this->mails()[0]->subject);
        $token = $this->linkToken(0, 'reset');
        $this->assertStringContainsString('https://site.test/fr/konto/reset/' . $token, $this->mails()[0]->text);
        $this->assertNotNull(Dom::first($this->html('/fr/konto/reset/' . $token), '//form[@action="/fr/konto/reset"]'));

        $reset = $this->post('/fr/konto/reset', ['token' => $token, 'password' => 'un tout nouveau mot de passe', 'repeat' => 'un tout nouveau mot de passe']);
        $this->assertSame('/fr/konto', $reset->getHeaderLine('Location'));
    }
}
