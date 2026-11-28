<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Http;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\Core\Context\Contexts;
use Preflow\Folio\Tests\Support\PublishingScaffold;
use Psr\Http\Message\ResponseInterface;
use Preflow\Testing\ChecksFrameBalance;

/**
 * Linked records in the page preview (0.92): a page template marks the element of a linked record
 * with preview_mark(type, record); in Folio's previews (live and whole page) it carries Folio's
 * marks, so a click opens that record in the drawer (GET {type}/{id}/edit?_drawer=1), whose save
 * answers drawer_saved for the editor behind it. A visit and a share-link preview carry no marks.
 */
final class LinkedRecordPreviewTest extends TestCase
{
    use ChecksFrameBalance;

    use PublishingScaffold;

    private ?Application $session = null;

    private string $token = '';

    private const MARKS = 'data-folio-record="note" data-folio-record-id="n1" data-folio-label="Note"';

    protected function setUp(): void
    {
        $this->scaffoldPublishing();
        // The page shows one linked note, marked by the template itself (the array form, by the note's id field).
        $this->write('resources/folio/frontend/post.twig', '<html><head></head><body><h1>{{ record.title }}</h1>'
            . '<aside class="teaser"{{ preview_mark("note", {uuid: "n1"}) }}>Opening hours</aside></body></html>');
        $this->write('config/app.php', "<?php\nreturn ['name' => 'Pub', 'debug' => 1, 'engine' => 'twig', 'key' => 'folio-integration-test-key-0123456789abcdef', 'url' => 'https://site.test'];\n");
        // Staff sign in (the whole-page preview's token is bound to their session): an admin, and a
        // viewer who may see notes but not edit them.
        $hash = password_hash('secret', PASSWORD_DEFAULT);
        $this->write('config/auth.php', "<?php\nreturn " . var_export([
            'default_guard' => 'session',
            'guards' => ['session' => ['class' => \Preflow\Auth\SessionGuard::class, 'provider' => 'config']],
            'providers' => ['config' => ['class' => \Preflow\Auth\ConfigUserProvider::class, 'users' => [
                ['email' => 'admin@site.test', 'password_hash' => $hash, 'roles' => ['admin']],
                ['email' => 'viewer@site.test', 'password_hash' => $hash, 'roles' => ['viewer']],
            ]]],
            'session' => ['cookie' => 'folio_linked_preview_test', 'path' => '/', 'secure' => false],
        ], true) . ";\n");
        $this->write('config/roles.php', "<?php\nreturn " . var_export(['staff' => [
            'admin' => ['*'],
            'viewer' => ['folio.dashboard', 'post.view', 'post.edit', 'note.view'],
        ]], true) . ";\n");
        $this->write('config/providers.php', "<?php\nreturn [\\Preflow\\Folio\\FolioServiceProvider::class, \\Preflow\\Roles\\RolesServiceProvider::class];\n");
    }

    protected function tearDown(): void
    {
        $this->removeScaffold();
    }

    #[RunInSeparateProcess]
    public function test_preview_mark_carries_folios_marks_in_the_live_and_the_whole_page_preview(): void
    {
        $this->signIn('admin');
        $this->working('note', 'n1', ['name' => 'Opening hours']);
        $this->working('post', 'p1', ['title' => ['en' => 'Hello'], 'slug' => ['en' => 'hello']]);

        $live = (string) $this->send('POST', '/folio/post/p1/preview', ['title' => 'Hello', 'slug' => 'hello', '_locale' => 'en'])->getBody();
        $this->assertStringContainsString('<aside class="teaser" ' . self::MARKS . '>', $live, 'the live preview');

        $page = $this->send('GET', '/folio/preview/page/post/p1');
        $this->assertSame(200, $page->getStatusCode());
        $this->assertStringContainsString('<aside class="teaser" ' . self::MARKS . '>', (string) $page->getBody(), 'the whole-page preview');
    }

