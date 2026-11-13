<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Publishing;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;
use Preflow\Folio\Tests\Support\PublishingScaffold;
use Preflow\I18n\Machine\TextSources;
use Preflow\I18n\Machine\WriteOutcomeStatus;
use Preflow\Publishing\Publisher;
use Psr\Http\Message\ResponseInterface;

/**
 * Every Folio entry point that saves a record goes through the RecordWriter (0.71): the form,
 * autosave, restore, the bulk set, a list move, "still correct" without the form, and AI
 * translation. A "shop" extension (written by the test) customizes the publishing, orderable
 * "story" type and the "memo" type, which saves itself (#[SaveRecord]); its hooks log to
 * Log::$entries and refuse every save while Log::$refuse is set.
 */
final class WriterEntryPointsTest extends TestCase
{
    use PublishingScaffold;

    private const string LATER = '2099-01-01T09:00';

    private ?Application $session = null;
    private string $token = '';
    private \Closure $autoload;

    protected function setUp(): void
    {
        $this->scaffoldPublishing();
        $this->writeModel('story', [
            'label' => 'Stories',
            'publishing' => true,
            'list' => ['orderable' => true, 'bulk' => ['mood']],
            'fields' => [
                'title' => ['type' => 'string', 'translatable' => true, 'validate' => ['required']],
                'mood' => ['type' => 'select', 'options' => ['calm' => 'Calm', 'busy' => 'Busy']],
            ],
        ]);
        $this->writeModel('memo', [
            'label' => 'Memos',
            'fields' => ['text' => ['type' => 'string', 'translatable' => true], 'via' => ['type' => 'string'], 'code' => ['type' => 'string', 'validate' => ['required']]],
        ]);
        $hash = password_hash('secret', PASSWORD_DEFAULT);
        $this->write('config/auth.php', "<?php\nreturn " . var_export([
            'default_guard' => 'session',
            'guards' => ['session' => ['class' => \Preflow\Auth\SessionGuard::class, 'provider' => 'config']],
            'providers' => ['config' => ['class' => \Preflow\Auth\ConfigUserProvider::class, 'users' => [
                ['email' => 'admin@site.test', 'password_hash' => $hash, 'roles' => ['admin']],
                ['email' => 'writer@site.test', 'password_hash' => $hash, 'roles' => ['writer']],
            ]]],
            'session' => ['cookie' => 'folio_writer_test', 'path' => '/', 'secure' => false],
        ], true) . ";\n");
        // The writer edits stories but may not publish them.
        $this->write('config/roles.php', "<?php\nreturn " . var_export(['staff' => [
            'admin' => ['*'],
            'writer' => ['folio.dashboard', 'story.view', 'story.edit'],
        ]], true) . ";\n");
        $this->write('config/providers.php', "<?php\nreturn [\\Preflow\\Folio\\FolioServiceProvider::class, \\Preflow\\Roles\\RolesServiceProvider::class];\n");
        $this->shop();

        $root = $this->dir . '/app/Folio/';
        $this->autoload = static function (string $class) use ($root): void {
            if (str_starts_with($class, 'App\\Folio\\')) {
                $file = $root . str_replace('\\', '/', substr($class, strlen('App\\Folio\\'))) . '.php';
                if (is_file($file)) {
                    require $file;
                }
            }
        };
        spl_autoload_register($this->autoload);
    }

    protected function tearDown(): void
    {
        spl_autoload_unregister($this->autoload);
        $this->removeScaffold();
    }

