<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Http;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\Core\Container\Container;
use Preflow\Core\Container\ServiceProvider;
use Preflow\Core\Context\Contexts;
use Preflow\Core\Http\CookieQueue;
use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;
use Preflow\Folio\Tests\Fixtures\Preview\JobCard;
use Preflow\Folio\Tests\Fixtures\Preview\JobWide;
use Preflow\Folio\Tests\Fixtures\Preview\PreviewFails;
use Preflow\Folio\Tests\Fixtures\Preview\PreviewUnbuildable;
use Preflow\Folio\Tests\Support\PublishingScaffold;
use Preflow\Notify\Email;
use Preflow\Notify\Notifier;
use Preflow\Notify\TransportFactory;
use Preflow\Rbac\RoleStore;
use Preflow\View\TemplateEngineInterface;
use Preflow\View\TemplateFunctionDefinition;
use Psr\Http\Message\ResponseInterface;

/**
 * Folio's live preview for a type with "preview" (0.94, calls 6–8): the record rendered through a view's
 * component on the canvas @folio/frontend/preview-canvas.twig, in the live preview's context (a visitor,
 * the editing locale, working copies, writes skipped, mail and cookies captured), the view chosen with
 * the form's _preview_view.
 *
 * Types: job (no route, publishing, views card and wide, links a post), memo (no route, one view), event
 * (a route and one view: "page" plus "default"), broken (views whose component fails while rendering or
 * cannot be built), note (no route, no preview). The job's component writes a note, queues a mail and a
 * cookie each time it renders (page_side_effects()).
 *
 * Staff: admin ("*"), writer (job.edit), creator (job.create), reader (job.view only).
 */
final class TypePreviewTest extends TestCase
{
    use PublishingScaffold;

    private ?Application $session = null;
    private string $token = '';

    protected function setUp(): void
    {
        Contexts::reset();
        $this->scaffoldPublishing();
        $this->write('config/app.php', "<?php\nreturn ['name' => 'Preview', 'debug' => 1, 'engine' => 'twig', 'key' => 'folio-type-preview-test-key-0123', 'url' => 'https://site.test'];\n");
        $this->write('config/notify.php', "<?php\nreturn ['transports' => ['mail' => ['driver' => 'memory']], 'channels' => ['email' => ['transport' => 'mail', 'from' => 'site@site.test']]];\n");
        ini_set('error_log', $this->dir . '/php.log');
        $this->writeModel('job', [
            'label' => 'Jobs', 'publishing' => true,
            'fields' => ['title' => ['type' => 'string', 'translatable' => true], 'post' => ['type' => 'string']],
            'preview' => ['views' => [
                'card' => ['label' => 'Card', 'component' => JobCard::class],
                'wide' => ['label' => 'Wide', 'component' => JobWide::class],
            ]],
        ]);
        $this->writeModel('memo', [
            'label' => 'Memos', 'publishing' => false,
            'fields' => ['title' => ['type' => 'string']],
            'preview' => ['component' => JobWide::class],
        ]);
        $this->writeModel('event', [
            'label' => 'Events', 'publishing' => false, 'frontend' => ['route' => '/events/{slug}'],
            'fields' => ['title' => ['type' => 'string'], 'slug' => ['type' => 'string'], 'status' => ['type' => 'string']],
            'preview' => ['component' => JobCard::class],
        ]);
        $this->writeModel('broken', [
            'label' => 'Broken', 'publishing' => false,
            'fields' => ['title' => ['type' => 'string']],
            'preview' => ['views' => [
                'render' => ['label' => 'Fails', 'component' => PreviewFails::class],
                'build' => ['label' => 'Unbuildable', 'component' => PreviewUnbuildable::class],
            ]],
        ]);
        $this->write('resources/folio/frontend/event.twig', '<html><head></head><body><h1>EVENT {{ record.title }}</h1></body></html>');
        $this->write('config/providers.php', "<?php\nreturn [\\Preflow\\Folio\\FolioServiceProvider::class, \\Preflow\\Rbac\\RbacServiceProvider::class, " . var_export(TypePreviewSiteProvider::class, true) . "];\n");

        $store = $this->app()->container()->get(RoleStore::class);
        $store->install();
        $store->seed([
            'staff' => [
                'admin' => ['*'],
                'writer' => ['job.view', 'job.edit'],
                'creator' => ['job.view', 'job.create'],
                'reader' => ['job.view'],
            ],
        ]);
        $this->publisher()->publish($this->working('post', 'p1', ['title' => ['en' => 'Hello', 'de' => 'Hallo'], 'slug' => ['en' => 'hello', 'de' => 'hallo'], '_locales' => ['en', 'de']]), null);
        $this->working('post', 'p1', ['title' => ['en' => 'Draft', 'de' => 'Entwurf'], 'slug' => ['en' => 'hello', 'de' => 'hallo'], '_locales' => ['en', 'de']]);
        $this->working('job', 'j1', ['title' => ['en' => 'Cook', 'de' => 'Koch'], 'post' => 'p1', '_locales' => ['en', 'de']]);
        $this->working('memo', 'm1', ['title' => 'A memo']);
        $this->working('event', 'e1', ['title' => 'Draft party', 'slug' => 'party', 'status' => 'draft']);
        $this->working('broken', 'b1', ['title' => 'Broken']);
        $this->working('note', 'n1', ['name' => 'A note']);
        $hash = password_hash('secret', PASSWORD_DEFAULT);
        $this->write('config/auth.php', "<?php\nreturn " . var_export([
            'default_guard' => 'session',
            'guards' => ['session' => ['class' => \Preflow\Auth\SessionGuard::class, 'provider' => 'config', 'population' => 'staff']],
            'providers' => ['config' => ['class' => \Preflow\Auth\ConfigUserProvider::class, 'users' => array_map(
                static fn (string $role): array => ['email' => $role . '@site.test', 'password_hash' => $hash, 'roles' => [$role]],
                ['admin', 'writer', 'creator', 'reader'],
            )]],
            'session' => ['cookie' => 'folio_type_preview_test', 'path' => '/', 'secure' => false],
        ], true) . ";\n");
    }

