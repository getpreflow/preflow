<?php

declare(strict_types=1);

namespace Preflow\Core\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\Core\Http\Csrf\CsrfSignInPaths;
use Preflow\Core\Http\Csrf\StatelessCsrfToken;
use Psr\Http\Message\ServerRequestInterface;

/** The Application checks unsafe requests without a session too, configured by config/csrf.php. */
final class CsrfBootTest extends TestCase
{
    #[RunInSeparateProcess]
    public function test_post_without_session_is_checked(): void
    {
        $app = Application::create($this->app("'key' => 'a-real-application-key-of-32-bytes'"));
        $app->boot();
        $token = $app->container()->get(StatelessCsrfToken::class)->issue();

        // The check passes (the kernel then answers 404, there are no routes) or refuses (403).
        $this->assertSame(403, $app->handle($this->post(['Sec-Fetch-Site' => 'cross-site']))->getStatusCode());
        $this->assertSame(403, $app->handle($this->post())->getStatusCode());
        $this->assertSame(404, $app->handle($this->post(['Sec-Fetch-Site' => 'same-origin']))->getStatusCode());
        $this->assertSame(404, $app->handle($this->post(['Origin' => 'https://example.com']))->getStatusCode());
        $this->assertSame(404, $app->handle($this->post(['Origin' => 'https://stage.example.com']))->getStatusCode());
        $this->assertSame(404, $app->handle($this->post([], ['_csrf_token' => $token]))->getStatusCode());
        $this->assertSame(404, $app->handle($this->post(['Sec-Fetch-Site' => 'cross-site'], [], '/hooks/stripe'))->getStatusCode());
    }

    #[RunInSeparateProcess]
    public function test_default_key_disables_the_stateless_token(): void
    {
        $app = Application::create($this->app(''));
        $app->boot();

        $this->assertFalse($app->container()->get(StatelessCsrfToken::class)->enabled());
        $this->assertSame(404, $app->handle($this->post(['Sec-Fetch-Site' => 'same-origin']))->getStatusCode());
        $this->assertSame(403, $app->handle($this->post())->getStatusCode());
    }

    #[RunInSeparateProcess]
    public function test_sign_in_paths_from_config_are_registered(): void
    {
        $app = Application::create($this->app("'key' => 'a-real-application-key-of-32-bytes'"));
        $app->boot();

        $paths = $app->container()->get(CsrfSignInPaths::class);
        $this->assertTrue($paths->matches('/account/login'));
        $this->assertFalse($paths->matches('/account'));
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, string> $body
     */
    private function post(array $headers = [], array $body = [], string $path = '/contact'): ServerRequestInterface
    {
        $request = (new Psr17Factory())->createServerRequest('POST', 'https://example.com' . $path)->withParsedBody($body);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $request;
    }

    private function app(string $key): string
    {
        $dir = sys_get_temp_dir() . '/csrfboot_' . bin2hex(random_bytes(4));
        mkdir($dir . '/config', 0777, true);
        file_put_contents($dir . '/config/app.php', "<?php\nreturn ['name'=>'t','debug'=>0,'engine'=>'twig','url'=>'https://example.com',$key];\n");
        file_put_contents($dir . '/config/csrf.php', "<?php\nreturn ["
            . "'exempt' => ['/--component/', '/hooks/'],"
            . "'trusted_origins' => ['https://stage.example.com'],"
            . "'sign_in_paths' => ['/account/login'],"
            . "];\n");

        return $dir;
    }
}
