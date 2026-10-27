<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Preflow\Folio\Tests\Support\PublishingScaffold;

final class PublishingAppTest extends TestCase
{
    use PublishingScaffold;

    protected function setUp(): void
    {
        $this->scaffoldPublishing();
    }

    protected function tearDown(): void
    {
        $this->removeScaffold();
    }

    public function test_public_site_reads_snapshot_preview_reads_working_copy(): void
    {
        $this->publisher()->publish($this->working('post', 'p1', ['title' => ['en' => 'Live'], 'slug' => ['en' => 'hello']]), null);
        $draft = $this->stored('post', 'p1');
        $draft->set('title', ['en' => 'Draft']);
        $this->publisher()->saveDraft($draft, null);

        $public = (string) $this->get('/hello')->getBody();
        $this->assertStringContainsString('Live', $public);
        $this->assertStringNotContainsString('Draft', $public);

        $preview = (string) $this->post('/folio/post/p1/preview', ['title' => 'Draft', 'slug' => 'hello', '_locale' => 'en'])->getBody();
        $this->assertStringContainsString('Draft', $preview);
    }

    public function test_unpublished_record_is_404(): void
    {
        $this->working('post', 'p1', ['title' => ['en' => 'Only draft'], 'slug' => ['en' => 'hello']]);
        $this->assertSame(404, $this->get('/hello')->getStatusCode());

        $this->publisher()->publish($this->stored('post', 'p1'), null);
        $this->assertSame(200, $this->get('/hello')->getStatusCode());

        $this->publisher()->unpublish('post', 'p1');
        $this->assertSame(404, $this->get('/hello')->getStatusCode());
    }

    public function test_locale_availability_comes_from_snapshot(): void
    {
        $this->publisher()->publish($this->working('post', 'p1', [
            'title' => ['en' => 'Hello', 'de' => 'Hallo'], 'slug' => ['en' => 'hello', 'de' => 'hallo'], '_locales' => ['en'],
        ]), null);
        $draft = $this->stored('post', 'p1');
        $draft->set('_locales', ['en', 'de']);
        $this->publisher()->saveDraft($draft, null);

        $this->assertSame(404, $this->get('/de/hallo')->getStatusCode());

        $this->publisher()->publish($this->stored('post', 'p1'), null);
        $this->assertSame(200, $this->get('/de/hallo')->getStatusCode());
    }

    public function test_folio_get_reads_singleton_snapshot(): void
    {
        $this->publisher()->publish($this->working('post', 'p1', ['title' => ['en' => 'T'], 'slug' => ['en' => 'hello']]), null);
        $this->publisher()->publish($this->working('site', 'site', ['tagline' => ['en' => 'Live tag']]), null);
        $site = $this->stored('site', 'site');
        $site->set('tagline', ['en' => 'Draft tag']);
        $this->publisher()->saveDraft($site, null);

        $body = (string) $this->get('/hello')->getBody();
        $this->assertStringContainsString('Live tag', $body);
        $this->assertStringNotContainsString('Draft tag', $body);
    }

    public function test_save_draft_with_failing_rules_succeeds(): void
    {
        $res = $this->post('/folio/post', ['title' => '', 'slug' => 'hello', '_locale' => 'en']);

        $this->assertSame(302, $res->getStatusCode(), (string) $res->getBody());
        $this->assertSame(1, $this->dm()->queryType('post')->get()->total());
        $this->assertSame(404, $this->get('/hello')->getStatusCode());
    }

    public function test_publish_action_publishes(): void
    {
        $res = $this->post('/folio/post', ['title' => 'Fresh', 'slug' => 'hello', '_locale' => 'en', '_action' => 'publish']);

        $this->assertSame(302, $res->getStatusCode(), (string) $res->getBody());
        $this->assertStringContainsString('Fresh', (string) $this->get('/hello')->getBody());
        $this->assertStringContainsString('is-published', (string) $this->get('/folio/post')->getBody());
    }

    public function test_failed_publish_keeps_draft_and_live_page(): void
    {
        $this->publisher()->publish($this->working('post', 'p1', ['title' => ['en' => 'Live'], 'slug' => ['en' => 'hello']]), null);

        $res = $this->post('/folio/post/p1', ['title' => '', 'slug' => 'hello', '_locale' => 'en', '_action' => 'publish']);

        $this->assertSame(422, $res->getStatusCode());
        $this->assertStringContainsString('Saved as draft — not published.', (string) $res->getBody());
        $this->assertSame('', $this->stored('post', 'p1')->get('title')['en'] ?? null);
        $this->assertStringContainsString('Live', (string) $this->get('/hello')->getBody());
    }