    #[RunInSeparateProcess]
    public function test_mark_labels_use_the_admins_language(): void
    {
        $this->write('lang/de/site.php', "<?php return ['types' => ['note' => ['singular' => 'Notiz']]];");
        $this->signIn('admin');
        $this->session->container()->get(\Preflow\Folio\Users\UserPreferences::class)->setLocale('admin@site.test', 'de');
        $this->working('note', 'n1', ['name' => 'Opening hours']);
        $this->working('post', 'p1', ['title' => ['en' => 'Hello'], 'slug' => ['en' => 'hello']]);
        $german = 'data-folio-record="note" data-folio-record-id="n1" data-folio-label="Notiz"';

        // The page's locale is English, the editor's language German: the label follows the editor.
        $live = (string) $this->send('POST', '/folio/post/p1/preview', ['title' => 'Hello', 'slug' => 'hello', '_locale' => 'en'])->getBody();
        $this->assertStringContainsString('<aside class="teaser" ' . $german . '>', $live, 'the live preview');

        $page = $this->send('GET', '/folio/preview/page/post/p1');
        $this->assertSame(200, $page->getStatusCode());
        $this->assertStringContainsString('<aside class="teaser" ' . $german . '>', (string) $page->getBody(), 'the whole-page preview');
    }

    /** Blocks labelled by a key of Folio's group and of the site's own, on the post's blocks field. */
    private function blocksWithKeyLabels(): void
    {
        $post = json_decode((string) file_get_contents($this->dir . '/config/models/post.json'), true);
        $post['fields']['sections'] = ['type' => 'blocks', 'allowed' => ['logout_label', 'site_label']];
        $this->write('config/models/post.json', (string) json_encode($post));
        $this->write('config/blocks.php', "<?php\nreturn ['classes' => [\\Preflow\\Folio\\Tests\\Fixtures\\Blocks\\LogoutLabelBlock\\LogoutLabelBlock::class, \\Preflow\\Folio\\Tests\\Fixtures\\Blocks\\SiteLabelBlock\\SiteLabelBlock::class]];\n");
        $this->write('resources/folio/frontend/post.twig', '<html><head></head><body><h1>{{ record.title }}</h1>{{ blocks(record.sections, true) }}</body></html>');
        $this->write('lang/de/site.php', "<?php return ['blocks' => ['teaser' => 'Teaser-Baustein']];");
        $this->write('lang/en/site.php', "<?php return ['blocks' => ['teaser' => 'Teaser block']];");
    }

    #[RunInSeparateProcess]
    public function test_block_mark_labels_use_the_admins_language_in_the_live_and_the_whole_page_preview(): void
    {
        $this->blocksWithKeyLabels();
        $this->signIn('admin');
        $this->session->container()->get(\Preflow\Folio\Users\UserPreferences::class)->setLocale('admin@site.test', 'de');
        $this->working('post', 'p1', ['title' => ['en' => 'Hello'], 'slug' => ['en' => 'hello'], 'sections' => [
            ['_uid' => 'aaaaaaaaaaaaaaaa', '_block' => 'logout_label'],
            ['_uid' => 'bbbbbbbbbbbbbbbb', '_block' => 'site_label'],
        ]]);
        $folio = 'data-folio-block="aaaaaaaaaaaaaaaa" data-folio-label="Abmelden"';
        $site = 'data-folio-block="bbbbbbbbbbbbbbbb" data-folio-label="Teaser-Baustein"';

        $live = (string) $this->send('POST', '/folio/post/p1/preview', ['title' => 'Hello', 'slug' => 'hello', '_locale' => 'en', 'sections' => [
            '_order' => ['aaaaaaaaaaaaaaaa', 'bbbbbbbbbbbbbbbb'],
            'aaaaaaaaaaaaaaaa' => ['_block' => 'logout_label'],
            'bbbbbbbbbbbbbbbb' => ['_block' => 'site_label'],
        ]])->getBody();
        $this->assertStringContainsString($folio, $live, 'a Folio key, live preview');
        $this->assertStringContainsString($site, $live, 'a site key, live preview');

        $page = (string) $this->send('GET', '/folio/preview/page/post/p1')->getBody();
        $this->assertStringContainsString($folio, $page, 'a Folio key, whole-page preview');
        $this->assertStringContainsString($site, $page, 'a site key, whole-page preview');
    }

