<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Http;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Ai\AiRequest;
use Preflow\Ai\AiUnavailable;
use Preflow\Ai\Testing\FakeAiClient;
use Preflow\Core\Http\Session\SessionInterface;
use Preflow\Folio\Http\AiTranslateController;
use Preflow\Folio\Tests\Support\AiScaffold;
use Preflow\Folio\Tests\Support\PublishingScaffold;
use Psr\Http\Message\ResponseInterface;

/**
 * The edit form's suggestion and estimate endpoints: units come from the type's text source,
 * nothing is written, and with AI off the routes do not exist.
 */
final class AiTranslateControllerTest extends TestCase
{
    use PublishingScaffold;
    use AiScaffold;

    private const A = 'aaaaaaaaaaaaaaaa';

    protected function setUp(): void
    {
        $this->scaffoldPublishing();
        $this->aiConfig(true);
        $this->script = [self::echo()];
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
        putenv(self::aiKeyEnv());
        $this->removeScaffold();
    }

    /** @param array<string, mixed> $body */
    private function call(string $uri, array $body): ResponseInterface
    {
        $request = (new Psr17Factory())->createServerRequest('POST', $uri)->withParsedBody($body + ['_csrf_token' => $this->token]);

        return ($this->session ?? $this->aiApp())->handle($request);
    }