    public function test_stale_version_is_refused(): void
    {
        $this->working('post', 'p1', ['title' => ['en' => 'Theirs'], 'slug' => ['en' => 'hello'], '_version' => 2]);

        $res = $this->post('/folio/post/p1', ['title' => 'Mine', 'slug' => 'hello', '_locale' => 'en', '_version' => '0']);

        $this->assertSame(409, $res->getStatusCode());
        $body = (string) $res->getBody();
        $this->assertStringContainsString('Someone else changed this record', $body);
        $this->assertStringContainsString('name="_version" id="folio-version" value="2"', $body);
        $this->assertSame('Theirs', $this->stored('post', 'p1')->get('title')['en']);

        $again = $this->post('/folio/post/p1', ['title' => 'Mine', 'slug' => 'hello', '_locale' => 'en', '_version' => '2']);
        $this->assertSame(302, $again->getStatusCode());
        $this->assertSame('Mine', $this->stored('post', 'p1')->get('title')['en']);
    }

    public function test_unpublish_and_discard_routes(): void
    {
        $this->publisher()->publish($this->working('post', 'p1', ['title' => ['en' => 'Live'], 'slug' => ['en' => 'hello']]), null);
        $draft = $this->stored('post', 'p1');
        $draft->set('title', ['en' => 'Edited']);
        $this->publisher()->saveDraft($draft, null);

        $this->assertSame(302, $this->post('/folio/post/p1/discard')->getStatusCode());
        $this->assertSame('Live', $this->stored('post', 'p1')->get('title')['en']);

        $this->assertSame(302, $this->post('/folio/post/p1/unpublish')->getStatusCode());
        $this->assertSame(404, $this->get('/hello')->getStatusCode());
        $this->assertStringContainsString('is-draft', (string) $this->get('/folio/post/p1/edit')->getBody());
    }

    public function test_reorder_updates_live_order_without_changing_state(): void
    {
        $this->publisher()->publish($this->working('post', 'a', ['title' => ['en' => 'A'], 'slug' => ['en' => 'a'], '_order' => 10]), null);
        $this->publisher()->publish($this->working('post', 'b', ['title' => ['en' => 'B'], 'slug' => ['en' => 'b'], '_order' => 20]), null);

        $this->assertSame(302, $this->post('/folio/post/b/move', ['direction' => 'up'])->getStatusCode());

        $publisher = $this->publisher();
        $this->assertSame(10, $publisher->snapshot('post', 'b')?->get('_order'));
        $this->assertSame(20, $publisher->snapshot('post', 'a')?->get('_order'));
        $this->assertSame(\Preflow\Folio\Publishing\PublishState::Published, $publisher->state($this->stored('post', 'a')));
        $this->assertSame(\Preflow\Folio\Publishing\PublishState::Published, $publisher->state($this->stored('post', 'b')));
    }

    public function test_destroy_removes_snapshot_and_revisions(): void
    {
        $this->publisher()->publish($this->working('post', 'p1', ['title' => ['en' => 'Live'], 'slug' => ['en' => 'hello']]), null);

        $this->assertSame(302, $this->post('/folio/post/p1/delete')->getStatusCode());

        $this->assertNull($this->publisher()->snapshot('post', 'p1'));
        $this->assertSame([], $this->publisher()->revisions('post', 'p1'));
    }

    public function test_publish_at_is_stored_and_cleared_on_publish(): void
    {
        $this->working('post', 'p1', ['title' => ['en' => 'T'], 'slug' => ['en' => 'hello']]);

        $this->post('/folio/post/p1', ['title' => 'T', 'slug' => 'hello', '_locale' => 'en', '_publish_at' => '2099-11-01T09:30']);
        $this->assertSame('2099-11-01T09:30', $this->stored('post', 'p1')->get('_publish_at'));
        $this->assertStringContainsString('value="2099-11-01T09:30"', (string) $this->get('/folio/post/p1/edit')->getBody());

        $this->post('/folio/post/p1', ['title' => 'T', 'slug' => 'hello', '_locale' => 'en', '_publish_at' => 'garbage']);
        $this->assertEmpty($this->stored('post', 'p1')->get('_publish_at'));

        $this->post('/folio/post/p1', ['title' => 'T', 'slug' => 'hello', '_locale' => 'en', '_publish_at' => '2099-11-01T09:30', '_action' => 'publish']);
        $this->assertEmpty($this->stored('post', 'p1')->get('_publish_at'));
    }

