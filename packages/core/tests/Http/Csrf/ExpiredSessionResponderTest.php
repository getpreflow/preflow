<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\Http\Csrf;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Http\Csrf\ExpiredSessionResponder;
use Preflow\Core\Http\OldInput;
use Preflow\Core\Http\Session\SessionInterface;
use Preflow\I18n\Translator;
use Preflow\Testing\ArraySession;
use Psr\Http\Message\ServerRequestInterface;

final class ExpiredSessionResponderTest extends TestCase
{
    private ArraySession $session;

    private ?string $langDir = null;

    protected function setUp(): void
    {
        $this->session = new ArraySession();
        $this->session->start();
    }

    protected function tearDown(): void
    {
        if ($this->langDir !== null) {
            array_map('unlink', glob($this->langDir . '/*/*.php') ?: []);
            array_map('rmdir', glob($this->langDir . '/*') ?: []);
            rmdir($this->langDir);
        }
    }

    public function test_full_post_redirects_back_with_flash_and_old_input(): void
    {
        $response = (new ExpiredSessionResponder('en'))($this->post(['Referer' => 'https://site.test/contact?topic=help#form']));

        $this->assertSame(303, $response->getStatusCode());
        $this->assertSame('/contact?topic=help', $response->getHeaderLine('Location'));
        $this->assertSame('Your session had expired. Please send the form again.', $this->session->getFlash('warning'));
        $this->assertSame(['name' => 'Ada'], $this->session->getFlash(OldInput::FLASH_KEY));
    }

    public function test_foreign_referer_redirects_home(): void
    {
        $responder = new ExpiredSessionResponder('en');

        foreach (['https://evil.test/contact', 'http://site.test/contact', 'https://site.test:8443/contact', ''] as $referer) {
            $response = $responder($this->post($referer !== '' ? ['Referer' => $referer] : []));
            $this->assertSame(303, $response->getStatusCode());
            $this->assertSame('/', $response->getHeaderLine('Location'), $referer);
        }
        // Same-origin paths that a browser would read as another host.
        $this->assertSame('/evil.test/x', $responder($this->post(['Referer' => 'https://site.test//evil.test/x']))->getHeaderLine('Location'));
        $this->assertSame('/evil.test/x', $responder($this->post(['Referer' => 'https://site.test/\\evil.test/x']))->getHeaderLine('Location'));
    }

    public function test_referer_of_the_app_url_goes_back_behind_a_tls_proxy(): void
    {
        // The proxy forwards http://internal:8080, the browser sent the public page as Referer.
        $responder = new ExpiredSessionResponder('en', null, 'https://example.com');
        $request = fn (string $referer) => (new Psr17Factory())->createServerRequest('POST', 'http://internal:8080/contact')
            ->withHeader('Referer', $referer)
            ->withAttribute(SessionInterface::class, $this->session);

        $this->assertSame('/contact?topic=help', $responder($request('https://example.com/contact?topic=help'))->getHeaderLine('Location'));
        $this->assertSame('/contact', $responder($request('http://internal:8080/contact'))->getHeaderLine('Location'));
        $this->assertSame('/', $responder($request('https://evil.test/contact'))->getHeaderLine('Location'));
    }

    public function test_htmx_gets_refresh(): void
    {
        $response = (new ExpiredSessionResponder('en'))($this->post(['HX-Request' => 'true', 'Referer' => 'https://site.test/contact']));

        $this->assertSame(204, $response->getStatusCode());
        $this->assertSame('true', $response->getHeaderLine('HX-Refresh'));
        $this->assertSame('', $response->getHeaderLine('Location'));
        $this->assertSame('Your session had expired. Please send the form again.', $this->session->getFlash('warning'));
        $this->assertSame(['name' => 'Ada'], $this->session->getFlash(OldInput::FLASH_KEY));
    }

    public function test_json_gets_409(): void
    {
        $response = (new ExpiredSessionResponder('en'))($this->post(['Accept' => 'application/json']));

        $this->assertSame(409, $response->getStatusCode());
        $this->assertStringStartsWith('application/json', $response->getHeaderLine('Content-Type'));
        $this->assertSame(['error' => 'session_expired'], json_decode((string) $response->getBody(), true));
        $this->assertNull($this->session->getFlash('warning'));
    }

    public function test_message_follows_the_request_locale_then_the_app_locale(): void
    {
        $responder = new ExpiredSessionResponder('fr');

        $responder($this->post([])->withAttribute('locale', 'de'));
        $this->assertSame('Deine Sitzung war abgelaufen. Bitte sende das Formular noch einmal.', $this->session->getFlash('warning'));

        $responder($this->post([])->withAttribute('locale', 'it'));
        $this->assertSame('La sessione era scaduta. Invia di nuovo il modulo.', $this->session->getFlash('warning'));

        $responder($this->post([]));
        $this->assertSame('Votre session avait expiré. Veuillez renvoyer le formulaire.', $this->session->getFlash('warning'));

        $responder($this->post([])->withAttribute('locale', 'ja'));
        $this->assertSame('Your session had expired. Please send the form again.', $this->session->getFlash('warning'));
    }

    public function test_site_translation_wins(): void
    {
        $this->langDir = sys_get_temp_dir() . '/preflow-expired-' . bin2hex(random_bytes(4));
        mkdir($this->langDir . '/de', 0777, true);
        file_put_contents($this->langDir . '/de/csrf.php', "<?php\nreturn ['session_expired' => 'Zu lange gewartet.'];\n");
        $translator = new Translator($this->langDir, 'de', 'de');
        $responder = new ExpiredSessionResponder('de', static fn () => $translator);

        $responder($this->post([])->withAttribute('locale', 'de'));
        $this->assertSame('Zu lange gewartet.', $this->session->getFlash('warning'));

        // No site text in English: the built-in one.
        $responder($this->post([])->withAttribute('locale', 'en'));
        $this->assertSame('Your session had expired. Please send the form again.', $this->session->getFlash('warning'));
    }

    /** @param array<string, string> $headers */
    private function post(array $headers): ServerRequestInterface
    {
        $request = (new Psr17Factory())->createServerRequest('POST', 'https://site.test/contact')
            ->withParsedBody(['name' => 'Ada', 'password' => 'secret', '_csrf_token' => 'old'])
            ->withAttribute(SessionInterface::class, $this->session);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $request;
    }
}
