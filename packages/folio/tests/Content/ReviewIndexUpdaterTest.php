<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Content;

use PHPUnit\Framework\TestCase;
use Preflow\Folio\Console\FolioCommands;
use Preflow\Folio\Console\ReviewIndexCommand;
use Preflow\Folio\Content\ReviewIndexUpdater;
use Preflow\Folio\Tests\Support\PublishingScaffold;
use Preflow\I18n\Machine\MachineMarks;
use Preflow\I18n\Machine\ReviewEntry;
use Preflow\I18n\Machine\ReviewIndex;
use Preflow\I18n\Machine\TextSources;

final class ReviewIndexUpdaterTest extends TestCase
{
    use PublishingScaffold;

    protected function setUp(): void
    {
        $this->scaffoldPublishing();
        $this->writeModel('memo', [
            'label' => 'Memos',
            'publishing' => false,
            'fields' => ['title' => ['type' => 'string', 'translatable' => true], 'body' => ['type' => 'text', 'translatable' => true]],
        ]);
    }

    protected function tearDown(): void
    {
        $this->removeScaffold();
    }

    public function test_refresh_counts_unchecked_texts_per_locale_and_forget_removes_the_entry(): void
    {
        $c = $this->app()->container();
        $record = $this->working('memo', 'm1', [
            'title' => ['en' => 'Hello', 'de' => 'Hallo'], 'body' => ['en' => 'Text', 'de' => 'Inhalt'],
            '_machine_translated' => ['de' => ['title' => MachineMarks::fingerprint('Hallo'), 'body' => MachineMarks::fingerprint('Inhalt')]],
        ]);

        $c->get(ReviewIndexUpdater::class)->refresh($record);

        $entries = $c->get(ReviewIndex::class)->entries();
        $this->assertCount(1, $entries);
        $this->assertSame(['memo', 'm1', 'Hello', ['de' => 2]], [$entries[0]->collection, $entries[0]->id, $entries[0]->title, $entries[0]->counts]);

        $c->get(ReviewIndexUpdater::class)->forget('memo', 'm1');
        $this->assertSame([], $c->get(ReviewIndex::class)->entries());
    }

    public function test_saves_of_a_type_without_publishing_keep_the_index_current(): void
    {
        $this->working('memo', 'm1', ['title' => ['en' => 'Hello'], 'body' => ['en' => 'Text']]);

        $this->app()->container()->get(TextSources::class)->get('memo')->write('m1', 'de', ['title' => 'Hallo']);
        $this->assertSame(['de' => 1], $this->index()->entries()[0]->counts ?? null, 'the worker\'s write is listed');

        $res = $this->post('/folio/memo/m1', ['title' => 'Hallo, von Hand', 'body' => '', '_locale' => 'de']);

        $this->assertSame(302, $res->getStatusCode(), (string) $res->getBody());
        $this->assertSame([], $this->index()->entries(), 'an edit counts as checked');
    }

    public function test_rebuild_command_finds_marked_records(): void
    {
        $this->working('memo', 'm1', [
            'title' => ['en' => 'Hello', 'de' => 'Hallo'],
            '_machine_translated' => ['de' => ['title' => MachineMarks::fingerprint('Hallo')]],
        ]);
        $this->working('memo', 'm2', ['title' => ['en' => 'Checked', 'de' => 'Geprüft']]);
        $this->working('post', 'p1', [
            'title' => ['en' => 'Post', 'de' => 'Beitrag'], 'slug' => ['en' => 'post', 'de' => 'beitrag'],
            '_machine_translated' => ['de' => ['title' => MachineMarks::fingerprint('Beitrag'), 'slug' => MachineMarks::fingerprint('beitrag')]],
        ]);
        // Changed since the machine wrote it: checked.
        $this->working('post', 'p2', [
            'title' => ['en' => 'Edited', 'de' => 'Von Hand'], 'slug' => ['en' => 'edited'],
            '_machine_translated' => ['de' => ['title' => MachineMarks::fingerprint('Maschine')]],
        ]);
        $this->working('note', 'n1', ['name' => 'No translatable fields']);
        // A stale entry of a record that is gone.
        $this->index()->put('post', 'gone', 'Gone', ['de' => 3]);

        ob_start();
        $code = (new ReviewIndexCommand(fn () => $this->app()))->execute([]);
        $out = (string) ob_get_clean();

        $this->assertSame(0, $code);
        $this->assertStringContainsString('2 records', $out);
        $found = array_map(static fn (ReviewEntry $e): array => [$e->collection, $e->id, $e->title, $e->counts], $this->index()->entries());
        usort($found, static fn (array $a, array $b): int => strcmp($a[1], $b[1]));
        $this->assertSame([['memo', 'm1', 'Hello', ['de' => 1]], ['post', 'p1', 'Post', ['de' => 2]]], $found);
    }

    public function test_folio_lists_the_command(): void
    {
        $this->assertContains(ReviewIndexCommand::class, FolioCommands::all());
        $this->assertSame('folio:review-index', (new ReviewIndexCommand())->getName());
    }

    /**
     * A render that skips writes (a preview, a view as a role) deletes and publishes nothing, so
     * the review index file stays byte for byte as it was; under Allow it follows as before.
     */
    public function test_a_render_that_skips_writes_leaves_the_review_index_file_as_it_was(): void
    {
        $app = $this->app();
        $c = $app->container();
        $marked = static fn (string $de): array => ['de' => ['title' => MachineMarks::fingerprint($de)]];
        $this->working('memo', 'm1', ['title' => ['en' => 'Hello', 'de' => 'Hallo'], '_machine_translated' => $marked('Hallo')]);
        $post = $this->working('post', 'p1', ['title' => ['en' => 'Post', 'de' => 'Beitrag'], 'slug' => ['en' => 'post', 'de' => 'beitrag'], '_machine_translated' => $marked('Beitrag')]);
        $updater = $c->get(ReviewIndexUpdater::class);
        $updater->refresh($c->get(\Preflow\Data\DataManager::class)->findType('memo', 'm1'));
        $updater->refresh($post);
        $file = $this->dir . '/storage/ai/review.json';
        $before = (string) file_get_contents($file);
        $this->assertCount(2, $this->index()->entries());

        $visit = \Preflow\Core\Context\RenderContext::fromRequest(new \Nyholm\Psr7\ServerRequest('GET', '/'), $app->site(), new \Preflow\Core\Context\SystemClock());
        $skipped = \Preflow\Core\Context\Contexts::run($visit->workingCopies()->skippingWrites(), function () use ($c, $post, $updater): array {
            $c->get(\Preflow\Publishing\RecordDeleter::class)->delete('memo', ['m1']);
            $c->get(\Preflow\Publishing\Publisher::class)->publish($post, null); // a publish checks the machine translations
            $updater->forget('post', 'p1'); // and no other writer gets past the policy either

            return \Preflow\Core\Context\Contexts::frame()->skippedWrites();
        });

        $this->assertSame($before, (string) file_get_contents($file), 'the index file is byte for byte as it was');
        $this->assertContains('review-index', array_map(static fn ($w): string => $w->storage, $skipped), 'the skipped index write is reported');

        \Preflow\Core\Context\Contexts::run($visit, static fn () => $c->get(\Preflow\Publishing\RecordDeleter::class)->delete('memo', ['m1']));
        $this->assertSame(['post'], array_map(static fn (ReviewEntry $e): string => $e->collection, $this->index()->entries()), 'under Allow the delete reaches the index');
    }

    private function index(): ReviewIndex
    {
        return $this->app()->container()->get(ReviewIndex::class);
    }
}
