<?php

declare(strict_types=1);

namespace Preflow\Core\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Auth\SessionGuard;
use Preflow\Core\Application;
use Preflow\View\Audit\PageRenderer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Since 0.75 an internal render (the SEO audit, GEO, a page cache rebuild) keeps the Set-Cookie
 * values the request queued before it: the guards' cookie queue is set aside for the render
 * (CookieQueue::isolated()) instead of being emptied by the render's own ResolveUserMiddleware.
 * Before, a signed-in marker or a rotated remember-me token queued by the request was lost, so an
 * admin running an audit step kept a token already rotated away and was signed out next time.
 */
final class InternalRenderCookieTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/internal_cookie_' . bin2hex(random_bytes(4));
        @mkdir($this->dir . '/config', 0777, true);
        @mkdir($this->dir . '/app/pages', 0777, true);
        file_put_contents($this->dir . '/config/app.php', "<?php\nreturn ['name'=>'t','debug'=>0,'engine'=>'twig','key'=>'internal-cookie-test-key-0123456789','url'=>'https://site.test'];\n");
        file_put_contents($this->dir . '/config/middleware.php', "<?php\nreturn [" . var_export(AuditStepRoute::class, true) . "];\n");
        file_put_contents($this->dir . '/config/auth.php', "<?php\nreturn " . var_export([
            'default_guard' => 'session',
            'guards' => ['session' => ['class' => SessionGuard::class, 'provider' => 'users']],
            'providers' => ['users' => ['class' => \Preflow\Auth\ConfigUserProvider::class, 'users' => [
                ['id' => 'admin', 'email' => 'admin@site.test', 'password_hash' => '', 'roles' => []],
            ]]],
            'session' => ['cookie' => 'cookie_test', 'secure' => false],
        ], true) . ";\n");
        file_put_contents($this->dir . '/app/pages/about.twig', '<html><head><title>About</title></head><body><main><h1>About</h1></main></body></html>');
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($this->dir);
    }

    /** @return list<string> the names of the cookies the response sets */
    private static function cookieNames(ResponseInterface $response): array
    {
        return array_map(static fn (string $c): string => strstr($c, '=', true) ?: $c, $response->getHeader('Set-Cookie'));
    }

    #[RunInSeparateProcess]
    public function test_a_request_that_renders_internally_keeps_the_cookies_it_queued(): void
    {
        $app = Application::create($this->dir);
        $app->boot();
        $this->assertSame(PHP_SESSION_ACTIVE, session_status());
        $_SESSION['_auth_user_id'] = (string) (new \Preflow\Auth\ConfigUserProvider([['email' => 'admin@site.test', 'password_hash' => '']]))
            ->findByCredentials(['email' => 'admin@site.test'])?->getAuthId();
        $request = (new Psr17Factory())->createServerRequest('GET', 'https://site.test/step')
            ->withHeader('Cookie', 'cookie_test=' . session_id());

        // Proof: signed in without the marker cookie, a page's response sets it.
        $page = $app->handle($request->withUri($request->getUri()->withPath('/about')));
        $this->assertContains(SessionGuard::SIGNED_IN_COOKIE, self::cookieNames($page));

        // The guard queues it for this response too; then the step
        // renders a page internally, as the SEO audit's "Continue" does.
        $response = $app->handle($request);

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame('rendered 200', (string) $response->getBody());
        $this->assertContains(SessionGuard::SIGNED_IN_COOKIE, self::cookieNames($response), 'the marker the request queued reaches its response');
    }
}

/** /step renders /about internally (as SeoAuditController::advance does), then answers. */
final class AuditStepRoute implements MiddlewareInterface
{
    public function __construct(private readonly \Preflow\Core\Container\Container $container) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($request->getUri()->getPath() !== '/step') {
            return $handler->handle($request);
        }
        $page = $this->container->get(PageRenderer::class)->render('/about');

        return new Response(200, [], 'rendered ' . $page->status);
    }
}