    protected function tearDown(): void
    {
        Contexts::reset();
        $this->removeScaffold();
    }

    #[RunInSeparateProcess]
    public function test_type_preview_renders_draft_values_without_writing(): void
    {
        $this->signIn('admin@site.test');
        $notes = count($this->session->container()->get(DataManager::class)->queryType('note')->get()->items());

        $preview = $this->preview('/folio/job/j1/preview', ['_locale' => 'en', 'title' => 'Head cook', 'post' => 'p1']);

        $body = (string) $preview->getBody();
        $this->assertSame(200, $preview->getStatusCode(), $body);
        $this->assertStringStartsWith('<!doctype html>', ltrim($body));
        $this->assertStringContainsString('<body class="folio-preview-canvas">', $body, 'Folio\'s canvas');
        $this->assertStringContainsString('<title>Jobs</title>', $body, 'the type\'s label');
        $this->assertStringContainsString('<meta name="viewport" content="width=device-width, initial-scale=1">', $body);
        $this->assertMatchesRegularExpression('~<article class="job-card" data-type="job" data-view="card" data-locale="en" data-preview="yes"><h2>Head cook</h2>~', $body, 'the draft as typed, through the first view');
        $this->assertStringContainsString('rebeccapurple', substr($body, 0, (int) stripos($body, '</head>')), 'the component\'s CSS in the canvas head');
        $this->assertStringContainsString('preview-editor.js', $body, 'the editor bridge');
        $this->assertStringContainsString('preview-editor.css', $body);

        $this->assertSame('Cook', $this->stored('job', 'j1')->get('title')['en'] ?? null, 'the record is unchanged');
        $this->assertCount($notes, $this->session->container()->get(DataManager::class)->queryType('note')->get()->items(), 'the component\'s write was skipped');
        $this->assertSame([], $this->session->container()->get(TransportFactory::class)->get('mail')->sent(), 'its mail was not sent');
        $this->assertSame([], array_values(array_filter($preview->getHeader('Set-Cookie'), static fn (string $c): bool => str_starts_with($c, 'probe='))), 'nor its cookie');
        $this->assertSame(0, Contexts::depth());
    }

