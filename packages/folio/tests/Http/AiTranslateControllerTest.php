<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Http;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Ai\AiUnavailable;
use Preflow\Ai\Testing\FakeAiClient;
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
}