    public function test_past_publish_at_is_not_stored(): void
    {
        // A stale editor tab still carrying a schedule that already ran must not re-arm it.
        $this->working('post', 'p1', ['title' => ['en' => 'T'], 'slug' => ['en' => 'hello']]);

        $this->post('/folio/post/p1', ['title' => 'T', 'slug' => 'hello', '_locale' => 'en', '_publish_at' => '2000-01-01T00:00']);

        $this->assertEmpty($this->stored('post', 'p1')->get('_publish_at'));
    }

    public function test_autosave_saves_working_copy_only(): void
    {
        $this->publisher()->publish($this->working('post', 'p1', ['title' => ['en' => 'Live'], 'slug' => ['en' => 'hello']]), null);

        $res = $this->post('/folio/post/p1/autosave', ['title' => 'Auto', 'slug' => 'hello', '_locale' => 'en', '_version' => '0']);

        $this->assertSame(200, $res->getStatusCode());
        $this->assertStringContainsString('Saved · not published', (string) $res->getBody());
        $this->assertSame('Auto', $this->stored('post', 'p1')->get('title')['en']);
        $this->assertStringContainsString('Live', (string) $this->get('/hello')->getBody());
    }

    public function test_save_after_autosave_does_not_conflict(): void
    {
        $this->working('post', 'p1', ['title' => ['en' => 'T'], 'slug' => ['en' => 'hello'], '_version' => 4]);

        $auto = (string) $this->post('/folio/post/p1/autosave', ['title' => 'Auto', 'slug' => 'hello', '_locale' => 'en', '_version' => '4'])->getBody();
        $this->assertSame(1, preg_match('/<input type="hidden" name="_version" id="folio-version" value="(\d+)" hx-swap-oob="true">/', $auto, $m), $auto);

        $res = $this->post('/folio/post/p1', ['title' => 'Manual', 'slug' => 'hello', '_locale' => 'en', '_version' => $m[1]]);
        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame('Manual', $this->stored('post', 'p1')->get('title')['en']);
    }

    public function test_autosave_conflict_reports_and_does_not_save(): void
    {
        $this->working('post', 'p1', ['title' => ['en' => 'Theirs'], 'slug' => ['en' => 'hello'], '_version' => 3]);

        $body = (string) $this->post('/folio/post/p1/autosave', ['title' => 'Mine', 'slug' => 'hello', '_locale' => 'en', '_version' => '1'])->getBody();

        $this->assertStringContainsString('Not saved — someone else changed this record', $body);
        $this->assertStringNotContainsString('hx-swap-oob', $body);
        $this->assertSame('Theirs', $this->stored('post', 'p1')->get('title')['en']);
    }

    public function test_autosave_refuses_a_save_landing_between_check_and_write(): void
    {
        $this->working('post', 'p1', ['title' => ['en' => 'Start'], 'slug' => ['en' => 'hello'], '_version' => 3]);

        $body = (string) $this->racingRequest('/folio/post/p1/autosave', ['title' => 'Mine', 'slug' => 'hello', '_locale' => 'en', '_version' => '3'])->getBody();

        $this->assertStringContainsString('Not saved — someone else changed this record', $body);
        $this->assertStringContainsString('is-conflict', $body);
        $this->assertStringNotContainsString('hx-swap-oob', $body);
        $this->assertSame('Theirs', $this->stored('post', 'p1')->get('title')['en']);
        $this->assertSame(4, (int) $this->stored('post', 'p1')->get('_version'));
    }

    public function test_form_save_refuses_a_save_landing_between_check_and_write(): void
    {
        $this->working('post', 'p1', ['title' => ['en' => 'Start'], 'slug' => ['en' => 'hello'], '_version' => 3]);

        $res = $this->racingRequest('/folio/post/p1', ['title' => 'Mine', 'slug' => 'hello', '_locale' => 'en', '_version' => '3']);

        $this->assertSame(409, $res->getStatusCode());
        $body = (string) $res->getBody();
        $this->assertStringContainsString('Someone else changed this record', $body);
        $this->assertStringContainsString('name="_version" id="folio-version" value="4"', $body);
        $this->assertSame('Theirs', $this->stored('post', 'p1')->get('title')['en']);
    }