    /** Review Focus 1: whoever may not publish cancels a pending schedule, on every path. */
    #[RunInSeparateProcess]
    public function test_a_save_by_someone_who_may_not_publish_cancels_the_schedule_on_every_path(): void
    {
        $this->as('writer');
        $this->seed('story', 's1', ['title' => ['en' => 'One'], 'mood' => 'calm', '_version' => 1, '_publish_at' => self::LATER]);

        $this->assertSame(200, $this->send('POST', '/folio/story/s1/autosave', ['_locale' => 'en', 'title' => 'Autosaved', '_version' => '1'])->getStatusCode());
        $this->assertSame('', $this->stored('story', 's1')?->get('_publish_at'), 'autosave');
        $this->assertSame(['before:s1', 'after:s1'], \App\Folio\Shop\Log::$entries, 'autosave runs the save hooks');

        $this->schedule('s1');
        $res = $this->send('POST', '/folio/story/s1', ['_locale' => 'en', 'title' => 'Form', 'mood' => 'calm', '_version' => $this->version('s1'), '_publish_at' => self::LATER]);
        $this->assertSame(302, $res->getStatusCode(), (string) $res->getBody());
        $this->assertSame('', $this->stored('story', 's1')?->get('_publish_at'), 'the form, which may not set a schedule either');

        $this->schedule('s1');
        $this->assertSame(302, $this->send('POST', '/folio/story/_bulk/set-mood', ['ids' => ['s1'], 'value' => 'busy'])->getStatusCode());
        $this->assertSame('busy', $this->stored('story', 's1')?->get('mood'));
        $this->assertSame('', $this->stored('story', 's1')?->get('_publish_at'), 'the bulk set');

        $this->schedule('s1');
        $this->assertSame(302, $this->send('POST', '/folio/story/s1/_translations/confirm', ['locale' => 'de', '_version' => $this->version('s1')])->getStatusCode());
        $this->assertSame('', $this->stored('story', 's1')?->get('_publish_at'), '"still correct" without the form');

        $this->schedule('s1');
        $res = $this->send('POST', '/folio/story/s1/_translations/confirm', ['_locale' => 'de', 'title' => 'Eins', 'mood' => 'busy', '_confirm_path' => 'title', '_version' => $this->version('s1'), '_publish_at' => self::LATER]);
        $this->assertSame(302, $res->getStatusCode(), (string) $res->getBody());
        $this->assertSame('', $this->stored('story', 's1')?->get('_publish_at'), '"still correct" from the form');

        $publisher = $this->session->container()->get(Publisher::class);
        $publisher->publish($this->stored('story', 's1'), 'admin');
        $revision = $publisher->revisions('story', 's1')[0]->id;
        $this->schedule('s1');
        $version = (int) $this->version('s1');
        $this->assertSame(302, $this->send('POST', '/folio/story/s1/revisions/' . $revision . '/restore')->getStatusCode());
        $this->assertSame('', $this->stored('story', 's1')?->get('_publish_at'), 'the restore');
        $this->assertSame($version + 1, (int) $this->version('s1'), 'in the restore\'s own write');

        $this->schedule('s1');
        $this->source('story')->write('s1', 'de', ['title' => 'Eins']);
        $this->assertSame('', $this->stored('story', 's1')?->get('_publish_at'), 'AI translation');
        $this->assertSame(['en' => 'Form', 'de' => 'Eins'], $this->stored('story', 's1')?->get('title'));

        // Someone who may publish keeps it.
        $this->as('admin');
        $this->schedule('s1');
        $this->send('POST', '/folio/story/s1/autosave', ['_locale' => 'en', 'title' => 'Admin', '_version' => $this->version('s1')]);
        $this->assertSame(self::LATER, $this->stored('story', 's1')?->get('_publish_at'));
        $this->assertSame(302, $this->send('POST', '/folio/story/s1/revisions/' . $revision . '/restore')->getStatusCode());
        $this->assertSame(self::LATER, $this->stored('story', 's1')?->get('_publish_at'));
    }

