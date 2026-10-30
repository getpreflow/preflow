<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Http;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Ai\Testing\FakeAiClient;
use Preflow\Folio\Tests\Support\AiScaffold;
use Preflow\Folio\Tests\Support\AssetFixtures;
use Preflow\Folio\Tests\Support\PublishingScaffold;
use Preflow\Folio\Translations\StoredOverrides;
use Preflow\Media\Asset;
use Preflow\Media\MediaLibrary;
use Psr\Http\Message\ResponseInterface;

/**
 * The suggestion endpoints of Settings → Translations (interface labels of the site's editable
 * group "shop") and of the media panel (alt texts): units come from the labels and the asset,
 * nothing is written, and with AI off the buttons and routes do not exist.
 */
final class AiLabelsAndMediaTest extends TestCase
{
    use PublishingScaffold;
    use AiScaffold;
    use AssetFixtures;

    protected function setUp(): void
    {
        $this->scaffoldPublishing();
        $this->aiConfig(true);
        $this->script = [self::echo()];
        $this->write('config/folio.php', "<?php\nreturn ['path' => '/folio', 'translations' => ['editable' => ['shop']]];\n");
        $this->write('lang/en/shop.php', "<?php\nreturn ['hello' => 'Hello :name', 'bye' => 'Goodbye', 'thanks' => 'Thanks', 'empty' => ''];\n");
        $this->write('lang/de/shop.php', "<?php\nreturn ['bye' => 'Tschüss'];\n");
        $this->overrides()->set('de', 'shop.thanks', 'Danke', null);
    }

    protected function tearDown(): void
    {
        putenv(self::aiKeyEnv());
        $this->removeScaffold();
    }

    private function overrides(): StoredOverrides
    {
        return $this->app()->container()->get(StoredOverrides::class);
    }

    private function library(array $config = [], ?\Preflow\Media\Image\VariantGenerator $variants = null, ?\Preflow\Media\Usage\AssetUsage $usage = null): MediaLibrary
    {
        return $this->app()->container()->get(MediaLibrary::class);
    }

