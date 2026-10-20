<?php

declare(strict_types=1);

namespace Preflow\Core\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\Core\Http\Session\SessionInterface;
use Preflow\Core\Http\Session\SessionMiddleware;
use Preflow\Testing\ArraySession;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** session.paths: public pages get no session (and so no cookie). */
final class SessionPathsTest extends TestCase
{
    public function test_no_session_outside_configured_paths(): void
    {
        $session = new ArraySession();
        $captured = $this->run_('/hello', $session, ['/folio']);

        $this->assertFalse($session->isStarted());
        $this->assertNull($captured->getAttribute(SessionInterface::class));
    }

    public function test_session_starts_under_a_configured_path(): void
    {
        foreach (['/folio', '/folio/login', '/folio/types/page'] as $path) {
            $session = new ArraySession();
            $captured = $this->run_($path, $session, ['/folio']);

            $this->assertTrue($session->isStarted(), $path);
            $this->assertSame($session, $captured->getAttribute(SessionInterface::class));
        }
    }

    public function test_prefix_match_is_segment_wise(): void
    {
        $session = new ArraySession();
        $this->run_('/folioxyz', $session, ['/folio']);

        $this->assertFalse($session->isStarted());
    }

    public function test_without_paths_the_session_starts_everywhere(): void
    {
        $session = new ArraySession();
        $this->run_('/hello', $session, null);

        $this->assertTrue($session->isStarted());
    }

    #[RunInSeparateProcess]
    public function test_boot_does_not_start_a_session_when_paths_are_set(): void
    {
        $dir = $this->app("'paths' => ['/folio']");
        $app = Application::create($dir);
        $app->boot();
        $response = $app->handle((new Psr17Factory())->createServerRequest('GET', '/hello'));

        $this->assertSame(PHP_SESSION_NONE, session_status());
        $this->assertSame([], $response->getHeader('Set-Cookie'));
    }

    #[RunInSeparateProcess]
    public function test_boot_without_paths_still_starts_the_session(): void
    {
        $dir = $this->app('');
        Application::create($dir)->boot();

        $this->assertSame(PHP_SESSION_ACTIVE, session_status());
    }

    /** @param list<string>|null $paths */
    private function run_(string $path, SessionInterface $session, ?array $paths): ServerRequestInterface
    {
        $handler = new class implements RequestHandlerInterface {
            public ?ServerRequestInterface $captured = null;

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->captured = $request;

                return new Response(200);
            }
        };
        (new SessionMiddleware($session, $paths))->process((new Psr17Factory())->createServerRequest('GET', $path), $handler);

        return $handler->captured ?? throw new \RuntimeException('handler not reached');
    }

    private function app(string $extraSession): string
    {
        $dir = sys_get_temp_dir() . '/sesspaths_' . bin2hex(random_bytes(4));
        mkdir($dir . '/config', 0777, true);
        file_put_contents($dir . '/config/app.php', "<?php\nreturn ['name'=>'t','debug'=>0,'engine'=>'twig','key'=>'k'];\n");
        file_put_contents($dir . '/config/auth.php', "<?php\nreturn ["
            . "'default_guard' => 'session',"
            . "'guards' => ['session' => ['class' => \\Preflow\\Auth\\SessionGuard::class, 'provider' => 'config']],"
            . "'providers' => ['config' => ['class' => \\Preflow\\Auth\\ConfigUserProvider::class, 'users' => []]],"
            . "'password_hasher' => \\Preflow\\Auth\\NativePasswordHasher::class,"
            . "'session' => ['cookie' => 'paths_test', {$extraSession}],"
            . "];\n");

        return $dir;
    }
}
