<?php

declare(strict_types=1);

namespace Preflow\Core\Tests;

require_once __DIR__ . '/Fixtures/RedirectingExpiredSessionHandler.php';
require_once __DIR__ . '/Fixtures/ExpiredSessionHandlerProvider.php';

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\Core\Http\Session\SessionInterface;
use Preflow\Core\Tests\Fixtures\ExpiredSessionHandlerProvider;

/** The Application's CsrfMiddleware asks a bound ExpiredSessionHandler (bound by a service provider). */
final class CsrfExpiredSessionBootTest extends TestCase
{
    #[RunInSeparateProcess]
    public function test_a_bound_handler_answers_a_post_whose_session_expired(): void
    {
        $app = Application::create($this->app(withProvider: true));
        $app->boot();

        $response = $app->handle($this->post(['_csrf_token' => 'token-of-the-old-session', 'title' => 'typed']));

        $this->assertSame(303, $response->getStatusCode());
        $this->assertSame('/login?next=/objects/1/edit', $response->getHeaderLine('Location'));
        $this->assertSame('yes', $response->getHeaderLine('X-Has-Token'));
        $this->assertSame('typed', $app->container()->get(SessionInterface::class)->get('pending_title'));
    }

    #[RunInSeparateProcess]
    public function test_a_bound_handler_is_not_asked_for_a_forged_token_on_a_live_session(): void
    {
        $app = Application::create($this->app(withProvider: true));
        $app->boot();
        $app->container()->get(SessionInterface::class)->set('_csrf_token', 'the-live-token');

        $response = $app->handle($this->post(['_csrf_token' => 'forged', 'title' => 'typed']));

        $this->assertSame(403, $response->getStatusCode());
        $this->assertNull($app->container()->get(SessionInterface::class)->get('pending_title'));
    }

    #[RunInSeparateProcess]
    public function test_without_a_handler_an_expired_session_is_a_403(): void
    {
        $app = Application::create($this->app(withProvider: false));
        $app->boot();

        $response = $app->handle($this->post(['_csrf_token' => 'token-of-the-old-session']));

        $this->assertSame(403, $response->getStatusCode());
    }

    /** @param array<string, string> $body */
    private function post(array $body): \Psr\Http\Message\ServerRequestInterface
    {
        return (new Psr17Factory())->createServerRequest('POST', '/objects/1/edit')->withParsedBody($body);
    }

    private function app(bool $withProvider): string
    {
        $dir = sys_get_temp_dir() . '/csrfexpired_' . bin2hex(random_bytes(4));
        mkdir($dir . '/config', 0777, true);
        file_put_contents($dir . '/config/app.php', "<?php\nreturn ['name'=>'t','debug'=>0,'engine'=>'twig','key'=>'k'];\n");
        file_put_contents($dir . '/config/auth.php', "<?php\nreturn ["
            . "'default_guard' => 'session',"
            . "'guards' => ['session' => ['class' => \\Preflow\\Auth\\SessionGuard::class, 'provider' => 'config']],"
            . "'providers' => ['config' => ['class' => \\Preflow\\Auth\\ConfigUserProvider::class, 'users' => []]],"
            . "'password_hasher' => \\Preflow\\Auth\\NativePasswordHasher::class,"
            . "'session' => ['cookie' => 'csrf_expired_test'],"
            . "];\n");
        if ($withProvider) {
            file_put_contents($dir . '/config/providers.php', "<?php\nreturn [\\" . ExpiredSessionHandlerProvider::class . "::class];\n");
        }

        return $dir;
    }
}