    /** Review Focus 2: a #[BeforeSave] refusal stops every path before anything is stored. */
    #[RunInSeparateProcess]
    public function test_a_before_save_refusal_stops_every_path(): void
    {
        $this->as('admin');
        $this->seed('story', 's1', ['title' => ['en' => 'One'], 'mood' => 'calm', '_version' => 1, '_order' => 10]);
        $this->seed('story', 's2', ['title' => ['en' => 'Two'], 'mood' => 'calm', '_version' => 1, '_order' => 20]);
        $this->seed('memo', 'm1', ['text' => ['en' => 'Hello'], 'via' => 'seed', 'code' => 'm']);
        $before = $this->stored('story', 's1')?->toArray();
        \App\Folio\Shop\Log::$refuse = true;

        $form = $this->send('POST', '/folio/story/s1', ['_locale' => 'en', 'title' => 'Changed', 'mood' => 'calm', '_version' => '1', '_action' => 'publish']);
        $this->assertSame(422, $form->getStatusCode());
        $this->assertStringContainsString('Refused by the hook.', (string) $form->getBody());

        $new = $this->send('POST', '/folio/story', ['_locale' => 'en', 'title' => 'New', 'mood' => 'calm']);
        $this->assertSame(422, $new->getStatusCode());
        $this->assertStringContainsString('action="/folio/story"', (string) $new->getBody(), 'nothing stored: the form still creates');

        $autosave = $this->send('POST', '/folio/story/s1/autosave', ['_locale' => 'en', 'title' => 'Autosaved', '_version' => '1']);
        $this->assertSame(200, $autosave->getStatusCode());
        $this->assertStringContainsString('Refused by the hook.', (string) $autosave->getBody(), 'the header says why it was not saved');

        $this->assertSame(302, $this->send('POST', '/folio/story/_bulk/set-mood', ['ids' => ['s1'], 'value' => 'busy'])->getStatusCode());
        $this->assertStringContainsString('Refused by the hook.', $this->text($this->send('GET', '/folio/story')));

        $move = $this->send('POST', '/folio/story/s2/move', ['direction' => 'up']);
        $this->assertSame(302, $move->getStatusCode());
        $this->assertStringContainsString('<div class="folio-alert folio-alert-error" role="alert">Refused by the hook.</div>', $this->text($this->send('GET', '/folio/story')));
        $this->assertSame(20, (int) $this->stored('story', 's2')?->get('_order'));
        $this->assertSame(10, (int) $this->stored('story', 's1')?->get('_order'));

        $this->assertSame(422, $this->send('POST', '/folio/story/s1/_translations/confirm', ['locale' => 'de', '_version' => '1'])->getStatusCode());
        $confirmForm = $this->send('POST', '/folio/story/s1/_translations/confirm', ['_locale' => 'de', 'title' => 'Eins', 'mood' => 'calm', '_confirm_path' => 'title', '_version' => '1']);
        $this->assertSame(422, $confirmForm->getStatusCode());
        $this->assertStringContainsString('Refused by the hook.', (string) $confirmForm->getBody());

        $this->assertSame(WriteOutcomeStatus::Skipped, $this->source('story')->write('s1', 'de', ['title' => 'Eins'])->status);
        $this->assertSame(WriteOutcomeStatus::Skipped, $this->source('memo')->write('m1', 'de', ['text' => 'Hallo'])->status);

        $this->assertSame($before, $this->stored('story', 's1')?->toArray(), 'nothing of s1 was stored');
        $this->assertSame(['en' => 'Hello'], $this->stored('memo', 'm1')?->get('text'));
        $this->assertSame(1, count($this->session->container()->get(DataManager::class)->queryType('memo')->get()->items()));
        $this->assertCount(2, $this->session->container()->get(DataManager::class)->queryType('story')->get()->items());
        $this->assertSame([], \App\Folio\Shop\Log::$entries, 'no #[AfterSave] and no #[SaveRecord] ran');
    }