    #[RunInSeparateProcess]
    public function test_type_preview_reads_working_copies_of_linked_records(): void
    {
        $this->signIn('admin@site.test');

        $body = (string) $this->preview('/folio/job/j1/preview', ['_locale' => 'en', 'title' => 'Cook', 'post' => 'p1'])->getBody();

        $this->assertStringContainsString('<p class="linked">Draft</p>', $body, 'the linked post\'s working copy, not its published "Hello"');
    }

    #[RunInSeparateProcess]
    public function test_canvas_renders_the_editing_locale(): void
    {
        $this->signIn('admin@site.test');

        $de = (string) $this->preview('/folio/job/j1/preview', ['_locale' => 'de', 'title' => 'Chefkoch', 'post' => 'p1'])->getBody();

        $this->assertStringContainsString('<html lang="de">', $de);
        $this->assertStringContainsString('data-locale="de" data-preview="yes"><h2>Chefkoch</h2>', $de);
        $this->assertStringContainsString('<p class="linked">Entwurf</p>', $de, 'the linked record in the editing locale too');

        $other = (string) $this->preview('/folio/job/j1/preview', ['_locale' => 'fr', 'title' => 'Cook'])->getBody();
        $this->assertStringContainsString('<html lang="en">', $other, 'a language the type lacks is its default');
    }

    #[RunInSeparateProcess]
    public function test_view_choice_picks_the_component(): void
    {
        $this->signIn('admin@site.test');

        $wide = (string) $this->preview('/folio/job/j1/preview', ['_locale' => 'en', 'title' => 'Cook', '_preview_view' => 'wide'])->getBody();
        $this->assertStringContainsString('<div class="job-wide" data-view="wide">WIDE Cook</div>', $wide);
        $this->assertStringNotContainsString('job-card', $wide);

        $card = (string) $this->preview('/folio/job/j1/preview', ['_locale' => 'en', 'title' => 'Cook', '_preview_view' => 'card'])->getBody();
        $this->assertStringContainsString('data-view="card"', $card);

        $new = $this->preview('/folio/job/preview', ['_locale' => 'en', 'title' => 'New job', '_preview_view' => 'wide']);
        $this->assertSame(200, $new->getStatusCode(), 'a new record previews too');
        $this->assertStringContainsString('WIDE New job', (string) $new->getBody());
    }

    #[RunInSeparateProcess]
    public function test_a_stale_view_choice_falls_back(): void
    {
        $this->signIn('admin@site.test');

        foreach (['gone', 'page', '', 'Card'] as $stale) {
            $body = (string) $this->preview('/folio/job/j1/preview', ['_locale' => 'en', 'title' => 'Cook', '_preview_view' => $stale])->getBody();
            $this->assertStringContainsString('data-view="card"', $body, var_export($stale, true) . ': the first view (the type has no page)');
        }
        $this->assertStringContainsString('data-view="card"', (string) $this->preview('/folio/job/j1/preview', ['_locale' => 'en', 'title' => 'Cook', '_preview_view' => ['wide']])->getBody(), 'not a key');

        $event = (string) $this->preview('/folio/event/e1/preview', ['title' => 'Party', 'slug' => 'party', '_preview_view' => 'gone'])->getBody();
        $this->assertStringContainsString('<h1>EVENT Party</h1>', $event, 'a type with a route: its page');
    }

