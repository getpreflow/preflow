<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Http;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Ai\AiRequest;
use Preflow\Ai\AiResponse;
use Preflow\Ai\AiUnavailable;
use Preflow\Ai\StopReason;
use Preflow\Ai\TokenUsage;
use Preflow\Folio\Content\TranslationStatus;
use Preflow\Folio\Tests\Support\AiScaffold;
use Preflow\Folio\Tests\Support\PublishingScaffold;
use Psr\Http\Message\ResponseInterface;

/**
 * "Write a draft" in rich text: POST {prefix}/_ai/draft writes nothing, answers sanitised HTML and
 * the cost, exists only while the draft writer is available, and asks ai.draft plus the type's
 * edit permission. The edit form loads the button's script only with a rich-text field, AI on and
 * both permissions.
 */
final class AiDraftRouteTest extends TestCase
{
    use PublishingScaffold;
    use AiScaffold;

    private const DRAFTER = ['folio.dashboard', 'article.view', 'article.edit', 'ai.draft'];

    protected function setUp(): void
    {
        $this->scaffoldPublishing();
        $this->write('config/blocks.php', "<?php\nreturn ['classes' => ["
            . "\\Preflow\\Folio\\Tests\\Fixtures\\Blocks\\HeroBlock\\HeroBlock::class, \\Preflow\\Folio\\Tests\\Fixtures\\Blocks\\LinkBlock::class, \\Preflow\\Folio\\Tests\\Fixtures\\Blocks\\TradeoffNoteBlock::class, "
            . "\\Preflow\\Folio\\Tests\\Fixtures\\Blocks\\ProseBlock\\ProseBlock::class, \\Preflow\\Folio\\Tests\\Fixtures\\Blocks\\QuoteBlock::class]];\n");
        $this->writeModel('article', [
            'label' => 'Articles',
            'fields' => [
                'title' => ['type' => 'string', 'translatable' => true, 'validate' => ['required']],
                'body' => ['type' => 'richtext', 'translatable' => true],
            ],
        ]);
        $this->writeModel('page', [
            'label' => 'Pages',
            'fields' => [
                'title' => ['type' => 'string', 'translatable' => true],
                'sections' => ['type' => 'blocks', 'blocks' => ['allowed' => ['prose']]],
            ],
        ]);
        $this->aiConfig(true);
        $this->script = [self::answer('<h2>Hours</h2><p>We open at nine.</p>')];
        $this->working('article', 'a1', ['title' => ['en' => 'Hello', 'de' => 'Hallo'], 'body' => ['en' => '<p>Old</p>']]);
        $this->working('page', 'g1', ['title' => ['en' => 'Home']]);
    }

    protected function tearDown(): void
    {
        putenv(self::aiKeyEnv());
        $this->removeScaffold();
    }

    private static function answer(string $html, StopReason $stop = StopReason::Complete, TokenUsage $usage = new TokenUsage(1000, 500)): AiResponse
    {
        return new AiResponse($html, $stop, $usage, 'fake', 'claude-sonnet-5-5');
    }

    /** @param array<string, mixed> $body */
    private function draft(array $body = []): ResponseInterface
    {
        $body += ['type' => 'article', 'field' => 'Body', 'brief' => 'Our opening hours', 'length' => 'short', 'language' => 'en', 'title' => 'Hello'];
        $request = (new Psr17Factory())->createServerRequest('POST', '/folio/_ai/draft')->withHeader('Sec-Fetch-Site', 'same-origin')->withParsedBody($body + ['_csrf_token' => $this->token]);

        return ($this->session ?? $this->aiApp())->handle($request);
    }

