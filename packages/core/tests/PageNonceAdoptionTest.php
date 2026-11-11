<?php

declare(strict_types=1);

namespace Preflow\Core\Tests;

require_once __DIR__ . '/Fixtures/Pluggable/SignalDriver.php';

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\Core\Tests\Fixtures\Pluggable\SignalDriver;
use Preflow\View\NonceGenerator;
use Psr\Http\Message\ResponseInterface;

/**
 * A page nonce a client sends back is untrusted input: adopted for a fragment request only, for
 * that request only, and a response answered with it is never stored by the page cache.
 */
final class PageNonceAdoptionTest extends TestCase
{
    /** 24 base64 characters, also a word on the page: what a poisoned store would replace. */
    private const string WORD = 'cGFnZS1ub25jZS0xMjM0NQ==';

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/page_nonce_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/config', 0777, true);
        mkdir($this->dir . '/app/pages', 0777, true);
        ini_set('error_log', $this->dir . '/php.log');
        file_put_contents($this->dir . '/config/app.php', '<?php return ' . var_export([
            'name' => 't', 'debug' => 0, 'engine' => 'twig', 'key' => 'page-nonce-test-key-0123456789abcdef',
            'url' => 'https://example.com', 'locale' => 'en', 'hypermedia' => SignalDriver::class,
        ], true) . ';');
        file_put_contents($this->dir . '/config/page_cache.php', '<?php return ' . var_export(['enabled' => true, 'explain' => true, 'verify' => 'off'], true) . ';');
        file_put_contents($this->dir . '/config/data.php', "<?php\nreturn ['default'=>'json','drivers'=>['json'=>['path'=>'{$this->dir}/storage/data']]];\n");
        file_put_contents($this->dir . '/app/pages/index.twig', '<html><head></head><body><p>' . self::WORD . '</p>'
            . '<script nonce="{{ csp_nonce() }}">window.x = 1;</script></body></html>');
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    private function app(): Application
    {
        $app = Application::create($this->dir);
        $app->setRunningInConsole(false);
        $app->runAfterResponseInHandle();
        $app->boot();

        return $app;
    }

    /** @param array<string, string> $headers */
    private function get(Application $app, array $headers): ResponseInterface
    {
        return $app->handle(new ServerRequest('GET', 'https://example.com/', $headers));
    }

    private static function cspNonce(ResponseInterface $response): string
    {
        preg_match("/'nonce-([^']+)'/", $response->getHeaderLine('Content-Security-Policy'), $m);

        return $m[1] ?? '';
    }

    #[RunInSeparateProcess]
    public function test_a_fragment_is_answered_with_its_pages_nonce_and_never_stored(): void
    {
        $app = $this->app();
        $fragment = $this->get($app, ['Signal-Request' => 'true', 'Signal-Nonce' => self::WORD]);

        $this->assertSame(self::WORD, self::cspNonce($fragment));
        $this->assertSame('refused:page-nonce', $fragment->getHeaderLine('X-Page-Cache-Skip'));
        $this->assertNotSame(self::WORD, $app->container()->get(NonceGenerator::class)->get(), 'the nonce goes back after the request');

        // Another visitor's fragment is rendered afresh: the word on the page is intact.
        $other = $this->get($app, ['Signal-Request' => 'true']);
        $this->assertStringContainsString('<p>' . self::WORD . '</p>', (string) $other->getBody());
        $this->assertNotSame(self::WORD, self::cspNonce($other));
        $hit = $this->get($this->app(), ['Signal-Request' => 'true']);
        $this->assertStringContainsString('<p>' . self::WORD . '</p>', (string) $hit->getBody());
    }

    #[RunInSeparateProcess]
    public function test_a_history_restore_a_plain_request_and_a_short_value_adopt_nothing(): void
    {
        $app = $this->app();

        foreach ([
            'restore' => ['Signal-Request' => 'true', 'Signal-Restore' => 'true', 'Signal-Nonce' => self::WORD],
            'plain' => ['Signal-Nonce' => self::WORD],
            'short' => ['Signal-Request' => 'true', 'Signal-Nonce' => 'abcdefghijklmnopqrstu'],
        ] as $case => $headers) {
            $response = $this->get($app, $headers);
            $this->assertNotSame($headers['Signal-Nonce'], self::cspNonce($response), $case);
            $this->assertNotSame('refused:page-nonce', $response->getHeaderLine('X-Page-Cache-Skip'), $case);
        }
    }
}