    #[RunInSeparateProcess]
    public function test_routed_type_with_preview_offers_page_and_its_views(): void
    {
        $this->signIn('admin@site.test');

        $form = (string) $this->send('GET', '/folio/event/e1/edit')->getBody();
        $this->assertStringContainsString('<button type="button" data-preview-view="page" aria-pressed="true">Page</button>', $form);
        $this->assertStringContainsString('<button type="button" data-preview-view="default" aria-pressed="false">Event</button>', $form, 'the default view under the type\'s singular label');
        $this->assertStringContainsString('<input type="hidden" name="_preview_view" value="page">', $form);
        $this->assertStringContainsString('data-preview-tab="page"', $form, 'the whole-page tab stays');
        $this->assertStringContainsString('page-preview.js', $form);

        $page = (string) $this->preview('/folio/event/e1/preview', ['title' => 'Party', 'slug' => 'party', '_preview_view' => 'page'])->getBody();
        $this->assertStringContainsString('<h1>EVENT Party</h1>', $page, '"page": the route\'s template');
        $this->assertStringNotContainsString('folio-preview-canvas', $page);
        $this->assertStringContainsString('<h1>EVENT Party</h1>', (string) $this->preview('/folio/event/e1/preview', ['title' => 'Party', 'slug' => 'party'])->getBody(), 'no choice: the page');

        $view = (string) $this->preview('/folio/event/e1/preview', ['title' => 'Party', 'slug' => 'party', '_preview_view' => 'default'])->getBody();
        $this->assertStringContainsString('<body class="folio-preview-canvas">', $view, 'a view key: the canvas');
        $this->assertStringContainsString('<article class="job-card" data-type="event" data-view="default" data-locale="en" data-preview="yes"><h2>Party</h2>', $view);
        $this->assertStringContainsString('preview-editor.js', $view);
    }

    #[RunInSeparateProcess]
    public function test_a_failing_component_stays_inside_the_canvas(): void
    {
        $this->signIn('admin@site.test');

        $render = $this->preview('/folio/broken/b1/preview', ['title' => 'Broken', '_preview_view' => 'render']);
        $body = (string) $render->getBody();
        $this->assertSame(200, $render->getStatusCode(), $body);
        $canvas = substr($body, (int) strpos($body, '<body class="folio-preview-canvas">'));
        $this->assertStringContainsString('<body class="folio-preview-canvas">', $body);
        $this->assertStringContainsString('The card could not read its record.', $canvas, 'a render failure: the boundary inside the canvas');
        $this->assertStringNotContainsString('<p>never</p>', $body);

        $build = $this->preview('/folio/broken/b1/preview', ['title' => 'Broken', '_preview_view' => 'build']);
        $body = (string) $build->getBody();
        $this->assertSame(200, $build->getStatusCode(), $body);
        $canvas = substr($body, (int) strpos($body, '<body class="folio-preview-canvas">'));
        $this->assertStringContainsString('<body class="folio-preview-canvas">', $body);
        $this->assertStringContainsString(htmlspecialchars(PreviewUnbuildable::class, ENT_QUOTES), $canvas, 'a component that cannot be built: its error inside the canvas');
        $this->assertStringContainsString('Countable', $canvas);
        $this->assertStringNotContainsString('<p>never built</p>', $body);
        $this->assertSame(0, Contexts::depth());
    }

    #[RunInSeparateProcess]
    public function test_type_preview_needs_the_types_edit_permission(): void
    {
        $this->signIn('writer@site.test');
        $this->assertSame(200, $this->preview('/folio/job/j1/preview', ['title' => 'Cook'])->getStatusCode(), 'job.edit: a stored record');
        $this->assertSame(403, $this->preview('/folio/job/preview', ['title' => 'Cook'])->getStatusCode(), 'a new record needs job.create');
        $this->assertStringContainsString('data-folio-split data-mode', (string) $this->send('GET', '/folio/job/j1/edit')->getBody());

        $this->signIn('creator@site.test');
        $this->assertSame(200, $this->preview('/folio/job/preview', ['title' => 'Cook'])->getStatusCode(), 'job.create: a new record');
        $this->assertSame(403, $this->preview('/folio/job/j1/preview', ['title' => 'Cook'])->getStatusCode(), 'a stored one needs job.edit');
        $this->assertStringContainsString('data-folio-split data-mode', (string) $this->send('GET', '/folio/job/new')->getBody());

        $this->signIn('reader@site.test');
        $this->assertSame(403, $this->preview('/folio/job/j1/preview', ['title' => 'Cook'])->getStatusCode());
        $this->assertSame(403, $this->preview('/folio/job/preview', ['title' => 'Cook'])->getStatusCode());
        $read = (string) $this->send('GET', '/folio/job/j1/edit')->getBody();
        $this->assertStringNotContainsString('data-folio-split data-mode', $read, 'no pane for a read-only form');
        $this->assertStringNotContainsString('_preview_view', $read);
    }

