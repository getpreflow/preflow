<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Support;

use Nyholm\Psr7\Factory\Psr17Factory;
use Preflow\Ai\Ai;
use Preflow\Ai\AiRequest;
use Preflow\Ai\AiResponse;
use Preflow\Ai\Testing\FakeAiClient;
use Preflow\Ai\Usage\Pricing;
use Preflow\Ai\Usage\UsageLog;
use Preflow\Core\Application;
use Preflow\I18n\Machine\AiTranslator;
use Preflow\I18n\Machine\Estimator;
use Preflow\I18n\Machine\MachineTranslator;
use Preflow\I18n\Machine\TranslationSettings;

/**
 * AI on top of PublishingScaffold: config/ai.php with a key from a test env var, an app whose
 * AI answers from a FakeAiClient, and staff sign-in with roles. The using class sets $script
 * and calls putenv(self::AI_KEY_ENV) in tearDown.
 */
trait AiScaffold
{
    /** @var list<AiResponse|\Throwable|callable(AiRequest): (AiResponse|\Throwable)> */
    private array $script = [];

    private ?FakeAiClient $fake = null;

    private ?Application $session = null;

    private string $token = '';

    private static function aiKeyEnv(): string
    {
        return 'FOLIO_AI_TEST_KEY';
    }

    private function aiConfig(bool $enabled): void
    {
        putenv(self::aiKeyEnv() . '=test-key');
        $this->write('config/ai.php', "<?php\nreturn " . var_export([
            'enabled' => $enabled,
            'provider' => 'claude',
            'models' => ['claude' => 'claude-sonnet-5-5'],
            'keys' => ['claude' => self::aiKeyEnv()],
        ], true) . ";\n");
    }

    /** A booted app whose AI answers from the fake client (routes are registered from the real config at boot). */
    private function aiApp(): Application
    {
        $app = Application::create($this->dir);
        $app->boot();
        $c = $app->container();
        $this->fake = new FakeAiClient($this->script === [] ? [self::echo()] : $this->script, 'claude-sonnet-5-5');
        $fake = $this->fake;
        $ai = new Ai(
            ['enabled' => true, 'provider' => 'claude', 'models' => ['claude' => 'claude-sonnet-5-5'], 'keys' => ['claude' => self::aiKeyEnv()]],
            $c->get(UsageLog::class),
            $c->get(Pricing::class),
            static fn (string $name): ?string => getenv($name) === false ? null : (string) getenv($name),
            static fn (): FakeAiClient => $fake,
        );
        $c->instance(Ai::class, $ai);
        $c->instance(MachineTranslator::class, new AiTranslator($ai, $c->get(TranslationSettings::class)));
        $c->instance(Estimator::class, new Estimator($ai));

        return $app;
    }

    /** Answers every item with "DE:" + its text (the items JSON is the prompt's last line), leaving out the text $skip. */
    private static function echo(?string $skip = null): \Closure
    {
        return static function (AiRequest $r) use ($skip): AiResponse {
            $newline = strrpos($r->prompt, "\n");
            $items = json_decode(substr($r->prompt, $newline === false ? 0 : $newline + 1), true, 512, JSON_THROW_ON_ERROR);
            $items = array_values(array_filter($items, static fn (array $i): bool => $i['text'] !== $skip));

            return FakeAiClient::json(array_map(static fn (array $i): array => ['n' => $i['n'], 'text' => 'DE:' . $i['text']], $items));
        };
    }

    /**
     * Folio with sign-in and roles, signed in as $role; later requests go through $this->session
     * (the login lives in-process). Roles: editor (post edit, no AI), reader (AI, no post edit),
     * translator (post edit and AI).
     */
    private function staff(string $role): void
    {
        $hash = password_hash('secret', PASSWORD_DEFAULT);
        $users = [];
        foreach (['editor', 'reader', 'translator'] as $name) {
            $users[] = ['email' => "{$name}@site.test", 'password_hash' => $hash, 'roles' => [$name]];
        }
        $this->write('config/auth.php', "<?php\nreturn " . var_export([
            'default_guard' => 'session',
            'guards' => ['session' => ['class' => \Preflow\Auth\SessionGuard::class, 'provider' => 'config']],
            'providers' => ['config' => ['class' => \Preflow\Auth\ConfigUserProvider::class, 'users' => $users]],
            'session' => ['cookie' => 'folio_ai_test', 'path' => '/', 'secure' => false],
        ], true) . ";\n");
        $this->write('config/roles.php', "<?php\nreturn " . var_export(['staff' => [
            'editor' => ['folio.dashboard', 'post.view', 'post.edit'],
            'reader' => ['folio.dashboard', 'post.view', 'ai.translate'],
            'translator' => ['folio.dashboard', 'post.view', 'post.edit', 'ai.translate'],
        ]], true) . ";\n");
        $this->write('config/providers.php', "<?php\nreturn [\\Preflow\\Folio\\FolioServiceProvider::class, \\Preflow\\Roles\\RolesServiceProvider::class];\n");

        $this->session = $this->aiApp();
        $f = new Psr17Factory();
        $page = (string) $this->session->handle($f->createServerRequest('GET', '/folio/login'))->getBody();
        preg_match('/name="_csrf_token" value="([^"]+)"/', $page, $m);
        $this->token = $m[1] ?? '';
        $login = $this->session->handle($f->createServerRequest('POST', '/folio/login')->withParsedBody([
            '_csrf_token' => $this->token, 'email' => "{$role}@site.test", 'password' => 'secret',
        ]));
        \PHPUnit\Framework\Assert::assertSame(302, $login->getStatusCode());
    }
}
