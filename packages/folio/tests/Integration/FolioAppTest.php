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
        $this->assertStringContainsString('No records yet', $body);
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

    public function test_upload_is_stored_and_served(): void
    {
        $app = $this->app();
        $f = new \Nyholm\Psr7\Factory\Psr17Factory();
        $file = $f->createUploadedFile($f->createStream('PNGDATA'), 7, UPLOAD_ERR_OK, 'pic.png', 'image/png');

        $res = $app->handle($f->createServerRequest('POST', '/folio/page')
            ->withParsedBody(['title' => 'A', 'slug' => 'a', 'body' => 'b', 'status' => 'published'])
            ->withUploadedFiles(['cover' => $file]));
        $this->assertSame(302, $res->getStatusCode());

        $record = $app->container()->get(\Preflow\Data\DataManager::class)
            ->queryType('page')->where('slug', 'a')->first();
        $cover = (string) $record->get('cover');
        $this->assertStringEndsWith('.png', $cover);

        $served = $app->handle($f->createServerRequest('GET', '/folio/_uploads/' . $cover));
        $this->assertSame(200, $served->getStatusCode());
        $this->assertSame('image/png', $served->getHeaderLine('Content-Type'));
        $this->assertSame('PNGDATA', (string) $served->getBody());
    }

    public function test_frontend_renders_uploaded_image(): void
    {
        $app = $this->app();
        $f = new \Nyholm\Psr7\Factory\Psr17Factory();
        $file = $f->createUploadedFile($f->createStream('PNGDATA'), 7, UPLOAD_ERR_OK, 'pic.png', 'image/png');
        $app->handle($f->createServerRequest('POST', '/folio/page')
            ->withParsedBody(['title' => 'Img', 'slug' => 'img', 'body' => 'b', 'status' => 'published'])
            ->withUploadedFiles(['cover' => $file]));

        $html = (string) $app->handle($f->createServerRequest('GET', '/img'))->getBody();
        $this->assertStringContainsString('<img src="/folio/_uploads/', $html);
    }

    public function test_update_removes_existing_asset(): void
    {
        $app = $this->app();
        $f = new \Nyholm\Psr7\Factory\Psr17Factory();
        $file = $f->createUploadedFile($f->createStream('PNGDATA'), 7, UPLOAD_ERR_OK, 'pic.png', 'image/png');
        $app->handle($f->createServerRequest('POST', '/folio/page')
            ->withParsedBody(['title' => 'R', 'slug' => 'r', 'body' => 'b', 'status' => 'published'])
            ->withUploadedFiles(['cover' => $file]));

        $dm = $app->container()->get(\Preflow\Data\DataManager::class);
        $record = $dm->queryType('page')->where('slug', 'r')->first();
        $id = $record->getId();
        $cover = (string) $record->get('cover');
        $this->assertStringEndsWith('.png', $cover);

        // update with a remove marker for that path and no new upload
        $app->handle($f->createServerRequest('POST', '/folio/page/' . $id)
            ->withAttribute('type', 'page')->withAttribute('id', $id)
            ->withParsedBody(['title' => 'R', 'slug' => 'r', 'body' => 'b', 'status' => 'published', 'cover_remove' => [$cover]]));

        $after = $dm->findType('page', $id);
        $this->assertSame('', (string) $after->get('cover'));
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
