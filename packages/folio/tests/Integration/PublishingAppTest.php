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

    public function test_published_field_is_ignored_when_publishing(): void
    {
        $this->publisher()->publish($this->working('post', 'p1', ['title' => ['en' => 'Shown'], 'slug' => ['en' => 'hello'], 'status' => 'draft']), null);

        $this->assertStringContainsString('Shown', (string) $this->get('/hello')->getBody());
    }
}