    #[RunInSeparateProcess]
    public function test_mark_labels_after_the_render_are_reset(): void
    {
        $this->write('lang/de/site.php', "<?php return ['types' => ['note' => ['singular' => 'Notiz']]];");
        $this->signIn('admin');
        $this->session->container()->get(\Preflow\Folio\Users\UserPreferences::class)->setLocale('admin@site.test', 'de');
        $this->working('note', 'n1', ['name' => 'Opening hours']);
        $this->working('post', 'p1', ['title' => ['en' => 'Hello'], 'slug' => ['en' => 'hello']]);

        $this->send('POST', '/folio/post/p1/preview', ['title' => 'Hello', 'slug' => 'hello', '_locale' => 'en']);
        // The language is a facet of the preview's context: it ends with that context.
        $this->assertNull(Contexts::peek()?->get(\Preflow\Folio\Content\PreviewLabelLanguage::class), 'gone when the render is over');

        // Outside a preview the marks translate as before (Folio's English texts in a visitor's render).
        $marks = $this->session->container()->get(\Preflow\Blocks\RecordPreviewMarks::class);
        $visitor = \Preflow\Testing\ContextFactory::forTest();
        $this->assertSame('Note', Contexts::run($visitor, fn (): string => $marks->attributes('note', 'n1')['data-folio-label']));
        $block = new \Preflow\Folio\Content\FolioBlockPreviewMarks(fn () => $this->session->container()->get(\Preflow\I18n\Translator::class));
        $def = new \Preflow\Blocks\BlockDefinition('x', 'folio.layout.log_out', 'g', null, null, 'X', false, []);
        $this->assertSame('Log out', Contexts::run($visitor, fn (): string => $block->attributes($def, ['_uid' => 'u'])['data-folio-label']));
        $german = $visitor->with(new \Preflow\Folio\Content\PreviewLabelLanguage('de'));
        $this->assertSame('Abmelden', Contexts::run($german, fn (): string => $block->attributes($def, ['_uid' => 'u'])['data-folio-label']), 'a block label in the admin\'s language');
    }

    #[RunInSeparateProcess]
    public function test_preview_mark_prints_nothing_on_a_visit_or_a_share_link_preview(): void
    {
        $this->signIn('admin');
        $this->publisher()->publish($this->working('note', 'n1', ['name' => 'Opening hours']), null);
        $this->publisher()->publish($this->working('post', 'p1', ['title' => ['en' => 'Hello'], 'slug' => ['en' => 'hello']]), null);

        $this->assertStringContainsString('<aside class="teaser">Opening hours</aside>', (string) $this->visitor('/hello')->getBody(), 'a visit');

        $share = (string) $this->send('POST', '/folio/post/p1/share', ['_locale' => 'en'])->getBody();
        $this->assertSame(1, preg_match('/folio_preview=([A-Za-z0-9_.\-]+)/', $share, $m), $share);
        $shared = $this->visitor('/hello?folio_preview=' . $m[1], ['folio_preview' => $m[1]]);
        $this->assertSame(200, $shared->getStatusCode());
        $this->assertStringContainsString('<aside class="teaser">Opening hours</aside>', (string) $shared->getBody(), 'a share-link preview is a visit');
    }