    /** @return array<string, mixed> */
    private static function json(ResponseInterface $res): array
    {
        return json_decode((string) $res->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_routes_404_when_ai_off(): void
    {
        $this->aiConfig(false);

        $this->assertSame(404, $this->post('/folio/post/p1/_ai/suggest', ['locale' => 'de', 'scope' => 'needed'])->getStatusCode());
        $this->assertSame(404, $this->post('/folio/post/p1/_ai/estimate', ['locale' => 'de', 'scope' => 'needed'])->getStatusCode());
    }

    public function test_routes_404_without_key(): void
    {
        putenv(self::aiKeyEnv());

        $this->assertSame(404, $this->post('/folio/post/p1/_ai/suggest', ['locale' => 'de', 'scope' => 'needed'])->getStatusCode());
    }

    public function test_suggest_returns_texts_without_writing(): void
    {
        $before = $this->stored('post', 'p1')->toArray();

        $res = $this->call('/folio/post/p1/_ai/suggest', ['locale' => 'de', 'scope' => 'needed', 'note' => 'Keep it short']);

        $this->assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        $this->assertStringStartsWith('application/json', $res->getHeaderLine('Content-Type'));
        $data = self::json($res);
        $this->assertSame(['text' => 'DE:Hello', 'source' => 'Hello', 'previous' => null, 'warnings' => []], $data['texts']['title']);
        $this->assertSame('DE:Big news', $data['texts']['sections.' . self::A . '.headline']['text']);
        $this->assertSame('DE:Read on', $data['texts']['sections.' . self::A . '.intro']['text']);
        $this->assertSame([], $data['failed']);
        $this->assertGreaterThan(0, $data['costUsd']);
        $this->assertStringContainsString('Keep it short', print_r($this->fake->requests()[0], true), 'the note goes along');
        $after = $this->stored('post', 'p1')->toArray();
        $this->assertSame($before, $after, 'the record is unchanged');
        $this->assertSame(3, (int) $after['_version']);
    }

    public function test_suggest_cost_is_null_when_the_model_has_no_price(): void
    {
        $this->aiModel = 'unpriced-model';

        $data = self::json($this->call('/folio/post/p1/_ai/suggest', ['locale' => 'de', 'scope' => 'needed']));

        $this->assertNotEmpty($data['texts']);
        $this->assertArrayHasKey('costUsd', $data);
        $this->assertNull($data['costUsd'], 'unknown, not $0.00');
    }

    public function test_invalid_utf8_in_a_reason_is_replaced_not_a_failed_response(): void
    {
        $this->script = [new \Preflow\Ai\AiRequestFailed("Gemini returned HTTP 500: bad \xB1 byte")];

        $res = $this->call('/folio/post/p1/_ai/suggest', ['locale' => 'de', 'scope' => 'needed']);

        $this->assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        $this->assertSame("Gemini returned HTTP 500: bad \u{FFFD} byte", self::json($res)['failed']['title']);
    }

    public function test_scope_all_sends_current_translation_as_previous(): void
    {
        $this->working('post', 'p1', ['title' => ['en' => 'Hello', 'de' => 'Hallo'], 'slug' => ['en' => 'hello', 'de' => 'hallo']]);

        $needed = self::json($this->call('/folio/post/p1/_ai/suggest', ['locale' => 'de', 'scope' => 'needed']));
        $all = self::json($this->call('/folio/post/p1/_ai/suggest', ['locale' => 'de', 'scope' => 'all']));

        $this->assertArrayNotHasKey('title', $needed['texts'], 'a current translation is not needed');
        $this->assertSame('Hallo', $all['texts']['title']['previous']);
    }

    public function test_failed_units_are_listed(): void
    {
        $this->script = [self::echo(skip: 'hello')];

        $data = self::json($this->call('/folio/post/p1/_ai/suggest', ['locale' => 'de', 'scope' => 'needed']));

        $this->assertSame('DE:Hello', $data['texts']['title']['text']);
        $this->assertArrayHasKey('slug', $data['failed']);
        $this->assertIsString($data['failed']['slug']);
    }

    public function test_prefix_limits_paths(): void
    {
        $data = self::json($this->call('/folio/post/p1/_ai/suggest', ['locale' => 'de', 'scope' => 'needed', 'prefix' => 'sections']));

        $this->assertSame(['sections.' . self::A . '.headline', 'sections.' . self::A . '.intro'], array_keys($data['texts']));
        // "sections" must not match a field that merely starts with the same letters.
        $none = self::json($this->call('/folio/post/p1/_ai/suggest', ['locale' => 'de', 'scope' => 'needed', 'prefix' => 'sect']));
        $this->assertSame([], $none['texts']);
        $this->assertCount(0, $this->fake->requests(), 'nothing to translate sends nothing');
    }

    public function test_unavailable_503(): void
    {
        $this->script = [new AiUnavailable('The quota is used up.')];

        $res = $this->call('/folio/post/p1/_ai/suggest', ['locale' => 'de', 'scope' => 'needed']);

        $this->assertSame(503, $res->getStatusCode());
        $this->assertSame('The quota is used up.', self::json($res)['error']);
    }

    public function test_estimate(): void
    {
        $res = $this->call('/folio/post/p1/_ai/estimate', ['locale' => 'de', 'scope' => 'needed']);

        $this->assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        $data = self::json($res);
        $this->assertSame(5, $data['units']); // title, slug, body, the hero's headline and intro
        $this->assertSame(1, $data['requests']);
        $this->assertGreaterThan(0, $data['costUsd']);
        $this->assertCount(0, $this->fake->requests(), 'an estimate sends nothing');

        $sections = self::json($this->call('/folio/post/p1/_ai/estimate', ['locale' => 'de', 'scope' => 'needed', 'prefix' => 'sections']));
        $this->assertSame(2, $sections['units']);
    }

    public function test_bad_locale_and_unknown_record(): void
    {
        $this->assertSame(422, $this->call('/folio/post/p1/_ai/suggest', ['locale' => 'en', 'scope' => 'needed'])->getStatusCode(), 'the default language is the source');
        $this->assertSame(422, $this->call('/folio/post/p1/_ai/suggest', ['locale' => 'xx', 'scope' => 'needed'])->getStatusCode());
        $this->assertSame(422, $this->call('/folio/post/p1/_ai/suggest', ['locale' => 'de', 'scope' => 'some'])->getStatusCode());
        $this->assertSame(404, $this->call('/folio/post/nope/_ai/suggest', ['locale' => 'de', 'scope' => 'needed'])->getStatusCode());
        $this->assertSame(404, $this->call('/folio/nope/p1/_ai/suggest', ['locale' => 'de', 'scope' => 'needed'])->getStatusCode());
    }

    /** The form's flow: the autosave right after the fill marks the texts, a later save posting the accepted path clears it. */
    public function test_accept_after_autosave_clears_mark(): void
    {
        $fields = ['title' => 'DE:Hello', 'slug' => 'DE:hello', '_locale' => 'de'];
        $this->assertSame(200, $this->post('/folio/post/p1/autosave', $fields + ['_version' => '3', '_ai_unchecked' => ['title', 'slug']])->getStatusCode());
        $status = $this->app()->container()->get(\Preflow\Folio\Content\TranslationStatus::class);
        $def = $this->dm()->getTypeRegistry()->get('post');
        $paths = fn (): array => array_map(static fn ($t): string => $t->path, $status->unchecked($def, $this->stored('post', 'p1'), 'de'));
        $this->assertSame(['title', 'slug'], $paths());

        $version = (string) $this->stored('post', 'p1')->get('_version');
        $this->assertSame(200, $this->post('/folio/post/p1/autosave', $fields + ['_version' => $version, '_ai_unchecked' => ['slug'], '_ai_checked' => ['title']])->getStatusCode());

        $this->assertSame(['slug'], $paths());
    }

    #[RunInSeparateProcess]
    public function test_post_without_csrf_token_refused(): void
    {
        $this->staff('translator');
        $request = (new Psr17Factory())->createServerRequest('POST', '/folio/post/p1/_ai/suggest')->withParsedBody(['locale' => 'de', 'scope' => 'needed']);

        $this->assertSame(403, $this->session->handle($request)->getStatusCode());
        $this->assertSame(403, $this->session->handle($request->withUri($request->getUri()->withPath('/folio/post/p1/_ai/estimate')))->getStatusCode());
        $this->assertCount(0, $this->fake->requests());
        $this->assertSame(200, $this->call('/folio/post/p1/_ai/suggest', ['locale' => 'de', 'scope' => 'needed'])->getStatusCode(), 'with the token it passes');
    }

    #[RunInSeparateProcess]
    public function test_403_without_permission(): void
    {
        $this->staff('editor');
        $this->assertSame(403, $this->call('/folio/post/p1/_ai/suggest', ['locale' => 'de', 'scope' => 'needed'])->getStatusCode(), 'edit without ai.translate');
        $this->assertSame(403, $this->call('/folio/post/p1/_ai/estimate', ['locale' => 'de', 'scope' => 'needed'])->getStatusCode());
        $this->assertCount(0, $this->fake->requests());
    }

    #[RunInSeparateProcess]
    public function test_403_without_edit_permission(): void
    {
        $this->staff('reader');
        $this->assertSame(403, $this->call('/folio/post/p1/_ai/suggest', ['locale' => 'de', 'scope' => 'needed'])->getStatusCode(), 'ai.translate without post.edit');
    }

    #[RunInSeparateProcess]
    public function test_translator_may_suggest(): void
    {
        $this->staff('translator');
        $res = $this->call('/folio/post/p1/_ai/suggest', ['locale' => 'de', 'scope' => 'needed']);
        $this->assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        $this->assertSame('DE:Hello', self::json($res)['texts']['title']['text']);
    }

    public function test_suggest_releases_the_session_before_asking_the_provider(): void
    {
        // A file session stays locked while it is open: every other request of the same browser
        // would wait for the minutes a translation takes.
        $closed = false;
        $session = $this->createMock(SessionInterface::class);
        $session->expects($this->once())->method('close')->willReturnCallback(static function () use (&$closed): void {
            $closed = true;
        });
        $echo = self::echo();
        $asked = 0;
        $this->script = [function (AiRequest $r) use (&$closed, &$asked, $echo) {
            $asked++;
            $this->assertTrue($closed, 'the session is closed before the provider is asked');

            return $echo($r);
        }];
        $controller = $this->aiApp()->container()->get(AiTranslateController::class);
        $request = (new Psr17Factory())->createServerRequest('POST', '/folio/post/p1/_ai/suggest')
            ->withParsedBody(['locale' => 'de', 'scope' => 'needed'])
            ->withAttribute('type', 'post')
            ->withAttribute('id', 'p1')
            ->withAttribute(SessionInterface::class, $session);

        $res = $controller->suggest($request);

        $this->assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        $this->assertGreaterThan(0, $asked);
    }
}
