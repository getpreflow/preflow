<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Http;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\Folio\Tests\Support\PublishingScaffold;
use Preflow\Publishing\PublishState;
use Psr\Http\Message\ResponseInterface;

/**
 * The editor's action bar (0.75): Save draft and Publish sit in the topbar, outside the record form,
 * and name it (form="folio-record"); so do the side panel's record controls (the address field, the
 * visible-in boxes) and the publish-at date. A browser sends them all with the form, so the POST the
 * topbar's Publish makes carries every value, and _action=publish.
 */
final class EditorActionsTest extends TestCase
{
    use PublishingScaffold;

    private ?Application $session = null;

    private string $token = '';

    protected function setUp(): void
    {
        $this->scaffoldPublishing();
    }

    protected function tearDown(): void
    {
        $this->removeScaffold();
    }

    /** The topbar. */
    private static function topbar(string $page): string
    {
        $start = (int) strpos($page, '<header class="folio-topbar">');

        return substr($page, $start, (int) strpos($page, '</header>', $start) - $start);
    }

    /** The record form. */
    private static function recordForm(string $page): string
    {
        $start = (int) strrpos(substr($page, 0, (int) strpos($page, 'id="folio-record"')), '<form');

        return substr($page, $start, (int) strpos($page, '</form>', $start) - $start);
    }

    /** The side panel. */
    private static function side(string $page): string
    {
        $start = (int) strpos($page, '<aside class="folio-side"');

        return substr($page, $start, (int) strpos($page, '</aside>', $start) - $start);
    }

    #[RunInSeparateProcess]
    public function test_buttons_and_side_controls_name_the_record_form(): void
    {
        $this->working('post', 'p1', ['title' => ['en' => 'Hello'], 'slug' => ['en' => 'hello']]);

        $page = (string) $this->get('/folio/post/p1/edit')->getBody();

        $this->assertMatchesRegularExpression('~<form[^>]* id="folio-record"~', $page);
        $top = self::topbar($page);
        $this->assertMatchesRegularExpression('~<button type="submit" form="folio-record" class="btn btn-secondary" data-folio-save data-busy-label="Saving…"[^>]*>Save draft</button>~', $top);
        $this->assertMatchesRegularExpression('~<button type="submit" form="folio-record" class="btn btn-primary folio-split-main" name="_action" value="publish" data-folio-publish data-busy-label="Publishing…"[^>]*>Publish</button>~', $top);
        $this->assertMatchesRegularExpression('~<input type="datetime-local" id="folio-publish-at" name="_publish_at" form="folio-record"~', $top);
        $this->assertStringContainsString('aria-haspopup="dialog" aria-label="Schedule or other publish options"', $top);
        $this->assertStringContainsString('aria-label="Back to Posts"', $top);
        $this->assertStringContainsString('(⌘S)', $top, 'the shortcut hint in the button\'s title');

        $side = self::side($page);
        $this->assertMatchesRegularExpression('~<input form="folio-record"[^>]* name="slug"~', $side, 'the address field, outside the form');
        $this->assertStringContainsString('<input type="checkbox" form="folio-record" name="_locales[]" value="de"', $side);
        // Inside the form: the token, the language, the "Visible in" marker and the default language.
        $form = self::recordForm($page);
        foreach (['name="_csrf_token"', 'name="_locale" value="en"', 'name="_locales_submitted" value="1"', 'name="_locales[]" value="en"', 'name="_version"'] as $inside) {
            $this->assertStringContainsString($inside, $form);
        }
        $this->assertStringNotContainsString('name="slug"', $form, 'the address field moved to the side panel');
    }