    #[RunInSeparateProcess]
    public function test_editing_an_existing_record_in_the_drawer_answers_drawer_saved(): void
    {
        $this->signIn('admin');
        $this->working('note', 'n1', ['name' => 'Opening hours']);

        $form = $this->send('GET', '/folio/note/n1/edit?_drawer=1');
        $html = (string) $form->getBody();
        $this->assertSame(200, $form->getStatusCode());
        $this->assertStringContainsString('folio-drawer-heading', $html, 'the drawer layout');
        $this->assertStringNotContainsString('folio-sidebar', $html);
        $this->assertStringContainsString('action="/folio/note/n1?_drawer=1"', $html, 'the save stays in the drawer');
        $this->assertStringContainsString('Opening hours', $html);
        // A translatable record's other language opens in the drawer too.
        $this->working('post', 'p1', ['title' => ['en' => 'Hello'], 'slug' => ['en' => 'hello']]);
        $drawer = (string) $this->send('GET', '/folio/post/p1/edit?_drawer=1')->getBody();
        $this->assertStringContainsString('/folio/post/p1/edit?_drawer=1&amp;locale=de"', $drawer);
        // A type with a frontend route: the drawer shows no preview of its own, so nothing in it can open another drawer.
        $this->assertStringNotContainsString('data-folio-stage', $drawer, 'no nested preview in the drawer');
        $this->assertStringNotContainsString('data-folio-split', $drawer);
        $full = (string) $this->send('GET', '/folio/post/p1/edit')->getBody();
        $this->assertStringContainsString('/folio/post/p1/edit?locale=de"', $full, 'the full page as before');
        $this->assertStringContainsString('data-folio-stage', $full, 'with its preview');
        $this->assertStringContainsString('id="folio-record"', $full);
        $this->assertStringContainsString('action="/folio/post/p1"', $full, 'the record it has open, which admin.js opens no drawer for');

        $saved = $this->send('POST', '/folio/note/n1?_drawer=1', ['name' => 'Open daily', '_locale' => 'en']);
        $body = (string) $saved->getBody();
        $this->assertSame(200, $saved->getStatusCode());
        $this->assertStringContainsString("source: 'folio-drawer'", $body, 'drawer_saved tells the editor behind it');
        $this->assertStringContainsString('type: "note"', $body);
        $this->assertStringContainsString('id: "n1"', $body);
        $this->assertSame('Open daily', $this->stored('note', 'n1')->get('name'));

        // A save that is refused stays in the drawer, with its form (another save came first).
        $this->working('note', 'n1', ['name' => 'Theirs', '_version' => 5]);
        $refused = $this->send('POST', '/folio/note/n1?_drawer=1', ['name' => 'Mine', '_locale' => 'en', '_version' => '4']);
        $this->assertSame(409, $refused->getStatusCode());
        $this->assertStringContainsString('href="/folio/note/n1/edit?_drawer=1"', (string) $refused->getBody(), 'its reload link stays in the drawer too');
        $this->assertStringContainsString('folio-drawer-heading', (string) $refused->getBody());
        $this->assertStringContainsString('action="/folio/note/n1?_drawer=1"', (string) $refused->getBody());
    }

    #[RunInSeparateProcess]
    public function test_still_correct_in_the_drawer_stays_in_the_drawer(): void
    {
        $this->signIn('admin');
        $status = $this->session->container()->get(\Preflow\Folio\Content\TranslationStatus::class);
        $outdated = [
            'title' => ['en' => 'Hello!', 'de' => 'Hallo'], 'slug' => ['en' => 'hello', 'de' => 'hallo'], '_locales' => ['en', 'de'],
            '_translated_from' => ['de' => ['title' => $status->fingerprint('Hello'), 'slug' => $status->fingerprint('hello')]],
        ];
        $this->working('post', 'p1', $outdated);

        $form = (string) $this->send('GET', '/folio/post/p1/edit?_drawer=1&locale=de')->getBody();
        $this->assertStringContainsString('formaction="/folio/post/p1/_translations/confirm?_drawer=1"', $form, 'the button posts in the drawer');

        // From the form (its fields and the button's path): saved, and the editor behind is told.
        $saved = $this->send('POST', '/folio/post/p1/_translations/confirm?_drawer=1', ['_locale' => 'de', '_confirm_path' => 'title', 'title' => 'Hallo', 'slug' => 'hallo']);
        $this->assertSame(200, $saved->getStatusCode(), substr((string) $saved->getBody(), 0, 2000));
        $this->assertStringContainsString("source: 'folio-drawer'", (string) $saved->getBody(), 'drawer_saved, not the full-page editor');
        $this->assertSame([], $status->outdated($this->dm()->getTypeRegistry()->get('post'), $this->stored('post', 'p1'), 'de'), 'the title is marked');

        // Without form fields (only the stamp) too.
        $this->assertStringContainsString("source: 'folio-drawer'", (string) $this->send('POST', '/folio/post/p1/_translations/confirm?_drawer=1', ['locale' => 'de', 'path' => 'title'])->getBody());

        // A conflict keeps the drawer's layout.
        $this->working('post', 'p1', ['_version' => 7] + $outdated);
        $conflict = $this->send('POST', '/folio/post/p1/_translations/confirm?_drawer=1', ['locale' => 'de', 'path' => 'title', '_version' => '1']);
        $this->assertSame(409, $conflict->getStatusCode());
        $this->assertStringContainsString('folio-drawer-heading', (string) $conflict->getBody());
        $this->assertStringContainsString('action="/folio/post/p1?_drawer=1"', (string) $conflict->getBody());
    }

