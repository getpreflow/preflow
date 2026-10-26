<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Integration;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Folio\Tests\Support\StaffScaffold;
use Preflow\Folio\Users\StaffPasswords;
use Preflow\Folio\Users\UserPreferences;
use Preflow\I18n\Translator;
use Preflow\Notify\Notifier;
use Psr\Http\Message\ResponseInterface;

/**
 * Each Folio user picks their own interface language (POST {prefix}/_locale, the sidebar
 * switch, the account page). It is stored per auth id, for record and config users alike;
 * signed-out admin pages follow the browser; staff mails go out in the recipient's language;
 * public pages never change.
 */
final class InterfaceLocaleTest extends TestCase
{
    use StaffScaffold;

    protected function setUp(): void
    {
        $this->staffSite();
    }

    protected function tearDown(): void
    {
        $this->removeSite();
    }

    /** @param array<string, string> $body @param array<string, string> $headers */
    private function request(string $method, string $uri, array $body = [], array $headers = []): ResponseInterface
    {
        if ($method === 'POST' && !isset($_SESSION['_csrf_token'])) {
            $this->send('GET', '/folio/login');
        }
        $request = (new Psr17Factory())->createServerRequest($method, $uri, ['REMOTE_ADDR' => $this->remoteAddr]);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        if ($method === 'POST') {
            $request = $request->withParsedBody($body + ['_csrf_token' => (string) ($_SESSION['_csrf_token'] ?? '')]);
        }

        return $this->flow->handle($request);
    }

    private function prefs(): UserPreferences
    {
        return $this->flow->container()->get(UserPreferences::class);
    }

    private function signIn(string $email): void
    {
        $this->assertSame('/folio', $this->post('/folio/login', ['email' => $email, 'password' => self::PASSWORD])->getHeaderLine('Location'));
    }

    #[RunInSeparateProcess]
    public function test_a_staff_user_picks_french_and_another_user_keeps_the_default(): void
    {
        $ada = $this->makeStaff();
        $this->makeStaff('bob@site.test', data: ['name' => 'Bob']);
        $this->signIn('ada@site.test');

        $page = $this->html('/folio');
        $this->assertStringContainsString('<html lang="en">', $page);
        $this->assertStringContainsString('action="/folio/_locale"', $page, 'the sidebar switch');
        $this->assertMatchesRegularExpression('~<button[^>]*name="locale" value="fr"[^>]*>.*Français~s', $page);

        $response = $this->request('POST', 'https://site.test/folio/_locale', ['locale' => 'fr'], ['Referer' => 'https://site.test/folio/page?sort=title']);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/folio/page?sort=title', $response->getHeaderLine('Location'), 'back to the referring admin page');
        $this->assertSame('fr', $this->prefs()->locale((string) $ada->getId()));
        $page = $this->html('/folio');
        $this->assertStringContainsString('<html lang="fr">', $page);
        $this->assertStringContainsString('Se déconnecter', $page);
        $this->assertStringNotContainsString('Log out', $page);
        $account = $this->html('/folio/_account');
        $this->assertStringContainsString('<html lang="fr">', $account);
        $this->assertMatchesRegularExpression('~<select[^>]*name="locale"~', $account, 'the account page select');
        $this->assertMatchesRegularExpression('~<option value="fr" selected>Français</option>~', $account);
        $this->assertStringContainsString('<option value="de">Deutsch</option>', $account);

        // Another site's page, or no referrer: the dashboard.
        $this->assertSame('/folio', $this->request('POST', 'https://site.test/folio/_locale', ['locale' => 'fr'], ['Referer' => 'https://evil.test/folio/page'])->getHeaderLine('Location'));
        $this->assertSame('/folio', $this->request('POST', 'https://site.test/folio/_locale', ['locale' => 'fr'], ['Referer' => 'https://site.test/elsewhere'])->getHeaderLine('Location'));
        $this->assertSame('/folio', $this->request('POST', 'https://site.test/folio/_locale', ['locale' => 'fr'])->getHeaderLine('Location'));

        // A second user in another browser still gets the default.
        $this->useSession([]);
        $this->signIn('bob@site.test');
        $page = $this->html('/folio');
        $this->assertStringContainsString('<html lang="en">', $page);
        $this->assertStringContainsString('Log out', $page);
    }

    #[RunInSeparateProcess]
    public function test_a_config_user_keeps_the_choice_across_sign_out_and_in(): void
    {
        $this->signIn(self::CONFIG_EMAIL);
        $this->assertSame(302, $this->request('POST', '/folio/_locale', ['locale' => 'de'])->getStatusCode());
        $this->assertSame('de', $this->prefs()->locale(self::CONFIG_EMAIL), 'stored without a user record');
        $this->assertStringContainsString('<html lang="de">', $this->html('/folio'));

        $this->post('/folio/logout', []);
        $this->useSession([]);
        $this->signIn(self::CONFIG_EMAIL);

        $page = $this->html('/folio');
        $this->assertStringContainsString('<html lang="de">', $page);
        $this->assertStringContainsString('Abmelden', $page);
    }