    /** After a form publish (content unchanged: the version stays) the editor and autosave go on with the stored version. */
    public function test_form_and_autosave_after_a_form_publish(): void
    {
        $this->working('post', 'p1', ['title' => ['en' => 'Start'], 'slug' => ['en' => 'hello'], '_version' => 3]);

        $this->assertSame(302, $this->post('/folio/post/p1', ['title' => 'Mine', 'slug' => 'hello', '_locale' => 'en', '_version' => '3', '_action' => 'publish'])->getStatusCode());
        $this->assertSame(4, (int) $this->stored('post', 'p1')->get('_version'), 'the draft save moved it on, the publish kept it');
        $this->assertStringContainsString('name="_version" id="folio-version" value="4"', (string) $this->get('/folio/post/p1/edit')->getBody());

        $auto = (string) $this->post('/folio/post/p1/autosave', ['title' => 'Later', 'slug' => 'hello', '_locale' => 'en', '_version' => '4'])->getBody();
        $this->assertStringContainsString('id="folio-version" value="5" hx-swap-oob="true"', $auto);
    }

    /** A publish that changed content (a site's approve) moves the version on: an editor still on the old one conflicts, a reload goes on. */
    public function test_form_and_autosave_after_a_publish_that_changed_content(): void
    {
        $this->working('post', 'p1', ['title' => ['en' => 'Start'], 'slug' => ['en' => 'hello'], 'status' => 'review', '_version' => 3]);
        $approved = $this->stored('post', 'p1');
        $approved->set('status', 'approved');
        $this->publisher()->publish($approved, 'reviewer', 3);
        $this->assertSame(4, (int) $this->stored('post', 'p1')->get('_version'));

        $stale = (string) $this->post('/folio/post/p1/autosave', ['title' => 'Start', 'slug' => 'hello', 'status' => 'review', '_locale' => 'en', '_version' => '3'])->getBody();
        $this->assertStringContainsString('is-conflict', $stale);
        $this->assertSame(409, $this->post('/folio/post/p1', ['title' => 'Start', 'slug' => 'hello', 'status' => 'review', '_locale' => 'en', '_version' => '3'])->getStatusCode());
        $this->assertSame('approved', $this->stored('post', 'p1')->get('status'));

        $this->assertStringContainsString('name="_version" id="folio-version" value="4"', (string) $this->get('/folio/post/p1/edit')->getBody());
        $auto = (string) $this->post('/folio/post/p1/autosave', ['title' => 'Later', 'slug' => 'hello', 'status' => 'approved', '_locale' => 'en', '_version' => '4'])->getBody();
        $this->assertStringContainsString('id="folio-version" value="5" hx-swap-oob="true"', $auto);
    }

    /** A save landing between the form's draft save and its publish: the publish conflicts, nothing goes live. */
    public function test_form_publish_refuses_a_save_landing_before_the_publish(): void
    {
        $this->working('post', 'p1', ['title' => ['en' => 'Start'], 'slug' => ['en' => 'hello'], '_version' => 3]);

        $res = $this->racingRequest('/folio/post/p1', ['title' => 'Mine', 'slug' => 'hello', '_locale' => 'en', '_version' => '3', '_action' => 'publish'], skip: 1, theirVersion: 5);

        $this->assertSame(409, $res->getStatusCode());
        $body = (string) $res->getBody();
        $this->assertStringContainsString('Someone else changed this record', $body);
        $this->assertStringContainsString('name="_version" id="folio-version" value="5"', $body);
        $this->assertSame('Theirs', $this->stored('post', 'p1')->get('title')['en']);
        $this->assertNull($this->publisher()->snapshot('post', 'p1'));
    }

    public function test_autosave_writes_no_files(): void
    {
        $this->working('post', 'p1', ['title' => ['en' => 'T'], 'slug' => ['en' => 'hello']]);
        $f = new \Nyholm\Psr7\Factory\Psr17Factory();
        $request = $f->createServerRequest('POST', '/folio/post/p1/autosave')
            ->withParsedBody(['title' => 'T', 'slug' => 'hello', '_locale' => 'en'])
            ->withUploadedFiles(['cover' => $f->createUploadedFile($f->createStream('PNGDATA'), 7, UPLOAD_ERR_OK, 'pic.png', 'image/png')]);

        $this->assertSame(200, $this->app()->handle($request)->getStatusCode());
        $this->assertDirectoryDoesNotExist($this->dir . '/storage/uploads');
    }