    #[RunInSeparateProcess]
    public function test_the_full_page_edit_is_unchanged(): void
    {
        $this->signIn('admin');
        $this->working('note', 'n1', ['name' => 'Opening hours']);

        $this->assertStringContainsString('action="/folio/note/n1"', (string) $this->send('GET', '/folio/note/n1/edit')->getBody());
        $saved = $this->send('POST', '/folio/note/n1', ['name' => 'Open daily', '_locale' => 'en']);
        $this->assertSame(302, $saved->getStatusCode(), 'a save without the drawer goes back to the list');
    }

    #[RunInSeparateProcess]
    public function test_the_drawer_of_a_type_the_user_may_not_edit_answers_403(): void
    {
        $this->signIn('viewer');
        $this->working('note', 'n1', ['name' => 'Opening hours']);

        $this->assertSame(200, $this->send('GET', '/folio/note/n1/edit')->getStatusCode(), 'viewing the record stays as it is');
        $drawer = $this->send('GET', '/folio/note/n1/edit?_drawer=1', [], ['Sec-Fetch-Dest' => 'iframe']);
        $this->assertSame(403, $drawer->getStatusCode());
        $this->assertStringContainsString('folio-drawer-heading', (string) $drawer->getBody(), 'in the drawer\'s layout');
        $this->assertStringNotContainsString('Opening hours', (string) $drawer->getBody());
        $this->assertSame(403, $this->send('POST', '/folio/note/n1?_drawer=1', ['name' => 'Changed', '_locale' => 'en'])->getStatusCode());
        $this->assertSame('Opening hours', $this->stored('note', 'n1')->get('name'));
    }

    /** The signed-in app's data (the scaffold's helpers read through it: one app, one session). */
    private function dm(): \Preflow\Data\DataManager
    {
        return ($this->session ?? $this->app())->container()->get(\Preflow\Data\DataManager::class);
    }

    private function publisher(): \Preflow\Publishing\Publisher
    {
        return ($this->session ?? $this->app())->container()->get(\Preflow\Publishing\Publisher::class);
    }

    /** Sign in on one app (the session lives in-process): every request of the test goes through it. */
    private function signIn(string $role): void
    {
        $f = new Psr17Factory();
        $this->session = $this->app();
        $page = (string) $this->session->handle($f->createServerRequest('GET', '/folio/login'))->getBody();
        preg_match('/name="_csrf_token" value="([^"]+)"/', $page, $m);
        $this->token = $m[1] ?? '';
        $login = $this->session->handle($f->createServerRequest('POST', '/folio/login')->withHeader('Sec-Fetch-Site', 'same-origin')->withParsedBody([
            '_csrf_token' => $this->token, 'email' => "{$role}@site.test", 'password' => 'secret',
        ]));
        $this->assertSame(302, $login->getStatusCode());
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     */
    private function send(string $method, string $uri, array $body = [], array $headers = []): ResponseInterface
    {
        $req = (new Psr17Factory())->createServerRequest($method, $uri);
        parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);
        $req = $req->withQueryParams($query);
        if ($method === 'POST') {
            // As a browser posts a form of the admin: same origin, with the session's token.
            $req = $req->withHeader('Sec-Fetch-Site', 'same-origin')->withParsedBody($body + ['_csrf_token' => $this->token]);
        }
        foreach ($headers as $name => $value) {
            $req = $req->withHeader($name, $value);
        }

        return $this->session->handle($req);
    }

    /**
     * A visitor's request: another browser, without the admin's session.
     *
     * @param array<string, string> $query
     */
    private function visitor(string $uri, array $query = []): ResponseInterface
    {
        $session = $_SESSION;
        $_SESSION = ['_flash' => ['current' => [], 'previous' => []]];
        try {
            return $this->session->handle((new Psr17Factory())->createServerRequest('GET', $uri)->withQueryParams($query));
        } finally {
            $_SESSION = $session;
        }
    }
}