    #[RunInSeparateProcess]
    public function test_routeless_type_offers_no_whole_page_tab_and_no_share_link(): void
    {
        $this->signIn('admin@site.test');

        $form = (string) $this->send('GET', '/folio/job/j1/edit')->getBody();
        $this->assertStringContainsString('data-folio-split data-mode', $form, 'the live preview pane');
        $this->assertStringContainsString('data-preview-url="/folio/job/j1/preview"', $form);
        $this->assertStringNotContainsString('data-preview-tab', $form, 'no whole-page tab');
        $this->assertStringNotContainsString('data-page-preview', $form);
        $this->assertStringNotContainsString('folio-page-frame', $form);
        $this->assertStringNotContainsString('page-preview.js', $form);
        $this->assertStringNotContainsString('folio-share-button', $form, 'no share link');
        $this->assertStringNotContainsString('folio-preview-url', $form, 'no address');
        $this->assertStringContainsString('data-preview-viewport="375px"', $form, 'the width switch');
        $this->assertSame(404, $this->send('GET', '/folio/preview/page/job/j1')->getStatusCode());
    }

    #[RunInSeparateProcess]
    public function test_routeless_type_without_preview_still_has_no_pane(): void
    {
        $this->signIn('admin@site.test');

        $form = (string) $this->send('GET', '/folio/note/n1/edit')->getBody();
        $this->assertStringNotContainsString('data-folio-split data-mode', $form);
        $this->assertStringNotContainsString('data-preview-view=', $form);
        $this->assertStringNotContainsString('_preview_view', $form);
        $this->assertSame(404, $this->preview('/folio/note/n1/preview', ['name' => 'x'])->getStatusCode());
        $this->assertSame(404, $this->preview('/folio/note/preview', ['name' => 'x'])->getStatusCode());

        // A routed type without preview: the pane as in 0.93.1, without a view switch or input.
        $post = (string) $this->send('GET', '/folio/post/p1/edit')->getBody();
        $this->assertStringContainsString('data-folio-split data-mode', $post);
        $this->assertStringNotContainsString('data-preview-view=', $post);
        $this->assertStringNotContainsString('_preview_view', $post);
    }

    #[RunInSeparateProcess]
    public function test_site_canvas_override_is_used(): void
    {
        $this->write('resources/folio/frontend/preview-canvas.twig', '<!doctype html><html lang="{{ locale }}"><head><link rel="stylesheet" href="/site.css"><title>{{ label }}</title></head>'
            . '<body class="site-canvas" data-type="{{ type }}" data-view="{{ view }}" data-title="{{ record.title }}"><main>{{ preview }}</main></body></html>');
        $this->signIn('admin@site.test');

        $body = (string) $this->preview('/folio/job/j1/preview', ['_locale' => 'en', 'title' => 'Cook', '_preview_view' => 'wide'])->getBody();

        $this->assertStringContainsString('<link rel="stylesheet" href="/site.css">', $body, 'the site\'s canvas, with its base CSS');
        $this->assertStringContainsString('<title>Jobs</title>', $body);
        $this->assertStringContainsString('<body class="site-canvas" data-type="job" data-view="wide" data-title="Cook">', $body);
        $this->assertMatchesRegularExpression('~<main><div id="JobWide-[^"]+"><div class="job-wide" data-view="wide">WIDE Cook</div>~', $body, 'the component printed as it is');
        $this->assertStringNotContainsString('folio-preview-canvas', $body);
        $this->assertStringContainsString('preview-editor.js', $body);
    }

