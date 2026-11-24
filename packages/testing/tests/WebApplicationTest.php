<?php

declare(strict_types=1);

namespace Preflow\Testing\Tests;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Core\PageCache\PageCacheConfig;
use Preflow\Core\PageCache\PageCacheMiddleware;
use Preflow\Testing\Tests\Fixtures\State\Greeter;
use Preflow\Testing\Tests\Fixtures\State\Tally;
use Preflow\Testing\Tests\Fixtures\State\Visitor;
use Preflow\Testing\WebApplication;
use Preflow\Testing\ChecksFrameBalance;

require_once __DIR__ . '/Fixtures/State/Tally.php';
require_once __DIR__ . '/Fixtures/State/Greeter.php';
require_once __DIR__ . '/Fixtures/State/Visitor.php';

final class WebApplicationTest extends TestCase
{
    use ChecksFrameBalance;

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/preflow_webapp_' . bin2hex(random_bytes(4));
        @mkdir($this->dir . '/config', 0777, true);
        @mkdir($this->dir . '/app/pages', 0777, true);
        file_put_contents($this->dir . '/config/app.php', "<?php\nreturn ['name'=>'t','debug'=>0,'engine'=>'twig','key'=>'web-application-test-key-0123456','url'=>'https://example.com'];\n");
        file_put_contents($this->dir . '/config/page_cache.php', "<?php\nreturn ['enabled' => true, 'explain' => true];\n");
        file_put_contents($this->dir . '/app/pages/page.twig', '<p>{{ request.query.who|default("") }}</p>');
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    #[RunInSeparateProcess]
    public function test_boots_for_the_web_and_verifies_every_store(): void
    {
        $_SESSION = ['left' => 'by an earlier test'];
        $app = WebApplication::boot($this->dir);
        $this->assertFalse(isset($_SESSION), 'a leftover $_SESSION outside an active session is unset');
        $this->assertTrue($app->container()->bound(PageCacheMiddleware::class), 'the cache serves: web mode');
        $this->assertSame(PageCacheConfig::VERIFY_ALWAYS, $app->container()->get(PageCacheConfig::class)->verify);

        $response = $app->handle(new ServerRequest('GET', 'https://example.com/page'));
        $this->assertSame('miss', $response->getHeaderLine('X-Page-Cache'));
        $this->assertSame('hit', WebApplication::boot($this->dir)->handle(new ServerRequest('GET', 'https://example.com/page'))->getHeaderLine('X-Page-Cache'));
    }

    #[RunInSeparateProcess]
    public function test_assert_page_cacheable_passes_for_a_page_every_visitor_gets_alike(): void
    {
        WebApplication::assertPageCacheable(WebApplication::boot($this->dir), '/page');

        $this->assertNull(WebApplication::boot($this->dir)->handle(new ServerRequest('GET', 'https://example.com/page'))->getHeaderLine('X-Page-Cache-Skip') ?: null);
    }

    #[RunInSeparateProcess]
    public function test_assert_page_cacheable_fails_with_reason(): void
    {
        file_put_contents($this->dir . '/app/pages/random.twig', '<p>{{ random(1000000000) }}</p>');

        try {
            WebApplication::assertPageCacheable(WebApplication::boot($this->dir), '/random');
            $this->fail('A page that differs per visitor must fail the assertion.');
        } catch (\PHPUnit\Framework\AssertionFailedError $e) {
            $this->assertStringContainsString('/random', $e->getMessage());
            $this->assertStringContainsString('differs', $e->getMessage(), 'the reason');
            $this->assertStringContainsString('live block', $e->getMessage(), 'the hint');
        }
    }

    #[RunInSeparateProcess]
    public function test_assert_page_cacheable_says_when_the_cache_is_off(): void
    {
        file_put_contents($this->dir . '/config/page_cache.php', "<?php\nreturn ['enabled' => false];\n");

        try {
            WebApplication::assertPageCacheable(WebApplication::boot($this->dir), '/page');
            $this->fail('With the cache off the assertion must fail.');
        } catch (\PHPUnit\Framework\AssertionFailedError $e) {
            $this->assertStringContainsString('The page cache is off for this app', $e->getMessage());
        }
    }

    private function statefulApp(): \Preflow\Core\Application
    {
        file_put_contents($this->dir . '/config/page_cache.php', "<?php\nreturn ['enabled' => false];\n");
        file_put_contents($this->dir . '/app/pages/tally.twig', '<html><head></head><body>'
            . '{{ component(' . json_encode(Tally::class) . ', {start: 4}) }}</body></html>');

        return WebApplication::boot($this->dir);
    }

    private function page(): string
    {
        return (string) $this->statefulApp()->handle(new ServerRequest('GET', 'https://example.com/tally'))->getBody();
    }