    private function asset(array $alt = ['en' => 'A red door', 'de' => '']): Asset
    {
        $lib = $this->library();
        $asset = $lib->upload($this->upload($this->png(), 'door.png', 'image/png'), null);

        return $lib->update($asset->id, ['alt' => $alt, 'title' => 'The door']);
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

    public function test_labels_needed_only_empty_overrides(): void
    {
        $res = $this->call('/folio/translations/_ai/suggest', ['group' => 'shop', 'locale' => 'de', 'scope' => 'needed']);

        $this->assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        $data = self::json($res);
        $this->assertSame(['shop.hello'], array_keys($data['texts']), 'bye has a German text, thanks an override, empty no source');
        $this->assertSame(['text' => 'DE:Hello :name', 'source' => 'Hello :name', 'previous' => null, 'warnings' => []], $data['texts']['shop.hello']);
        $this->assertSame([], $data['failed']);

        $all = self::json($this->call('/folio/translations/_ai/suggest', ['group' => 'shop', 'locale' => 'de', 'scope' => 'all']));
        $this->assertSame(['shop.bye', 'shop.hello', 'shop.thanks'], array_keys($all['texts']));
        $this->assertSame('Danke', $all['texts']['shop.thanks']['previous'], 'an override is the previous text');
        $this->assertSame('Tschüss', $all['texts']['shop.bye']['previous']);
    }

    public function test_labels_placeholders_preserved_or_failed(): void
    {
        $drop = static fn (\Preflow\Ai\AiRequest $r): \Preflow\Ai\AiResponse => FakeAiClient::json(array_map(
            static fn (array $i): array => ['n' => $i['n'], 'text' => 'Hallo'],
            json_decode(substr($r->prompt, (int) strrpos($r->prompt, "\n") + 1), true, 512, JSON_THROW_ON_ERROR),
        ));
        $this->script = [$drop, $drop, $drop, $drop];

        $data = self::json($this->call('/folio/translations/_ai/suggest', ['group' => 'shop', 'locale' => 'de', 'scope' => 'needed']));

        $this->assertSame([], $data['texts'], 'a translation that lost :name is not offered');
        $this->assertArrayHasKey('shop.hello', $data['failed']);

        $this->script = [self::echo()];
        $ok = self::json($this->call('/folio/translations/_ai/suggest', ['group' => 'shop', 'locale' => 'de', 'scope' => 'needed']));
        $this->assertSame('DE:Hello :name', $ok['texts']['shop.hello']['text'], 'the placeholder survives');
    }

    public function test_labels_bad_input(): void
    {
        $this->assertSame(422, $this->call('/folio/translations/_ai/suggest', ['group' => 'shop', 'locale' => 'xx', 'scope' => 'needed'])->getStatusCode());
        $this->assertSame(422, $this->call('/folio/translations/_ai/suggest', ['group' => 'nope', 'locale' => 'de', 'scope' => 'needed'])->getStatusCode());
        $this->assertSame(422, $this->call('/folio/translations/_ai/suggest', ['group' => 'shop', 'locale' => 'de', 'scope' => 'some'])->getStatusCode());
        $this->assertSame(422, $this->call('/folio/translations/_ai/suggest', ['group' => 'shop', 'locale' => 'en', 'scope' => 'all'])->getStatusCode(), 'English is the source');
        $this->assertCount(0, $this->fake->requests());
    }

    public function test_media_alt_suggest(): void
    {
        $asset = $this->asset();

        $res = $this->call('/folio/assets/' . $asset->id . '/_ai/suggest', ['locale' => 'de']);

        $this->assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        $data = self::json($res);
        $this->assertSame(['alt.de'], array_keys($data['texts']));
        $this->assertSame(['text' => 'DE:A red door', 'source' => 'A red door', 'previous' => null, 'warnings' => []], $data['texts']['alt.de']);
        $this->assertSame([], $data['failed']);

        $this->assertSame(422, $this->call('/folio/assets/' . $asset->id . '/_ai/suggest', ['locale' => 'en'])->getStatusCode(), 'the default language is the source');
        $this->assertSame(422, $this->call('/folio/assets/' . $asset->id . '/_ai/suggest', ['locale' => 'xx'])->getStatusCode());
        $this->assertSame(404, $this->call('/folio/assets/nope/_ai/suggest', ['locale' => 'de'])->getStatusCode());
    }

    public function test_media_without_default_alt_has_nothing_to_translate(): void
    {
        $asset = $this->asset(['en' => '', 'de' => '']);

        $data = self::json($this->call('/folio/assets/' . $asset->id . '/_ai/suggest', ['locale' => 'de']));

        $this->assertSame([], $data['texts']);
        $this->assertCount(0, $this->fake->requests());
    }

    public function test_never_writes(): void
    {
        $asset = $this->asset();
        $before = $this->overrides()->all('de');

        $this->call('/folio/translations/_ai/suggest', ['group' => 'shop', 'locale' => 'de', 'scope' => 'all']);
        $this->call('/folio/assets/' . $asset->id . '/_ai/suggest', ['locale' => 'de']);

        $this->assertSame($before, $this->overrides()->all('de'));
        $this->assertSame('', $this->library()->find($asset->id)->alt['de'] ?? '');
        $this->assertSame('The door', $this->library()->find($asset->id)->title);
    }

    public function test_buttons_shown_when_ai_on(): void
    {
        $asset = $this->asset();
        $app = $this->aiApp();
        $get = static fn (string $uri): string => (string) $app->handle((new Psr17Factory())->createServerRequest('GET', $uri))->getBody();

        $labels = $get('/folio/translations?group=shop&locale=de');
        $this->assertStringContainsString('data-ai-translate', $labels);
        $this->assertStringContainsString('/folio/translations/_ai/suggest', $labels);
        $this->assertStringContainsString('data-ai-path="shop.hello"', $labels);
        $this->assertStringNotContainsString('data-ai-translate', $get('/folio/translations?group=shop&locale=en'), 'no button for the source language');

        $panel = $get('/folio/assets/' . $asset->id);
        $this->assertStringContainsString('data-ai-translate', $panel);
        $this->assertStringContainsString('/folio/assets/' . $asset->id . '/_ai/suggest', $panel);
    }

    public function test_source_language_says_choose_a_target(): void
    {
        $app = $this->aiApp();
        $get = static fn (string $uri): string => (string) $app->handle((new Psr17Factory())->createServerRequest('GET', $uri))->getBody();

        $this->assertStringContainsString('Choose another language', $get('/folio/translations?group=shop&locale=en'));
        $this->assertStringNotContainsString('Choose another language', $get('/folio/translations?group=shop&locale=de'));
    }

    public function test_hidden_and_404_when_ai_off(): void
    {
        $asset = $this->asset();
        $this->aiConfig(false);

        $this->assertSame(404, $this->post('/folio/translations/_ai/suggest', ['group' => 'shop', 'locale' => 'de', 'scope' => 'needed'])->getStatusCode());
        $this->assertSame(404, $this->post('/folio/assets/' . $asset->id . '/_ai/suggest', ['locale' => 'de'])->getStatusCode());
        $this->assertStringNotContainsString('data-ai-translate', (string) $this->get('/folio/translations?group=shop&locale=de')->getBody());
        $this->assertStringNotContainsString('data-ai-translate', (string) $this->get('/folio/assets/' . $asset->id)->getBody());
        $this->assertStringContainsString('shop.hello', (string) $this->get('/folio/translations?group=shop&locale=de')->getBody(), 'the screen itself works');
    }

    #[RunInSeparateProcess]
    public function test_403_without_permission(): void
    {
        $this->staff('translator'); // post.edit and ai.translate, but neither translations.edit nor assets.edit
        $this->assertSame(403, $this->call('/folio/translations/_ai/suggest', ['group' => 'shop', 'locale' => 'de', 'scope' => 'needed'])->getStatusCode());
        $this->assertSame(403, $this->call('/folio/assets/any/_ai/suggest', ['locale' => 'de'])->getStatusCode());
        $this->assertCount(0, $this->fake->requests());
    }
}
