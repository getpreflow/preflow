<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Http;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Folio\Tests\Support\AiScaffold;
use Preflow\Folio\Tests\Support\PublishingScaffold;
use Preflow\I18n\Machine\Scope;
use Preflow\I18n\Machine\TextSources;

/**
 * The Translate button on the edit form: only on a translation tab with AI on and the
 * permissions, and every path the endpoint can return names an input of that tab's form.
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
        $this->assertStringContainsString('data-suggest-url="/folio/post/p1/_ai/suggest"', $de);
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