    #[RunInSeparateProcess]
    public function test_a_language_that_is_not_offered_is_refused(): void
    {
        $ada = $this->makeStaff();
        $this->signIn('ada@site.test');

        $this->assertSame(422, $this->request('POST', '/folio/_locale', ['locale' => 'xx'])->getStatusCode());
        $this->assertSame(422, $this->request('POST', '/folio/_locale', [])->getStatusCode());
        $this->assertNull($this->prefs()->locale((string) $ada->getId()));
    }

    #[RunInSeparateProcess]
    public function test_signing_in_needs_no_permission_but_a_session(): void
    {
        $response = $this->request('POST', '/folio/_locale', ['locale' => 'fr']);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/folio/login', $response->getHeaderLine('Location'), 'signed out: to the sign-in page');
    }

    #[RunInSeparateProcess]
    public function test_the_sign_in_page_follows_the_browser(): void
    {
        $french = (string) $this->request('GET', '/folio/login', [], ['Accept-Language' => 'fr-CH,fr;q=0.9'])->getBody();
        $this->assertStringContainsString('<html lang="fr">', $french);
        $this->assertStringContainsString('Se connecter', $french);
        $this->assertStringNotContainsString('Sign in', $french);

        $other = (string) $this->request('GET', '/folio/login', [], ['Accept-Language' => 'it, es;q=0.5'])->getBody();
        $this->assertStringContainsString('<html lang="en">', $other);
        $this->assertStringContainsString('Sign in', $other);

        $forgot = (string) $this->request('GET', '/folio/forgot', [], ['Accept-Language' => 'de'])->getBody();
        $this->assertStringContainsString('<html lang="de">', $forgot);
    }

    #[RunInSeparateProcess]
    public function test_the_default_is_the_folio_locale(): void
    {
        $this->removeSite();
        $this->staffSite(folio: ['locale' => 'de']);
        $this->makeStaff();
        $this->signIn('ada@site.test');

        $this->assertStringContainsString('<html lang="de">', $this->html('/folio'));
        $this->useSession([]);
        $this->assertStringContainsString('<html lang="de">', $this->html('/folio/login'), 'and for signed-out pages without a browser match');
    }

    #[RunInSeparateProcess]
    public function test_a_public_page_renders_the_same_after_an_admin_request_in_french(): void
    {
        $this->write('lang/en/site.php', "<?php\nreturn ['greeting' => 'Hello'];\n");
        $this->write('lang/fr/site.php', "<?php\nreturn ['greeting' => 'Bonjour'];\n");
        $this->write('app/pages/hello.twig', "{{ current_locale() }}|{{ t('site.greeting') }}|{{ t('folio.layout.log_out') }}");
        $this->flow = $this->app();
        $ada = $this->makeStaff();

        $before = (string) $this->send('GET', '/hello')->getBody();
        $this->assertSame('en|Hello|Log out', $before);

        $this->signIn('ada@site.test');
        $this->request('POST', '/folio/_locale', ['locale' => 'fr']);
        $this->assertStringContainsString('Se déconnecter', $this->html('/folio'));

        $this->assertSame($before, (string) $this->send('GET', '/hello')->getBody(), 'byte-identical, in the frontend locale');
        $translator = $this->flow->container()->get(Translator::class);
        $this->assertSame('en', $translator->getLocale());
        $this->assertSame('en', $this->flow->container()->get(\Preflow\Media\LocaleProvider::class)->current(), 'alt texts in the frontend locale');
        $this->assertSame('fr', $this->prefs()->locale((string) $ada->getId()));
    }

    #[RunInSeparateProcess]
    public function test_an_invitation_goes_out_in_the_recipients_language(): void
    {
        $ada = $this->makeStaff(password: null);
        $bob = $this->makeStaff('bob@site.test', password: null, data: ['name' => 'Bob']);
        $this->prefs()->setLocale((string) $ada->getId(), 'de');

        $passwords = $this->flow->container()->get(StaffPasswords::class);
        $passwords->invite($ada);
        $passwords->invite($bob);
        $this->flow->container()->get(Notifier::class)->flushDeferred();

        [$german, $english] = $this->mails();
        $this->assertSame('Dein Zugang zu Staff Site', $german->subject);
        $this->assertStringStartsWith("Hallo Ada Lovelace,\n\nfür dich wurde", (string) $german->text);
        $this->assertStringContainsString('Freundliche Grüße', (string) $german->text);
        $this->assertSame('Your account for Staff Site', $english->subject, 'no preference: the site default');
    }
}
