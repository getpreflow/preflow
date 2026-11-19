<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Integration;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Auth\Access\Access;
use Preflow\I18n\Machine\AiPackage;
use Preflow\Folio\Tests\Support\HiddenAiPackage;
use Preflow\Folio\Tests\Support\PublishingScaffold;

/**
 * preflow/ai is only suggested: a Folio app without it boots and renders the dashboard, the editor
 * and Settings → Translations with no AI action anywhere (no Settings → AI, no Translate, no
 * "Write a draft", no AI permissions in the roles), and its AI endpoints do not exist. With the
 * package and AI switched on, the same screens offer every AI action as before. The no-ai case
 * runs in its own process with the ai package's classes hidden (HiddenAiPackage).
 */
final class FolioWithoutAiTest extends TestCase
{
    use PublishingScaffold;

    private const KEY_ENV = 'FOLIO_NO_AI_TEST_KEY';

    protected function setUp(): void
    {
        $this->scaffoldPublishing();
        $this->writeModel('article', [
            'label' => 'Articles',
            'fields' => [
                'title' => ['type' => 'string', 'translatable' => true, 'validate' => ['required']],
                'body' => ['type' => 'richtext', 'translatable' => true],
            ],
        ]);
        // AI switched on with a key: only the missing package keeps it off.
        putenv(self::KEY_ENV . '=test-key');
        $this->write('config/ai.php', "<?php\nreturn " . var_export([
            'enabled' => true,
            'provider' => 'claude',
            'models' => ['claude' => 'claude-sonnet-5-5'],
            'keys' => ['claude' => self::KEY_ENV],
        ], true) . ";\n");
        $this->write('config/folio.php', "<?php\nreturn ['path' => '/folio', 'translations' => ['editable' => ['shop']]];\n");
        $this->write('lang/en/shop.php', "<?php\nreturn ['hello' => 'Hello', 'bye' => 'Goodbye'];\n");
    }

    /** The records the screens show; stored through a booted app, so only after the ai package is hidden. */
    private function seed(): void
    {
        $this->working('post', 'p1', ['title' => ['en' => 'Hello', 'de' => ''], 'slug' => ['en' => 'hello'], 'body' => ['en' => 'Some text']]);
        $this->working('article', 'a1', ['title' => ['en' => 'Hours'], 'body' => ['en' => '<p>Old</p>']]);
    }