    public function test_form_wires_autosave_for_publishing_types_only(): void
    {
        $this->writeModel('memo', ['fields' => ['title' => ['type' => 'string']]]);
        $this->working('post', 'p1', ['title' => ['en' => 'T'], 'slug' => ['en' => 'hello']]);
        $this->working('memo', 'm1', ['title' => 'M']);

        $this->assertStringContainsString('hx-trigger="input delay:3s', (string) $this->get('/folio/post/p1/edit')->getBody());
        $this->assertStringNotContainsString('hx-trigger="input delay:3s', (string) $this->get('/folio/post/new')->getBody());
        $this->assertStringNotContainsString('hx-trigger="input delay:3s', (string) $this->get('/folio/memo/m1/edit')->getBody());
    }

    public function test_edit_form_lists_revisions(): void
    {
        $this->publisher()->publish($this->working('post', 'p1', ['title' => ['en' => 'T'], 'slug' => ['en' => 'hello']]), 'u@x');
        $post = $this->stored('post', 'p1');
        $post->set('body', ['en' => 'More']);
        $this->publisher()->publish($post, 'u@x');

        $body = (string) $this->get('/folio/post/p1/edit')->getBody();

        $this->assertSame(2, substr_count($body, 'class="folio-revision"'));
        $newest = strpos($body, 'Changed: Body');
        $oldest = strpos($body, 'Changed: Title, Slug');
        $this->assertNotFalse($newest);
        $this->assertNotFalse($oldest);
        $this->assertLessThan($oldest, $newest);
        $this->assertStringContainsString('u@x', $body);
    }

    public function test_restore_route_restores_and_redirects(): void
    {
        $this->publisher()->publish($this->working('post', 'p1', ['title' => ['en' => 'First'], 'slug' => ['en' => 'hello']]), null);
        $post = $this->stored('post', 'p1');
        $post->set('title', ['en' => 'Second']);
        $this->publisher()->publish($post, null);
        $first = $this->publisher()->revisions('post', 'p1')[1];

        $res = $this->post('/folio/post/p1/revisions/' . $first->id . '/restore');

        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame('/folio/post/p1/edit?restored=1', $res->getHeaderLine('Location'));
        $this->assertSame('First', $this->stored('post', 'p1')->get('title')['en']);
        $this->assertStringContainsString('Restored into the working copy — publish to make it live.', (string) $this->get('/folio/post/p1/edit?restored=1')->getBody());
    }

    public function test_restore_unknown_revision_is_404(): void
    {
        $this->working('post', 'p1', ['title' => ['en' => 'T'], 'slug' => ['en' => 'hello']]);

        $this->assertSame(404, $this->post('/folio/post/p1/revisions/nope/restore')->getStatusCode());
    }

    public function test_preview_injects_editor_assets(): void
    {
        $body = (string) $this->post('/folio/post/p1/preview', ['title' => 'Hello', 'slug' => 'hello'])->getBody();

        $this->assertMatchesRegularExpression('#<link rel="stylesheet" href="/folio/_assets/preview-editor\.css\?v=[^"]+">\s*</head>#', $body);
        $this->assertMatchesRegularExpression('#</head>.*<script src="/folio/_assets/preview-editor\.js\?v=[^"]+" defer></script>.*</body>#s', $body); // in the body
    }

    public function test_public_and_share_renders_have_no_editor_assets(): void
    {
        $this->working('post', 'p1', ['title' => ['en' => 'Secret'], 'slug' => ['en' => 'hello']]);
        $token = $this->shareToken('p1');

        $this->assertStringNotContainsString('preview-editor', (string) $this->getWithPreview('/hello', $token)->getBody());
        $this->assertStringNotContainsString('preview-editor', (string) $this->get('/hello')->getBody());
    }

    public function test_editor_assets_are_served(): void
    {
        $this->assertSame(200, $this->get('/folio/_assets/preview-editor.js')->getStatusCode());
        $this->assertSame(200, $this->get('/folio/_assets/preview-editor.css')->getStatusCode());
    }