    /** Review Focus 2: a failed publish keeps the saved draft, runs #[AfterSave] and says so; a new record's form then updates it. */
    #[RunInSeparateProcess]
    public function test_a_failed_publish_keeps_the_draft_and_runs_after_save(): void
    {
        $this->as('admin');
        $this->seed('story', 's0', ['title' => ['en' => 'Zero'], 'mood' => 'calm', '_version' => 1, '_order' => 30]);

        $res = $this->send('POST', '/folio/story', ['_locale' => 'en', 'title' => '', 'mood' => 'calm', '_action' => 'publish']);

        $this->assertSame(422, $res->getStatusCode());
        $stories = array_values(array_filter(
            $this->session->container()->get(DataManager::class)->queryType('story')->get()->items(),
            static fn (DynamicRecord $r): bool => $r->getId() !== 's0',
        ));
        $this->assertCount(1, $stories, 'the draft is saved');
        $id = (string) $stories[0]->getId();
        $this->assertStringContainsString('Saved as draft — not published.', (string) $res->getBody());
        $this->assertStringContainsString('action="/folio/story/' . $id . '"', (string) $res->getBody(), 'saving again updates the draft');
        $this->assertNull($this->session->container()->get(Publisher::class)->snapshot('story', $id));
        $this->assertSame(['before:' . $id, 'after:' . $id], \App\Folio\Shop\Log::$entries);
        $this->assertSame(40, (int) $stories[0]->get('_order'), 'placed last by the writer');

        // The same on an update: the draft is stored (a new version), the publish refused.
        \App\Folio\Shop\Log::$entries = [];
        $update = $this->send('POST', '/folio/story/s0', ['_locale' => 'en', 'title' => '', 'mood' => 'busy', '_version' => '1', '_action' => 'publish']);
        $this->assertSame(422, $update->getStatusCode());
        $this->assertStringContainsString('Saved as draft — not published.', (string) $update->getBody());
        $this->assertSame(2, (int) $this->stored('story', 's0')?->get('_version'));
        $this->assertSame('busy', $this->stored('story', 's0')?->get('mood'));
        $this->assertNull($this->session->container()->get(Publisher::class)->snapshot('story', 's0'));
        $this->assertSame(['before:s0', 'after:s0'], \App\Folio\Shop\Log::$entries);
    }

    /** An AI write on a type without publishing is checked first: a record the rules refuse is skipped, not stored. */
    #[RunInSeparateProcess]
    public function test_an_ai_write_the_rules_refuse_is_skipped(): void
    {
        $this->as('admin');
        $this->seed('memo', 'm2', ['text' => ['en' => 'Hello'], 'via' => 'seed']); // no code: required

        $outcome = $this->source('memo')->write('m2', 'de', ['text' => 'Hallo']);

        $this->assertSame(WriteOutcomeStatus::Skipped, $outcome->status);
        $this->assertSame(['en' => 'Hello'], $this->stored('memo', 'm2')?->get('text'));
        $this->assertSame(['before:m2'], \App\Folio\Shop\Log::$entries, 'no #[SaveRecord], no #[AfterSave]');
    }

    /** Review Focus 3: AI translation of a type that saves itself goes through its #[SaveRecord]. */
    #[RunInSeparateProcess]
    public function test_ai_translation_uses_the_types_save_record(): void
    {
        $this->as('admin');
        $this->seed('memo', 'm1', ['text' => ['en' => 'Hello'], 'via' => 'seed', 'code' => 'm']);

        $outcome = $this->source('memo')->write('m1', 'de', ['text' => 'Hallo']);

        $this->assertSame(WriteOutcomeStatus::Written, $outcome->status, (string) $outcome->message);
        $this->assertSame(['before:m1', 'write:m1:previous=seed', 'after:m1'], \App\Folio\Shop\Log::$entries);
        $this->assertSame('hook', $this->stored('memo', 'm1')?->get('via'), 'stored by the #[SaveRecord] method');
        $this->assertSame(['en' => 'Hello', 'de' => 'Hallo'], $this->stored('memo', 'm1')?->get('text'));
    }

