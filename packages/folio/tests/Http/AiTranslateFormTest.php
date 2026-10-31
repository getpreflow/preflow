<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Http;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Folio\Tests\Support\AiScaffold;
use Preflow\Folio\Tests\Support\PublishingScaffold;
use Preflow\Folio\Content\TranslationStatus;
use Preflow\Folio\FolioText;
use Preflow\Folio\Http\AdminFormat;
use Preflow\I18n\Machine\JobRunner;
use Preflow\I18n\Machine\Scope;
use Preflow\I18n\Machine\TextSources;

/**
 * The Translate button on the edit form: only on a translation tab with AI on and the
 * permissions; it queues a background job, and the form says while one is pending.
 */
final class AiTranslateFormTest extends TestCase
{
    use PublishingScaffold;
    use AiScaffold;

    private const A = 'aaaaaaaaaaaaaaaa';
    private const C = 'cccccccccccccccc';

    protected function setUp(): void
    {
        $this->scaffoldPublishing();
        $this->working('post', 'p1', [
            'title' => ['en' => 'Hello'],
            'slug' => ['en' => 'hello'],
            'body' => ['en' => 'Body text'],
            'sections' => [[
                '_uid' => self::A,
                '_block' => 'hero',
                'headline' => ['en' => 'Big news'],
                'intro' => ['en' => 'Read on'],
                'tradeoff' => [['_uid' => self::C, '_block' => 'tradeoff_note', 'chose' => ['en' => 'Speed']]],
            ]],
        ]);
    }

    protected function tearDown(): void
    {
        putenv(self::aiKeyEnv());
        $this->removeScaffold();
    }