    public function test_valid_link_renders_working_copy_with_headers(): void
    {
        $this->working('post', 'p1', ['title' => ['en' => 'Secret'], 'slug' => ['en' => 'hello']]);
        $token = $this->shareToken('p1');

        $res = $this->getWithPreview('/hello', $token);

        $this->assertSame(200, $res->getStatusCode());
        $this->assertStringContainsString('Secret', (string) $res->getBody());
        $this->assertSame('noindex', $res->getHeaderLine('X-Robots-Tag'));
        $this->assertSame('private, no-store', $res->getHeaderLine('Cache-Control'));
    }

    public function test_expired_link_falls_back(): void
    {
        $this->working('post', 'p1', ['title' => ['en' => 'Secret'], 'slug' => ['en' => 'hello']]);
        $this->shareToken('p1');
        $nonce = (string) $this->stored('post', 'p1')->get('_share_token');
        $old = (new \Preflow\Folio\Publishing\PreviewLinks('folio-integration-test-key', 604800, fn (): int => time() - 8 * 86400))->create('post', 'p1', $nonce);

        $this->assertSame(404, $this->getWithPreview('/hello', $old)->getStatusCode());
    }

    public function test_tampered_foreign_or_revoked_token_is_ignored(): void
    {
        $this->working('post', 'p1', ['title' => ['en' => 'Secret'], 'slug' => ['en' => 'hello']]);
        $this->working('post', 'p2', ['title' => ['en' => 'Other'], 'slug' => ['en' => 'other']]);
        $token = $this->shareToken('p1');

        $tampered = substr($token, 0, -1) . (str_ends_with($token, 'A') ? 'B' : 'A');
        $this->assertSame(404, $this->getWithPreview('/hello', $tampered)->getStatusCode());
        $this->assertSame(404, $this->getWithPreview('/other', $token)->getStatusCode());

        $this->post('/folio/post/p1/share/revoke');
        $this->assertSame(404, $this->getWithPreview('/hello', $token)->getStatusCode());

        // A changed, published page shows its published content to a revoked link.
        $this->publisher()->publish($this->stored('post', 'p1'), null);
        $draft = $this->stored('post', 'p1');
        $draft->set('title', ['en' => 'Unreleased']);
        $this->publisher()->saveDraft($draft, null);
        $fresh = $this->shareToken('p1');
        $this->assertStringContainsString('Unreleased', (string) $this->getWithPreview('/hello', $fresh)->getBody());
        $this->post('/folio/post/p1/share/revoke');
        $body = (string) $this->getWithPreview('/hello', $fresh)->getBody();
        $this->assertStringContainsString('Secret', $body);
        $this->assertStringNotContainsString('Unreleased', $body);
    }

    public function test_links_disabled_with_default_key(): void
    {
        $this->write('config/app.php', "<?php\nreturn ['name' => 'Pub', 'debug' => 1, 'engine' => 'twig', 'key' => 'preflow-default-key-change-me!!'];\n");
        $this->working('post', 'p1', ['title' => ['en' => 'Secret'], 'slug' => ['en' => 'hello']]);

        $this->assertSame(404, $this->post('/folio/post/p1/share')->getStatusCode());
        $this->assertStringNotContainsString('Copy preview link', (string) $this->get('/folio/post/p1/edit')->getBody());
    }

    public function test_share_button_on_publishing_edit_form(): void
    {
        $this->working('post', 'p1', ['title' => ['en' => 'Secret'], 'slug' => ['en' => 'hello']]);

        $this->assertStringContainsString('Copy preview link', (string) $this->get('/folio/post/p1/edit')->getBody());
    }

    private function shareToken(string $id): string
    {
        $body = (string) $this->post('/folio/post/' . $id . '/share', ['_locale' => 'en'])->getBody();
        $this->assertSame(1, preg_match('/folio_preview=([A-Za-z0-9_.\-]+)/', $body, $m), $body);

        return $m[1];
    }

    private function getWithPreview(string $path, string $token): \Psr\Http\Message\ResponseInterface
    {
        return $this->app()->handle((new \Nyholm\Psr7\Factory\Psr17Factory())
            ->createServerRequest('GET', $path . '?folio_preview=' . $token)
            ->withQueryParams(['folio_preview' => $token]));
    }

