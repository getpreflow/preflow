<?php

declare(strict_types=1);

namespace Preflow\Routing\Tests\Redirect;

require_once __DIR__ . '/Fixtures/InMemoryRedirectStore.php';

use PHPUnit\Framework\TestCase;
use Preflow\Routing\Redirect\AreaMode;
use Preflow\Routing\Redirect\Redirect;
use Preflow\Routing\Redirect\RedirectResolver;
use Preflow\Routing\Tests\Redirect\Fixtures\InMemoryRedirectStore;

final class RedirectResolverTest extends TestCase
{
    private function resolver(): RedirectResolver
    {
        return new RedirectResolver(new InMemoryRedirectStore([
            new Redirect('1', '/old-help', 'de', false, AreaMode::One, null, null, '/de/help', 301),
            new Redirect('2', '/old', 'de', true, AreaMode::Prefix, null, null, '/de/new', 302),
            new Redirect('3', '/old/deep', 'de', true, AreaMode::Prefix, null, null, '/de/deeper', 301),
            new Redirect('4', '/broken', 'de', false, AreaMode::One, null, null, null, 301),
        ]));
    }

    public function test_exact_entry_resolves_with_its_url(): void
    {
        $found = $this->resolver()->resolve('/old-help', 'de');

        $this->assertSame('1', $found['entry']->id);
        $this->assertSame('/de/help', $found['url']);
    }

    public function test_the_longest_area_wins_and_the_rest_is_kept(): void
    {
        $this->assertSame('/de/new/page', $this->resolver()->resolve('/old/page', 'de')['url']);
        $this->assertSame('/de/deeper/x', $this->resolver()->resolve('/old/deep/x', 'de')['url']);
    }

    public function test_no_entry_or_no_target_resolves_to_null_but_has_tells_the_entry(): void
    {
        $this->assertNull($this->resolver()->resolve('/faq', 'de'));
        $this->assertFalse($this->resolver()->has('/faq', 'de'));
        $this->assertNull($this->resolver()->resolve('/broken', 'de'));
        $this->assertTrue($this->resolver()->has('/broken', 'de'), 'an entry with no target is still an entry');
        $this->assertTrue($this->resolver()->has('/old/anything', 'de'));
        $this->assertFalse($this->resolver()->has('/old-help', 'en'));
    }
}
