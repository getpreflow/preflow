<?php

declare(strict_types=1);

namespace Preflow\I18n\Tests\Machine;

use PHPUnit\Framework\TestCase;
use Preflow\I18n\Machine\FileReviewIndex;
use Preflow\I18n\Machine\ReviewEntry;

final class FileReviewIndexTest extends TestCase
{
    private string $dir;
    private string $file;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/review_' . bin2hex(random_bytes(4));
        $this->file = $this->dir . '/review.json';
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    public function test_put_and_entries(): void
    {
        $index = new FileReviewIndex($this->file, static fn (): \DateTimeImmutable => new \DateTimeImmutable('2026-10-30T10:00:00+00:00'));

        $index->put('game', 'g1', 'Catan', ['de' => 3, 'fr' => 1]);

        $entries = $index->entries();
        $this->assertCount(1, $entries);
        $this->assertSame('game', $entries[0]->collection);
        $this->assertSame('g1', $entries[0]->id);
        $this->assertSame('Catan', $entries[0]->title);
        $this->assertSame(['de' => 3, 'fr' => 1], $entries[0]->counts);
        $this->assertSame('2026-10-30T10:00:00+00:00', $entries[0]->updatedAt);
    }

    public function test_put_replaces_an_existing_entry_and_orders_newest_first(): void
    {
        $times = ['2026-10-30T10:00:00+00:00', '2026-10-30T11:00:00+00:00', '2026-10-30T12:00:00+00:00'];
        $index = new FileReviewIndex($this->file, static function () use (&$times): \DateTimeImmutable {
            return new \DateTimeImmutable(array_shift($times));
        });

        $index->put('game', 'a', 'A', ['de' => 1]);
        $index->put('game', 'b', 'B', ['de' => 1]);
        $index->put('game', 'a', 'A2', ['de' => 2]);

        $entries = $index->entries();
        $this->assertSame(['a', 'b'], array_map(static fn (ReviewEntry $e): string => $e->id, $entries));
        $this->assertSame('A2', $entries[0]->title);
        $this->assertSame(['de' => 2], $entries[0]->counts);
    }

    public function test_zero_counts_forget(): void
    {
        $index = new FileReviewIndex($this->file);
        $index->put('game', 'g1', 'Catan', ['de' => 2]);

        $index->put('game', 'g1', 'Catan', ['de' => 0, 'fr' => 0]);
        $this->assertSame([], $index->entries());

        $index->put('game', 'g2', 'Azul', []);
        $this->assertSame([], $index->entries());
    }

    public function test_zero_counts_are_dropped_from_the_stored_counts(): void
    {
        $index = new FileReviewIndex($this->file);
        $index->put('game', 'g1', 'Catan', ['de' => 2, 'fr' => 0]);

        $this->assertSame(['de' => 2], $index->entries()[0]->counts);
    }

    public function test_forget(): void
    {
        $index = new FileReviewIndex($this->file);
        $index->put('game', 'g1', 'Catan', ['de' => 1]);
        $index->put('game', 'g2', 'Azul', ['de' => 1]);

        $index->forget('game', 'g1');
        $index->forget('game', 'unknown');

        $this->assertSame(['g2'], array_map(static fn (ReviewEntry $e): string => $e->id, $index->entries()));
    }

    public function test_replace_all(): void
    {
        $index = new FileReviewIndex($this->file);
        $index->put('game', 'old', 'Old', ['de' => 1]);

        $index->replaceAll([
            new ReviewEntry('game', 'n1', 'New 1', ['de' => 2], '2026-10-30T09:00:00+00:00'),
            new ReviewEntry('game', 'n2', 'New 2', ['fr' => 1], '2026-10-30T10:00:00+00:00'),
        ]);

        $entries = $index->entries();
        $this->assertSame(['n2', 'n1'], array_map(static fn (ReviewEntry $e): string => $e->id, $entries));
        $this->assertSame('2026-10-30T10:00:00+00:00', $entries[0]->updatedAt);
    }

    public function test_missing_or_broken_file_is_empty(): void
    {
        $index = new FileReviewIndex($this->file);
        $this->assertSame([], $index->entries());

        mkdir($this->dir, 0775, true);
        file_put_contents($this->file, '{not json');
        $this->assertSame([], $index->entries());

        $index->put('game', 'g1', 'Catan', ['de' => 1]);
        $this->assertCount(1, $index->entries());
    }

    public function test_two_instances_on_one_file_do_not_lose_each_others_entries(): void
    {
        $a = new FileReviewIndex($this->file);
        $b = new FileReviewIndex($this->file);

        $a->put('game', 'g1', 'Catan', ['de' => 1]);
        $b->put('game', 'g2', 'Azul', ['de' => 1]);

        $ids = array_map(static fn (ReviewEntry $e): string => $e->id, $a->entries());
        sort($ids);
        $this->assertSame(['g1', 'g2'], $ids);
    }
}