    public function test_public_page_hides_drafts_of_referenced_records(): void
    {
        $this->working('post', 'p2', ['title' => ['en' => 'SecretDraftTitle'], 'slug' => ['en' => 'secret']]);
        $this->working('note', 'n1', ['name' => 'SecretNote']);
        $this->publisher()->publish($this->working('post', 'p1', [
            'title' => ['en' => 'Live'], 'slug' => ['en' => 'hello'], 'related' => 'p2', 'notes' => '[{"_type":"note","id":"n1"}]',
        ]), null);

        $body = (string) $this->get('/hello')->getBody();
        $this->assertStringNotContainsString('SecretDraftTitle', $body);
        $this->assertStringNotContainsString('SecretNote', $body);
        $this->assertStringNotContainsString('/secret', $body);

        $this->publisher()->publish($this->stored('post', 'p2'), null);
        $this->publisher()->publish($this->stored('note', 'n1'), null);
        $draft = $this->stored('post', 'p2');
        $draft->set('title', ['en' => 'UnreleasedTitle']);
        $this->publisher()->saveDraft($draft, null);

        $body = (string) $this->get('/hello')->getBody();
        $this->assertStringContainsString('SecretDraftTitle', $body);
        $this->assertStringNotContainsString('UnreleasedTitle', $body);
        $this->assertStringContainsString('SecretNote', $body);
        $this->assertStringContainsString('/secret', $body);
    }

    public function test_preview_shows_working_copies_of_referenced_records(): void
    {
        $this->working('post', 'p2', ['title' => ['en' => 'SecretDraftTitle'], 'slug' => ['en' => 'secret']]);
        $this->working('post', 'p1', ['title' => ['en' => 'Live'], 'slug' => ['en' => 'hello']]);

        $body = (string) $this->post('/folio/post/p1/preview', ['title' => 'Live', 'slug' => 'hello', 'related' => 'p2', '_locale' => 'en'])->getBody();

        $this->assertStringContainsString('SecretDraftTitle', $body);
    }

    public function test_publishing_page_has_hreflang_alternates(): void
    {
        $this->publisher()->publish($this->working('post', 'p1', [
            'title' => ['en' => 'Hello', 'de' => 'Hallo'], 'slug' => ['en' => 'hello', 'de' => 'hallo'], '_locales' => ['en', 'de'],
        ]), null);

        $body = (string) $this->get('/hello')->getBody();

        $this->assertStringContainsString('hreflang="de"', $body);
        $this->assertStringContainsString('/de/hallo', $body);
    }

    public function test_autosave_form_disables_submit_while_saving(): void
    {
        $this->working('post', 'p1', ['title' => ['en' => 'T'], 'slug' => ['en' => 'hello']]);

        $this->assertStringContainsString('hx-disabled-elt="find button[type=submit]"', (string) $this->get('/folio/post/p1/edit')->getBody());
    }

    public function test_published_field_is_ignored_when_publishing(): void
    {
        $this->publisher()->publish($this->working('post', 'p1', ['title' => ['en' => 'Shown'], 'slug' => ['en' => 'hello'], 'status' => 'draft']), null);

        $this->assertStringContainsString('Shown', (string) $this->get('/hello')->getBody());
    }

    public function test_destroy_detaches_the_working_copy_only_and_the_live_page_skips_the_gone_record(): void
    {
        $this->publisher()->publish($this->working('post', 'p2', ['title' => ['en' => 'Second'], 'slug' => ['en' => 'second']]), null);
        $this->publisher()->publish($this->working('post', 'p1', ['title' => ['en' => 'First'], 'slug' => ['en' => 'first'], 'related' => 'p2']), null);

        $plan = json_decode((string) $this->post('/folio/post/_delete-plan', ['ids' => ['p2']])->getBody(), true);
        $this->assertSame("This can't be undone.\n1 post loses a link.", $plan['confirm']);

        $this->assertSame(302, $this->post('/folio/post/p2/delete')->getStatusCode());

        $this->assertNull($this->dm()->findType('post', 'p2'));
        $this->assertNull($this->publisher()->snapshot('post', 'p2'));
        $this->assertSame('', $this->stored('post', 'p1')->get('related'));
        $this->assertSame('p2', $this->publisher()->snapshot('post', 'p1')?->get('related')); // until the next publish

        $live = $this->get('/first');
        $this->assertSame(200, $live->getStatusCode());
        $this->assertStringContainsString('First', (string) $live->getBody());
        $this->assertStringNotContainsString('Second', (string) $live->getBody());
    }
}

