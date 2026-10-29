<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Integration;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Psr\Http\Message\ResponseInterface;

/**
 * End-to-end coverage for Folio booted inside a real Preflow application:
 * the service provider wires routes + the @folio template namespace, content
 * types are discovered from config/models, and admin CRUD + frontend rendering
 * work through the real kernel and real Twig.
 *
 * Debug is enabled so Twig runs with strict_variables — this is what catches
 * template defects (e.g. missing-key access on a new record's empty values).
 *
 * No config/auth.php is scaffolded, so no session is started and CsrfMiddleware
 * is a no-op; the POST therefore needs no CSRF token. (CSRF token emission is
 * covered separately and exercised by the example app.)
 */
final class FolioAppTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        putenv('APP_DEBUG=1'); // -> Twig strict_variables on
        $this->dir = sys_get_temp_dir() . '/folio_app_' . bin2hex(random_bytes(5));
        $this->scaffold();
    }

    protected function tearDown(): void
    {
        putenv('APP_DEBUG');
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        $this->rrmdir($this->dir);
    }

    public function test_admin_dashboard_lists_discovered_type(): void
    {
        $res = $this->get('/folio');
        $this->assertSame(200, $res->getStatusCode());
        $this->assertStringContainsString('Pages', (string) $res->getBody()); // the type label
    }

    public function test_dashboard_renders_type_card_with_count(): void
    {
        // Seed one record so the count is non-zero and deterministic.
        $app = $this->app();
        $app->handle((new Psr17Factory())->createServerRequest('POST', '/folio/page')
            ->withParsedBody(['title' => 'Seed', 'slug' => 'seed', 'body' => 'B', 'status' => 'published']));

        $body = (string) $app->handle((new Psr17Factory())->createServerRequest('GET', '/folio'))->getBody();
        $this->assertStringContainsString('folio-card-grid', $body);
        $this->assertStringContainsString('folio-card-label', $body);
        $this->assertStringContainsString('Pages', $body);
        $this->assertStringContainsString('1 record', $body);
    }

    public function test_create_form_renders_under_strict_twig(): void
    {
        // New-record form passes an empty values map; the template must guard
        // missing-key access or it throws a Twig RuntimeError under strict mode.
        $res = $this->get('/folio/page/new');
        $this->assertSame(200, $res->getStatusCode());
        $this->assertStringContainsString('name="title"', (string) $res->getBody());
    }

    public function test_create_form_renders_fields_via_registry(): void
    {
        $body = (string) $this->get('/folio/page/new')->getBody();
        // string field -> text input; text field (body) -> textarea (registry mapping)
        $this->assertStringContainsString('name="title"', $body);
        $this->assertStringContainsString('type="text"', $body);
        $this->assertStringContainsString('<trix-editor input="folio-rt-body"', $body);
    }

    public function test_richtext_form_includes_trix_assets_and_editor(): void
    {
        $body = (string) $this->get('/folio/page/new')->getBody();
        $this->assertStringContainsString('<trix-editor input="folio-rt-body"', $body);
        $this->assertStringContainsString('/folio/_assets/trix.js?v=', $body);
        $this->assertStringContainsString('/folio/_assets/trix.css?v=', $body);
    }

    public function test_create_form_has_styled_actions_and_cancel(): void
    {
        $body = (string) $this->get('/folio/page/new')->getBody();
        $this->assertStringContainsString('name="title"', $body);       // fields still render
        $this->assertStringContainsString('folio-form-actions', $body);  // action bar
        $this->assertStringContainsString('btn btn-primary', $body);     // emerald save button
        $this->assertStringContainsString('>Cancel<', $body);            // cancel link
        $this->assertStringContainsString('href="/folio/page"', $body);  // back to list
    }

    public function test_create_then_render_on_frontend(): void
    {
        $app = $this->app();

        $create = $app->handle(
            (new Psr17Factory())->createServerRequest('POST', '/folio/page')
                ->withParsedBody([
                    'title'  => 'About Us',
                    'slug'   => 'about',
                    'body'   => '<p>Hello from Folio.</p>',
                    'status' => 'published',
                ])
        );
        $this->assertSame(302, $create->getStatusCode());

        $page = $app->handle((new Psr17Factory())->createServerRequest('GET', '/about'));
        $this->assertSame(200, $page->getStatusCode());
        $this->assertStringContainsString('About Us', (string) $page->getBody());
    }

    public function test_layout_inline_script_carries_request_nonce(): void
    {
        // Debug off: the policy is enforced (debug mode is report-only and adds the
        // dev toolbar, which is not CSP-clean by design).
        putenv('APP_DEBUG=0');
        $response = $this->get('/folio');
        putenv('APP_DEBUG=1');
        $body = (string) $response->getBody();

        preg_match("/'nonce-([^']+)'/", $response->getHeaderLine('Content-Security-Policy'), $m);
        $this->assertNotEmpty($m[1] ?? '', 'enforced CSP carries a nonce');

        preg_match_all('/<script\b[^>]*>/i', $body, $tags);
        $inline = array_filter($tags[0], fn (string $t) => !preg_match('/\bsrc=|type="application\/json"/', $t));
        $this->assertNotEmpty($inline);
        foreach ($inline as $tag) {
            $this->assertStringContainsString('nonce="' . $m[1] . '"', $tag);
        }

        // Trix inserts its own <style> elements; it takes the nonce from this meta.
        $this->assertStringContainsString('<meta name="csp-nonce" content="' . $m[1] . '">', $body);
    }

    public function test_unchecked_boolean_saves_false_on_update(): void
    {
        $this->writeEventType();
        $app = $this->app();
        $f = new \Nyholm\Psr7\Factory\Psr17Factory();
        $app->handle($f->createServerRequest('POST', '/folio/event')
            ->withParsedBody(['title' => 'E', 'featured' => '1', 'state' => 'a']));
        $dm = $app->container()->get(\Preflow\Data\DataManager::class);
        $id = $dm->queryType('event')->where('title', 'E')->first()->getId();
        $this->assertTrue((bool) $dm->findType('event', $id)->get('featured'));

        // unchecked box: only the hidden "0" is submitted
        $app->handle($f->createServerRequest('POST', '/folio/event/' . $id)
            ->withAttribute('type', 'event')->withAttribute('id', $id)
            ->withParsedBody(['title' => 'E', 'featured' => '0', 'state' => 'a']));
        $this->assertFalse($dm->findType('event', $id)->get('featured'));

        // field absent from the body altogether
        $app->handle($f->createServerRequest('POST', '/folio/event/' . $id)
            ->withAttribute('type', 'event')->withAttribute('id', $id)
            ->withParsedBody(['title' => 'E', 'featured' => '1', 'state' => 'a']));
        $app->handle($f->createServerRequest('POST', '/folio/event/' . $id)
            ->withAttribute('type', 'event')->withAttribute('id', $id)
            ->withParsedBody(['title' => 'E', 'state' => 'a']));
        $this->assertFalse($dm->findType('event', $id)->get('featured'));
    }

    public function test_number_field_saves_decimal_on_step(): void
    {
        file_put_contents($this->dir . '/config/models/event.json', json_encode([
            'key' => 'event', 'table' => 'event', 'storage' => 'json', 'id_field' => 'uuid', 'label' => 'Events',
            'fields' => [
                'title' => ['type' => 'string'],
                'price' => ['type' => 'number', 'step' => 0.5],
            ],
        ]));
        $app = $this->app();
        $f = new \Nyholm\Psr7\Factory\Psr17Factory();

        $res = $app->handle($f->createServerRequest('POST', '/folio/event')
            ->withParsedBody(['title' => 'A', 'price' => '2.5']));
        $this->assertNotSame(422, $res->getStatusCode());
        $dm = $app->container()->get(\Preflow\Data\DataManager::class);
        $this->assertEquals(2.5, $dm->queryType('event')->where('title', 'A')->first()->get('price'));

        $res = $app->handle($f->createServerRequest('POST', '/folio/event')
            ->withParsedBody(['title' => 'B', 'price' => '2.3']));
        $this->assertSame(422, $res->getStatusCode());
        $this->assertStringContainsString('Must be a multiple of 0.5.', (string) $res->getBody());
    }

    public function test_optional_number_field_with_step_saves_empty(): void
    {
        file_put_contents($this->dir . '/config/models/event.json', json_encode([
            'key' => 'event', 'table' => 'event', 'storage' => 'json', 'id_field' => 'uuid', 'label' => 'Events',
            'fields' => [
                'title' => ['type' => 'string'],
                'price' => ['type' => 'number', 'step' => 0.5],
                'weight' => ['type' => 'number', 'step' => 'any'],
            ],
        ]));
        $app = $this->app();
        $f = new \Nyholm\Psr7\Factory\Psr17Factory();

        $res = $app->handle($f->createServerRequest('POST', '/folio/event')
            ->withParsedBody(['title' => 'Empty', 'price' => '', 'weight' => '']));
        $this->assertNotSame(422, $res->getStatusCode(), (string) $res->getBody());
        $record = $app->container()->get(\Preflow\Data\DataManager::class)->queryType('event')->where('title', 'Empty')->first();
        $this->assertNotNull($record);
        $this->assertEmpty($record->get('price'));
        $this->assertEmpty($record->get('weight'));

        $res = $app->handle($f->createServerRequest('POST', '/folio/event')
            ->withParsedBody(['title' => 'Off', 'price' => '2.3', 'weight' => 'abc']));
        $this->assertSame(422, $res->getStatusCode());
        $body = (string) $res->getBody();
        $this->assertStringContainsString('Must be a multiple of 0.5.', $body);
        $this->assertStringContainsString('Must be a number.', $body);
    }

    public function test_required_number_field_with_step_posted_empty_fails_on_required_only(): void
    {
        file_put_contents($this->dir . '/config/models/event.json', json_encode([
            'key' => 'event', 'table' => 'event', 'storage' => 'json', 'id_field' => 'uuid', 'label' => 'Events',
            'fields' => [
                'title' => ['type' => 'string'],
                'price' => ['type' => 'number', 'step' => 0.5, 'validate' => ['required']],
            ],
        ]));
        $app = $this->app();
        $f = new \Nyholm\Psr7\Factory\Psr17Factory();

        $res = $app->handle($f->createServerRequest('POST', '/folio/event')
            ->withParsedBody(['title' => 'Empty', 'price' => '']));
        $this->assertSame(422, $res->getStatusCode());
        $body = (string) $res->getBody();
        $this->assertStringContainsString('This field is required.', $body);
        $this->assertStringNotContainsString('Must be a number.', $body);
        $this->assertStringNotContainsString('Must be a multiple of', $body);
    }

    public function test_select_renders_stale_value_and_rejects_it_on_save(): void
    {
        $this->writeEventType();
        $app = $this->app();
        $dm = $app->container()->get(\Preflow\Data\DataManager::class);
        $typeDef = $app->container()->get(\Preflow\Data\TypeRegistry::class)->get('event');
        $dm->saveType(\Preflow\Data\DynamicRecord::fromArray($typeDef, ['uuid' => 'ev1', 'title' => 'Old', 'state' => 'archived']), validate: false);

        $form = (string) $app->handle((new \Nyholm\Psr7\Factory\Psr17Factory())
            ->createServerRequest('GET', '/folio/event/ev1/edit')
            ->withAttribute('type', 'event')->withAttribute('id', 'ev1'))->getBody();
        $this->assertStringContainsString('<option value="archived" selected>archived (no longer an option)</option>', $form);

        $res = $app->handle((new \Nyholm\Psr7\Factory\Psr17Factory())->createServerRequest('POST', '/folio/event/ev1')
            ->withAttribute('type', 'event')->withAttribute('id', 'ev1')
            ->withParsedBody(['title' => 'Old', 'state' => 'archived']));
        $this->assertSame(422, $res->getStatusCode());
    }

    public function test_type_rules_are_enforced_on_save(): void
    {
        $this->writeEventType(); // state has no "validate" in the JSON; select adds in:a,b itself
        $res = $this->app()->handle((new \Nyholm\Psr7\Factory\Psr17Factory())->createServerRequest('POST', '/folio/event')
            ->withParsedBody(['title' => 'E', 'state' => 'zzz']));

        $this->assertSame(422, $res->getStatusCode());
        $this->assertStringContainsString('Must be one of', (string) $res->getBody());
    }

    public function test_app_field_type_replaces_builtin(): void
    {
        file_put_contents(
            $this->dir . '/config/folio.php',
            "<?php\nreturn ['path' => '/folio', 'field_types' => [\\Preflow\\Folio\\Tests\\Fixtures\\Field\\TestStringType::class]];\n",
        );

        $this->assertStringContainsString('data-test-string', (string) $this->get('/folio/page/new')->getBody());
    }

    public function test_admin_shell_renders_sidebar_stylesheet_and_toggle(): void
    {
        $body = (string) $this->get('/folio')->getBody();
        $this->assertStringContainsString('class="folio-shell"', $body);
        $this->assertStringContainsString('class="folio-sidebar"', $body);
        $this->assertStringContainsString('/folio/_assets/admin.css?v=', $body); // versioned link
        $this->assertStringContainsString('id="folio-theme-toggle"', $body);
        $this->assertStringContainsString("localStorage.getItem('folio-theme')", $body); // no-flash script
    }

    public function test_no_extensions_keeps_todays_sidebar(): void
    {
        $body = (string) $this->get('/folio')->getBody();
        preg_match('#<nav class="folio-nav".*?</nav>#s', $body, $nav);
        preg_match_all('#<a href="([^"]*)"[^>]*>.*?<span>([^<]*)</span>#s', $nav[0], $links, PREG_SET_ORDER);
        $items = array_map(static fn (array $m): array => [$m[2], $m[1]], $links);
        preg_match_all('#<span class="folio-nav-label">([^<]*)</span>#', $nav[0], $labels);

        $this->assertSame([
            ['Dashboard', '/folio'],
            ['Authors', '/folio/author'],
            ['Notes', '/folio/note'],
            ['Pages', '/folio/page'],
            ['Assets', '/folio/assets'],
            ['Translations', '/folio/translations'], // Settings → Translations and Cache are always there
            ['Cache', '/folio/cache'],
        ], $items);
        $this->assertSame(['Content', 'Library', 'Settings'], $labels[1]);
        $this->assertSame(1, substr_count($nav[0], 'class="active" aria-current="page"'));
        $this->assertMatchesRegularExpression('#<a href="/folio" class="active"#', $nav[0]);

        $page = (string) $this->get('/folio/page')->getBody();
        $this->assertMatchesRegularExpression('#<a href="/folio/page" class="active"#', $page);
        $this->assertDoesNotMatchRegularExpression('#<a href="/folio" class="active"#', $page);
    }

    public function test_admin_js_loads_on_every_admin_page(): void
    {
        // Confirm dialogs, toasts and the unsaved-changes guard live in admin.js.
        foreach (['/folio', '/folio/page', '/folio/note/new'] as $uri) {
            $body = (string) $this->get($uri)->getBody();
            $this->assertSame(1, substr_count($body, '/folio/_assets/admin.js?v='), $uri);
        }
    }

    public function test_layout_has_toast_region(): void
    {
        $this->assertStringContainsString('<div class="folio-toasts" aria-live="polite" data-folio-toasts></div>', (string) $this->get('/folio')->getBody());
    }

    public function test_admin_stylesheet_is_served(): void
    {
        $res = $this->get('/folio/_assets/admin.css');
        $this->assertSame(200, $res->getStatusCode());
        $this->assertStringContainsString('text/css', $res->getHeaderLine('Content-Type'));
        $this->assertStringContainsString(':root', (string) $res->getBody());
        $this->assertStringContainsString('--c-accent', (string) $res->getBody());
    }

    public function test_vendored_trix_assets_served(): void
    {
        $js = $this->get('/folio/_assets/trix.js');
        $this->assertSame(200, $js->getStatusCode());
        $this->assertStringContainsString('text/javascript', $js->getHeaderLine('Content-Type'));
        $this->assertStringContainsString('Trix', (string) $js->getBody());

        $css = $this->get('/folio/_assets/trix.css');
        $this->assertSame(200, $css->getStatusCode());
        $this->assertStringContainsString('text/css', $css->getHeaderLine('Content-Type'));
        $this->assertStringContainsString('trix-editor', (string) $css->getBody());
    }

    public function test_unknown_slug_is_404(): void
    {
        $this->assertSame(404, $this->get('/no-such-page')->getStatusCode());
    }

    public function test_app_route_beats_folio_catch_all(): void
    {
        // app/pages/index.twig serves '/'; Folio's catch-all must not shadow it.
        $res = $this->get('/');
        $this->assertSame(200, $res->getStatusCode());
        $this->assertStringContainsString('HOME PAGE', (string) $res->getBody());
    }

    public function test_list_shows_empty_state_when_no_records(): void
    {
        $body = (string) $this->get('/folio/page')->getBody();
        $this->assertStringContainsString('folio-empty', $body);
        $this->assertStringContainsString('No pages yet', $body); // names the type
    }

    public function test_list_shows_row_with_edit_and_delete_actions(): void
    {
        $app = $this->app();
        $app->handle((new Psr17Factory())->createServerRequest('POST', '/folio/page')
            ->withParsedBody(['title' => 'Listed', 'slug' => 'listed', 'body' => 'B', 'status' => 'published']));

        $body = (string) $app->handle((new Psr17Factory())->createServerRequest('GET', '/folio/page'))->getBody();
        $this->assertStringContainsString('folio-table', $body);
        $this->assertStringContainsString('Listed', $body);
        $this->assertStringContainsString('/edit', $body);
        $this->assertStringContainsString('/delete', $body);
        $this->assertStringContainsString('name="_csrf_token"', $body);
    }

    public function test_login_template_renders_standalone_card(): void
    {
        $app = $this->app();
        $engine = $app->container()->get(\Preflow\View\TemplateEngineInterface::class);
        $html = $engine->render('@folio/admin/login.twig', []);

        $this->assertStringContainsString('folio-auth-card', $html);
        $this->assertStringContainsString('type="email"', $html);
        $this->assertStringContainsString('type="password"', $html);
        $this->assertStringContainsString('type="submit"', $html);
        $this->assertStringNotContainsString('folio-sidebar', $html); // not the app shell
    }

    public function test_invalid_create_rerenders_form_with_errors_422(): void
    {
        $app = $this->app();
        // page model requires title, slug, status(in:draft,published). Omit title.
        $res = $app->handle((new \Nyholm\Psr7\Factory\Psr17Factory())
            ->createServerRequest('POST', '/folio/page')
            ->withParsedBody(['title' => '', 'slug' => 'x', 'body' => 'b', 'status' => 'draft']));

        $this->assertSame(422, $res->getStatusCode());
        $body = (string) $res->getBody();
        $this->assertStringContainsString('form-error', $body);   // error block shown
        $this->assertStringContainsString('name="title"', $body);  // form re-rendered
        $this->assertStringContainsString('value="x"', $body);     // slug input retained
    }

    public function test_valid_create_still_redirects(): void
    {
        $app = $this->app();
        $res = $app->handle((new \Nyholm\Psr7\Factory\Psr17Factory())
            ->createServerRequest('POST', '/folio/page')
            ->withParsedBody(['title' => 'Valid', 'slug' => 'valid', 'body' => 'b', 'status' => 'published']));
        $this->assertSame(302, $res->getStatusCode());
    }

    public function test_richtext_frontend_renders_sanitized_html(): void
    {
        $app = $this->app();
        $f = new \Nyholm\Psr7\Factory\Psr17Factory();
        $app->handle($f->createServerRequest('POST', '/folio/page')->withParsedBody([
            'title' => 'Post', 'slug' => 'post',
            'body' => '<p>safe</p><script>alert(1)</script>', 'status' => 'published',
        ]));

        $html = (string) $app->handle($f->createServerRequest('GET', '/post'))->getBody();
        $this->assertStringContainsString('<p>safe</p>', $html);   // rich HTML rendered raw
        // Extract the article body only — the debug toolbar may inject its own <script>.
        preg_match('/<article>(.*?)<\/article>/s', $html, $m);
        $article = $m[1] ?? $html;
        $this->assertStringNotContainsString('<script', $article); // sanitized end-to-end
    }

    public function test_new_form_is_multipart_with_file_input(): void
    {
        $body = (string) $this->get('/folio/page/new')->getBody();
        $this->assertStringContainsString('enctype="multipart/form-data"', $body);
        $this->assertStringContainsString('type="file"', $body);
        $this->assertStringContainsString('name="cover"', $body);
    }

    public function test_upload_goes_into_the_library(): void
    {
        $app = $this->app();
        $f = new \Nyholm\Psr7\Factory\Psr17Factory();
        $png = $this->pngBytes();

        $res = $app->handle($f->createServerRequest('POST', '/folio/page')
            ->withParsedBody(['title' => 'A', 'slug' => 'a', 'body' => 'b', 'status' => 'published'])
            ->withUploadedFiles(['cover' => $f->createUploadedFile($f->createStream($png), strlen($png), UPLOAD_ERR_OK, 'pic.png', 'image/png')]));
        $this->assertSame(302, $res->getStatusCode());

        $record = $app->container()->get(\Preflow\Data\DataManager::class)->queryType('page')->where('slug', 'a')->first();
        $cover = (string) $record->get('cover');
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $cover);
        $asset = $app->container()->get(\Preflow\Media\MediaLibrary::class)->find($cover);
        $this->assertSame('pic.png', $asset?->originalName);
        $this->assertFileExists($this->dir . '/public/media/' . $asset->path);
    }

    public function test_frontend_renders_uploaded_image(): void
    {
        $app = $this->app();
        $f = new \Nyholm\Psr7\Factory\Psr17Factory();
        $png = $this->pngBytes();
        $app->handle($f->createServerRequest('POST', '/folio/page')
            ->withParsedBody(['title' => 'Img', 'slug' => 'img', 'body' => 'b', 'status' => 'published'])
            ->withUploadedFiles(['cover' => $f->createUploadedFile($f->createStream($png), strlen($png), UPLOAD_ERR_OK, 'pic.png', 'image/png')]));

        $html = (string) $app->handle($f->createServerRequest('GET', '/img'))->getBody();
        $this->assertStringContainsString('<img src="/media/', $html);
    }

    public function test_update_removes_existing_asset(): void
    {
        $app = $this->app();
        $f = new \Nyholm\Psr7\Factory\Psr17Factory();
        $png = $this->pngBytes();
        $app->handle($f->createServerRequest('POST', '/folio/page')
            ->withParsedBody(['title' => 'R', 'slug' => 'r', 'body' => 'b', 'status' => 'published'])
            ->withUploadedFiles(['cover' => $f->createUploadedFile($f->createStream($png), strlen($png), UPLOAD_ERR_OK, 'pic.png', 'image/png')]));

        $dm = $app->container()->get(\Preflow\Data\DataManager::class);
        $record = $dm->queryType('page')->where('slug', 'r')->first();
        $id = $record->getId();
        $this->assertNotSame('', (string) $record->get('cover'));

        // The editor posts its selection: only the empty marker means "nothing selected".
        $app->handle($f->createServerRequest('POST', '/folio/page/' . $id)
            ->withAttribute('type', 'page')->withAttribute('id', $id)
            ->withParsedBody(['title' => 'R', 'slug' => 'r', 'body' => 'b', 'status' => 'published', 'cover_ids' => ['']]));

        $after = $dm->findType('page', $id);
        $this->assertSame('', (string) $after->get('cover'));
    }

    private function pngBytes(): string
    {
        $image = imagecreatetruecolor(40, 20);
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    public function test_relation_picker_lists_targets_and_round_trips(): void
    {
        $app = $this->app();
        $f = new \Nyholm\Psr7\Factory\Psr17Factory();

        // create a target author
        $app->handle($f->createServerRequest('POST', '/folio/author')
            ->withParsedBody(['name' => 'Ada Lovelace']));
        $dm = $app->container()->get(\Preflow\Data\DataManager::class);
        $authorId = $dm->queryType('author')->first()->getId();

        // the new-page form lists the author as an option
        $newForm = (string) $app->handle($f->createServerRequest('GET', '/folio/page/new'))->getBody();
        $this->assertStringContainsString('<select name="author"', $newForm);
        $this->assertStringContainsString('Ada Lovelace', $newForm);

        // create a page selecting that author, then the edit form shows it selected
        $app->handle($f->createServerRequest('POST', '/folio/page')
            ->withParsedBody(['title' => 'Rel', 'slug' => 'rel', 'body' => 'b', 'status' => 'published', 'author' => $authorId]));
        $pageId = $dm->queryType('page')->where('slug', 'rel')->first()->getId();

        $editForm = (string) $app->handle($f->createServerRequest('GET', '/folio/page/' . $pageId . '/edit')
            ->withAttribute('type', 'page')->withAttribute('id', $pageId))->getBody();
        $this->assertStringContainsString('value="' . $authorId . '" selected', $editForm);
    }

    public function test_matrix_editor_renders_picker_and_loads_admin_js(): void
    {
        $app = $this->app();
        $f = new \Nyholm\Psr7\Factory\Psr17Factory();
        $app->handle($f->createServerRequest('POST', '/folio/note')->withParsedBody(['name' => 'Note A']));

        $body = (string) $app->handle($f->createServerRequest('GET', '/folio/page/new'))->getBody();
        $this->assertStringContainsString('data-folio-matrix', $body);
        $this->assertStringContainsString('Note A', $body);               // record in options blob
        $this->assertStringContainsString('/folio/_assets/admin.js?v=', $body); // matrix declares admin.js
    }

    public function test_matrix_reference_round_trips_and_renders_on_frontend(): void
    {
        $app = $this->app();
        $f = new \Nyholm\Psr7\Factory\Psr17Factory();
        $app->handle($f->createServerRequest('POST', '/folio/note')->withParsedBody(['name' => 'Block Note']));
        $dm = $app->container()->get(\Preflow\Data\DataManager::class);
        $noteId = $dm->queryType('note')->first()->getId();

        $app->handle($f->createServerRequest('POST', '/folio/page')->withParsedBody([
            'title' => 'Composed', 'slug' => 'composed', 'body' => 'b', 'status' => 'published',
            'blocks' => [['_type' => 'note', 'id' => $noteId]],
        ]));
        $pageId = $dm->queryType('page')->where('slug', 'composed')->first()->getId();

        $edit = (string) $app->handle($f->createServerRequest('GET', '/folio/page/' . $pageId . '/edit')
            ->withAttribute('type', 'page')->withAttribute('id', $pageId))->getBody();
        $this->assertStringContainsString('value="' . $noteId . '"', $edit); // row present in editor

        $front = (string) $app->handle($f->createServerRequest('GET', '/composed'))->getBody();
        $this->assertStringContainsString('Block Note', $front); // referenced note rendered via _default type template
    }

    public function test_matrix_editor_renders_create_new_button(): void
    {
        $body = (string) $this->app()->handle(
            (new \Nyholm\Psr7\Factory\Psr17Factory())->createServerRequest('GET', '/folio/page/new')
        )->getBody();
        $this->assertStringContainsString('data-matrix-create', $body);
        $this->assertStringContainsString('"prefix":"/folio"', $body); // prefix embedded for admin.js
    }

    public function test_drawer_create_returns_postmessage_with_id(): void
    {
        $app = $this->app();
        $f = new \Nyholm\Psr7\Factory\Psr17Factory();
        $res = $app->handle($f->createServerRequest('POST', '/folio/note?_drawer=1')
            ->withParsedBody(['name' => 'Drawer Note']));

        $this->assertSame(200, $res->getStatusCode());
        $body = (string) $res->getBody();
        $this->assertStringContainsString("'folio-drawer'", $body);          // postMessage source literal
        $id = $app->container()->get(\Preflow\Data\DataManager::class)
            ->queryType('note')->where('name', 'Drawer Note')->first()->getId();
        $this->assertStringContainsString($id, $body);                        // new id in the payload
    }

    public function test_label_endpoint_returns_record_label(): void
    {
        $app = $this->app();
        $f = new \Nyholm\Psr7\Factory\Psr17Factory();
        $app->handle($f->createServerRequest('POST', '/folio/note')->withParsedBody(['name' => 'Labeled Note']));
        $id = $app->container()->get(\Preflow\Data\DataManager::class)
            ->queryType('note')->where('name', 'Labeled Note')->first()->getId();

        $res = $app->handle($f->createServerRequest('GET', '/folio/note/' . $id . '/label'));
        $this->assertSame(200, $res->getStatusCode());
        $data = json_decode((string) $res->getBody(), true);
        $this->assertSame('Labeled Note', $data['label']);
        $this->assertSame($id, $data['id']);
    }

    public function test_matrix_view_override_selects_per_view_template(): void
    {
        $app = $this->app();
        $f = new \Nyholm\Psr7\Factory\Psr17Factory();
        $app->handle($f->createServerRequest('POST', '/folio/note')->withParsedBody(['name' => 'Viewed Note']));
        $dm = $app->container()->get(\Preflow\Data\DataManager::class);
        $noteId = $dm->queryType('note')->where('name', 'Viewed Note')->first()->getId();

        $app->handle($f->createServerRequest('POST', '/folio/page')->withParsedBody([
            'title' => 'Views', 'slug' => 'views', 'body' => 'b', 'status' => 'published',
            'blocks' => [
                ['_type' => 'note', 'id' => $noteId],                    // default view
                ['_type' => 'note', 'id' => $noteId, 'view' => 'card'],  // card view
            ],
        ]));

        $front = (string) $app->handle($f->createServerRequest('GET', '/views'))->getBody();
        $this->assertStringContainsString('note-default', $front); // viewless -> note.twig
        $this->assertStringContainsString('note-card', $front);    // view=card -> note_card.twig
    }

    public function test_matrix_view_round_trips_in_editor_and_drops_undeclared(): void
    {
        $app = $this->app();
        $f = new \Nyholm\Psr7\Factory\Psr17Factory();
        $app->handle($f->createServerRequest('POST', '/folio/note')->withParsedBody(['name' => 'RT Note']));
        $dm = $app->container()->get(\Preflow\Data\DataManager::class);
        $noteId = $dm->queryType('note')->where('name', 'RT Note')->first()->getId();

        $app->handle($f->createServerRequest('POST', '/folio/page')->withParsedBody([
            'title' => 'RT', 'slug' => 'rt', 'body' => 'b', 'status' => 'published',
            'blocks' => [
                ['_type' => 'note', 'id' => $noteId, 'view' => 'card'],  // declared -> kept
                ['_type' => 'note', 'id' => $noteId, 'view' => 'bogus'], // undeclared -> stripped
            ],
        ]));
        $pageId = $dm->queryType('page')->where('slug', 'rt')->first()->getId();

        $edit = (string) $app->handle($f->createServerRequest('GET', '/folio/page/' . $pageId . '/edit')
            ->withAttribute('type', 'page')->withAttribute('id', $pageId))->getBody();
        $this->assertStringContainsString('<option value="card" selected>card</option>', $edit); // kept view shows selected
        $this->assertStringNotContainsString('bogus', $edit);                                     // undeclared dropped
    }

    public function test_preview_renders_draft_without_saving(): void
    {
        $app = $this->app();
        $f = new \Nyholm\Psr7\Factory\Psr17Factory();
        $res = $app->handle($f->createServerRequest('POST', '/folio/page/preview')->withParsedBody([
            'title' => 'Live Draft', 'slug' => 'live-draft', 'body' => 'b', 'status' => 'draft',
        ]));

        $this->assertSame(200, $res->getStatusCode());                 // not a 302 -> hit preview, not update/store
        $this->assertStringContainsString('Live Draft', (string) $res->getBody());
        $dm = $app->container()->get(\Preflow\Data\DataManager::class);
        $this->assertNull($dm->queryType('page')->where('slug', 'live-draft')->first()); // nothing saved
    }

    public function test_preview_existing_record_leaves_storage_unchanged(): void
    {
        $app = $this->app();
        $f = new \Nyholm\Psr7\Factory\Psr17Factory();
        $app->handle($f->createServerRequest('POST', '/folio/page')->withParsedBody([
            'title' => 'Orig', 'slug' => 'orig', 'body' => 'b', 'status' => 'published',
        ]));
        $dm = $app->container()->get(\Preflow\Data\DataManager::class);
        $id = $dm->queryType('page')->where('slug', 'orig')->first()->getId();

        $res = $app->handle($f->createServerRequest('POST', '/folio/page/' . $id . '/preview')
            ->withParsedBody(['title' => 'Edited', 'slug' => 'orig', 'body' => 'b', 'status' => 'published']));

        $this->assertStringContainsString('Edited', (string) $res->getBody());          // draft shown
        $this->assertSame('Orig', $dm->findType('page', $id)->get('title'));             // storage unchanged
    }

    public function test_edit_form_has_preview_button_for_page(): void
    {
        $app = $this->app();
        $f = new \Nyholm\Psr7\Factory\Psr17Factory();
        $body = (string) $app->handle($f->createServerRequest('GET', '/folio/page/new'))->getBody();
        $this->assertStringContainsString('data-folio-preview', $body);
        $this->assertStringContainsString('data-preview-url="/folio/page/preview"', $body);
    }

    public function test_edit_form_renders_split_view_for_routed_type(): void
    {
        $body = (string) $this->get('/folio/page/new')->getBody();

        $this->assertStringContainsString('data-folio-split', $body);
        $this->assertMatchesRegularExpression('#<section class="folio-split-stage" data-folio-stage data-preview-url="/folio/page/preview"#', $body);
        $this->assertMatchesRegularExpression('#data-folio-resizer role="separator" aria-orientation="vertical"#', $body);
        foreach (['form', 'split', 'preview'] as $mode) {
            $this->assertStringContainsString('data-folio-view="' . $mode . '"', $body);
        }
        $this->assertStringContainsString('<iframe class="folio-preview-frame" name="folio-preview-frame"', $body);
        $this->assertStringContainsString('data-preview-viewport="768px"', $body); // tablet preset
        $this->assertStringContainsString('data-preview-viewport="375px"', $body); // mobile preset
    }

    public function test_plain_form_has_no_split_view(): void
    {
        $body = (string) $this->get('/folio/note/new')->getBody();

        $this->assertStringNotContainsString('data-folio-split', $body);
        $this->assertStringNotContainsString('data-folio-view', $body);
    }

    public function test_userland_template_is_used(): void
    {
        $this->writeArticleType(['route' => '/blog/{slug}', 'template' => 'folio/article.twig']);
        @mkdir($this->dir . '/folio', 0777, true);
        file_put_contents($this->dir . '/folio/article.twig', '<html><head></head><body>ARTICLE-TEMPLATE {{ record.title }} {{ type }}</body></html>');
        $this->seedArticle('a1', 'hello', 'published');

        $res = $this->get('/blog/hello');

        $this->assertSame(200, $res->getStatusCode());
        $this->assertStringContainsString('ARTICLE-TEMPLATE Hello article', (string) $res->getBody());
    }

    public function test_public_render_has_no_field_markers(): void
    {
        $app = $this->app();
        $app->handle((new \Nyholm\Psr7\Factory\Psr17Factory())->createServerRequest('POST', '/folio/page')
            ->withParsedBody(['title' => 'Pub', 'slug' => 'pub', 'body' => 'b', 'status' => 'published']));

        $body = (string) $this->get('/pub')->getBody();

        $this->assertStringContainsString('Pub', $body);
        $this->assertStringNotContainsString('data-folio-field', $body);
    }

    public function test_article_type_is_previewable(): void
    {
        $this->writeArticleType(['route' => '/blog/{slug}']);

        $body = (string) $this->get('/folio/article/new')->getBody();

        $this->assertStringContainsString('data-folio-preview', $body);
        $this->assertStringContainsString('/folio/article/preview', $body);
    }

    public function test_frontend_config_conflict_fails_at_boot(): void
    {
        $this->writeArticleType(['route' => '/{slug}', 'home' => 'home']);
        $page = json_decode((string) file_get_contents($this->dir . '/config/models/page.json'), true);
        $page['frontend'] = ['route' => '/{slug}'];
        file_put_contents($this->dir . '/config/models/page.json', json_encode($page));

        $this->expectException(\Preflow\Folio\Content\FrontendConfigException::class);
        $this->app();
    }

    public function test_list_uses_configured_columns(): void
    {
        $this->writeItemType(['columns' => ['title', 'featured']]);
        $this->postItem(['title' => 'Alpha', 'featured' => '1', 'note' => 'n']);

        $body = (string) $this->get('/folio/item')->getBody();

        $this->assertMatchesRegularExpression('#<th[^>]*>(<a[^>]*>)?Title\b.*?</th>\s*<th[^>]*>(<a[^>]*>)?Featured\b#s', $body);
        $this->assertStringContainsString('<td>Yes</td>', $body);
        $this->assertStringNotContainsString('<th>Note</th>', $body);
    }

    public function test_list_default_columns_without_config(): void
    {
        $this->writeItemType([]);
        $this->postItem(['title' => 'Alpha', 'note' => 'n']);

        $body = (string) $this->get('/folio/item')->getBody();

        // first three plain fields, no hardcoded page columns
        $this->assertMatchesRegularExpression('#<th[^>]*>(<a[^>]*>)?Title\b.*?</th><th[^>]*>(<a[^>]*>)?Featured\b.*?</th><th[^>]*>(<a[^>]*>)?Note\b#s', $body);
        $this->assertStringNotContainsString('<th>Slug</th>', $body);
        $this->assertStringContainsString('<td>No</td>', $body);
    }

    public function test_list_default_sort_descending(): void
    {
        $this->writeItemType(['sort' => '-title']);
        $this->postItem(['title' => 'A item']);
        $this->postItem(['title' => 'B item']);

        $body = (string) $this->get('/folio/item')->getBody();

        $this->assertLessThan(strpos($body, 'A item'), strpos($body, 'B item'));
    }

    public function test_orderable_move_down_persists(): void
    {
        $this->writeItemType(['orderable' => true]);
        foreach (['A item', 'B item', 'C item'] as $title) {
            $this->postItem(['title' => $title]);
        }
        $this->move($this->itemId('A item'), 'down');

        $this->assertListOrder(['B item', 'A item', 'C item']);
    }

    public function test_reorder_initializes_missing_order(): void
    {
        $this->writeItemType(['orderable' => true]);
        $app = $this->app();
        $typeDef = $app->container()->get(\Preflow\Data\TypeRegistry::class)->get('item');
        $dm = $app->container()->get(\Preflow\Data\DataManager::class);
        foreach (['i1' => 'A item', 'i2' => 'B item', 'i3' => 'C item'] as $id => $title) {
            $dm->saveType(\Preflow\Data\DynamicRecord::fromArray($typeDef, ['uuid' => $id, 'title' => $title]), validate: false);
        }

        $this->move('i2', 'up');

        $this->assertListOrder(['B item', 'A item', 'C item']);
        foreach (['i1', 'i2', 'i3'] as $id) {
            $this->assertIsInt($dm->findType('item', $id)->get('_order'));
        }
    }

    public function test_new_record_appends_to_end(): void
    {
        $this->writeItemType(['orderable' => true]);
        $this->postItem(['title' => 'A item']);
        $this->postItem(['title' => 'B item']);
        $this->move($this->itemId('B item'), 'up');
        $this->postItem(['title' => 'C item']);

        $this->assertListOrder(['B item', 'A item', 'C item']);
    }

    public function test_update_keeps_order(): void
    {
        $this->writeItemType(['orderable' => true]);
        $this->postItem(['title' => 'A item']);
        $this->postItem(['title' => 'B item']);
        $id = $this->itemId('A item');

        $this->app()->handle((new \Nyholm\Psr7\Factory\Psr17Factory())->createServerRequest('POST', '/folio/item/' . $id)
            ->withAttribute('type', 'item')->withAttribute('id', $id)
            ->withParsedBody(['title' => 'A item']));

        $this->assertListOrder(['A item', 'B item']);
    }

    public function test_edit_form_shows_language_tabs_and_visibility(): void
    {
        $this->enableGerman();
        $id = $this->createPage(['title' => 'About', 'slug' => 'about', 'body' => 'b', 'status' => 'published']);

        $body = (string) $this->get('/folio/page/' . $id . '/edit?locale=de')->getBody();

        $this->assertStringContainsString('href="/folio/page/' . $id . '/edit?locale=en"', $body);
        $this->assertMatchesRegularExpression('#<a[^>]*class="[^"]*is-active[^"]*"[^>]*href="/folio/page/' . $id . '/edit\?locale=de"|href="/folio/page/' . $id . '/edit\?locale=de"[^>]*class="[^"]*is-active#', $body);
        $this->assertStringContainsString('name="_locales[]"', $body);
        $this->assertStringContainsString('name="_locales_submitted" value="1"', $body);
        $this->assertStringContainsString('name="_locale" value="de"', $body);
    }

    public function test_empty_translation_shows_fallback_placeholder(): void
    {
        $this->enableGerman();
        $id = $this->createPage(['title' => 'About', 'slug' => 'about', 'body' => 'b', 'status' => 'published']);

        $body = (string) $this->get('/folio/page/' . $id . '/edit?locale=de')->getBody();

        $this->assertStringContainsString('placeholder="About"', $body);
        $this->assertStringContainsString('Falls back to English', $body);
        $this->assertStringContainsString('folio-reference', $body);
    }

    public function test_shared_field_is_marked(): void
    {
        $this->enableGerman();
        $id = $this->createPage(['title' => 'About', 'slug' => 'about', 'body' => 'b', 'status' => 'published']);

        $this->assertStringContainsString('Shared across languages', (string) $this->get('/folio/page/' . $id . '/edit?locale=de')->getBody());
    }

    public function test_save_in_de_redirects_back_to_de_and_keeps_en(): void
    {
        $this->enableGerman();
        $id = $this->createPage(['title' => 'About', 'slug' => 'about', 'body' => 'b', 'status' => 'published']);

        $res = $this->postPage('/folio/page/' . $id, $id, ['_locale' => 'de', 'title' => 'Über', 'slug' => 'ueber', 'body' => 'b', 'status' => 'published']);

        $this->assertSame(302, $res->getStatusCode());
        $this->assertStringContainsString('locale=de', $res->getHeaderLine('Location'));
        $stored = $this->dm()->findType('page', $id);
        $this->assertSame(['en' => 'About', 'de' => 'Über'], $stored->get('title'));
    }

    public function test_validation_error_rerender_stays_in_locale(): void
    {
        $this->enableGerman();
        $id = $this->createPage(['title' => 'About', 'slug' => 'about', 'body' => 'b', 'status' => 'published']);

        $res = $this->postPage('/folio/page/' . $id, $id, ['_locale' => 'de', 'title' => 'Über', 'slug' => 'Nicht Gültig', 'body' => 'b', 'status' => 'published']);

        $this->assertSame(422, $res->getStatusCode());
        $body = (string) $res->getBody();
        $this->assertStringContainsString('name="_locale" value="de"', $body);
        $this->assertStringContainsString('value="Nicht Gültig"', $body);
        $this->assertSame(['en' => 'about'], (array) $this->dm()->findType('page', $id)->get('slug'));
    }

    public function test_unknown_locale_param_uses_default(): void
    {
        $this->enableGerman();
        $id = $this->createPage(['title' => 'About', 'slug' => 'about', 'body' => 'b', 'status' => 'published']);

        $this->assertStringContainsString('name="_locale" value="en"', (string) $this->get('/folio/page/' . $id . '/edit?locale=xx')->getBody());

        $this->postPage('/folio/page/' . $id, $id, ['_locale' => 'xx', 'title' => 'New', 'slug' => 'about', 'body' => 'b', 'status' => 'published']);
        $this->assertSame(['en' => 'New'], $this->dm()->findType('page', $id)->get('title'));
    }

    public function test_list_shows_missing_translation_badge(): void
    {
        $this->enableGerman();
        $this->createPage(['title' => 'About', 'slug' => 'about', 'body' => 'b', 'status' => 'published', '_locales_submitted' => '1', '_locales' => ['de']]);

        $body = (string) $this->get('/folio/page')->getBody();

        $this->assertStringContainsString('<th>Languages</th>', $body);
        $this->assertStringContainsString('de · 3 missing', $body); // every text the default language has: title, slug and body
        $this->assertStringContainsString('folio-badge is-incomplete', $body);
    }

    public function test_list_shows_outdated_badge(): void
    {
        $this->outdatedPage();

        $body = (string) $this->get('/folio/page')->getBody();

        $this->assertStringContainsString('de · 1 outdated', $body);
        $this->assertStringContainsString('folio-badge is-outdated', $body);
        $this->assertStringNotContainsString('missing', $body);
    }

    public function test_list_marks_a_finished_translation_ok(): void
    {
        $this->enableGerman();
        $id = $this->createPage(['title' => 'About', 'slug' => 'about', 'body' => 'b', 'status' => 'published', '_locales_submitted' => '1', '_locales' => ['de']]);
        $this->postPage('/folio/page/' . $id, $id, ['_locale' => 'de', 'title' => 'Über', 'slug' => 'ueber', 'body' => 'k', 'status' => 'published']);

        $body = (string) $this->get('/folio/page')->getBody();

        $this->assertMatchesRegularExpression('/<span class="folio-badge is-ok">de<\/span>/', $body);
    }

    public function test_form_marks_outdated_field_and_offers_still_correct(): void
    {
        $id = $this->outdatedPage();

        $body = (string) $this->get('/folio/page/' . $id . '/edit?locale=de')->getBody();

        $this->assertStringContainsString('folio-outdated', $body);
        $this->assertStringContainsString('/folio/page/' . $id . '/_translations/confirm', $body);
        $this->assertStringContainsString('name="_confirm_path" value="title"', $body);
                $this->assertStringContainsString('Still correct', $body);
        $this->assertStringNotContainsString('name="_confirm_path" value="slug"', $body);

        $this->assertStringNotContainsString('folio-outdated', (string) $this->get('/folio/page/' . $id . '/edit')->getBody(), 'the default language has nothing outdated');
    }

    public function test_still_correct_on_a_plain_type_saves_typed_edits_with_the_stamp(): void
    {
        $id = $this->outdatedPage();

        $res = $this->app()->handle((new \Nyholm\Psr7\Factory\Psr17Factory())->createServerRequest('POST', '/folio/page/' . $id . '/_translations/confirm')
            ->withAttribute('type', 'page')->withAttribute('id', $id)
            ->withParsedBody(['_locale' => 'de', 'title' => 'Über uns', 'slug' => 'ueber-about', 'body' => 'k', 'status' => 'published', '_confirm_path' => 'title']));

        $this->assertSame(302, $res->getStatusCode(), (string) $res->getBody());
        $this->assertSame('Über uns', $this->dm()->findType('page', $id)->get('title')['de']);
        $body = (string) $this->get('/folio/page/' . $id . '/edit?locale=de')->getBody();
        $this->assertStringNotContainsString('folio-outdated', $body);
    }

    public function test_language_tab_shows_counts(): void
    {
        $this->enableGerman();
        $id = $this->createPage(['title' => 'About', 'slug' => 'about', 'body' => 'b', 'status' => 'published', '_locales_submitted' => '1', '_locales' => ['de']]);

        $body = (string) $this->get('/folio/page/' . $id . '/edit')->getBody();
        $this->assertStringContainsString('title="de · 3 missing"', $body);

        $outdated = (string) $this->get('/folio/page/' . $this->outdatedPage('Other') . '/edit')->getBody();
        $this->assertStringContainsString('title="de · 1 outdated"', $outdated);
    }

    /** A page whose German translation was made from an English title that has changed since. */
    private function outdatedPage(string $title = 'About'): string
    {
        $this->enableGerman();
        $slug = strtolower($title);
        $id = $this->createPage(['title' => $title, 'slug' => $slug, 'body' => 'b', 'status' => 'published', '_locales_submitted' => '1', '_locales' => ['de']]);
        $this->postPage('/folio/page/' . $id, $id, ['_locale' => 'de', 'title' => 'Über', 'slug' => 'ueber-' . $slug, 'body' => 'k', 'status' => 'published']);
        $this->postPage('/folio/page/' . $id, $id, ['_locale' => 'en', 'title' => $title . '!', 'slug' => $slug, 'body' => 'b', 'status' => 'published']);

        return $id;
    }

    public function test_translated_slug_resolves_under_prefix(): void
    {
        $this->enableGerman();
        $id = $this->createPage(['title' => 'About', 'slug' => 'about', 'body' => 'b', 'status' => 'published', '_locales_submitted' => '1', '_locales' => ['de']]);
        $this->postPage('/folio/page/' . $id, $id, ['_locale' => 'de', 'title' => 'Über mich', 'slug' => 'ueber-mich', 'body' => 'b', 'status' => 'published']);

        $res = $this->get('/de/ueber-mich');

        $this->assertSame(200, $res->getStatusCode());
        $body = (string) $res->getBody();
        $this->assertStringContainsString('Über mich', $body);
        $this->assertStringContainsString('<html lang="de">', $body);
    }

    public function test_unavailable_locale_is_404(): void
    {
        $this->enableGerman();
        $this->createPage(['title' => 'About', 'slug' => 'about', 'body' => 'b', 'status' => 'published']); // visible in en only

        $this->assertSame(200, $this->get('/about')->getStatusCode());
        $this->assertSame(404, $this->get('/de/about')->getStatusCode());
    }

    public function test_missing_translation_falls_back_per_field(): void
    {
        $this->enableGerman();
        $id = $this->createPage(['title' => 'About', 'slug' => 'about', 'body' => '<p>English body</p>', 'status' => 'published', '_locales_submitted' => '1', '_locales' => ['de']]);
        $this->postPage('/folio/page/' . $id, $id, ['_locale' => 'de', 'title' => 'Über', 'slug' => 'ueber', 'body' => '', 'status' => 'published']);

        $body = (string) $this->get('/de/ueber')->getBody();

        $this->assertStringContainsString('Über', $body);
        $this->assertStringContainsString('English body', $body);
    }

    public function test_fallback_richtext_is_sanitized(): void
    {
        $this->enableGerman();
        $id = $this->createPage(['title' => 'About', 'slug' => 'about', 'body' => '<b>x</b><script>alert(1)</script>', 'status' => 'published', '_locales_submitted' => '1', '_locales' => ['de']]);
        $this->postPage('/folio/page/' . $id, $id, ['_locale' => 'de', 'title' => 'Über', 'slug' => 'ueber', 'body' => '', 'status' => 'published']);

        $body = (string) $this->get('/de/ueber')->getBody();

        $this->assertStringContainsString('<b>x</b>', $body);
        $this->assertStringNotContainsString('<script>alert', $body);
    }

    public function test_alternate_links(): void
    {
        $this->enableGerman();
        $id = $this->createPage(['title' => 'About', 'slug' => 'about', 'body' => 'b', 'status' => 'published', '_locales_submitted' => '1', '_locales' => ['de']]);
        $this->postPage('/folio/page/' . $id, $id, ['_locale' => 'de', 'title' => 'Über mich', 'slug' => 'ueber-mich', 'body' => 'b', 'status' => 'published']);

        $body = (string) $this->get('/about')->getBody();

        $this->assertStringContainsString('<link rel="alternate" hreflang="de" href="/de/ueber-mich">', $body);
        $this->assertStringContainsString('<link rel="alternate" hreflang="en" href="/about">', $body);
        $this->assertStringContainsString('<link rel="alternate" hreflang="x-default" href="/about">', $body);
    }

    public function test_alternate_links_are_absolute_when_host_is_known(): void
    {
        $this->enableGerman();
        $id = $this->createPage(['title' => 'About', 'slug' => 'about', 'body' => 'b', 'status' => 'published', '_locales_submitted' => '1', '_locales' => ['de']]);
        $this->postPage('/folio/page/' . $id, $id, ['_locale' => 'de', 'title' => 'Über mich', 'slug' => 'ueber-mich', 'body' => 'b', 'status' => 'published']);

        $body = (string) $this->get('https://example.test/about')->getBody();

        // Search engines require fully qualified hreflang URLs.
        $this->assertStringContainsString('<link rel="alternate" hreflang="de" href="https://example.test/de/ueber-mich">', $body);
        $this->assertStringContainsString('<link rel="alternate" hreflang="x-default" href="https://example.test/about">', $body);
    }

    public function test_default_locale_form_shows_errors_of_other_locales(): void
    {
        $this->enableGerman();
        $id = $this->createPage(['title' => 'About', 'slug' => 'about', 'body' => 'b', 'status' => 'published']);
        // A stored German slug that no longer passes the (tightened) rule.
        $record = $this->dm()->findType('page', $id);
        $record->set('slug', ['en' => 'about', 'de' => 'Nicht Gültig']);
        $this->dm()->saveType($record, validate: false);

        $res = $this->postPage('/folio/page/' . $id, $id, ['_locale' => 'en', 'title' => 'About', 'slug' => 'about', 'body' => 'b', 'status' => 'published']);

        $this->assertSame(422, $res->getStatusCode());
        $body = (string) $res->getBody();
        $this->assertStringContainsString('folio-locale-errors', $body);
        $this->assertMatchesRegularExpression('#German[^<]*</a>[^<]*Slug#', $body);
    }

    public function test_legacy_scalar_slug_resolves_in_default_locale_only(): void
    {
        // Stored before the type became translatable: plain scalars.
        $app = $this->app();
        $app->handle((new \Nyholm\Psr7\Factory\Psr17Factory())->createServerRequest('POST', '/folio/page')
            ->withParsedBody(['title' => 'Legacy', 'slug' => 'legacy', 'body' => 'b', 'status' => 'published']));
        $this->enableGerman();

        $this->assertSame(200, $this->get('/legacy')->getStatusCode());
        $this->assertSame(404, $this->get('/de/legacy')->getStatusCode());
    }

    public function test_same_slug_in_two_locales(): void
    {
        $this->enableGerman();
        $id = $this->createPage(['title' => 'About EN', 'slug' => 'about', 'body' => 'b', 'status' => 'published', '_locales_submitted' => '1', '_locales' => ['de']]);
        $this->postPage('/folio/page/' . $id, $id, ['_locale' => 'de', 'title' => 'About DE', 'slug' => 'about', 'body' => 'b', 'status' => 'published']);

        $this->assertStringContainsString('About EN', (string) $this->get('/about')->getBody());
        $this->assertStringContainsString('About DE', (string) $this->get('/de/about')->getBody());
    }

    public function test_matrix_reference_renders_in_page_locale(): void
    {
        $this->enableGerman();
        $note = json_decode((string) file_get_contents($this->dir . '/config/models/note.json'), true);
        $note['fields']['name']['translatable'] = true;
        file_put_contents($this->dir . '/config/models/note.json', json_encode($note));
        $f = new \Nyholm\Psr7\Factory\Psr17Factory();
        $this->app()->handle($f->createServerRequest('POST', '/folio/note')->withParsedBody(['name' => 'English note']));
        $noteId = (string) $this->dm()->queryType('note')->first()->getId();
        $this->app()->handle($f->createServerRequest('POST', '/folio/note/' . $noteId)
            ->withAttribute('type', 'note')->withAttribute('id', $noteId)->withParsedBody(['_locale' => 'de', 'name' => 'Deutsche Notiz']));
        $id = $this->createPage(['title' => 'Composed', 'slug' => 'composed', 'body' => 'b', 'status' => 'published',
            'blocks' => [['_type' => 'note', 'id' => $noteId]], '_locales_submitted' => '1', '_locales' => ['de']]);
        $this->postPage('/folio/page/' . $id, $id, ['_locale' => 'de', 'title' => 'Zusammengesetzt', 'slug' => 'zusammen', 'body' => 'b',
            'status' => 'published', 'blocks' => [['_type' => 'note', 'id' => $noteId]]]);

        $this->assertStringContainsString('Deutsche Notiz', (string) $this->get('/de/zusammen')->getBody());
        $this->assertStringContainsString('English note', (string) $this->get('/composed')->getBody());
    }

    public function test_folio_url_links_a_record_in_a_locale(): void
    {
        $this->enableGerman();
        $id = $this->createPage(['title' => 'About', 'slug' => 'about', 'body' => 'b', 'status' => 'published', '_locales_submitted' => '1', '_locales' => ['de']]);
        $this->postPage('/folio/page/' . $id, $id, ['_locale' => 'de', 'title' => 'Über', 'slug' => 'ueber', 'body' => 'b', 'status' => 'published']);
        $this->writeArticleType(['route' => '/blog/{slug}', 'template' => 'folio/article.twig']);
        // Once any type declares a route, page needs its own (the implicit legacy route is gone).
        $page = json_decode((string) file_get_contents($this->dir . '/config/models/page.json'), true);
        $page['frontend'] = ['route' => '/{slug}', 'home' => 'home'];
        $page['publishing'] = false; // pins the legacy convention: the stored record is the live one
        file_put_contents($this->dir . '/config/models/page.json', json_encode($page));
        @mkdir($this->dir . '/folio', 0777, true);
        file_put_contents($this->dir . '/folio/article.twig', '<html><head></head><body>de={{ folio_url("page", "' . $id . '", "de") }} en={{ folio_url("page", "' . $id . '", "en") }} none={{ folio_url("page", "missing") ?? "null" }}</body></html>');
        $this->seedArticle('a1', 'hello', 'published');

        $body = (string) $this->get('/blog/hello')->getBody();

        $this->assertStringContainsString('de=/de/ueber en=/about none=null', $body);
    }

    public function test_preview_renders_editing_locale(): void
    {
        $this->enableGerman();
        $id = $this->createPage(['title' => 'About', 'slug' => 'about', 'body' => 'b', 'status' => 'published']);

        $res = $this->postPage('/folio/page/' . $id . '/preview', $id, ['_locale' => 'de', 'title' => 'Vorschau', 'slug' => 'vorschau', 'body' => 'b', 'status' => 'published']);

        $body = (string) $res->getBody();
        $this->assertStringContainsString('Vorschau', $body);
        $this->assertStringContainsString('<html lang="de">', $body);
    }

    public function test_single_locale_form_has_no_language_ui(): void
    {
        $id = $this->createPage(['title' => 'About', 'slug' => 'about', 'body' => 'b', 'status' => 'published']);

        $body = (string) $this->get('/folio/page/' . $id . '/edit')->getBody();

        $this->assertStringNotContainsString('_locales_submitted', $body);
        $this->assertStringNotContainsString('Shared across languages', $body);
    }

    public function test_block_validation_error_is_422_and_not_saved(): void
    {
        $this->enableBlocks();
        $uid = 'aaaaaaaaaaaaaaaa';

        $res = $this->app()->handle((new \Nyholm\Psr7\Factory\Psr17Factory())->createServerRequest('POST', '/folio/page')
            ->withParsedBody(['title' => 'Blocks', 'slug' => 'blocks', 'body' => 'b', 'status' => 'published',
                'sections' => ['_order' => [$uid], $uid => ['_block' => 'hero', 'headline' => '']]]));

        $this->assertSame(422, $res->getStatusCode());
        $this->assertNull($this->dm()->queryType('page')->where('slug', 'blocks')->first());
    }

    public function test_valid_blocks_save(): void
    {
        $this->enableBlocks();
        $uid = 'aaaaaaaaaaaaaaaa';

        $id = $this->createPage(['title' => 'Blocks', 'slug' => 'blocks', 'body' => 'b', 'status' => 'published',
            'sections' => ['_order' => [$uid], $uid => ['_block' => 'hero', 'headline' => 'Hello']]]);

        $sections = $this->dm()->findType('page', $id)->get('sections');
        $this->assertCount(1, $sections);
        $this->assertSame('hero', $sections[0]['_block']);
        $this->assertSame('Hello', $sections[0]['headline']);
    }

    public function test_blocks_editor_renders_cards_picker_and_order(): void
    {
        $this->enableBlocks();
        $uid = 'aaaaaaaaaaaaaaaa';
        $id = $this->createPage(['title' => 'Blocks', 'slug' => 'blocks', 'body' => 'b', 'status' => 'published',
            'sections' => ['_order' => [$uid], $uid => ['_block' => 'hero', 'headline' => 'Hello']]]);

        $body = (string) $this->get('/folio/page/' . $id . '/edit')->getBody();

        $this->assertStringContainsString('data-block-uid="' . $uid . '"', $body);
        $this->assertStringContainsString('name="sections[_order][]" value="' . $uid . '"', $body);
        $this->assertStringContainsString('name="sections[' . $uid . '][headline]"', $body);
        $this->assertStringContainsString('name="sections[' . $uid . '][_block]" value="hero"', $body);
        $this->assertStringContainsString('/folio/_blocks/hero/new?', $body);
        $this->assertStringContainsString('/folio/_blocks/link/new?', $body);
        $this->assertStringContainsString('/folio/_assets/htmx.js?v=', $body);
        $this->assertStringContainsString('enctype="multipart/form-data"', $body); // blocks may hold uploads
    }

    public function test_block_fields_without_a_label_are_labelled_by_field_name(): void
    {
        $this->enableBlocks();
        $uid = 'aaaaaaaaaaaaaaaa';
        $id = $this->createPage(['title' => 'Blocks', 'slug' => 'blocks', 'body' => 'b', 'status' => 'published',
            'sections' => ['_order' => [$uid], $uid => ['_block' => 'hero', 'headline' => 'Hello']]]);

        $body = (string) $this->get('/folio/page/' . $id . '/edit')->getBody();

        $this->assertMatchesRegularExpression('/<label[^>]*>\s*Headline\b/', $body);
        $this->assertDoesNotMatchRegularExpression('/<label[^>]*>\s*Sections\[/i', $body);
    }

    public function test_block_card_has_drag_handle_and_live_region(): void
    {
        $this->enableBlocks();
        $uid = 'aaaaaaaaaaaaaaaa';
        $id = $this->createPage(['title' => 'Blocks', 'slug' => 'blocks', 'body' => 'b', 'status' => 'published',
            'sections' => ['_order' => [$uid], $uid => ['_block' => 'hero', 'headline' => 'Hello']]]);

        $body = (string) $this->get('/folio/page/' . $id . '/edit')->getBody();

        $this->assertMatchesRegularExpression('#<button type="button" class="folio-block-handle" data-block-handle aria-roledescription="drag handle" aria-label="Reorder Hero"#', $body);
        $this->assertStringContainsString('<div class="folio-sr" aria-live="assertive" data-blocks-live></div>', $body);
    }

    public function test_blocks_editor_loads_admin_js_for_card_controls(): void
    {
        // Collapse, move and remove are admin.js; without it only duplicate (htmx) responds.
        // A type whose only non-plain field is the blocks list (no matrix or asset field
        // to pull admin.js in by accident).
        $this->enableBlocks();
        $page = json_decode((string) file_get_contents($this->dir . '/config/models/page.json'), true);
        $page['fields'] = array_intersect_key($page['fields'], array_flip(['title', 'slug', 'status', 'sections']));
        file_put_contents($this->dir . '/config/models/page.json', json_encode($page));
        $id = $this->createPage(['title' => 'Blocks', 'slug' => 'blocks', 'status' => 'published']);

        $body = (string) $this->get('/folio/page/' . $id . '/edit')->getBody();

        $this->assertStringContainsString('data-blocks-field', $body);
        $this->assertStringContainsString('/folio/_assets/admin.js?v=', $body);
    }

    public function test_block_errors_render_inside_the_card(): void
    {
        $this->enableBlocks();
        $uid = 'aaaaaaaaaaaaaaaa';

        $res = $this->app()->handle((new \Nyholm\Psr7\Factory\Psr17Factory())->createServerRequest('POST', '/folio/page')
            ->withParsedBody(['title' => 'Blocks', 'slug' => 'blocks', 'body' => 'b', 'status' => 'published',
                'sections' => ['_order' => [$uid], $uid => ['_block' => 'hero', 'headline' => '']]]));

        $body = (string) $res->getBody();
        $this->assertMatchesRegularExpression('#class="[^"]*is-open[^"]*" id="block-' . $uid . '"[\s\S]*?This field is required#', $body);
        $this->assertStringContainsString('href="#block-' . $uid . '"', $body); // error summary link
    }

    public function test_new_block_fragment_returns_card_with_fresh_uid(): void
    {
        $this->enableBlocks();

        $res = $this->get('/folio/_blocks/hero/new?name=sections&locale=en&depth=0');

        $this->assertSame(200, $res->getStatusCode());
        $this->assertMatchesRegularExpression('#name="sections\[([a-f0-9]{16})\]\[headline\]"#', (string) $res->getBody());
        $this->assertStringContainsString('name="sections[_order][]"', (string) $res->getBody());
    }

    public function test_new_block_fragment_rejects_bad_name_and_unknown_key(): void
    {
        $this->enableBlocks();

        $this->assertSame(400, $this->get('/folio/_blocks/hero/new?name=' . rawurlencode('x"><script>') . '&locale=en&depth=0')->getStatusCode());
        $this->assertSame(404, $this->get('/folio/_blocks/nope/new?name=sections&locale=en&depth=0')->getStatusCode());
    }

    public function test_unknown_block_renders_remove_only_card(): void
    {
        $this->enableBlocks();
        $id = $this->createPage(['title' => 'Blocks', 'slug' => 'blocks', 'body' => 'b', 'status' => 'published']);
        $record = $this->dm()->findType('page', $id);
        $record->set('sections', [['_uid' => 'bbbbbbbbbbbbbbbb', '_block' => 'gone', 'foo' => 'bar']]);
        $this->dm()->saveType($record, validate: false);

        $body = (string) $this->get('/folio/page/' . $id . '/edit')->getBody();

        $this->assertStringContainsString('Unknown block', $body);
        $this->assertStringContainsString('name="sections[bbbbbbbbbbbbbbbb][_raw]"', $body);
        $this->assertStringContainsString('data-block-remove', $body);
    }

    public function test_nested_blocks_render_inside_card(): void
    {
        $this->enableBlocks();
        $a = 'aaaaaaaaaaaaaaaa';
        $c = 'cccccccccccccccc';
        $id = $this->createPage(['title' => 'Blocks', 'slug' => 'blocks', 'body' => 'b', 'status' => 'published',
            'sections' => ['_order' => [$a], $a => ['_block' => 'hero', 'headline' => 'H',
                'tradeoff' => ['_order' => [$c], $c => ['_block' => 'tradeoff_note', 'chose' => 'one conviction']]]]]);

        $body = (string) $this->get('/folio/page/' . $id . '/edit')->getBody();

        $this->assertStringContainsString('name="sections[' . $a . '][tradeoff][' . $c . '][chose]"', $body);
        $this->assertStringContainsString('name="sections[' . $a . '][tradeoff][' . $c . '][cover]"', $body);
        $this->assertStringContainsString('/folio/_blocks/tradeoff_note/new?name=' . rawurlencode('sections[' . $a . '][tradeoff]'), $body);
    }

    public function test_depth_limit_shows_notice(): void
    {
        $this->enableBlocks();
        file_put_contents($this->dir . '/config/folio.php', str_replace("'blocks' => [", "'blocks' => ['max_depth' => 1, ", (string) file_get_contents($this->dir . '/config/folio.php')));
        $a = 'aaaaaaaaaaaaaaaa';
        $id = $this->createPage(['title' => 'Blocks', 'slug' => 'blocks', 'body' => 'b', 'status' => 'published',
            'sections' => ['_order' => [$a], $a => ['_block' => 'hero', 'headline' => 'H']]]);

        $this->assertStringContainsString('Nested too deep to edit here', (string) $this->get('/folio/page/' . $id . '/edit')->getBody());
    }

    public function test_renders_component_blocks_in_order_with_data_only_children(): void
    {
        $this->enableBlocks(withFrontendTemplate: true);
        $a = 'aaaaaaaaaaaaaaaa';
        $b = 'bbbbbbbbbbbbbbbb';
        $c = 'cccccccccccccccc';
        $this->createPage(['title' => 'Blocks', 'slug' => 'blocks', 'body' => 'b', 'status' => 'published',
            'sections' => ['_order' => [$b, $a],
                $a => ['_block' => 'hero', 'headline' => 'Second', 'tradeoff' => ['_order' => [$c], $c => ['_block' => 'tradeoff_note', 'chose' => 'one conviction']]],
                $b => ['_block' => 'hero', 'headline' => 'First'],
            ]]);

        $body = (string) $this->get('/blocks')->getBody();

        $this->assertLessThan(strpos($body, '<h1>Second</h1>'), strpos($body, '<h1>First</h1>'));
        $this->assertStringContainsString('<p class="note">one conviction</p>', $body);
        $this->assertStringNotContainsString('data-folio-block', $body);
    }

    public function test_unknown_block_is_skipped_on_frontend(): void
    {
        $this->enableBlocks(withFrontendTemplate: true);
        $id = $this->createPage(['title' => 'Blocks', 'slug' => 'blocks', 'body' => 'b', 'status' => 'published']);
        $record = $this->dm()->findType('page', $id);
        $record->set('sections', [
            ['_uid' => 'bbbbbbbbbbbbbbbb', '_block' => 'gone', 'headline' => 'ghost'],
            ['_uid' => 'aaaaaaaaaaaaaaaa', '_block' => 'hero', 'headline' => 'Visible'],
            ['_uid' => 'cccccccccccccccc', '_block' => 'link', 'label' => 'data-only', 'url' => '/x'],
        ]);
        $this->dm()->saveType($record, validate: false);

        $res = $this->get('/blocks');

        $this->assertSame(200, $res->getStatusCode());
        $this->assertStringContainsString('<h1>Visible</h1>', (string) $res->getBody());
        $this->assertStringNotContainsString('ghost', (string) $res->getBody());
        $this->assertStringNotContainsString('data-only', (string) $res->getBody());
    }

    public function test_throwing_block_is_contained(): void
    {
        $this->enableBlocks(withFrontendTemplate: true, extraClasses: [\Preflow\Folio\Tests\Fixtures\Blocks\BoomBlock\BoomBlock::class]);
        $id = $this->createPage(['title' => 'Blocks', 'slug' => 'blocks', 'body' => 'b', 'status' => 'published']);
        $record = $this->dm()->findType('page', $id);
        $record->set('sections', [
            ['_uid' => 'bbbbbbbbbbbbbbbb', '_block' => 'boom'],
            ['_uid' => 'aaaaaaaaaaaaaaaa', '_block' => 'hero', 'headline' => 'Still here'],
        ]);
        $this->dm()->saveType($record, validate: false);

        $res = $this->get('/blocks');

        $this->assertSame(200, $res->getStatusCode());
        $this->assertStringContainsString('<h1>Still here</h1>', (string) $res->getBody());
        $this->assertStringNotContainsString('<p>never</p>', (string) $res->getBody());
    }

    public function test_preview_markers_only_in_preview(): void
    {
        $this->enableBlocks(withFrontendTemplate: true);
        $a = 'aaaaaaaaaaaaaaaa';
        $id = $this->createPage(['title' => 'Blocks', 'slug' => 'blocks', 'body' => 'b', 'status' => 'published',
            'sections' => ['_order' => [$a], $a => ['_block' => 'hero', 'headline' => 'H']]]);

        $preview = (string) $this->postPage('/folio/page/' . $id . '/preview', $id, ['title' => 'Blocks', 'slug' => 'blocks', 'body' => 'b', 'status' => 'published',
            'sections' => ['_order' => [$a], $a => ['_block' => 'hero', 'headline' => 'H']]])->getBody();

        $this->assertStringContainsString('data-folio-block="' . $a . '"', $preview);
        $this->assertStringNotContainsString('data-folio-block', (string) $this->get('/blocks')->getBody());
    }

    /** A section with one hero inside, published, then previewed with the same values. */
    private function sectionPage(): array
    {
        $this->enableBlocks(withFrontendTemplate: true, extraClasses: [\Preflow\Folio\Tests\Fixtures\Blocks\SectionBlock\SectionBlock::class], allowedExtra: ['section']);
        $s = 'aaaaaaaaaaaaaaaa';
        $h = 'bbbbbbbbbbbbbbbb';
        $values = ['title' => 'Blocks', 'slug' => 'blocks', 'body' => 'b', 'status' => 'published',
            'sections' => ['_order' => [$s], $s => ['_block' => 'section', 'heading' => 'Work',
                'items' => ['_order' => [$h], $h => ['_block' => 'hero', 'headline' => 'Inner']]]]];
        $id = $this->createPage($values);

        return [(string) $this->postPage('/folio/page/' . $id . '/preview', $id, $values)->getBody(), (string) $this->get('/blocks')->getBody()];
    }

    public function test_blocks_editor_loads_assets_of_fields_inside_blocks(): void
    {
        // A rich text field inside a block needs Trix on the page (and on cards added later).
        $this->enableBlocks(extraClasses: [\Preflow\Folio\Tests\Fixtures\Blocks\SectionBlock\SectionBlock::class], allowedExtra: ['section']);
        $page = json_decode((string) file_get_contents($this->dir . '/config/models/page.json'), true);
        $page['fields'] = array_intersect_key($page['fields'], array_flip(['title', 'slug', 'status', 'sections']));
        file_put_contents($this->dir . '/config/models/page.json', json_encode($page));

        $body = (string) $this->get('/folio/page/new')->getBody();

        $this->assertStringContainsString('/folio/_assets/trix.js?v=', $body);
        $this->assertStringContainsString('/folio/_assets/trix.css?v=', $body);
    }

    public function test_drawer_form_has_no_split_view(): void
    {
        // The create drawer must never run a preview: its form posts to the save route.
        $body = (string) $this->get('/folio/page/new?_drawer=1')->getBody();

        $this->assertStringContainsString('<form', $body);
        $this->assertStringNotContainsString('data-folio-split', $body);
        $this->assertStringNotContainsString('data-preview-url', $body);
    }

    public function test_nested_render_inherits_preview(): void
    {
        [$preview, $public] = $this->sectionPage();

        $this->assertStringContainsString('data-folio-block="aaaaaaaaaaaaaaaa"', $preview);
        $this->assertStringContainsString('data-folio-block="bbbbbbbbbbbbbbbb"', $preview); // child rendered without a flag
        $this->assertStringContainsString('Inner', $public);
        $this->assertStringNotContainsString('data-folio-block', $public);
    }

    public function test_folio_editable_only_in_preview(): void
    {
        [$preview, $public] = $this->sectionPage();

        $this->assertStringContainsString('<h2 data-folio-edit="heading">Work</h2>', $preview);
        $this->assertStringContainsString('<h2>Work</h2>', $public);
        $this->assertStringNotContainsString('data-folio-edit', $public);
    }

    public function test_preview_marks_the_component_root_instead_of_wrapping(): void
    {
        [$preview, $public] = $this->sectionPage();

        $this->assertStringNotContainsString('class="folio-block"', $preview);
        $this->assertMatchesRegularExpression('#<div id="SectionBlock-[^"]+" data-folio-block="aaaaaaaaaaaaaaaa"#', $preview);
        $this->assertMatchesRegularExpression('#<div id="HeroBlock-[^"]+" data-folio-block="bbbbbbbbbbbbbbbb"#', $preview);
        // Same elements as the public page: only the editor attributes are extra.
        $strip = static fn (string $html): string => preg_replace('# data-folio-(block|label|edit)="[^"]*"#', '', substr($html, (int) strpos($html, '<main>'), (int) strpos($html, '</main>') - (int) strpos($html, '<main>')));
        $this->assertSame($strip($public), $strip($preview));
    }

    public function test_preview_wrapper_carries_block_label(): void
    {
        [$preview] = $this->sectionPage();

        $this->assertStringContainsString('data-folio-block="aaaaaaaaaaaaaaaa" data-folio-label="Section"', $preview);
        $this->assertStringContainsString('data-folio-block="bbbbbbbbbbbbbbbb" data-folio-label="Hero"', $preview);
    }

    public function test_blocks_render_in_page_locale(): void
    {
        $this->enableGerman();
        $this->enableBlocks(withFrontendTemplate: true);
        $a = 'aaaaaaaaaaaaaaaa';
        $c = 'cccccccccccccccc';
        $id = $this->createPage(['title' => 'About', 'slug' => 'about', 'body' => 'b', 'status' => 'published', '_locales_submitted' => '1', '_locales' => ['de'],
            'sections' => ['_order' => [$a], $a => ['_block' => 'hero', 'headline' => 'English headline', 'tradeoff' => ['_order' => [$c], $c => ['_block' => 'tradeoff_note', 'chose' => 'english note']]]]]);
        $this->postPage('/folio/page/' . $id, $id, ['_locale' => 'de', 'title' => 'Über', 'slug' => 'ueber', 'body' => 'b', 'status' => 'published',
            'sections' => ['_order' => [$a], $a => ['_block' => 'hero', 'headline' => 'Deutsche Überschrift', 'tradeoff' => ['_order' => [$c], $c => ['_block' => 'tradeoff_note', 'chose' => '']]]]]);

        $de = (string) $this->get('/de/ueber')->getBody();

        $this->assertStringContainsString('<h1>Deutsche Überschrift</h1>', $de);
        $this->assertStringContainsString('<p class="note">english note</p>', $de); // missing leaf falls back
        $this->assertStringContainsString('<h1>English headline</h1>', (string) $this->get('/about')->getBody());
    }

    public function test_singleton_nav_goes_straight_to_form(): void
    {
        $this->writeSiteType();

        $res = $this->get('/folio/site');
        $body = (string) $res->getBody();

        $this->assertSame(200, $res->getStatusCode());
        $this->assertStringContainsString('action="/folio/site/site"', $body);
        $this->assertStringNotContainsString('href="/folio/site/new"', $body);
        $this->assertStringNotContainsString('/delete"', $body);
    }

    public function test_first_save_creates_record(): void
    {
        $this->writeSiteType();
        $this->assertNull($this->dm()->findType('site', 'site'));

        $res = $this->postSite(['name' => 'Steffen Meyer', 'tagline' => 'technologist']);

        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame('Steffen Meyer', $this->dm()->findType('site', 'site')?->get('name'));
    }

    public function test_singleton_new_and_delete_are_404(): void
    {
        $this->writeSiteType();
        $this->postSite(['name' => 'S']);
        $f = new \Nyholm\Psr7\Factory\Psr17Factory();

        $this->assertSame(404, $this->get('/folio/site/new')->getStatusCode());
        $this->assertSame(404, $this->app()->handle($f->createServerRequest('POST', '/folio/site')->withParsedBody(['name' => 'X']))->getStatusCode());
        $this->assertSame(404, $this->app()->handle($f->createServerRequest('POST', '/folio/site/site/delete')
            ->withAttribute('type', 'site')->withAttribute('id', 'site'))->getStatusCode());
        $this->assertNotNull($this->dm()->findType('site', 'site'));
    }

    public function test_folio_get_returns_null_before_first_save_then_values(): void
    {
        $this->writeSiteType(withTemplate: true);
        $this->createPage(['title' => 'About', 'slug' => 'about', 'body' => 'b', 'status' => 'published']);

        $this->assertStringContainsString('site=none', (string) $this->get('/about')->getBody());

        $this->postSite(['name' => 'Steffen Meyer']);
        $this->assertStringContainsString('site=Steffen Meyer', (string) $this->get('/about')->getBody());
    }

    public function test_singleton_is_localized(): void
    {
        $this->enableGerman();
        $this->writeSiteType(withTemplate: true);
        $id = $this->createPage(['title' => 'About', 'slug' => 'about', 'body' => 'b', 'status' => 'published', '_locales_submitted' => '1', '_locales' => ['de']]);
        $this->postPage('/folio/page/' . $id, $id, ['_locale' => 'de', 'title' => 'Über', 'slug' => 'about', 'body' => 'b', 'status' => 'published']);
        $this->postSite(['name' => 'English name']);
        $this->postSite(['_locale' => 'de', 'name' => 'Deutscher Name']);

        $this->assertStringContainsString('site=Deutscher Name', (string) $this->get('/de/about')->getBody());
        $this->assertStringContainsString('site=English name', (string) $this->get('/about')->getBody());
    }

    private function writeSiteType(bool $withTemplate = false): void
    {
        file_put_contents($this->dir . '/config/models/site.json', json_encode([
            'key' => 'site', 'table' => 'site', 'storage' => 'json', 'id_field' => 'uuid', 'label' => 'Site', 'singleton' => true, 'publishing' => false,
            'fields' => [
                'name' => ['type' => 'string', 'translatable' => true, 'validate' => ['required']],
                'tagline' => ['type' => 'string'],
            ],
        ]));
        if ($withTemplate) {
            @mkdir($this->dir . '/resources/folio/frontend', 0777, true);
            file_put_contents($this->dir . '/resources/folio/frontend/page.twig', '<html><head></head><body>{% set site = folio_get(\'site\') %}site={{ site ? site.name : \'none\' }}</body></html>');
        }
    }

    /** @param array<string, mixed> $body */
    private function postSite(array $body): ResponseInterface
    {
        return $this->app()->handle((new \Nyholm\Psr7\Factory\Psr17Factory())->createServerRequest('POST', '/folio/site/site')
            ->withAttribute('type', 'site')->withAttribute('id', 'site')->withParsedBody($body));
    }

    public function test_home_resolves_at_root(): void
    {
        unlink($this->dir . '/app/pages/index.twig'); // no app route for "/" in this app
        $this->createPage(['title' => 'Welcome home', 'slug' => 'home', 'body' => 'b', 'status' => 'published']);

        $res = $this->get('/');

        $this->assertSame(200, $res->getStatusCode());
        $this->assertStringContainsString('Welcome home', (string) $res->getBody());
    }

    public function test_admin_htmx_gets_the_page_nonce(): void
    {
        $this->enableBlocks();
        putenv('APP_DEBUG=0');
        $res = $this->get('/folio/page/new');
        putenv('APP_DEBUG=1');

        preg_match("/'nonce-([^']+)'/", $res->getHeaderLine('Content-Security-Policy'), $m);
        // htmx inserts its own indicator <style>; without the nonce the CSP blocks it.
        // Only the indicator style needs the nonce; swapped content never runs scripts.
        $this->assertStringContainsString('<meta name="htmx-config" content=\'{"inlineStyleNonce":"' . $m[1] . '","allowScriptTags":false}\'>', (string) $res->getBody());
    }

    public function test_blocks_field_is_not_labelled_shared(): void
    {
        $this->enableGerman();
        $this->enableBlocks();

        $body = (string) $this->get('/folio/page/new')->getBody();

        $this->assertDoesNotMatchRegularExpression('#data-blocks-field[\s\S]*?</div>\s*</div>\s*<p class="folio-shared">#', $body);
        $this->assertSame(1 + 0, substr_count($body, 'data-blocks-field'));
        preg_match('#<div class="folio-field-wrap">\s*<div class="folio-blocks" data-blocks-field>[\s\S]*?(?=<div class="folio-field-wrap">)#', $body, $wrap);
        $this->assertStringNotContainsString('Shared across languages', $wrap[0] ?? 'missing');
    }

    public function test_registries_are_built_once_per_request(): void
    {
        $this->enableBlocks();
        $c = $this->app()->container();

        // Block discovery (reflection over all components) and the field type
        // registry must not be rebuilt on every lookup.
        $this->assertSame($c->get(\Preflow\Folio\Block\BlockRegistry::class), $c->get(\Preflow\Folio\Block\BlockRegistry::class));
        $this->assertSame($c->get(\Preflow\Folio\Field\FieldTypeRegistry::class), $c->get(\Preflow\Folio\Field\FieldTypeRegistry::class));
    }

    public function test_duplicate_is_a_server_fragment_with_fresh_uids(): void
    {
        $this->enableGerman();
        $this->enableBlocks();
        $a = 'aaaaaaaaaaaaaaaa';
        $c = 'cccccccccccccccc';
        $id = $this->createPage(['title' => 'About', 'slug' => 'about', 'body' => 'b', 'status' => 'published',
            'sections' => ['_order' => [$a], $a => ['_block' => 'hero', 'headline' => 'English', 'intro' => 'I',
                'tradeoff' => ['_order' => [$c], $c => ['_block' => 'tradeoff_note', 'chose' => 'note']]]]]);
        // A stored upload inside the child, and a German translation.
        $record = $this->dm()->findType('page', $id);
        $sections = $record->get('sections');
        $sections[0]['tradeoff'][0]['cover'] = '2026/10/' . str_repeat('e', 32) . '.png';
        $sections[0]['headline'] = ['en' => 'English', 'de' => 'Deutsch'];
        $record->set('sections', $sections);
        $this->dm()->saveType($record, validate: false);
        @mkdir($this->dir . '/storage/uploads/2026/10', 0777, true);
        touch($this->dir . '/storage/uploads/2026/10/' . str_repeat('e', 32) . '.png');

        $edit = (string) $this->get('/folio/page/' . $id . '/edit')->getBody();
        $this->assertMatchesRegularExpression('#hx-post="/folio/_blocks/duplicate\?[^"]*uid=' . $a . '#', $edit);

        // The card's current (unsaved) values are posted along.
        $res = $this->app()->handle((new \Nyholm\Psr7\Factory\Psr17Factory())
            ->createServerRequest('POST', '/folio/_blocks/duplicate?' . http_build_query(['name' => 'sections', 'uid' => $a, 'locale' => 'en', 'depth' => 0, 'type' => 'page', 'id' => $id]))
            ->withParsedBody(['sections' => [$a => ['_block' => 'hero', 'headline' => 'Edited copy', 'intro' => 'I',
                'tradeoff' => ['_order' => [$c], $c => ['_block' => 'tradeoff_note', 'chose' => 'note']]]]]));
        $body = (string) $res->getBody();

        $this->assertSame(200, $res->getStatusCode());
        $this->assertStringNotContainsString($a, $body);
        $this->assertStringNotContainsString($c, $body);
        $this->assertStringContainsString('value="Edited copy"', $body);
        $this->assertSame(2, preg_match_all('#data-block-uid="[a-f0-9]{16}"#', $body)); // card + nested child
        $this->assertStringContainsString('[cover_ids][]" value="2026/10/' . str_repeat('e', 32) . '.png"', $body);
    }

    public function test_duplicate_rejects_bad_input(): void
    {
        $this->enableBlocks();
        $f = new \Nyholm\Psr7\Factory\Psr17Factory();

        $this->assertSame(400, $this->app()->handle($f->createServerRequest('POST', '/folio/_blocks/duplicate?name=' . rawurlencode('x"><b>') . '&uid=aaaaaaaaaaaaaaaa'))->getStatusCode());
        $this->assertSame(400, $this->app()->handle($f->createServerRequest('POST', '/folio/_blocks/duplicate?name=sections&uid=nope'))->getStatusCode());
        $this->assertSame(404, $this->app()->handle($f->createServerRequest('POST', '/folio/_blocks/duplicate?name=sections&uid=aaaaaaaaaaaaaaaa')
            ->withParsedBody(['sections' => ['aaaaaaaaaaaaaaaa' => ['_block' => 'nope']]]))->getStatusCode());
    }

    public function test_same_block_twice_gets_distinct_dom_ids(): void
    {
        $this->enableBlocks(withFrontendTemplate: true);
        $a = 'aaaaaaaaaaaaaaaa';
        $b = 'bbbbbbbbbbbbbbbb';
        $this->createPage(['title' => 'Blocks', 'slug' => 'blocks', 'body' => 'b', 'status' => 'published',
            'sections' => ['_order' => [$a, $b], $a => ['_block' => 'hero', 'headline' => 'One'], $b => ['_block' => 'hero', 'headline' => 'Two']]]);

        preg_match_all('#<div id="(HeroBlock-[^"]+)"#', (string) $this->get('/blocks')->getBody(), $m);

        $this->assertCount(2, $m[1]);
        $this->assertNotSame($m[1][0], $m[1][1]);
    }

    public function test_block_that_cannot_be_built_is_contained(): void
    {
        $this->enableBlocks(withFrontendTemplate: true, extraClasses: [\Preflow\Folio\Tests\Fixtures\Blocks\NeedyBlock\NeedyBlock::class], allowedExtra: ['needy']);
        $id = $this->createPage(['title' => 'Blocks', 'slug' => 'blocks', 'body' => 'b', 'status' => 'published']);
        $record = $this->dm()->findType('page', $id);
        $record->set('sections', [
            ['_uid' => 'bbbbbbbbbbbbbbbb', '_block' => 'needy'],
            ['_uid' => 'aaaaaaaaaaaaaaaa', '_block' => 'hero', 'headline' => 'Still here'],
        ]);
        $this->dm()->saveType($record, validate: false);

        $res = $this->get('/blocks');

        $this->assertSame(200, $res->getStatusCode());
        $this->assertStringContainsString('<h1>Still here</h1>', (string) $res->getBody());
    }

    public function test_camel_case_block_fields_can_add_blocks(): void
    {
        $this->enableBlocks();

        $res = $this->get('/folio/_blocks/link/new?' . http_build_query(['name' => 'sections[aaaaaaaaaaaaaaaa][subItems]', 'locale' => 'en', 'depth' => 1]));

        $this->assertSame(200, $res->getStatusCode());
    }

    public function test_edit_form_no_preview_button_for_non_frontend_type(): void
    {
        $app = $this->app();
        $f = new \Nyholm\Psr7\Factory\Psr17Factory();
        $body = (string) $app->handle($f->createServerRequest('GET', '/folio/note/new'))->getBody();
        $this->assertStringNotContainsString('data-folio-preview', $body);
    }

    public function test_preview_output_carries_field_markers(): void
    {
        $app = $this->app();
        $f = new \Nyholm\Psr7\Factory\Psr17Factory();
        $body = (string) $app->handle($f->createServerRequest('POST', '/folio/page/preview')->withParsedBody([
            'title' => 'Marked', 'slug' => 'marked', 'body' => 'hello', 'status' => 'draft',
        ]))->getBody();
        $this->assertStringContainsString('data-folio-field="title"', $body);
        $this->assertStringContainsString('data-folio-field="body"', $body);
    }

    public function test_list_pages_fifty_rows_at_a_time(): void
    {
        $this->writeGameType();
        $titles = [];
        for ($i = 1; $i <= 51; $i++) {
            $titles[] = sprintf('Game %02d', $i);
        }
        $this->seedGames(array_map(static fn (string $t) => ['title' => $t], $titles));

        $first = (string) $this->get('/folio/game?sort=title')->getBody();
        $this->assertSame(50, substr_count($first, 'class="folio-row-link"'));
        $this->assertStringContainsString('1–50 of 51', $first);
        $this->assertStringContainsString('href="/folio/game?sort=title&amp;page=2"', $first);
        $this->assertStringNotContainsString('Game 51', $first);

        $second = (string) $this->get('/folio/game?sort=title&page=2')->getBody();
        $this->assertSame(1, substr_count($second, 'class="folio-row-link"'));
        $this->assertStringContainsString('51–51 of 51', $second);
        $this->assertStringContainsString('Game 51', $second);
    }

    public function test_list_search_narrows_and_highlights(): void
    {
        $this->writeGameType();
        $this->seedGames([['title' => 'Night at the Zoo'], ['title' => 'Day trip'], ['title' => 'Tom & <b>Jerry</b>']]);

        $body = (string) $this->get('/folio/game?q=night+zo')->getBody();
        $this->assertStringContainsString('<mark>Night</mark> at the <mark>Zo</mark>o', $body);
        $this->assertStringNotContainsString('Day trip', $body);
        $this->assertStringContainsString('value="night zo"', $body); // the search box keeps the term

        $escaped = (string) $this->get('/folio/game?q=jerry')->getBody();
        $this->assertStringContainsString('Tom &amp; &lt;b&gt;<mark>Jerry</mark>&lt;/b&gt;', $escaped);
        $this->assertStringNotContainsString('<b>Jerry', $escaped);
    }

    public function test_list_filter_shows_a_removable_chip(): void
    {
        $this->writeGameType();
        $this->seedGames([['title' => 'Soon one', 'status' => 'soon'], ['title' => 'Out one', 'status' => 'out']]);

        $body = (string) $this->get('/folio/game?q=one&f%5Bstatus%5D=soon')->getBody();

        $this->assertStringContainsString('Soon <mark>one</mark>', $body);
        $this->assertStringNotContainsString('Out <mark>one', $body);
        $this->assertMatchesRegularExpression('#class="folio-filter-chip"[^>]*>\s*<span>Status: Coming soon</span>#', $body);
        $this->assertStringContainsString('href="/folio/game?q=one" aria-label="Remove filter Status"', $body);
        $this->assertStringContainsString('<input type="hidden" name="f[status]" value="soon">', $body);
        // The menu offers the filters not yet in use.
        $this->assertStringContainsString('name="f[designer]"', $body);
        $this->assertStringNotContainsString('id="folio-filter-status"', $body);
    }

    public function test_list_relation_column_shows_labels_and_filters(): void
    {
        $this->writeGameType();
        $dm = $this->dm();
        $author = $dm->getTypeRegistry()->get('author');
        $dm->saveType(\Preflow\Data\DynamicRecord::fromArray($author, ['uuid' => 'a1', 'name' => 'Ada <Lovelace>']), validate: false);
        $dm->saveType(\Preflow\Data\DynamicRecord::fromArray($author, ['uuid' => 'a2', 'name' => 'Bob']), validate: false);
        $this->seedGames([['title' => 'First', 'designer' => 'a1'], ['title' => 'Second', 'designer' => 'a2']]);

        $body = (string) $this->get('/folio/game')->getBody();
        $this->assertStringContainsString('<td>Ada &lt;Lovelace&gt;</td>', $body);
        $this->assertStringContainsString('<option value="a1">Ada &lt;Lovelace&gt;</option>', $body); // ≤ 200 targets: a select

        $filtered = (string) $this->get('/folio/game?f%5Bdesigner%5D=a2')->getBody();
        $this->assertStringContainsString('Designer: Bob', $filtered);
        $this->assertStringNotContainsString('First', $filtered);
    }

    public function test_list_sorts_by_header(): void
    {
        $this->writeGameType();
        $this->seedGames([['title' => 'Beta'], ['title' => 'Alpha'], ['title' => 'Gamma']]);

        $asc = (string) $this->get('/folio/game?sort=title')->getBody();
        $this->assertLessThan(strpos($asc, 'Beta'), strpos($asc, 'Alpha'));
        $this->assertMatchesRegularExpression('#<th aria-sort="ascending"><a class="folio-sort" href="/folio/game\?sort=-title">Title#', $asc);
        $this->assertStringContainsString('Title <span class="folio-sort-mark" aria-hidden="true">▲</span></a>', $asc);
        // Every other sortable header shows a faint ↕; a relation column is no link and has no mark.
        $this->assertStringContainsString('<a class="folio-sort" href="/folio/game?sort=status">Status <span class="folio-sort-mark is-idle" aria-hidden="true"></span></a>', $asc);
        $this->assertMatchesRegularExpression('#<th>Designer</th>#', $asc);
        // The bar and the table share a frame, so the bar can lie over the header row.
        $this->assertStringContainsString('<div class="folio-table-frame">', $asc);

        $desc = (string) $this->get('/folio/game?sort=-title')->getBody();
        $this->assertLessThan(strpos($desc, 'Beta'), strpos($desc, 'Gamma'));
        $this->assertStringContainsString('aria-sort="descending"', $desc);
    }

    public function test_list_without_matches_offers_to_clear(): void
    {
        $this->writeGameType();
        $this->seedGames([['title' => 'Alpha']]);

        $body = (string) $this->get('/folio/game?q=zzz&sort=-title')->getBody();

        $this->assertStringContainsString('No matches', $body);
        $this->assertStringContainsString('href="/folio/game?sort=-title">Clear search and filters</a>', $body);
        $this->assertStringNotContainsString('No games yet', $body);
        $this->assertStringContainsString('data-folio-list-search', $body); // the search box stays
    }

    public function test_empty_list_has_no_search_box(): void
    {
        $this->writeGameType();

        $body = (string) $this->get('/folio/game?q=x')->getBody();

        $this->assertStringContainsString('No games yet', $body);
        $this->assertStringNotContainsString('data-folio-list-search', $body);
    }

    public function test_list_partial_renders_only_the_results(): void
    {
        $this->writeGameType();
        $this->seedGames([['title' => 'Night'], ['title' => 'Day']]);

        $body = (string) $this->get('/folio/game?q=night&_partial=1')->getBody();

        $this->assertStringNotContainsString('<html', $body);
        $this->assertStringNotContainsString('data-folio-list-search', $body);
        $this->assertStringContainsString('<mark>Night</mark>', $body);
        $this->assertStringNotContainsString('>Day<', $body);
        $this->assertStringNotContainsString('_partial', $body); // links never carry the flag
    }

    public function test_list_hides_move_buttons_while_sorted_or_searched(): void
    {
        $this->writeItemType(['orderable' => true, 'columns' => ['title']]);
        $this->postItem(['title' => 'A item']);
        $this->postItem(['title' => 'B item']);

        $this->assertStringContainsString('aria-label="Move up"', (string) $this->get('/folio/item')->getBody());
        $this->assertStringNotContainsString('aria-label="Move up"', (string) $this->get('/folio/item?sort=title')->getBody());
        $this->assertStringNotContainsString('aria-label="Move up"', (string) $this->get('/folio/item?q=item')->getBody());
    }

    public function test_move_returns_to_the_page_it_was_made_on(): void
    {
        $this->writeItemType(['orderable' => true]);
        $this->postItem(['title' => 'A item']);
        $this->postItem(['title' => 'B item']);
        $id = $this->itemId('B item');

        $res = $this->app()->handle((new Psr17Factory())->createServerRequest('POST', '/folio/item/' . $id . '/move')
            ->withParsedBody(['direction' => 'up', 'page' => '2']));

        $this->assertSame('/folio/item?page=2', $res->getHeaderLine('Location'));
    }

    /** A "game" type: searchable title, a status select and a designer relation, both list filters. */
    private function writeGameType(): void
    {
        file_put_contents($this->dir . '/config/models/game.json', json_encode([
            'key' => 'game', 'table' => 'game', 'storage' => 'json', 'id_field' => 'uuid', 'label' => 'Games',
            'fields' => [
                'title' => ['type' => 'string', 'searchable' => true, 'validate' => ['required']],
                'status' => ['type' => 'select', 'options' => ['soon' => 'Coming soon', 'out' => 'Available']],
                'designer' => ['type' => 'relation', 'relation' => ['to' => 'author']],
            ],
            'list' => ['columns' => ['title', 'designer', 'status'], 'filters' => ['status', 'designer']],
        ]));
    }

    /** @param list<array<string, string>> $games */
    private function seedGames(array $games): void
    {
        $dm = $this->dm();
        $def = $dm->getTypeRegistry()->get('game');
        foreach ($games as $i => $game) {
            $dm->saveType(\Preflow\Data\DynamicRecord::fromArray($def, ['uuid' => sprintf('g%03d', $i)] + $game), validate: false);
        }
    }

    private function app(): Application
    {
        $app = Application::create($this->dir);
        $app->boot();
        return $app;
    }

    private function get(string $uri): ResponseInterface
    {
        return $this->app()->handle((new Psr17Factory())->createServerRequest('GET', $uri));
    }

    private function scaffold(): void
    {
        $w = function (string $rel, string $content): void {
            $path = $this->dir . '/' . $rel;
            @mkdir(dirname($path), 0777, true);
            file_put_contents($path, $content);
        };

        $w('config/app.php', <<<'PHP'
            <?php
            return [
                'name'   => 'Folio Test',
                'debug'  => (int) (getenv('APP_DEBUG') ?: 0),
                'engine' => 'twig',
                'key'    => 'folio-integration-test-key',
            ];
            PHP);

        $w('config/data.php', <<<'PHP'
            <?php
            return [
                'drivers' => [
                    'json' => [
                        'driver' => \Preflow\Data\Driver\JsonFileDriver::class,
                        'path'   => __DIR__ . '/../storage/data',
                    ],
                ],
                'default'     => 'json',
                'models_path' => __DIR__ . '/models',
            ];
            PHP);

        $w('config/providers.php', "<?php\nreturn [\\Preflow\\Folio\\FolioServiceProvider::class];\n");
        $w('config/folio.php', "<?php\nreturn ['path' => '/folio'];\n");

        $w('config/models/note.json', json_encode([
            'key' => 'note', 'table' => 'note', 'storage' => 'json', 'id_field' => 'uuid', 'label' => 'Notes', 'matrixable' => true,
            'views' => ['card'],
            'fields' => [
                'name' => ['type' => 'string', 'validate' => ['required']],
            ],
        ], JSON_PRETTY_PRINT));

        $w('config/models/author.json', json_encode([
            'key' => 'author', 'table' => 'author', 'storage' => 'json', 'id_field' => 'uuid', 'label' => 'Authors',
            'fields' => [
                'name' => ['type' => 'string', 'validate' => ['required']],
            ],
        ], JSON_PRETTY_PRINT));

        $w('config/models/page.json', json_encode([
            'key'      => 'page',
            'table'    => 'page',
            'storage'  => 'json',
            'id_field' => 'uuid',
            'label'    => 'Pages',
            'fields'   => [
                'title'  => ['type' => 'string', 'searchable' => true, 'validate' => ['required']],
                'slug'   => ['type' => 'string', 'searchable' => true, 'validate' => ['required']],
                'body'   => ['type' => 'richtext'],
                'status' => ['type' => 'string', 'validate' => ['required', 'in:draft,published']],
                'cover'  => ['type' => 'asset', 'asset' => ['multiple' => false, 'accept' => 'image/*']],
                'author' => ['type' => 'relation', 'relation' => ['to' => 'author', 'multiple' => false]],
                'blocks' => ['type' => 'matrix', 'matrix' => ['allowed' => ['note']]],
            ],
        ], JSON_PRETTY_PRINT));

        $w('app/pages/index.twig', 'HOME PAGE');
        $w('resources/folio/frontend/types/note.twig', '<section class="note-default"><h3>{{ record.name }}</h3></section>');
        $w('resources/folio/frontend/types/note_card.twig', '<aside class="note-card">{{ record.name }}</aside>');
        @mkdir($this->dir . '/storage/data', 0777, true);
    }

    /** @param array<string, mixed> $frontend */
    private function writeArticleType(array $frontend): void
    {
        $path = $this->dir . '/config/models/article.json';
        $def = is_file($path) ? json_decode((string) file_get_contents($path), true) : [
            'key' => 'article', 'table' => 'article', 'storage' => 'json', 'id_field' => 'uuid', 'label' => 'Articles',
            'publishing' => false, // these tests pin the legacy frontend.published convention
            'fields' => [
                'title' => ['type' => 'string', 'validate' => ['required']],
                'slug' => ['type' => 'string', 'validate' => ['required']],
                'status' => ['type' => 'string'],
            ],
        ];
        $def['frontend'] = $frontend;
        file_put_contents($path, json_encode($def));
    }

    private function seedArticle(string $id, string $slug, string $status): void
    {
        $app = $this->app();
        $typeDef = $app->container()->get(\Preflow\Data\TypeRegistry::class)->get('article');
        $app->container()->get(\Preflow\Data\DataManager::class)->saveType(\Preflow\Data\DynamicRecord::fromArray($typeDef, [
            'uuid' => $id, 'title' => ucfirst($slug) . ' article', 'slug' => $slug, 'status' => $status,
        ]), validate: false);
    }

    /** Page gets a "sections" blocks field; the fixture blocks are registered via config. */
    /**
     * @param list<class-string> $extraClasses
     * @param list<string>       $allowedExtra
     */
    private function enableBlocks(bool $withFrontendTemplate = false, array $extraClasses = [], array $allowedExtra = ['boom']): void
    {
        $page = json_decode((string) file_get_contents($this->dir . '/config/models/page.json'), true);
        $page['fields']['sections'] = ['type' => 'blocks', 'label' => 'Sections', 'allowed' => ['hero', 'link']];
        file_put_contents($this->dir . '/config/models/page.json', json_encode($page));
        file_put_contents($this->dir . '/config/folio.php', "<?php\nreturn ['path' => '/folio', 'blocks' => ['classes' => [\n"
            . "    \\Preflow\\Folio\\Tests\\Fixtures\\Blocks\\HeroBlock\\HeroBlock::class,\n"
            . "    \\Preflow\\Folio\\Tests\\Fixtures\\Blocks\\LinkBlock::class,\n"
            . "    \\Preflow\\Folio\\Tests\\Fixtures\\Blocks\\TradeoffNoteBlock::class,\n"
            . implode('', array_map(fn (string $c) => '    \\' . $c . "::class,\n", $extraClasses))
            . "]]];\n");
        if ($extraClasses !== []) {
            $page = json_decode((string) file_get_contents($this->dir . '/config/models/page.json'), true);
            $page['fields']['sections']['allowed'] = ['hero', 'link', ...$allowedExtra];
            file_put_contents($this->dir . '/config/models/page.json', json_encode($page));
        }
        if ($withFrontendTemplate) {
            @mkdir($this->dir . '/resources/folio/frontend', 0777, true);
            file_put_contents($this->dir . '/resources/folio/frontend/page.twig', '<!DOCTYPE html><html lang="{{ locale|default(\'en\') }}"><head></head><body><main>{{ folio_blocks(record.sections, preview|default(false)) }}</main></body></html>');
        }
    }

    /** English + German content; page title/slug/body translatable, slug lower-case only. */
    private function enableGerman(): void
    {
        file_put_contents($this->dir . '/config/i18n.php', "<?php\nreturn ['default' => 'en', 'available' => ['en', 'de'], 'fallback' => 'en', 'url_strategy' => 'prefix'];\n");
        $page = json_decode((string) file_get_contents($this->dir . '/config/models/page.json'), true);
        $page['fields']['title']['translatable'] = true;
        $page['fields']['slug']['translatable'] = true;
        $page['fields']['slug']['validate'] = ['required', 'regex:/^[a-z0-9-]+$/'];
        $page['fields']['body']['translatable'] = true;
        file_put_contents($this->dir . '/config/models/page.json', json_encode($page));
    }

    /** @param array<string, mixed> $body */
    private function createPage(array $body): string
    {
        $res = $this->app()->handle((new \Nyholm\Psr7\Factory\Psr17Factory())->createServerRequest('POST', '/folio/page')->withParsedBody($body));
        $this->assertSame(302, $res->getStatusCode(), (string) $res->getBody());

        return (string) $this->dm()->queryType('page')->where('title', $body['title'])->first()->getId();
    }

    /** @param array<string, mixed> $body */
    private function postPage(string $uri, string $id, array $body): ResponseInterface
    {
        return $this->app()->handle((new \Nyholm\Psr7\Factory\Psr17Factory())->createServerRequest('POST', $uri)
            ->withAttribute('type', 'page')->withAttribute('id', $id)->withParsedBody($body));
    }

    private function dm(): \Preflow\Data\DataManager
    {
        return $this->app()->container()->get(\Preflow\Data\DataManager::class);
    }

    /** @param array<string, mixed> $list */
    private function writeItemType(array $list): void
    {
        file_put_contents($this->dir . '/config/models/item.json', json_encode([
            'key' => 'item', 'table' => 'item', 'storage' => 'json', 'id_field' => 'uuid', 'label' => 'Items',
            'fields' => [
                'title' => ['type' => 'string', 'validate' => ['required']],
                'featured' => ['type' => 'boolean'],
                'note' => ['type' => 'string'],
            ],
            'list' => $list,
        ]));
    }

    /** @param array<string, string> $body */
    private function postItem(array $body): void
    {
        $res = $this->app()->handle((new \Nyholm\Psr7\Factory\Psr17Factory())->createServerRequest('POST', '/folio/item')
            ->withParsedBody($body));
        $this->assertSame(302, $res->getStatusCode());
    }

    private function itemId(string $title): string
    {
        return (string) $this->app()->container()->get(\Preflow\Data\DataManager::class)
            ->queryType('item')->where('title', $title)->first()->getId();
    }

    private function move(string $id, string $direction): void
    {
        $res = $this->app()->handle((new \Nyholm\Psr7\Factory\Psr17Factory())->createServerRequest('POST', '/folio/item/' . $id . '/move')
            ->withAttribute('type', 'item')->withAttribute('id', $id)
            ->withParsedBody(['direction' => $direction]));
        $this->assertSame(302, $res->getStatusCode());
    }

    /** @param list<string> $titles */
    private function assertListOrder(array $titles): void
    {
        $body = (string) $this->get('/folio/item')->getBody();
        $positions = array_map(fn (string $t) => strpos($body, $t), $titles);
        $this->assertNotContains(false, $positions, 'all titles listed');
        $sorted = $positions;
        sort($sorted);
        $this->assertSame($sorted, $positions, 'list order is ' . implode(', ', $titles));
    }

    /** A type exercising boolean, date and select fields. */
    private function writeEventType(): void
    {
        file_put_contents($this->dir . '/config/models/event.json', json_encode([
            'key' => 'event', 'table' => 'event', 'storage' => 'json', 'id_field' => 'uuid', 'label' => 'Events',
            'fields' => [
                'title' => ['type' => 'string', 'validate' => ['required']],
                'featured' => ['type' => 'boolean'],
                'happens' => ['type' => 'date'],
                'state' => ['type' => 'select', 'options' => ['a' => 'A', 'b' => 'B']],
            ],
        ]));
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->rrmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
