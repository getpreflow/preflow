<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Jobs\JobFilters;
use PHPUnit\Framework\TestCase;

final class JobFiltersTest extends TestCase
{
    public function test_defaults(): void
    {
        $f = JobFilters::fromArray([]);

        $this->assertSame('', $f->q);
        $this->assertNull($f->place);
        $this->assertNull($f->type);
        $this->assertNull($f->remote);
        $this->assertSame('cards', $f->view);
        $this->assertSame(1, $f->page);
    }

    public function test_unknown_values_become_defaults(): void
    {
        $f = JobFilters::fromArray(['type' => '<script>', 'remote' => ['x'], 'page' => '-3', 'view' => 'grid']);

        $this->assertNull($f->type);
        $this->assertNull($f->remote);
        $this->assertSame(1, $f->page);
        $this->assertSame('cards', $f->view);
    }

    public function test_q_is_trimmed_and_cut_to_100(): void
    {
        $this->assertSame('php', JobFilters::fromArray(['q' => "  php \n"])->q);
        $this->assertSame(100, mb_strlen(JobFilters::fromArray(['q' => str_repeat('ä', 300)])->q));
        $this->assertSame('', JobFilters::fromArray(['q' => ['a']])->q);
    }

    public function test_a_huge_page_is_capped_so_the_offset_stays_an_integer(): void
    {
        $this->assertLessThanOrEqual(10000, JobFilters::fromArray(['page' => '99999999999999999999'])->page);
    }

    public function test_to_query_omits_defaults_and_keeps_order(): void
    {
        $f = JobFilters::fromArray(['page' => '2', 'q' => 'php', 'remote' => 'full']);

        $this->assertSame(['q' => 'php', 'remote' => 'full', 'page' => 2], $f->toQuery());
    }

    public function test_url_with_override(): void
    {
        $f = JobFilters::fromArray(['q' => 'php', 'page' => '3']);

        $this->assertSame('/jobs?q=php&page=3', $f->url());
        $this->assertSame('/jobs?q=php&page=4', $f->url('/jobs', ['page' => 4]));
        $this->assertSame('/jobs?q=php&view=list', $f->url('/jobs', ['page' => null, 'view' => 'list']));
        $this->assertSame('/jobs', JobFilters::fromArray([])->url());
    }

    public function test_with_changes_are_normalised_too(): void
    {
        $f = JobFilters::fromArray(['q' => 'php'])->with(['type' => 'contract', 'remote' => 'nope']);

        $this->assertSame('contract', $f->type);
        $this->assertNull($f->remote);
        $this->assertSame('php', $f->q);
    }
}