    #[RunInSeparateProcess]
    public function test_calls_an_action_like_the_page_does(): void
    {
        $page = $this->page();

        $response = WebApplication::componentAction(WebApplication::boot($this->dir), $page, Tally::class, 'bump', ['by' => 3]);

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertStringContainsString('Tally 7', (string) $response->getBody());
    }

    #[RunInSeparateProcess]
    public function test_missing_component_lists_the_ones_on_the_page(): void
    {
        $page = $this->page();
        $id = (new Tally())->getComponentId();
        $other = new Tally();
        $other->setProps(['key' => 'other']);
        $otherId = $other->getComponentId();

        try {
            WebApplication::componentAction(WebApplication::boot($this->dir), $page, Tally::class, 'bump', [], 'other');
            $this->fail('A component whose state is not on the page must fail.');
        } catch (\PHPUnit\Framework\AssertionFailedError $e) {
            $this->assertStringContainsString($otherId, $e->getMessage());
            $this->assertStringContainsString($id, $e->getMessage(), 'the ones on the page');
        }
    }

    #[RunInSeparateProcess]
    public function test_returned_state_can_be_used_for_the_next_call(): void
    {
        $page = $this->page();
        $id = (new Tally())->getComponentId();

        $first = (string) WebApplication::componentAction(WebApplication::boot($this->dir), $page, Tally::class, 'bump', ['by' => 2])->getBody();
        $this->assertStringContainsString('Tally 6', $first);
        $next = WebApplication::stateFrom($first, $id);
        $this->assertNotNull($next, 'the answer carries the new state');
        $this->assertNull(WebApplication::stateFrom($page, 'Nope-00000000'));

        // The next page is the answer's state in the old page's place.
        $again = (string) WebApplication::componentAction(
            WebApplication::boot($this->dir),
            '<input type="hidden" id="preflow-state--' . $id . '" name="_state[' . $id . ']" value="' . htmlspecialchars($next) . '">',
            Tally::class,
            'bump',
            ['by' => 5],
        )->getBody();

        $this->assertStringContainsString('Tally 11', $again);
    }

    #[RunInSeparateProcess]
    public function test_calls_an_action_of_a_component_without_state_with_its_props(): void
    {
        $app = WebApplication::boot($this->dir);

        $answer = (string) WebApplication::componentAction($app, '<p>no carrier needed</p>', Greeter::class, 'greet', ['name' => 'Ada'], props: ['greeting' => 'Hello'])->getBody();
        $this->assertStringContainsString('Hello, Ada', $answer, 'the prop and the param');

        $keyed = (string) WebApplication::componentAction(WebApplication::boot($this->dir), '', Greeter::class, 'greet', ['name' => 'Bo'], 'k1', props: ['greeting' => 'Hi'])->getBody();
        $this->assertStringContainsString('Hi, Bo', $keyed);
        $this->assertStringContainsString('Greeter-', $keyed);
        $this->assertStringContainsString('-k1', $keyed, 'the key is part of the id');
    }

    #[RunInSeparateProcess]
    public function test_a_localised_page_calls_through_its_prefix(): void
    {
        file_put_contents($this->dir . '/config/app.php', "<?php\nreturn ['name'=>'t','debug'=>0,'engine'=>'twig','key'=>'web-application-test-key-0123456','url'=>'https://example.com','locale'=>'en'];\n");
        file_put_contents($this->dir . '/config/i18n.php', "<?php\nreturn ['default'=>'en','available'=>['en','de']];\n");
        $app = WebApplication::boot($this->dir);

        $answer = WebApplication::componentAction($app, '', Greeter::class, 'greet', ['name' => 'Eva'], localePrefix: '/de', props: ['greeting' => 'Hallo']);

        $this->assertSame(200, $answer->getStatusCode(), (string) $answer->getBody());
        $this->assertStringContainsString('Hallo, Eva', (string) $answer->getBody());
    }

    #[RunInSeparateProcess]
    public function test_an_action_sees_the_cookies_it_is_given(): void
    {
        $with = (string) WebApplication::componentAction(WebApplication::boot($this->dir), '', Visitor::class, 'hello', cookies: ['visitor' => 'ada', 'theme' => 'dark'])->getBody();
        $this->assertStringContainsString('Visitor ada', $with);
        $this->assertStringContainsString('header visitor=ada; theme=dark', $with, 'the Cookie header carries them as a browser sends them');

        $without = (string) WebApplication::componentAction(WebApplication::boot($this->dir), '', Visitor::class, 'hello')->getBody();
        $this->assertStringContainsString('Visitor none / header ', $without, 'no cookies by default');
    }

    #[RunInSeparateProcess]
    public function test_props_are_refused_for_a_component_with_state(): void
    {
        try {
            WebApplication::componentAction($this->statefulApp(), $this->page(), Tally::class, 'bump', props: ['start' => 1]);
            $this->fail('Props for a component with state must fail.');
        } catch (\PHPUnit\Framework\AssertionFailedError $e) {
            $this->assertStringContainsString('has state', $e->getMessage());
        }
    }
}