    /** The "shop" extension: StoryAdmin (story) and MemoAdmin (memo, saving itself). */
    private function shop(): void
    {
        $this->write('app/Folio/Shop/Extension.php', <<<'PHP'
            <?php
            namespace App\Folio\Shop;
            #[\Preflow\Folio\Extension\Attributes\FolioExtension(name: 'shop', label: 'Shop')]
            final class Extension extends \Preflow\Folio\Extension\Extension {}
            PHP);
        $this->write('app/Folio/Shop/Log.php', <<<'PHP'
            <?php
            namespace App\Folio\Shop;
            final class Log
            {
                public static array $entries = [];
                public static bool $refuse = false;

                public static function before(\Preflow\Data\DynamicRecord $record): void
                {
                    if (self::$refuse) {
                        throw new \Preflow\Validation\ValidationException(new \Preflow\Validation\ValidationResult(['title' => ['Refused by the hook.']]));
                    }
                    self::$entries[] = 'before:' . $record->getId();
                }
            }
            PHP);
        $this->write('app/Folio/Shop/Types/StoryAdmin.php', <<<'PHP'
            <?php
            namespace App\Folio\Shop\Types;

            use App\Folio\Shop\Log;
            use Preflow\Data\DynamicRecord;

            #[\Preflow\Folio\Extension\Attributes\FolioType('story')]
            final class StoryAdmin
            {
                #[\Preflow\Folio\Extension\BeforeSave]
                public function before(DynamicRecord $record): void
                {
                    Log::before($record);
                }

                #[\Preflow\Folio\Extension\AfterSave]
                public function after(DynamicRecord $record): void
                {
                    Log::$entries[] = 'after:' . $record->getId();
                }
            }
            PHP);
        $this->write('app/Folio/Shop/Types/MemoAdmin.php', <<<'PHP'
            <?php
            namespace App\Folio\Shop\Types;

            use App\Folio\Shop\Log;
            use Preflow\Data\DataManager;
            use Preflow\Data\DynamicRecord;

            #[\Preflow\Folio\Extension\Attributes\FolioType('memo')]
            final class MemoAdmin
            {
                public function __construct(private readonly DataManager $dm) {}

                #[\Preflow\Folio\Extension\BeforeSave]
                public function before(DynamicRecord $record): void
                {
                    Log::before($record);
                }

                #[\Preflow\Folio\Extension\SaveRecord]
                public function write(DynamicRecord $record, ?DynamicRecord $previous): void
                {
                    Log::$entries[] = 'write:' . $record->getId() . ':previous=' . $previous?->get('via');
                    $record->set('via', 'hook');
                    $this->dm->saveType($record, false);
                }

                #[\Preflow\Folio\Extension\AfterSave]
                public function after(DynamicRecord $record): void
                {
                    Log::$entries[] = 'after:' . $record->getId();
                }
            }
            PHP);
        $this->write('config/folio/extensions.json', (string) json_encode(['enabled' => ['shop' => ['disabled_parts' => []]]]));
    }

    private function source(string $type): \Preflow\I18n\Machine\TextSource
    {
        return $this->session->container()->get(TextSources::class)->get($type);
    }

    /** A pending schedule on the stored record (no hooks, no new version). */
    private function schedule(string $id): void
    {
        $record = $this->stored('story', $id);
        $record?->set('_publish_at', self::LATER);
        $this->session->container()->get(DataManager::class)->saveType($record, false);
        \App\Folio\Shop\Log::$entries = [];
    }

    private function version(string $id): string
    {
        return (string) $this->stored('story', $id)?->get('_version');
    }

    /** @param array<string, mixed> $data */
    private function seed(string $type, string $id, array $data): void
    {
        $dm = $this->session->container()->get(DataManager::class);
        $dm->saveType(DynamicRecord::fromArray($dm->getTypeRegistry()->get($type), ['uuid' => $id] + $data), false);
    }

    private function stored(string $type, string $id): ?DynamicRecord
    {
        return $this->session->container()->get(DataManager::class)->findType($type, $id);
    }

    private function text(ResponseInterface $response): string
    {
        return (string) $response->getBody();
    }

    private function as(string $role): void
    {
        $this->session = $this->app();
        $f = new Psr17Factory();
        $page = (string) $this->session->handle($f->createServerRequest('GET', '/folio/login'))->getBody();
        preg_match('/name="_csrf_token" value="([^"]+)"/', $page, $m);
        $this->token = $m[1] ?? '';
        $login = $this->session->handle($f->createServerRequest('POST', '/folio/login')->withHeader('Sec-Fetch-Site', 'same-origin')->withParsedBody([
            '_csrf_token' => $this->token, 'email' => "{$role}@site.test", 'password' => 'secret',
        ]));
        $this->assertSame(302, $login->getStatusCode());
    }

    /** @param array<string, mixed> $body */
    private function send(string $method, string $uri, array $body = []): ResponseInterface
    {
        $req = (new Psr17Factory())->createServerRequest($method, $uri);
        if ($method !== 'GET') {
            $req = $req->withHeader('Sec-Fetch-Site', 'same-origin');
            if (session_id() !== '') {
                $req = $req->withCookieParams([session_name() => session_id()]);
            }
        }
        if ($method === 'POST') {
            $req = $req->withParsedBody(['_csrf_token' => $this->token] + $body);
        }

        return $this->session->handle($req);
    }
}
