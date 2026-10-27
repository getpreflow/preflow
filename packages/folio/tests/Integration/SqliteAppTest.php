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

    public function test_autosave_refuses_a_save_landing_between_check_and_write(): void
    {
        $this->migrateStep();
        $this->working('post', 'p1', ['title' => ['en' => 'Start'], 'slug' => ['en' => 'hello'], '_version' => 3]);

        $body = (string) $this->racingRequest('/folio/post/p1/autosave', ['title' => 'Mine', 'slug' => 'hello', '_locale' => 'en', '_version' => '3'])->getBody();

        $this->assertStringContainsString('is-conflict', $body);
        $this->assertStringNotContainsString('hx-swap-oob', $body);
        $this->assertSame('Theirs', $this->storedTitle('p1'));
        $this->assertSame(4, (int) $this->stored('post', 'p1')->get('_version'));

        $this->post('/folio/post/p1/autosave', ['title' => 'Mine', 'slug' => 'hello', '_locale' => 'en', '_version' => '4']);
        $this->assertSame('Mine', $this->storedTitle('p1'));
        $this->assertSame(5, (int) $this->stored('post', 'p1')->get('_version'));
    }

    /** A save landing between the form's draft save and its publish: the publish conflicts, nothing goes live. */
    public function test_form_publish_refuses_a_save_landing_before_the_publish(): void
    {
        $this->migrateStep();
        $this->working('post', 'p1', ['title' => ['en' => 'Start'], 'slug' => ['en' => 'hello'], '_version' => 3]);

        $res = $this->racingRequest('/folio/post/p1', ['title' => 'Mine', 'slug' => 'hello', '_locale' => 'en', '_version' => '3', '_action' => 'publish'], skip: 1, theirVersion: 5);

        $this->assertSame(409, $res->getStatusCode());
        $body = (string) $res->getBody();
        $this->assertStringContainsString('Someone else changed this record', $body);
        $this->assertStringContainsString('name="_version" id="folio-version" value="5"', $body);
        $this->assertSame('Theirs', $this->storedTitle('p1'));
        $this->assertNull($this->publisher()->snapshot('post', 'p1'));
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

    public function test_list_filters_sorts_and_labels_relations_on_sqlite(): void
    {
        $this->writeModel('maker', ['label' => 'Makers', 'fields' => ['name' => ['type' => 'string']]]);
        $this->writeModel('game', [
            'label' => 'Games',
            'fields' => [
                'title' => ['type' => 'string', 'searchable' => true],
                'status' => ['type' => 'select', 'options' => ['soon' => 'Coming soon', 'out' => 'Available']],
                'maker' => ['type' => 'relation', 'relation' => ['to' => 'maker']],
            ],
            'list' => ['columns' => ['title', 'maker', 'status'], 'filters' => ['status', 'maker']],
        ]);
        $this->migrateStep();
        $this->working('maker', 'm1', ['name' => 'Acme & Co']);
        $this->working('game', 'g1', ['title' => 'Night Zoo', 'status' => 'soon', 'maker' => 'm1']);
        $this->working('game', 'g2', ['title' => 'Arctic Night', 'status' => 'soon', 'maker' => 'm1']);
        $this->working('game', 'g3', ['title' => 'Night Out', 'status' => 'out', 'maker' => 'm1']);

        $res = $this->get('/folio/game?q=night&f%5Bstatus%5D=soon&sort=-title');
        $body = (string) $res->getBody();

        $this->assertSame(200, $res->getStatusCode(), $body);
        $this->assertStringNotContainsString('Night</mark> Out', $body);
        $this->assertLessThan(strpos($body, 'Arctic'), strpos($body, '<mark>Night</mark> Zoo')); // -title: Night Zoo first
        $this->assertStringContainsString('Status: Coming soon', $body);
        $this->assertStringContainsString('<td>Acme &amp; Co</td>', $body);
        $this->assertStringContainsString('1–2 of 2', $body);
    }

    /** @return list<string> */
    /** The English title as stored (SQL columns hold a translatable field as JSON text). */
    private function storedTitle(string $id): ?string
    {
        $title = $this->stored('post', $id)->get('title');

        return (is_string($title) ? json_decode($title, true) : $title)['en'] ?? null;
    }

    private function migrateStep(): array
    {
        return ($this->app()->container()->get(MigrationRegistry::class)->steps()['folio.tables'] ?? throw new \RuntimeException('step not registered'))();
    }
}
