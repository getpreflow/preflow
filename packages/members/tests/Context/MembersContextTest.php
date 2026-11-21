<?php

declare(strict_types=1);

namespace Preflow\Members\Tests\Context;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Context\Contexts;
use Preflow\Core\Context\RenderContext;
use Preflow\Core\Context\SystemClock;
use Preflow\Members\MemberEvents;
use Preflow\Members\Members;
use Preflow\Members\MembersView;
use Preflow\Members\Tests\Support\MemberFlows;
use Preflow\Members\Tests\Support\MembersScaffold;
use Psr\Http\Message\ServerRequestInterface;
use Preflow\Testing\ChecksFrameBalance;

/** The members read the locale, the request and the clock from the render context. */
final class MembersContextTest extends TestCase
{
    use ChecksFrameBalance;

    use MembersScaffold;
    use MemberFlows;

    /** @var array<string, mixed> */
    private array $server = [];

    protected function setUp(): void
    {
        $this->server = $_SERVER;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;
        Contexts::reset();
        $this->removeScaffold();
    }

    private function visit(ServerRequestInterface $request): RenderContext
    {
        return RenderContext::fromRequest($request, $this->flow->site(), new SystemClock());
    }

    /** A member page renders in its locale (texts, layout) and the render's locale is back afterwards. */
    #[RunInSeparateProcess]
    public function test_members_view_page_locale_is_restored_after_its_render(): void
    {
        $this->flowSite();
        $request = (new Psr17Factory())->createServerRequest('GET', 'https://site.test/de/members/login')->withAttribute('locale', 'de');

        [$html, $after] = Contexts::run($this->visit($request), function () use ($request): array {
            $response = $this->flow->container()->get(MembersView::class)->render('login', [], $request);

            return [(string) $response->getBody(), Contexts::current()->locale];
        });

        $this->assertStringContainsString('<html lang="de">', $html);
        $this->assertStringContainsString('<h1>Anmelden</h1>', $html, 'the page\'s texts follow the page\'s locale');
        $this->assertSame('en', $after, 'the render\'s own locale is back after the page');
    }

    /** A verified registration without a request hands the admission the render's request, not one built from $_SERVER. */
    #[RunInSeparateProcess]
    public function test_members_current_request_reads_the_context_not_server_globals(): void
    {
        $this->flowSite();
        $_SERVER['REQUEST_URI'] = '/from-the-superglobals';
        $_SERVER['REQUEST_METHOD'] = 'DELETE';
        $seen = [];
        $this->flow->container()->get(MemberEvents::class)->on(MemberEvents::CONFIRMED, static function (array $payload) use (&$seen): void {
            $seen[] = $payload['request']->getMethod() . ' ' . $payload['request']->getUri()->getPath();
        });
        $request = (new Psr17Factory())->createServerRequest('POST', 'https://site.test/signup/verified');

        Contexts::run($this->visit($request), fn (): mixed => $this->flow->container()->get(Members::class)->register('ada@example.com', 'Ada Lovelace', self::PASSWORD, 'en', emailVerified: true));

        $this->assertSame(['POST /signup/verified'], $seen);
    }

    /** Outside every render a verified registration fails before it writes: no half-registered member is left behind. */
    #[RunInSeparateProcess]
    public function test_a_verified_registration_without_a_render_stores_nothing(): void
    {
        $this->flowSite();
        $members = $this->flow->container()->get(Members::class);

        try {
            $members->register('ada@example.com', 'Ada Lovelace', self::PASSWORD, 'en', emailVerified: true);
            $this->fail('NoRenderContext expected');
        } catch (\Preflow\Core\Context\NoRenderContext) {
        }

        $this->assertNull($members->findByEmail('ada@example.com'));
    }
}
