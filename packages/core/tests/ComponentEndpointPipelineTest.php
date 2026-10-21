<?php

declare(strict_types=1);

namespace Preflow\Core\Tests;

require_once __DIR__ . '/Fixtures/PipelineCounter/PipelineCounter.php';

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\Core\Tests\Fixtures\PipelineCounter\PipelineCounter;
use Preflow\Htmx\ComponentToken;
use Psr\Http\Message\ResponseInterface;

/** Component requests (/--component) run through the app's middleware and error handling. */
final class ComponentEndpointPipelineTest extends TestCase
{
    private string $dir;

    private const KEY = 'pipeline-test-key-0123456789abcdef';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/cmp_pipe_' . bin2hex(random_bytes(4));
        @mkdir($this->dir . '/config', 0777, true);
        @mkdir($this->dir . '/app/pages', 0777, true);
        file_put_contents($this->dir . '/config/app.php', "<?php\nreturn ['name'=>'t','debug'=>0,'engine'=>'twig','key'=>'" . self::KEY . "'];\n");
        file_put_contents($this->dir . '/app/pages/index.twig', '<html><head></head><body>home</body></html>');
        $this->auth(['/admin']);
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($this->dir);
    }

    /** @param list<string>|null $paths session.paths */
    private function auth(?array $paths): void
    {
        $session = ['cookie' => 'pipe_test', 'secure' => false] + ($paths !== null ? ['paths' => $paths] : []);
        file_put_contents($this->dir . '/config/auth.php', "<?php\nreturn " . var_export(['session' => $session], true) . ";\n");
    }

    private function action(string $token, array $body = []): ResponseInterface
    {
        $app = Application::create($this->dir);
        $app->boot();

        return $app->handle((new Psr17Factory())->createServerRequest('POST', '/--component/action')
            ->withParsedBody($body + ['token' => $token])->withHeader('HX-Request', 'true'));
    }

    private function token(string $action = 'bump'): string
    {
        return (new ComponentToken(self::KEY))->encode(PipelineCounter::class, ['count' => 1], $action);
    }

    #[RunInSeparateProcess]
    public function test_component_action_gets_security_headers_and_nonce(): void
    {
        $res = $this->action($this->token());

        $this->assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        $this->assertStringContainsString('Count: 2', (string) $res->getBody());
        $this->assertNotSame('', $res->getHeaderLine('Content-Security-Policy'));
        $this->assertNotSame('', $res->getHeaderLine('HX-Nonce'));
        $this->assertStringContainsString("'nonce-" . $res->getHeaderLine('HX-Nonce') . "'", $res->getHeaderLine('Content-Security-Policy'));
    }

    #[RunInSeparateProcess]
    public function test_component_post_needs_no_csrf_token(): void
    {
        $this->auth(null); // session (and CSRF) on every path
        $this->assertSame(200, $this->action($this->token())->getStatusCode());
    }

    #[RunInSeparateProcess]
    public function test_invalid_token_is_403_not_fatal(): void
    {
        $res = $this->action('tampered');
        $this->assertSame(403, $res->getStatusCode());
    }

    #[RunInSeparateProcess]
    public function test_session_paths_still_apply_to_component_requests(): void
    {
        $res = $this->action($this->token());
        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame('', $res->getHeaderLine('Set-Cookie'));
    }

    #[RunInSeparateProcess]
    public function test_guarded_components_see_the_middleware_request(): void
    {
        $this->auth(null);
        $this->action($this->token());
        // The session middleware ran: authorize() got the request it prepared.
        $this->assertNotNull(PipelineCounter::$seen?->getAttribute(\Preflow\Core\Http\Session\SessionInterface::class));
    }
}
