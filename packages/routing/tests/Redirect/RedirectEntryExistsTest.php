<?php

declare(strict_types=1);

namespace Preflow\Routing\Tests\Redirect;

require_once __DIR__ . '/Fixtures/InMemoryRedirectStore.php';

use PHPUnit\Framework\TestCase;
use Preflow\Core\Routing\PageExists as CorePageExists;
use Preflow\Routing\Redirect\AreaMode;
use Preflow\Routing\Redirect\PageExists;
use Preflow\Routing\Redirect\Redirect;
use Preflow\Routing\Redirect\RedirectEntryExists;
use Preflow\Routing\Redirect\RedirectResolver;
use Preflow\Routing\Tests\Redirect\Fixtures\InMemoryRedirectStore;

final class RedirectEntryExistsTest extends TestCase
{
    private function resolver(): RedirectResolver
    {
        return new RedirectResolver(new InMemoryRedirectStore([
            new Redirect('1', '/old-help', 'de', false, AreaMode::One, null, null, '/de/help', 301),
            new Redirect('2', '/old-area', 'de', true, AreaMode::One, null, null, '/de/area', 301),
            new Redirect('3', '/broken', 'de', false, AreaMode::One, null, null, null, 301),
            new Redirect('4', '/favicon/old.ico', '', false, AreaMode::One, null, null, '/favicon.ico', 301),
            new Redirect('5', '/old-files', '', true, AreaMode::Prefix, null, null, '/files', 301),
        ]));
    }

    public function test_an_entry_answers_its_address_in_its_language(): void
    {
        $lookup = new RedirectEntryExists(fn (): RedirectResolver => $this->resolver());

        $this->assertTrue($lookup->exists('de', '/old-help'));
        $this->assertTrue($lookup->exists('de', '/old-area/deep'));
        $this->assertTrue($lookup->exists('de', '/broken'), 'an entry with no target is still an entry');
        $this->assertFalse($lookup->exists('en', '/old-help'));
        $this->assertFalse($lookup->exists('de', '/help'));
    }

    public function test_an_entry_for_every_language_does_not_count(): void
    {
        $lookup = new RedirectEntryExists($this->resolver());

        // The answer is the same in every language, so the language redirect adds nothing.
        $this->assertFalse($lookup->exists('de', '/favicon/old.ico'));
        $this->assertFalse($lookup->exists('en', '/old-files/a.png'));
    }

    public function test_the_path_is_read_in_its_clean_form(): void
    {
        $lookup = new RedirectEntryExists($this->resolver());

        $this->assertTrue($lookup->exists('de', '/old-help/'));
        $this->assertTrue($lookup->exists('de', '//old-help'));
    }

    public function test_without_a_store_nothing_answers(): void
    {
        $this->assertFalse((new RedirectEntryExists(static fn (): ?RedirectResolver => null))->exists('de', '/old-help'));
    }

    public function test_a_store_that_fails_answers_nothing(): void
    {
        $store = new InMemoryRedirectStore();
        $store->fail = true;

        $this->assertFalse((new RedirectEntryExists(new RedirectResolver($store)))->exists('de', '/old-help'));
    }

    public function test_it_is_a_page_lookup_for_core_but_never_the_redirect_rules_page_lookup(): void
    {
        $lookup = new RedirectEntryExists($this->resolver());

        $this->assertInstanceOf(CorePageExists::class, $lookup);
        $this->assertNotInstanceOf(PageExists::class, $lookup);
    }
}
