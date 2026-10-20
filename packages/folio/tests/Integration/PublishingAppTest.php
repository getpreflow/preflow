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

        $this->post('/folio/post/p1', ['title' => 'T', 'slug' => 'hello', '_locale' => 'en', '_publish_at' => '2026-11-01T09:30']);
        $this->assertSame('2026-11-01T09:30', $this->stored('post', 'p1')->get('_publish_at'));
        $this->assertStringContainsString('value="2026-11-01T09:30"', (string) $this->get('/folio/post/p1/edit')->getBody());

        $this->post('/folio/post/p1', ['title' => 'T', 'slug' => 'hello', '_locale' => 'en', '_publish_at' => 'garbage']);
        $this->assertNull($this->stored('post', 'p1')->get('_publish_at'));

        $this->post('/folio/post/p1', ['title' => 'T', 'slug' => 'hello', '_locale' => 'en', '_publish_at' => '2026-11-01T09:30', '_action' => 'publish']);
        $this->assertNull($this->stored('post', 'p1')->get('_publish_at'));
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

    public function test_published_field_is_ignored_when_publishing(): void
    {
        $this->publisher()->publish($this->working('post', 'p1', ['title' => ['en' => 'Shown'], 'slug' => ['en' => 'hello'], 'status' => 'draft']), null);

        $this->assertStringContainsString('Shown', (string) $this->get('/hello')->getBody());
    }
}