    private function editForm(string $locale): string
    {
        $request = (new Psr17Factory())->createServerRequest('GET', '/folio/post/p1/edit?locale=' . $locale)->withQueryParams(['locale' => $locale]);
        $res = ($this->session ?? $this->aiApp())->handle($request);
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

    public function test_form_renders_unchanged_when_ai_off(): void
    {
        $without = $this->get('/folio/post/p1/edit?locale=de');
        $this->assertSame(200, $without->getStatusCode());
        $this->aiConfig(false);
        putenv(self::aiKeyEnv()); // AI off means neither the switch nor a key: a key alone still lists Settings → AI
        $off = $this->get('/folio/post/p1/edit?locale=de');

        $this->assertSame(self::normalized((string) $without->getBody()), self::normalized((string) $off->getBody()));
        $this->assertStringNotContainsString('data-ai-translate', (string) $off->getBody());
        $this->assertStringNotContainsString('ai-translate', (string) $off->getBody());
    }

    public function test_button_on_translation_tab_only(): void
    {
        $this->aiConfig(true);

        $de = $this->editForm('de');
        $this->assertStringContainsString('data-ai-translate', $de);
        $this->assertStringContainsString('data-queue-url="/folio/post/p1/_ai/queue"', $de);
        $this->assertStringContainsString('cd ' . $this->dir . ' &amp;&amp; php vendor/bin/preflow translate:work', $de, 'the cron hint names the app');
        $this->assertStringNotContainsString('data-suggest-url', $de, 'the record form no longer fills its inputs');
        $this->assertStringNotContainsString('data-ai-review-template', $de);
        $this->assertStringNotContainsString('name="confirm_live"', $de, 'posts are saved as working copies');
        $this->assertStringContainsString('data-estimate-url="/folio/post/p1/_ai/estimate"', $de);
        $this->assertStringContainsString('data-locale="de"', $de);
        $this->assertMatchesRegularExpression('#<script src="[^"]*ai-translate\.js[^"]*" defer>#', $de);
        $this->assertStringContainsString('<dialog', $de);

        $this->assertStringNotContainsString('data-ai-translate', $this->editForm('en'), 'the default language is the source');
    }

    public function test_no_button_on_create_form(): void
    {
        $this->aiConfig(true);
        $res = $this->aiApp()->handle((new Psr17Factory())->createServerRequest('GET', '/folio/post/new?locale=de')->withQueryParams(['locale' => 'de']));

        $this->assertStringNotContainsString('data-ai-translate', (string) $res->getBody());
    }

    /** The JS turns path a.b.c into the input name a[b][c]: every unit's input must be on the tab's form. */
    public function test_every_unit_path_names_an_input_of_the_form(): void
    {
        $this->aiConfig(true);
        $app = $this->aiApp();
        $units = $app->container()->get(TextSources::class)->get('post')->units('p1', 'de', Scope::All);
        $paths = array_map(static fn ($u): string => $u->id, $units);
        $this->assertContains('sections.' . self::A . '.tradeoff.' . self::C . '.chose', $paths, 'nested block leaves are units');

        $html = $this->editForm('de');
        foreach ($paths as $path) {
            $parts = explode('.', $path);
            $name = array_shift($parts) . implode('', array_map(static fn (string $p): string => '[' . $p . ']', $parts));
            $this->assertStringContainsString('name="' . htmlspecialchars($name) . '"', $html, "input for {$path}");
        }
    }

    public function test_form_shows_pending_translation(): void
    {
        $this->aiConfig(true);
        $app = $this->aiApp();
        $runner = $app->container()->get(JobRunner::class);
        $job = $runner->start($runner->plan('post', ['de'], Scope::Needed, ['p1'])->id);

        $html = $this->editForm('de');

        $since = (new AdminFormat())->date($job->queuedAt, 'datetime');
        $line = FolioText::get('folio.ai_queue.pending', ['language' => FolioText::get('folio.languages.de'), 'since' => $since]);
        $this->assertStringContainsString(htmlspecialchars($line), $html);
        $this->assertStringContainsString(htmlspecialchars($line), $this->editForm('en'), 'shown on every language tab');
    }

    public function test_form_shows_how_far_a_running_translation_is(): void
    {
        $this->aiConfig(true);
        $app = $this->aiApp();
        $runner = $app->container()->get(JobRunner::class);
        $job = $runner->start($runner->plan('post', ['de'], Scope::Needed, ['p1'])->id);
        $app->container()->get(\Preflow\I18n\Machine\JobStore::class)->update($job->id, static function (\Preflow\I18n\Machine\Job $j): void {
            $j->state = 'running';
            $j->items['p1']['progress'] = ['locale' => 'de', 'done' => 120, 'total' => 410];
        });

        $line = FolioText::get('folio.ai_queue.pending_running', ['language' => FolioText::get('folio.languages.de'), 'done' => 120, 'total' => 410]);
        $this->assertStringContainsString('120 of 410 texts', $line);
        $this->assertStringContainsString(htmlspecialchars($line), $this->editForm('de'));
    }

    public function test_posted_ai_paths_are_ignored(): void
    {
        $this->aiConfig(true);

        $res = $this->post('/folio/post/p1/autosave', ['title' => 'Hallo', 'slug' => 'hallo', '_locale' => 'de', '_version' => '0', '_ai_unchecked' => ['title'], '_ai_checked' => ['slug']]);

        $this->assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        $stored = $this->stored('post', 'p1');
        $this->assertSame('Hallo', $stored->get('title')['de'] ?? null);
        $this->assertSame([], $this->app()->container()->get(TranslationStatus::class)->unchecked($this->dm()->getTypeRegistry()->get('post'), $stored, 'de'));
        $this->assertEmpty($stored->get(TranslationStatus::MACHINE), 'no mark saved');
    }

    public function test_live_type_dialog_asks_to_confirm(): void
    {
        $this->aiConfig(true);
        $this->writeModel('faq', ['label' => 'FAQ', 'fields' => ['question' => ['type' => 'string', 'translatable' => true]]]);
        $this->working('faq', 'f1', ['question' => ['en' => 'Why?']]);

        $res = $this->aiApp()->handle((new Psr17Factory())->createServerRequest('GET', '/folio/faq/f1/edit?locale=de')->withQueryParams(['locale' => 'de']));

        $this->assertStringContainsString('name="confirm_live" value="1"', (string) $res->getBody());
    }

    #[RunInSeparateProcess]
    public function test_no_button_without_ai_permission(): void
    {
        $this->aiConfig(true);
        $this->staff('editor');

        $this->assertStringNotContainsString('data-ai-translate', $this->editForm('de'));
    }

    #[RunInSeparateProcess]
    public function test_no_button_without_edit_permission(): void
    {
        $this->aiConfig(true);
        $this->staff('reader');

        $this->assertStringNotContainsString('data-ai-translate', $this->editForm('de'));
    }

    #[RunInSeparateProcess]
    public function test_button_for_translator(): void
    {
        $this->aiConfig(true);
        $this->staff('translator');

        $this->assertStringContainsString('data-ai-translate', $this->editForm('de'));
    }
}
