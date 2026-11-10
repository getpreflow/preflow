<?php

declare(strict_types=1);

namespace Tests\Site;

use App\Components\Blocks\Hero\Hero;
use PHPUnit\Framework\TestCase;
use Preflow\Components\ComponentRenderer;
use Tests\Support\StarterApp;

/** The Hero block prints its button only for a site path or a web address. */
final class HeroTest extends TestCase
{
    use StarterApp;

    protected function setUp(): void
    {
        $this->bootStarter();
    }

    protected function tearDown(): void
    {
        $this->removeStarter();
    }

    private function render(string $url): string
    {
        $hero = $this->container()->make(Hero::class);
        $hero->setProps(['headline' => 'Hi', 'cta_label' => 'Go', 'cta_url' => $url]);

        return $this->container()->get(ComponentRenderer::class)->render($hero);
    }

    public function test_site_paths_and_web_addresses_get_the_button(): void
    {
        $this->assertStringContainsString('class="hero__cta" href="/jobs"', $this->render('/jobs'));
        $this->assertStringContainsString('href="https://example.com/x"', $this->render('https://example.com/x'));
    }

    public function test_other_links_get_no_button(): void
    {
        // Browsers drop tabs and newlines in a URL: "/\t/host" is followed as "//host", another site.
        foreach (['javascript:alert(1)', '//evil.example', '/\\evil.example', "/\t/evil.example", "/\n/evil.example", '/ /x', "/jobs\x00", "https://x\x7f"] as $url) {
            $this->assertStringNotContainsString('hero__cta', $this->render($url), json_encode($url));
        }
    }
}
