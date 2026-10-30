<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Http;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Ai\AiRequestFailed;
use Preflow\Ai\Settings\AiSettingsStore;
use Preflow\Ai\TokenUsage;
use Preflow\Ai\Usage\UsageEntry;
use Preflow\Ai\Usage\UsageLog;
use Preflow\Ai\Testing\FakeAiClient;
use Preflow\Folio\Tests\Support\AiScaffold;
use Preflow\Folio\Tests\Support\PublishingScaffold;
use Preflow\I18n\Machine\TranslationSettings;
use Psr\Http\Message\ResponseInterface;

/**
 * Settings → AI: status with the Test button and usage, tuning per level, the glossary. The key
 * is never rendered; the screens need ai.settings and exist only while AI is switched on or a key
 * is present.
 */
final class AiSettingsTest extends TestCase
{
    use PublishingScaffold;
    use AiScaffold;

    protected function setUp(): void
    {
        $this->scaffoldPublishing();
        $this->aiConfig(true);
    }

    protected function tearDown(): void
    {
        putenv(self::aiKeyEnv());
        $this->removeScaffold();
    }

    /** @param array<string, mixed> $body */
    private function call(string $method, string $uri, array $body = []): ResponseInterface
    {
        $request = (new Psr17Factory())->createServerRequest($method, $uri);
        if ($method === 'POST') {
            $request = $request->withParsedBody($body + ['_csrf_token' => $this->token]);
        }

        return ($this->session ?? $this->aiApp())->handle($request);
    }

    private function settings(): TranslationSettings
    {
        return ($this->session ?? $this->aiApp())->container()->get(TranslationSettings::class);
    }

    #[RunInSeparateProcess]
    public function test_status_never_renders_key(): void
    {
        $res = $this->call('GET', '/folio/ai');
        $html = (string) $res->getBody();

        $this->assertSame(200, $res->getStatusCode(), $html);
        $this->assertStringContainsString('claude-sonnet-5-5', $html);
        $this->assertStringContainsString('Key present', $html);
        $this->assertStringNotContainsString('test-key', $html);
        $this->assertStringContainsString('action="/folio/ai/test"', $html);
        $this->assertStringContainsString('href="/folio/ai"', (string) $this->call('GET', '/folio')->getBody(), 'the nav entry');
    }

    #[RunInSeparateProcess]
    public function test_status_shows_usage_per_purpose_and_model_with_dash_for_unpriced(): void
    {
        $app = $this->aiApp();
        $log = $app->container()->get(UsageLog::class);
        $now = new \DateTimeImmutable('now');
        $log->record(new UsageEntry($now, 'translate', 'claude', 'claude-sonnet-5-5', new TokenUsage(1200, 300), 0.0054));
        $log->record(new UsageEntry($now, 'vibe', 'claude', 'mystery-model', new TokenUsage(50, 5), null));
        $log->record(new UsageEntry($now->modify('first day of last month'), 'translate', 'claude', 'claude-sonnet-5-5', new TokenUsage(10, 10), 0.01));
        $this->session = $app;

        $html = (string) $this->call('GET', '/folio/ai')->getBody();

        $this->assertMatchesRegularExpression('#<td>translate</td><td>claude-sonnet-5-5</td><td>1</td><td>1,?200</td><td>300</td><td>\$0\.0054</td>#', $html);
        $this->assertMatchesRegularExpression('#<td>vibe</td><td>mystery-model</td><td>1</td><td>50</td><td>5</td><td>—</td>#', $html);
        $this->assertStringContainsString($now->format('Y-m'), $html);
        $this->assertStringContainsString($now->modify('first day of last month')->format('Y-m'), $html);
    }

    #[RunInSeparateProcess]
    public function test_test_button_reports_ok_with_fake_client(): void
    {
        $this->script = [FakeAiClient::json(['ok' => true])];

        $res = $this->call('POST', '/folio/ai/test');
        $html = (string) $res->getBody();

        $this->assertSame(200, $res->getStatusCode(), $html);
        $this->assertStringContainsString('The connection works', $html);
        $this->assertStringNotContainsString('test-key', $html);
    }

    #[RunInSeparateProcess]
    public function test_test_button_shows_failure_instead_of_a_500(): void
    {
        $this->script = [new AiRequestFailed('Provider said no')];

        $res = $this->call('POST', '/folio/ai/test');

        $this->assertSame(200, $res->getStatusCode());
        $this->assertStringContainsString('The test request failed: Provider said no', (string) $res->getBody());
    }

    #[RunInSeparateProcess]
    public function test_screens_exist_with_switch_on_but_key_missing(): void
    {
        putenv(self::aiKeyEnv());

        $html = (string) $this->call('GET', '/folio/ai')->getBody();

        $this->assertStringContainsString('the provider&#039;s key is missing', str_replace("'", '&#039;', $html));
        $this->assertSame(200, $this->call('POST', '/folio/ai/test')->getStatusCode());
        $this->assertStringContainsString('AI is off', (string) $this->call('POST', '/folio/ai/test')->getBody());
    }

