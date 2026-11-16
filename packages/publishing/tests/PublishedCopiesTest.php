<?php

declare(strict_types=1);

namespace Preflow\Publishing\Tests;

use Preflow\Data\Schema\RecordTableSync;
use Preflow\Publishing\PublishedCopies;
use Preflow\Publishing\ShadowTypes;

/** The snapshots a delete plan counts: only where the storage has them. */
final class PublishedCopiesTest extends PublishingAppTestCase
{
    private function copies(): PublishedCopies
    {
        return new PublishedCopies($this->dm(), $this->publisher());
    }

    private function useSqlite(): void
    {
        $this->write('config/data.php', "<?php\nreturn [\n"
            . "'drivers' => ['sqlite' => ['driver' => \\Preflow\\Data\\Driver\\SqliteDriver::class, 'path' => __DIR__ . '/../storage/site.sqlite']],\n"
            . "'default' => 'sqlite', 'models_path' => __DIR__ . '/models',\n];\n");
        $this->write('config/models/post.json', (string) json_encode([
            'key' => 'post', 'table' => 'post', 'storage' => 'sqlite', 'publishing' => true,
            'fields' => ['title' => ['type' => 'string']],
        ]));
        $this->write('config/models/note.json', (string) json_encode(['key' => 'note', 'table' => 'note', 'storage' => 'sqlite', 'fields' => ['title' => ['type' => 'string']]]));
        $this->write('config/models/job.json', (string) json_encode(['key' => 'job', 'table' => 'job', 'storage' => 'sqlite', 'fields' => ['title' => ['type' => 'string']]]));
        $this->reboot();
    }

    public function test_sqlite_without_the_shadow_table_has_no_copies_and_with_it_the_shadow(): void
    {
        $this->useSqlite();
        $post = $this->dm()->getTypeRegistry()->get('post');
        $shadow = ShadowTypes::published($post);

        $this->assertSame([], $this->copies()->of($post));

        (new RecordTableSync($this->dm()))->sync($shadow);

        $this->assertEquals([$shadow], $this->copies()->of($post));
    }

    public function test_json_before_the_first_publish_has_no_copies(): void
    {
        $post = $this->dm()->getTypeRegistry()->get('post');
        $this->assertSame([], $this->copies()->of($post));

        $this->publisher()->publish($this->working('post', 'p1', ['title' => ['en' => 'One']]), null);

        $this->assertEquals([ShadowTypes::published($post)], $this->copies()->of($post));
    }

    public function test_a_type_that_does_not_publish_has_no_copies(): void
    {
        $this->assertSame([], $this->copies()->of($this->dm()->getTypeRegistry()->get('note')));
    }
}