    #[RunInSeparateProcess]
    public function test_publish_from_topbar_posts_side_panel_values(): void
    {
        $this->working('post', 'p1', ['title' => ['en' => 'Hello'], 'slug' => ['en' => 'hello']]);

        // What the browser sends for a click on the topbar's Publish: the form's own fields and the
        // side panel's (the address, the visible-in boxes), with _action=publish.
        $res = $this->post('/folio/post/p1', ['title' => 'Hello', 'slug' => 'renamed', '_locale' => 'en', '_locales_submitted' => '1',
            '_locales' => ['en', 'de'], '_publish_at' => '', '_action' => 'publish', '_version' => '0']);
        $this->assertSame(302, $res->getStatusCode());

        $stored = $this->stored('post', 'p1');
        $this->assertSame(PublishState::Published, $this->publisher()->state($stored));
        $live = $this->publisher()->live('post', 'p1');
        $this->assertNotNull($live);
        $this->assertSame('renamed', $live->get('slug')['en']);
        $this->assertSame(['en', 'de'], $live->availableLocales());

        // With a date from the split button's menu, Publish reads "Schedule" and sends _action=schedule:
        // a save that keeps the date (publish:scheduled publishes it when due); nothing goes live now.
        $future = (new \DateTimeImmutable('+3 days'))->format('Y-m-d\TH:i');
        $this->post('/folio/post/p1', ['title' => 'Hello again', 'slug' => 'renamed', '_locale' => 'en', '_locales_submitted' => '1',
            '_locales' => ['en'], '_publish_at' => $future, '_action' => 'schedule']);
        $stored = $this->stored('post', 'p1');
        $this->assertSame($future, $stored->get('_publish_at'));
        $this->assertSame('Hello', $this->publisher()->live('post', 'p1')?->get('title')['en'], 'scheduled, not live yet');

        $page = (string) $this->get('/folio/post/p1/edit')->getBody();
        $this->assertMatchesRegularExpression('~name="_action" value="schedule" data-folio-publish data-busy-label="Saving…"[^>]*>Schedule</button>~', $page, 'a date set: the button schedules');
        $this->assertMatchesRegularExpression('~name="_action" value="schedule" data-folio-schedule[^>]*>Publish at this time</button>~', $page);
        $this->assertStringContainsString('value="' . $future . '"', $page);
        $this->assertStringContainsString('Scheduled to publish on', self::side($page));
    }

    #[RunInSeparateProcess]
    public function test_a_type_without_publishing_has_one_save(): void
    {
        $this->writeModel('event', ['label' => 'Events', 'fields' => ['name' => ['type' => 'string']]]);
        $this->working('event', 'e1', ['name' => 'Fair']);

        $top = self::topbar((string) $this->get('/folio/event/e1/edit')->getBody());

        $this->assertSame(1, preg_match_all('~type="submit" form="folio-record"~', $top));
        $this->assertMatchesRegularExpression('~<button type="submit" form="folio-record" class="btn btn-primary" data-folio-save[^>]*>Save</button>~', $top);
        $this->assertStringNotContainsString('Save draft', $top);
        $this->assertStringNotContainsString('value="publish"', $top);
        $this->assertStringNotContainsString('_publish_at', $top);
    }

    #[RunInSeparateProcess]
    public function test_no_floating_actions_box_and_cancel_is_the_back_button(): void
    {
        $this->working('post', 'p1', ['title' => ['en' => 'Hello'], 'slug' => ['en' => 'hello']]);

        $page = (string) $this->get('/folio/post/p1/edit')->getBody();

        $this->assertStringNotContainsString('folio-form-actions', $page);
        $this->assertStringNotContainsString('>Cancel</a>', $page);
        $this->assertMatchesRegularExpression('~<a class="btn btn-secondary btn-icon folio-back" href="/folio/post" aria-label="Back to Posts"~', $page);
    }

    #[RunInSeparateProcess]
    public function test_the_create_drawer_keeps_its_buttons_in_the_form(): void
    {
        $page = (string) $this->get('/folio/post/new?_drawer=1')->getBody();

        $form = self::recordForm($page);
        $this->assertMatchesRegularExpression('~<div class="folio-drawer-actions">\s*<button type="submit" class="btn btn-secondary"~', $form);
        $this->assertStringContainsString('name="_action" value="publish"', $form);
        $this->assertStringNotContainsString('folio-side', $page, 'no side panel in the drawer');
        $this->assertStringContainsString('name="slug"', $form, 'the address field stays in the drawer\'s form');
    }