    /** @return array<string, mixed> */
    private static function json(ResponseInterface $res): array
    {
        return json_decode((string) $res->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function editForm(string $uri): string
    {
        $request = (new Psr17Factory())->createServerRequest('GET', $uri);
        parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);
        $res = ($this->session ?? $this->aiApp())->handle($request->withQueryParams($query));
        $this->assertSame(200, $res->getStatusCode());

        return (string) $res->getBody();
    }

    /** The markup without what differs per request: nonces, tokens and the debug bar's timings. */
    private static function normalized(string $html): string
    {
        return (string) preg_replace(
            ['/nonce="[^"]*"/', '/name="csp-nonce" content="[^"]*"/', '/"inlineStyleNonce":"[^"]*"/', '/name="_csrf_token" value="[^"]*"/', '/[\d.]+\s?(ms|KB|MB|B)\b/'],
            ['nonce=""', 'csp', 'nonce', 'csrf', 'N'],
            $html,
        );
    }

    public function test_writes_a_draft_without_saving_anything(): void
    {
        $res = $this->draft(['language' => 'de', 'length' => 'short']);

        $this->assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        $data = self::json($res);
        $this->assertSame('<h2>Hours</h2><p>We open at nine.</p>', $data['html']);
        $this->assertSame('< $0.01', $data['cost']);
        $request = $this->fake->requests()[0];
        $this->assertInstanceOf(AiRequest::class, $request);
        $this->assertSame('draft', $request->purpose);
        $this->assertSame(600, $request->maxOutputTokens);
        $this->assertStringContainsString('"de"', $request->tuning->system());
        $prompt = json_decode($request->prompt, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('Our opening hours', $prompt['brief']);
        $this->assertSame(['Content type' => 'Articles', 'Title' => 'Hello', 'Field' => 'Body'], $prompt['context']);
        $this->assertSame(['en' => '<p>Old</p>'], $this->stored('article', 'a1')->get('body'), 'nothing written');
    }

    public function test_html_is_sanitised(): void
    {
        $this->script = [self::answer('<p onclick="steal()">Hi<script>alert(1)</script></p><img src="x" onerror="steal()">'
            . '<a href="javascript:alert(1)">bad</a><a href="https://example.com/">good</a><iframe src="https://evil.test"></iframe><h2 style="color:red">T</h2>')];

        $html = self::json($this->draft())['html'];

        foreach (['<script', 'onclick', 'onerror', 'javascript:', '<iframe', 'style=', 'steal()'] as $bad) {
            $this->assertStringNotContainsString($bad, $html);
        }
        $this->assertStringContainsString('<a href="https://example.com/">good</a>', $html);
        $this->assertStringContainsString('<h2>T</h2>', $html);
        $this->assertStringContainsString('Hi', $html);
    }

    public function test_cost_line(): void
    {
        $this->script = [self::answer('<p>x</p>', usage: new TokenUsage(100000, 100000))];
        $this->assertSame('$1.20', self::json($this->draft())['cost'], '0.2 input + 1.0 output');
    }

    public function test_unpriced_model_has_no_cost(): void
    {
        $this->aiModel = 'unpriced-model';
        $this->script = [new AiResponse('<p>x</p>', StopReason::Complete, new TokenUsage(10, 10), 'fake', 'unpriced-model')];

        $data = self::json($this->draft());
        $this->assertArrayHasKey('cost', $data);
        $this->assertNull($data['cost'], 'unknown, never $0.00');
    }

    public function test_unavailable_answers_503(): void
    {
        $this->script = [new AiUnavailable('The provider has no credit left.')];

        $res = $this->draft();
        $this->assertSame(503, $res->getStatusCode());
        $this->assertStringContainsString('no credit', self::json($res)['error']);
    }

    public function test_a_failed_draft_answers_502_with_the_reason(): void
    {
        $this->script = [self::answer('<p>half', StopReason::Truncated)];

        $res = $this->draft();
        $this->assertSame(502, $res->getStatusCode());
        $this->assertStringContainsString('cut off', self::json($res)['error']);
        $this->assertArrayNotHasKey('html', self::json($res));
    }

    public function test_an_answer_with_nothing_left_after_sanitising_fails(): void
    {
        $this->script = [self::answer('<script>alert(1)</script>')];

        $this->assertSame(502, $this->draft()->getStatusCode());
    }

    public function test_bad_input_is_refused_before_any_request(): void
    {
        foreach ([
            [422, ['brief' => '   '], 'no brief'],
            [422, ['brief' => str_repeat('x', 2001)], 'too long'],
            [422, ['length' => 'huge'], 'no such length'],
            [422, ['language' => 'fr'], 'not a language of the type'],
            [404, ['type' => 'nope'], 'no such type'],
        ] as [$status, $body, $why]) {
            $this->assertSame($status, $this->draft($body)->getStatusCode(), $why);
            $this->assertCount(0, $this->fake->requests(), $why);
        }
    }

    public function test_route_does_not_exist_while_ai_is_off(): void
    {
        $this->aiConfig(false);
        putenv(self::aiKeyEnv());

        $res = $this->app()->handle((new Psr17Factory())->createServerRequest('POST', '/folio/_ai/draft')->withHeader('Sec-Fetch-Site', 'same-origin')
            ->withParsedBody(['type' => 'article', 'field' => 'Body', 'brief' => 'x', 'length' => 'short', 'language' => 'en']));
        $this->assertSame(404, $res->getStatusCode());
    }

    public function test_no_button_while_ai_is_off_and_the_form_is_unchanged(): void
    {
        unlink($this->dir . '/config/ai.php');
        putenv(self::aiKeyEnv());
        $without = $this->get('/folio/article/a1/edit');
        $this->aiConfig(false);
        putenv(self::aiKeyEnv());
        $off = $this->get('/folio/article/a1/edit');

        $this->assertSame(200, $off->getStatusCode());
        $this->assertSame(self::normalized((string) $without->getBody()), self::normalized((string) $off->getBody()));
        $this->assertStringNotContainsString('ai-draft', (string) $off->getBody());
    }

    public function test_button_script_and_dialog_on_a_form_with_rich_text(): void
    {
        $html = $this->editForm('/folio/article/a1/edit?locale=de');

        $this->assertMatchesRegularExpression('#<script src="[^"]*ai-draft-core\.js[^"]*" defer></script>\s*<script src="[^"]*ai-draft\.js[^"]*" defer>#', $html);
        $this->assertStringContainsString('data-ai-draft', $html);
        $this->assertStringContainsString('data-url="/folio/_ai/draft"', $html);
        $this->assertStringContainsString('data-type="article"', $html);
        $this->assertStringContainsString('data-language="de"', $html, 'the tab\'s language');
        $this->assertStringContainsString('data-title="Hallo"', $html);
        $this->assertStringContainsString('<dialog class="folio-ai-draft-dialog"', $html);
        // The dialog sits outside the record form: its brief is never posted or autosaved.
        $this->assertMatchesRegularExpression('#</form>.*data-ai-draft#s', $html);
    }

    public function test_button_script_with_rich_text_inside_blocks_only(): void
    {
        $this->assertStringContainsString('ai-draft.js', $this->editForm('/folio/page/g1/edit'), 'cards with rich text may be added later');
    }

    public function test_no_button_on_a_form_without_rich_text(): void
    {
        $this->assertStringNotContainsString('ai-draft', $this->editForm('/folio/note/new'));
    }

    #[RunInSeparateProcess]
    public function test_with_ai_draft_and_type_edit_it_writes(): void
    {
        $this->staff('drafter', [
            'drafter' => self::DRAFTER,
            'noai' => ['folio.dashboard', 'article.view', 'article.edit'],
            'noedit' => ['folio.dashboard', 'article.view', 'ai.draft'],
        ]);
        $this->assertSame(200, $this->draft()->getStatusCode());
        $this->assertCount(1, $this->fake->requests());
    }

    #[RunInSeparateProcess]
    public function test_without_ai_draft_refused_and_no_button(): void
    {
        $this->staff('noai', ['noai' => ['folio.dashboard', 'article.view', 'article.edit']]);

        $this->assertSame(403, $this->draft()->getStatusCode());
        $this->assertStringNotContainsString('ai-draft', $this->editForm('/folio/article/a1/edit'));
        $this->assertCount(0, $this->fake->requests());
    }

    #[RunInSeparateProcess]
    public function test_without_type_edit_refused_and_no_button(): void
    {
        $this->staff('noedit', ['noedit' => ['folio.dashboard', 'article.view', 'ai.draft']]);

        $this->assertSame(403, $this->draft()->getStatusCode());
        $this->assertStringNotContainsString('ai-draft', $this->editForm('/folio/article/a1/edit'));
        $this->assertCount(0, $this->fake->requests());
    }

    #[RunInSeparateProcess]
    public function test_post_without_csrf_token_refused(): void
    {
        $this->staff('drafter', ['drafter' => self::DRAFTER]);
        $request = (new Psr17Factory())->createServerRequest('POST', '/folio/_ai/draft')->withHeader('Sec-Fetch-Site', 'same-origin')
            ->withCookieParams([session_name() => session_id()])
            ->withParsedBody(['type' => 'article', 'field' => 'Body', 'brief' => 'x', 'length' => 'short', 'language' => 'en']);

        $this->assertSame(403, $this->session->handle($request)->getStatusCode());
        $this->assertCount(0, $this->fake->requests());
    }

    #[RunInSeparateProcess]
    public function test_the_admin_role_has_it(): void
    {
        $this->staff('everything', ['everything' => ['*']]);

        $this->assertSame(200, $this->draft()->getStatusCode());
    }

    public function test_a_draft_saved_in_another_language_is_not_marked_machine_translated(): void
    {
        $html = self::json($this->draft(['language' => 'de']))['html'];

        $save = $this->aiApp()->handle((new Psr17Factory())->createServerRequest('POST', '/folio/article/a1')->withHeader('Sec-Fetch-Site', 'same-origin')->withParsedBody([
            '_locale' => 'de', 'title' => 'Hallo', 'body' => $html,
        ]));
        $this->assertContains($save->getStatusCode(), [200, 302, 303], (string) $save->getBody());

        $stored = $this->stored('article', 'a1');
        $this->assertSame($html, $stored->get('body')['de'] ?? null);
        $this->assertNull($stored->get(TranslationStatus::MACHINE));
    }
}
