<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Preflow\Data\Migration\MigrationRegistry;
use Preflow\Folio\Tests\Support\PublishingScaffold;

/** The publishing scaffold with SQLite-stored types, tables made by the `folio.tables` migrate step. */
final class SqliteAppTest extends TestCase
{
    use PublishingScaffold;

    protected function setUp(): void
    {
        $this->scaffoldPublishing('sqlite');
    }

    protected function tearDown(): void
    {
        $this->removeScaffold();
    }

    public function test_migrate_step_creates_the_tables(): void
    {
        $lines = $this->migrateStep();

        $this->assertContains('created table post', $lines);
        $this->assertContains('created table post__published', $lines);
        $this->assertContains('created table site__revisions', $lines);
        $this->assertSame([], $this->migrateStep());
    }

    public function test_admin_publish_and_public_page(): void
    {
        $this->migrateStep();

        $this->assertSame(302, $this->post('/folio/post', ['title' => 'Fresh', 'slug' => 'hello', '_locale' => 'en', '_action' => 'publish'])->getStatusCode());

        $this->assertStringContainsString('<h1>Fresh</h1>', (string) $this->get('/hello')->getBody());
    }

    public function test_saving_another_locale_keeps_visible_locales(): void
    {
        $this->migrateStep();
        $this->post('/folio/post', ['title' => 'Hello', 'slug' => 'hello', '_locale' => 'en', '_locales_submitted' => '1', '_locales' => ['de'], '_action' => 'publish']);
        $id = (string) $this->dm()->queryType('post')->first()?->getId();

        $this->post('/folio/post/' . $id, ['title' => 'Hallo', 'slug' => 'hallo', '_locale' => 'de', '_action' => 'publish']);

        $this->assertSame(['en', 'de'], $this->stored('post', $id)->availableLocales());
        $this->assertStringContainsString('<h1>Hallo</h1>', (string) $this->get('/de/hallo')->getBody());
    }

    public function test_reorder_swaps_positions_without_renumbering(): void
    {
        $this->migrateStep();
        $this->publisher()->publish($this->working('post', 'a', ['title' => ['en' => 'A'], 'slug' => ['en' => 'a'], '_order' => 5]), null);
        $this->publisher()->publish($this->working('post', 'b', ['title' => ['en' => 'B'], 'slug' => ['en' => 'b'], '_order' => 7]), null);

        $this->assertSame(302, $this->post('/folio/post/b/move', ['direction' => 'up'])->getStatusCode());

        $this->assertSame('5', $this->publisher()->snapshot('post', 'b')?->get('_order'));
        $this->assertSame('7', $this->publisher()->snapshot('post', 'a')?->get('_order'));
    }

    /** @return list<string> */
    private function migrateStep(): array
    {
        return ($this->app()->container()->get(MigrationRegistry::class)->steps()['folio.tables'] ?? throw new \RuntimeException('step not registered'))();
    }
}