    #[RunInSeparateProcess]
    public function test_without_publish_right_save_draft_is_primary(): void
    {
        $this->roles();
        $this->login('editor');
        $top = self::topbar((string) $this->send('GET', '/folio/post/p1/edit')->getBody());
        $this->assertMatchesRegularExpression('~<button type="submit" form="folio-record" class="btn btn-primary" data-folio-save[^>]*>Save draft</button>~', $top);
        $this->assertStringNotContainsString('value="publish"', $top);
        $this->assertStringNotContainsString('folio-split-button', $top);
    }

    #[RunInSeparateProcess]
    public function test_read_only_shows_no_buttons(): void
    {
        $this->roles();
        $this->login('viewer');
        $page = (string) $this->send('GET', '/folio/post/p1/edit')->getBody();
        $this->assertStringNotContainsString('form="folio-record" class="btn', self::topbar($page), 'read-only: no buttons');
        $this->assertDoesNotMatchRegularExpression('~data-folio-save[\s>]~', $page);
        $this->assertMatchesRegularExpression('~<fieldset class="folio-side-fields" form="folio-record" disabled>~', self::side($page), 'the side panel\'s record controls are read-only too');
        $this->assertMatchesRegularExpression('~name="_locales\[\]" value="de"[^>]* disabled>~', self::side($page));
    }

    /** Staff sign-in with roles: an editor (no publish) and a viewer (no edit). */
    private function roles(): void
    {
        $this->session = null;
        // The record first, while the app has no sign-in (one session per process).
        $this->working('post', 'p1', ['title' => ['en' => 'Hello'], 'slug' => ['en' => 'hello']]);
        $hash = password_hash('secret', PASSWORD_DEFAULT);
        $this->write('config/auth.php', "<?php\nreturn " . var_export([
            'default_guard' => 'session',
            'guards' => ['session' => ['class' => \Preflow\Auth\SessionGuard::class, 'provider' => 'config']],
            'providers' => ['config' => ['class' => \Preflow\Auth\ConfigUserProvider::class, 'users' => [
                ['email' => 'editor@site.test', 'password_hash' => $hash, 'roles' => ['editor']],
                ['email' => 'viewer@site.test', 'password_hash' => $hash, 'roles' => ['viewer']],
            ]]],
            'session' => ['cookie' => 'folio_editor_test', 'path' => '/', 'secure' => false],
        ], true) . ";\n");
        $this->write('config/roles.php', "<?php\nreturn " . var_export(['staff' => [
            'editor' => ['folio.dashboard', 'post.view', 'post.edit'],
            'viewer' => ['post.view'],
        ]], true) . ";\n");
        $this->write('config/providers.php', "<?php\nreturn [\\Preflow\\Folio\\FolioServiceProvider::class, \\Preflow\\Roles\\RolesServiceProvider::class];\n");
    }

    private function login(string $role): void
    {
        $this->session = Application::create($this->dir);
        $this->session->boot();
        $f = new Psr17Factory();
        $page = (string) $this->session->handle($f->createServerRequest('GET', '/folio/login'))->getBody();
        preg_match('/name="_csrf_token" value="([^"]+)"/', $page, $m);
        $this->token = $m[1] ?? '';
        $login = $this->session->handle($f->createServerRequest('POST', '/folio/login')->withHeader('Sec-Fetch-Site', 'same-origin')->withParsedBody([
            '_csrf_token' => $this->token, 'email' => "{$role}@site.test", 'password' => 'secret',
        ]));
        $this->assertSame(302, $login->getStatusCode());
    }

    private function send(string $method, string $uri): ResponseInterface
    {
        return $this->session->handle((new Psr17Factory())->createServerRequest($method, $uri));
    }
}
