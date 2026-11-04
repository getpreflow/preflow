<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Redirects;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Preflow\Folio\Redirects\RedirectEntries;
use Preflow\Folio\Tests\Support\InternalStorage;
use Preflow\Routing\Redirect\AreaMode;
use Preflow\Routing\Redirect\Redirect;

/** Folio's redirect entries (folio_redirects) on JSON and on SQLite. */
final class RedirectEntriesTest extends TestCase
{
    use InternalStorage;

    protected function tearDown(): void
    {
        $this->removeInternalStorage();
    }

    private function entries(string $storage): RedirectEntries
    {
        return new RedirectEntries($this->internalStorage($storage, RedirectEntries::definition()));
    }

    #[DataProvider('storages')]
    public function test_exact_tries_the_locale_then_any_language(string $storage): void
    {
        $entries = $this->entries($storage);
        $entries->save(['path' => '/werkstatt', 'locale' => 'de', 'target_url' => '/de/workshops']);
        $entries->save(['path' => '/werkstatt', 'locale' => '', 'target_url' => '/workshops', 'status' => 302]);

        $de = $entries->exact('/werkstatt', 'de');
        $this->assertInstanceOf(Redirect::class, $de);
        $this->assertSame('de', $de->locale);
        $this->assertSame('/de/workshops', $de->targetUrl);
        $this->assertSame(301, $de->status);
        $this->assertFalse($de->area);
        $this->assertSame(Redirect::id('exact', 'de', '/werkstatt'), $de->id);

        $en = $entries->exact('/werkstatt', 'en');
        $this->assertSame('', $en?->locale);
        $this->assertSame('/workshops', $en->targetUrl);
        $this->assertSame(302, $en->status);

        $this->assertNull($entries->exact('/elsewhere', 'de'));
    }

    #[DataProvider('storages')]
    public function test_areas_returns_both(string $storage): void
    {
        $entries = $this->entries($storage);
        $entries->save(['path' => '/kurse', 'locale' => 'de', 'area' => true, 'mode' => 'prefix', 'target_url' => '/de/workshops']);
        $entries->save(['path' => '/archive', 'locale' => '', 'area' => true, 'target_type' => 'page', 'target_id' => 'p1']);
        $entries->save(['path' => '/courses', 'locale' => 'en', 'area' => true, 'target_url' => '/workshops']);
        $entries->save(['path' => '/single', 'locale' => 'de', 'target_url' => '/x']);

        $areas = $entries->areas('de');
        $byPath = [];
        foreach ($areas as $area) {
            $byPath[$area->path] = $area;
        }
        ksort($byPath);

        $this->assertSame(['/archive', '/kurse'], array_keys($byPath));
        $this->assertSame(AreaMode::Prefix, $byPath['/kurse']->mode);
        $this->assertTrue($byPath['/kurse']->area);
        $this->assertSame(AreaMode::One, $byPath['/archive']->mode);
        $this->assertSame('page', $byPath['/archive']->targetType);
        $this->assertSame('p1', $byPath['/archive']->targetId);
        $this->assertNull($byPath['/archive']->targetUrl);
        $this->assertSame(Redirect::id('area', '', '/archive'), $byPath['/archive']->id);
    }

    #[DataProvider('storages')]
    public function test_hit_counts_and_stamps(string $storage): void
    {
        $entries = $this->entries($storage);
        $entries->save(['path' => '/old', 'locale' => 'en', 'target_url' => '/new']);
        $redirect = $entries->exact('/old', 'en');
        $this->assertNotNull($redirect);
        $this->assertSame(0, (int) $entries->find($redirect->id)['hits']);
        $this->assertNull($entries->find($redirect->id)['last_hit_at']);

        $entries->hit($redirect, new \DateTimeImmutable('2026-11-04T10:00:00+00:00'));
        $entries->hit($redirect, new \DateTimeImmutable('2026-11-05T11:30:00+00:00'));

        $row = $entries->find($redirect->id);
        $this->assertSame(2, $row['hits']);
        $this->assertSame('2026-11-05T11:30:00+00:00', $row['last_hit_at']);
    }

    #[DataProvider('storages')]
    public function test_save_replaces_the_same_address(string $storage): void
    {
        $entries = $this->entries($storage);
        $entries->save(['path' => '/old', 'locale' => 'en', 'target_url' => '/first', 'kind' => 'auto']);
        // Typed by hand with a trailing slash and an extra one: the same address.
        $entries->save(['path' => '//old/', 'locale' => 'en', 'target_url' => '/second', 'kind' => 'manual', 'status' => 302]);
        // Another language and an area at the same path are other entries.
        $entries->save(['path' => '/old', 'locale' => 'de', 'target_url' => '/de/x']);
        $entries->save(['path' => '/old', 'locale' => 'en', 'area' => true, 'target_url' => '/y']);

        $this->assertCount(3, $entries->all());
        $row = $entries->at('en', '/old');
        $this->assertSame(Redirect::id('exact', 'en', '/old'), $row['id']);
        $this->assertSame('/old', $row['path']);
        $this->assertSame('/second', $row['target_url']);
        $this->assertSame('manual', $row['kind']);
        $this->assertSame(302, $row['status']);
        $this->assertFalse($row['area']);

        $entries->delete($row['id']);
        $this->assertNull($entries->at('en', '/old'));
        $this->assertNull($entries->find($row['id']));
        $this->assertCount(2, $entries->all());
    }

    #[DataProvider('storages')]
    public function test_lookups_are_exact_on_case(string $storage): void
    {
        $entries = $this->entries($storage);
        $entries->save(['path' => '/Old', 'locale' => '', 'target_url' => '/new', 'target_type' => null]);
        $entries->save(['path' => '/x', 'locale' => '', 'target_type' => 'Page', 'target_id' => 'P1', 'source_type' => 'Page', 'source_id' => 'P1']);

        $this->assertNull($entries->exact('/old', 'en'));
        $this->assertNull($entries->at('', '/old'));
        $this->assertSame('/Old', $entries->exact('/Old', 'en')?->path);
        $this->assertSame([], $entries->targeting('page', 'p1'));
        $this->assertSame([], $entries->sourcedBy('page', 'p1'));
        $this->assertCount(1, $entries->targeting('Page', 'P1'));
        $this->assertCount(1, $entries->sourcedBy('Page', 'P1'));
    }
}
