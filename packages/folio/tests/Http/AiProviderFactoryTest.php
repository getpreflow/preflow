<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Http;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Ai\AiClient;
use Preflow\Ai\AiClientFactory;
use Preflow\Ai\AiRequest;
use Preflow\Ai\AiResponse;
use Preflow\Ai\Testing\FakeAiClient;
use Preflow\Core\Application;
use Preflow\Folio\Tests\Support\PublishingScaffold;
use Psr\Http\Message\ResponseInterface;

/**
 * Folio's Translate and Settings → AI with a provider of the app's own (config/ai.php `provider`
 * is the class of an AiClientFactory, 0.66): a keyless one, booted as configured, nothing rebound.
 */
final class AiProviderFactoryTest extends TestCase
{
    use PublishingScaffold;

    private const A = 'aaaaaaaaaaaaaaaa';

    protected function setUp(): void
    {
        $this->scaffoldPublishing();
        $this->write('config/ai.php', "<?php\nreturn ['enabled' => true, 'provider' => \\" . LocalEchoFactory::class . "::class, 'models' => [\\" . LocalEchoFactory::class . "::class => 'local-echo']];\n");
        $this->working('post', 'p1', [
            'title' => ['en' => 'Hello'],
            'slug' => ['en' => 'hello'],
            'body' => ['en' => 'Body text'],
            'sections' => [['_uid' => self::A, '_block' => 'hero', 'headline' => ['en' => 'Big news'], 'intro' => ['en' => 'Read on']]],
            '_version' => 3,
        ]);
    }

    protected function tearDown(): void
    {
        $this->removeScaffold();
    }

    private function booted(): Application
    {
        $app = Application::create($this->dir);
        $app->boot();

        return $app;
    }

    /** @param array<string, mixed> $body */
    private function send(string $method, string $uri, array $body = []): ResponseInterface
    {
        $request = (new Psr17Factory())->createServerRequest($method, $uri);
        if ($method === 'POST') {
            $request = $request->withHeader('Sec-Fetch-Site', 'same-origin')->withParsedBody($body);
        }

        return $this->booted()->handle($request);
    }

    #[RunInSeparateProcess]
    public function test_translate_suggests_through_the_factory_built_client(): void
    {
        $res = $this->send('POST', '/folio/post/p1/_ai/suggest', ['locale' => 'de', 'scope' => 'needed']);

        $this->assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        $data = json_decode((string) $res->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('DE:Hello', $data['texts']['title']['text']);
        $this->assertSame('DE:Big news', $data['texts']['sections.' . self::A . '.headline']['text']);
        $this->assertNull($data['costUsd'], 'the price table does not know local-echo: unknown, not 0, no crash');
    }

    #[RunInSeparateProcess]
    public function test_settings_show_the_provider_available_without_a_key(): void
    {
        $res = $this->send('GET', '/folio/ai');
        $html = (string) $res->getBody();

        $this->assertSame(200, $res->getStatusCode(), $html);
        $this->assertStringContainsString(htmlspecialchars(LocalEchoFactory::class), $html);
        $this->assertStringContainsString('local-echo', $html);
        $this->assertStringNotContainsString('key is missing', $html);
        $this->assertStringNotContainsString('disabled>', $html, 'the Test button is active');
    }

    #[RunInSeparateProcess]
    public function test_the_test_button_reaches_the_factory_built_client(): void
    {
        $res = $this->send('POST', '/folio/ai/test');

        $this->assertSame(200, $res->getStatusCode());
        $this->assertStringContainsString('The connection works', (string) $res->getBody());
    }

    #[RunInSeparateProcess]
    public function test_an_unavailable_factory_says_so_on_the_settings_screen(): void
    {
        $this->write('config/ai.php', "<?php\nreturn ['enabled' => true, 'provider' => \\" . LocalEchoFactory::class . "::class, 'options' => [\\" . LocalEchoFactory::class . "::class => ['down' => true]]];\n");

        $html = (string) $this->send('GET', '/folio/ai')->getBody();

        $this->assertStringContainsString('says it is not available', $html);
        $this->assertStringNotContainsString('key is missing', $html);
    }
}

/** A keyless local provider: answers the connection test with {"ok":true} and translations with "DE:" + the text. */
final class LocalEchoFactory implements AiClientFactory
{
    public function available(array $options): bool
    {
        return ($options['down'] ?? false) !== true;
    }

    public function create(array $options): AiClient
    {
        return new FakeAiClient([static function (AiRequest $r): AiResponse {
            if ($r->purpose === 'ai-check') {
                return FakeAiClient::json(['ok' => true]);
            }
            $newline = strrpos($r->prompt, "\n");
            $items = json_decode(substr($r->prompt, $newline === false ? 0 : $newline + 1), true, 512, JSON_THROW_ON_ERROR);

            return FakeAiClient::json(array_map(static fn (array $i): array => ['n' => $i['n'], 'text' => 'DE:' . $i['text']], $items));
        }], (string) $options['model']);
    }
}