    #[RunInSeparateProcess]
    public function test_404_with_switch_off_and_no_key(): void
    {
        $this->aiConfig(false);
        putenv(self::aiKeyEnv());

        foreach (['/folio/ai', '/folio/ai/tuning', '/folio/ai/glossary'] as $uri) {
            $this->assertSame(404, $this->get($uri)->getStatusCode(), $uri);
        }
        $this->assertSame(404, $this->post('/folio/ai/test')->getStatusCode());
        $this->assertStringNotContainsString('href="/folio/ai"', (string) $this->get('/folio')->getBody());
    }

    #[RunInSeparateProcess]
    public function test_tuning_save_and_reload(): void
    {
        $res = $this->call('POST', '/folio/ai/tuning', [
            'levels' => ['project' => "Be brief\n  Use du \n", 'lang:de' => 'Informal', 'post' => ''],
            'new_key' => 'post.body:heading',
            'new_text' => 'Short headings',
        ]);
        $this->assertSame(302, $res->getStatusCode(), (string) $res->getBody());

        $levels = $this->settings()->levels();
        $this->assertSame(['Be brief', 'Use du'], $levels['project']);
        $this->assertSame(['Short headings'], $levels['post.body:heading']);

        $html = (string) $this->call('GET', '/folio/ai/tuning')->getBody();
        $this->assertStringContainsString("Be brief\nUse du</textarea>", $html);
        $this->assertStringContainsString('name="levels[lang:de]"', $html);
        $this->assertStringContainsString('name="levels[post]"', $html);
        $this->assertStringContainsString('name="levels[post.body:heading]"', $html);
        $this->assertStringContainsString('Short headings</textarea>', $html);

        // Emptying a stored level drops the edit.
        $this->call('POST', '/folio/ai/tuning', ['levels' => ['project' => '']]);
        $this->assertSame([], $this->settings()->levels()['project']);
    }

    #[RunInSeparateProcess]
    public function test_tuning_rejects_bad_field_level_key(): void
    {
        $res = $this->call('POST', '/folio/ai/tuning', ['levels' => ['project' => 'x'], 'new_key' => 'post', 'new_text' => 'y']);

        $this->assertSame(422, $res->getStatusCode());
        $this->assertStringContainsString('type.field or type.field:kind', (string) $res->getBody());
        $this->assertSame([], $this->settings()->edited(), 'nothing saved');
    }

    #[RunInSeparateProcess]
    public function test_glossary_save_keep_and_types(): void
    {
        $res = $this->call('POST', '/folio/ai/glossary', ['action' => 'save', 'rows' => [
            ['term' => 'Meeple', 'tr' => ['de' => ''], 'keep' => ['de' => '1'], 'types' => ['post'], 'note' => 'a game piece'],
            ['term' => 'Board', 'tr' => ['de' => 'Brett']],
            ['term' => '', 'tr' => ['de' => 'ignored']],
            ['term' => 'Gone', 'remove' => '1'],
        ]]);
        $this->assertSame(302, $res->getStatusCode(), (string) $res->getBody());

        $rows = $this->settings()->glossary()->toArray();
        $this->assertCount(2, $rows);
        $this->assertSame(['de' => null], $rows[0]['translations']);
        $this->assertSame(['post'], $rows[0]['types']);
        $this->assertSame('a game piece', $rows[0]['note']);
        $this->assertSame(['de' => 'Brett'], $rows[1]['translations']);

        $html = (string) $this->call('GET', '/folio/ai/glossary')->getBody();
        $this->assertStringContainsString('value="Meeple"', $html);
        $this->assertMatchesRegularExpression('#name="rows\[0\]\[keep\]\[de\]" value="1" checked#', $html);
        $this->assertStringContainsString('value="Brett"', $html);
        $this->assertMatchesRegularExpression('#<option value="post" selected>#', $html);
    }

    #[RunInSeparateProcess]
    public function test_glossary_add_row_keeps_edits_and_adds_an_empty_row(): void
    {
        $res = $this->call('POST', '/folio/ai/glossary', ['action' => 'add', 'rows' => [['term' => 'Meeple']]]);
        $html = (string) $res->getBody();

        $this->assertSame(200, $res->getStatusCode());
        $this->assertStringContainsString('name="rows[1][term]"', $html);
        $this->assertCount(1, $this->settings()->glossary()->toArray());
    }

    #[RunInSeparateProcess]
    public function test_requires_ai_settings_permission(): void
    {
        foreach (['translator', 'editor'] as $role) {
            $this->session = null;
            $this->staff($role);
            foreach (['/folio/ai', '/folio/ai/tuning', '/folio/ai/glossary'] as $uri) {
                $this->assertSame(403, $this->call('GET', $uri)->getStatusCode(), "{$role} {$uri}");
            }
            $this->assertSame(403, $this->call('POST', '/folio/ai/test')->getStatusCode());
            $this->assertSame(403, $this->call('POST', '/folio/ai/tuning', ['levels' => ['project' => 'x']])->getStatusCode());
            $this->assertStringNotContainsString('href="/folio/ai"', (string) $this->call('GET', '/folio')->getBody());
        }

        $this->session = null;
        $this->staff('aiadmin');
        $this->assertSame(200, $this->call('GET', '/folio/ai')->getStatusCode());
        $this->assertSame(200, $this->call('GET', '/folio/ai/glossary')->getStatusCode());
        $this->assertStringContainsString('href="/folio/ai"', (string) $this->call('GET', '/folio')->getBody());
    }
}