    protected function tearDown(): void
    {
        putenv(self::KEY_ENV);
        $this->removeScaffold();
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_without_the_ai_package_folio_works_and_offers_no_ai(): void
    {
        HiddenAiPackage::hide();
        $this->assertFalse(class_exists(\Preflow\Ai\Ai::class), 'the ai package is hidden');
        $this->assertFalse(AiPackage::installed());
        $this->seed();

        $pages = $this->screens();
        foreach ($pages as $name => $html) {
            $this->assertStringNotContainsString('data-ai-translate', $html, $name);
            $this->assertStringNotContainsString('data-ai-draft', $html, $name);
            $this->assertStringNotContainsString('ai-translate.js', $html, $name);
            $this->assertStringNotContainsString('ai-draft.js', $html, $name);
            $this->assertStringNotContainsString('href="/folio/ai"', $html, $name);
            $this->assertStringNotContainsString('/_ai/', $html, $name);
        }

        $this->assertSame(404, $this->get('/folio/ai')->getStatusCode(), 'no Settings → AI screen');
        $this->assertNotContains($this->post('/folio/_ai/draft', ['type' => 'article', 'id' => 'a1', 'brief' => 'x'])->getStatusCode(), [200, 500], 'no draft endpoint');
        $this->assertNotContains($this->post('/folio/post/p1/_ai/queue', ['locales' => ['de']])->getStatusCode(), [200, 500], 'no translate endpoint');

        $registry = $this->app()->container()->get(Access::class)->registry();
        foreach (['ai.translate', 'ai.draft', 'ai.settings'] as $permission) {
            $this->assertFalse($registry->isDeclared($permission), "{$permission}: roles do not offer it");
            $this->assertNotNull($registry->disabledReason($permission), "{$permission}: a role that still grants it is denied quietly");
        }
    }

    public function test_with_the_ai_package_every_ai_action_is_offered(): void
    {
        $this->assertTrue(AiPackage::installed());
        $this->seed();

        $pages = $this->screens();
        $this->assertStringContainsString('href="/folio/ai"', $pages['dashboard'], 'Settings → AI in the nav');
        $this->assertStringContainsString('data-ai-translate', $pages['editor'], 'Translate on the German tab');
        $this->assertStringContainsString('data-ai-draft', $pages['rich text editor'], 'Write a draft');
        $this->assertStringContainsString('data-ai-translate', $pages['translations'], 'Translate on Settings → Translations');
        $this->assertStringContainsString('href="/folio/post/_ai/run"', $pages['list'], 'Translate… on the list');
        $this->assertStringContainsString('formaction="/folio/post/_ai/run"', $pages['list'], 'bulk translate');
        $this->assertSame(200, $this->get('/folio/ai')->getStatusCode());

        $registry = $this->app()->container()->get(Access::class)->registry();
        foreach (['ai.translate', 'ai.draft', 'ai.settings'] as $permission) {
            $this->assertTrue($registry->isDeclared($permission), $permission);
        }
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_without_the_ai_package_a_configured_translator_brings_translate_back(): void
    {
        HiddenAiPackage::hide();
        // A translator of the app's own (a translation API): only drafts and Settings → AI need preflow/ai.
        $this->write('config/ai.php', "<?php\nreturn ['translation' => ['translator' => \\" . PlainTranslator::class . "::class]];\n");
        $this->seed();

        $pages = $this->screens();
        $this->assertStringContainsString('data-ai-translate', $pages['editor'], 'Translate on the German tab');
        $this->assertStringContainsString('data-ai-translate', $pages['translations'], 'Translate on Settings → Translations');
        $this->assertStringContainsString('href="/folio/post/_ai/run"', $pages['list'], 'Translate… on the list');
        foreach ($pages as $name => $html) {
            $this->assertStringNotContainsString('data-ai-draft', $html, $name);
            $this->assertStringNotContainsString('href="/folio/ai"', $html, $name);
        }
        $this->assertSame(404, $this->get('/folio/ai')->getStatusCode());

        $c = $this->app()->container();
        $this->assertTrue($c->get(\Preflow\I18n\Machine\TextSources::class)->has('post'), 'Folio records are a text source');
        $registry = $c->get(Access::class)->registry();
        $this->assertTrue($registry->isDeclared('ai.translate'));
        $this->assertNull($registry->disabledReason('ai.translate'));
        foreach (['ai.draft', 'ai.settings'] as $permission) {
            $this->assertFalse($registry->isDeclared($permission), $permission);
        }
    }

    /** @return array<string, string> the dashboard, the editor (both kinds), Settings → Translations and a type list, each answered 200 */
    private function screens(): array
    {
        $pages = [];
        foreach ([
            'dashboard' => '/folio',
            'editor' => '/folio/post/p1/edit?locale=de',
            'rich text editor' => '/folio/article/a1/edit',
            'translations' => '/folio/translations?group=shop&locale=de',
            'list' => '/folio/post',
        ] as $name => $uri) {
            $res = $this->get($uri);
            $this->assertSame(200, $res->getStatusCode(), $name . ': ' . substr((string) $res->getBody(), 0, 500));
            $pages[$name] = (string) $res->getBody();
        }

        return $pages;
    }
}

/** A translator of an app's own that needs no AI (config/ai.php translation.translator). */
final class PlainTranslator implements \Preflow\I18n\Machine\MachineTranslator
{
    public function available(): bool
    {
        return true;
    }

    public function estimateCost(\Preflow\I18n\Machine\Estimate $size): ?float
    {
        return null;
    }

    public function translate(string $text, string $to, ?\Preflow\Ai\Tuning $tuning = null, ?string $from = null, \Preflow\I18n\Machine\TextFormat $format = \Preflow\I18n\Machine\TextFormat::Plain): string
    {
        return $text;
    }

    public function translateUnits(array $units, ?string $note = null): array
    {
        return [];
    }
}