    #[RunInSeparateProcess]
    public function test_view_switch_shows_only_with_two_choices(): void
    {
        $this->signIn('admin@site.test');

        $job = (string) $this->send('GET', '/folio/job/j1/edit')->getBody();
        $this->assertStringContainsString('<div class="folio-segmented folio-preview-views" role="group" aria-label="View">', $job);
        $this->assertStringContainsString('<button type="button" data-preview-view="card" aria-pressed="true">Card</button>', $job);
        $this->assertStringContainsString('<button type="button" data-preview-view="wide" aria-pressed="false">Wide</button>', $job);
        $this->assertStringContainsString('<input type="hidden" name="_preview_view" value="card">', $job, 'the first view first');
        $this->assertStringNotContainsString('data-preview-view="page"', $job, 'no page without a route');
        // The view switch sits beside the width switch.
        $this->assertGreaterThan(strpos($job, 'folio-device-switch'), strpos($job, 'folio-preview-views'));

        $memo = (string) $this->send('GET', '/folio/memo/m1/edit')->getBody();
        $this->assertStringContainsString('data-folio-split data-mode', $memo, 'one view: the pane');
        $this->assertStringNotContainsString('data-preview-view=', $memo, 'but nothing to switch');
        $this->assertStringNotContainsString('folio-preview-views', $memo);
        $this->assertStringContainsString('WIDE A memo', (string) $this->preview('/folio/memo/m1/preview', ['title' => 'A memo'])->getBody());
    }

    private function signIn(string $email): void
    {
        $f = new Psr17Factory();
        if ($this->session !== null) {
            $out = $this->session->handle($f->createServerRequest('POST', '/folio/logout')->withHeader('Sec-Fetch-Site', 'same-origin')->withParsedBody(['_csrf_token' => $this->token]));
            $this->assertSame(302, $out->getStatusCode(), 'signed out first');
        }
        $this->session ??= $this->app();
        $page = (string) $this->session->handle($f->createServerRequest('GET', '/folio/login'))->getBody();
        preg_match('/name="_csrf_token" value="([^"]+)"/', $page, $m);
        $this->token = $m[1] ?? '';
        $login = $this->session->handle($f->createServerRequest('POST', '/folio/login')->withHeader('Sec-Fetch-Site', 'same-origin')->withParsedBody([
            '_csrf_token' => $this->token, 'email' => $email, 'password' => 'secret',
        ]));
        $this->assertSame(302, $login->getStatusCode());
    }

    private function send(string $method, string $uri): ResponseInterface
    {
        return $this->session->handle((new Psr17Factory())->createServerRequest($method, $uri));
    }

    /** @param array<string, mixed> $body the form as the live preview posts it */
    private function preview(string $uri, array $body): ResponseInterface
    {
        return $this->session->handle((new Psr17Factory())->createServerRequest('POST', $uri)
            ->withHeader('Sec-Fetch-Site', 'same-origin')
            ->withParsedBody($body + ['_csrf_token' => $this->token]));
    }
}

/** The site of TypePreviewTest: page_side_effects() writes a note, queues a mail and a cookie. */
final class TypePreviewSiteProvider extends ServiceProvider
{
    public function register(Container $container): void
    {
    }

    public function boot(Container $container): void
    {
        $container->get(TemplateEngineInterface::class)->addFunction(new TemplateFunctionDefinition(
            name: 'page_side_effects',
            callable: static function () use ($container): string {
                $dm = $container->get(DataManager::class);
                $dm->saveType(DynamicRecord::fromArray($dm->getTypeRegistry()->get('note'), ['uuid' => bin2hex(random_bytes(4)), 'name' => 'Visited']), false);
                $container->get(Notifier::class)->sendLater(new Email('owner@site.test', 'Visited', 'Someone read the page.'));
                $container->get(CookieQueue::class)->queue('probe=1; Path=/');

                return '';
            },
        ));
    }
}
