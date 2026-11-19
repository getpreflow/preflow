<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\Context;

use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Context\Facet;
use Preflow\Core\Context\FrozenClock;
use Preflow\Core\Context\OutboxPolicy;
use Preflow\Core\Context\Purpose;
use Preflow\Core\Context\ReadMode;
use Preflow\Core\Context\RenderContext;
use Preflow\Core\Context\RoleView;
use Preflow\Core\Context\Site;
use Preflow\Core\Context\Surface;
use Preflow\Core\Context\SystemClock;
use Preflow\Core\Context\Viewer;
use Preflow\Core\Context\WritePolicy;
use Preflow\Core\Http\Hypermedia\HypermediaProtocol;
use Preflow\Core\Http\Hypermedia\HypermediaProtocols;
use Preflow\Core\Http\LocaleUrls;
use Preflow\Core\Identity;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class RenderContextTest extends TestCase
{
    public function test_withers_return_new_values_and_leave_the_original(): void
    {
        $a = self::visit();
        $b = $a->in('de');

        $this->assertNotSame($a, $b);
        $this->assertSame('en', $a->locale);
        $this->assertSame('de', $b->locale);

        $c = $b->admin('fr')->workingCopies()->capturing()->skippingWrites()->because(Purpose::Preview);
        $this->assertSame('de', $b->locale);
        $this->assertFalse($b->surface->admin);
        $this->assertFalse($b->reads->readsWorkingCopies());
        $this->assertSame(OutboxPolicy::Deliver, $b->outbox);
        $this->assertSame(WritePolicy::Allow, $b->writes);
        $this->assertSame(Purpose::Visit, $b->purpose);

        $this->assertTrue($c->surface->admin);
        $this->assertSame('fr', $c->surface->uiLocale);
        $this->assertTrue($c->surface->overrides);
        $this->assertTrue($c->reads->readsWorkingCopies());
        $this->assertSame(OutboxPolicy::Capture, $c->outbox);
        $this->assertSame(WritePolicy::Skip, $c->writes);
        $this->assertSame(Purpose::Preview, $c->purpose);
        $this->assertEquals($a->now, $c->now, 'now is kept by every wither but at()');
    }

    public function test_internal_preset_is_anonymous_public_published_discarding_skipping(): void
    {
        $ctx = RenderContext::internal(Purpose::Audit, self::site(), new SystemClock(), '/x');

        $this->assertSame(Purpose::Audit, $ctx->purpose);
        $this->assertTrue($ctx->viewer->isAnonymous());
        $this->assertFalse($ctx->surface->admin);
        $this->assertSame('en', $ctx->locale);
        $this->assertFalse($ctx->reads->readsWorkingCopies());
        $this->assertNull($ctx->reads->at);
        $this->assertSame(OutboxPolicy::Discard, $ctx->outbox);
        $this->assertSame(WritePolicy::Skip, $ctx->writes);
        $this->assertNotNull($ctx->request);
        $this->assertSame('/x', $ctx->request->path);
        $this->assertSame('GET', $ctx->request->method);
        $this->assertSame('GET', $ctx->request->request?->getMethod());
        $this->assertSame('/x', $ctx->request->request?->getUri()->getPath());
        $this->assertSame([], $ctx->request->request?->getCookieParams());
    }

    public function test_from_request_is_a_visit_and_component_paths_are_fragments(): void
    {
        $site = self::site();
        $clock = new SystemClock();

        $visit = RenderContext::fromRequest(new ServerRequest('POST', '/a'), $site, $clock);
        $this->assertSame(Purpose::Visit, $visit->purpose);
        $this->assertSame('/a', $visit->request?->path);
        $this->assertSame('POST', $visit->request?->method);
        $this->assertTrue($visit->viewer->isAnonymous());
        $this->assertSame('en', $visit->locale);
        $this->assertSame(OutboxPolicy::Deliver, $visit->outbox);
        $this->assertSame(WritePolicy::Allow, $visit->writes);

        $this->assertSame(Purpose::Fragment, RenderContext::fromRequest(new ServerRequest('POST', '/--component/action'), $site, $clock)->purpose);
        $this->assertSame(Purpose::Fragment, RenderContext::fromRequest(new ServerRequest('GET', '/de/--component/action'), $site, $clock)->purpose, 'a component URL under a locale prefix');
        $this->assertSame(Purpose::Visit, RenderContext::fromRequest(new ServerRequest('GET', '/--components'), $site, $clock)->purpose, 'only the whole segment');
    }

    public function test_from_request_follows_the_hypermedia_client(): void
    {
        $site = self::site();
        $clock = new SystemClock();
        $protocols = new HypermediaProtocols();
        $protocols->add(new HeaderProtocol());
        $request = (new ServerRequest('GET', '/a'))->withAttribute(HypermediaProtocols::class, $protocols);

        $this->assertSame(Purpose::Visit, RenderContext::fromRequest($request, $site, $clock)->purpose, 'a plain browser request');
        $this->assertSame(Purpose::Fragment, RenderContext::fromRequest($request->withHeader('X-Fragment', '1'), $site, $clock)->purpose);
        $this->assertSame(
            Purpose::Visit,
            RenderContext::fromRequest($request->withHeader('X-Fragment', '1')->withHeader('X-Restore', '1'), $site, $clock)->purpose,
            'a history restore asks for the whole page',
        );
    }

    public function test_as_role_forces_skip_and_capture(): void
    {
        $ctx = self::visit()->asRole(new RoleView('member', ['gold']));

        $this->assertSame(WritePolicy::Skip, $ctx->writes);
        $this->assertSame(OutboxPolicy::Capture, $ctx->outbox);
        $this->assertTrue($ctx->viewer->isSynthetic());
        $this->assertSame('role:member:gold', $ctx->viewer->key());

        $this->assertSame(WritePolicy::Skip, $ctx->as(Viewer::synthetic(new RoleView('staff', ['editor'])))->writes, 'as() with a synthetic viewer is asRole()');
        $this->assertSame(OutboxPolicy::Capture, $ctx->delivering()->outbox, 'a synthetic viewer never delivers');
    }

    public function test_as_role_keeps_refuse(): void
    {
        $this->assertSame(WritePolicy::Refuse, self::visit()->refusingWrites()->asRole(new RoleView('member', ['gold']))->writes);
    }

    public function test_is_public_default(): void
    {
        $fresh = self::visit();
        $this->assertTrue($fresh->isPublicDefault());

        $this->assertFalse($fresh->workingCopies()->isPublicDefault());
        $this->assertFalse($fresh->asOf(new \DateTimeImmutable('2026-01-01'))->isPublicDefault());
        $this->assertFalse($fresh->as(new Viewer(['web' => new FixedIdentity('1')], 'web'))->isPublicDefault());
        $this->assertFalse($fresh->asRole(new RoleView('member', ['gold']))->isPublicDefault());
        $this->assertFalse($fresh->admin('de')->isPublicDefault());
        $this->assertFalse($fresh->at(new \DateTimeImmutable('2026-01-01'))->isPublicDefault());
        $this->assertFalse($fresh->with(new VariantFacet('b', false))->isPublicDefault());

        $this->assertTrue($fresh->with(new VariantFacet('a', true))->isPublicDefault());
        $this->assertTrue($fresh->in('de')->isPublicDefault(), 'the locale is part of the public default');
        $this->assertTrue($fresh->admin('de')->public()->isPublicDefault());
        $this->assertTrue(
            RenderContext::fromRequest(new ServerRequest('GET', '/a'), self::site(), new FrozenClock(new \DateTimeImmutable('2026-01-01')))->isPublicDefault(),
            'a frozen clock the app was built with is not a render frozen with at()',
        );
    }

    public function test_storable_only_for_visit_and_rebuild(): void
    {
        $ctx = self::visit();
        foreach (Purpose::cases() as $purpose) {
            $this->assertSame(
                in_array($purpose, [Purpose::Visit, Purpose::Rebuild], true),
                $ctx->because($purpose)->isStorable(),
                $purpose->name,
            );
        }
        $this->assertFalse($ctx->workingCopies()->isStorable(), 'a visit that is not the public default');
    }

    public function test_memo_key_differs_by_locale_reads_viewer_surface_and_facets(): void
    {
        $base = self::visit();
        $variants = [
            $base,
            $base->in('de'),
            $base->workingCopies(),
            $base->as(new Viewer(['web' => new FixedIdentity('1')], 'web')),
            $base->admin('en'),
            $base->with(new VariantFacet('b', true)),
        ];
        $keys = array_map(static fn (RenderContext $c): string => $c->memoKey(), $variants);
        $this->assertCount(6, array_unique($keys), implode("\n", $keys));

        $this->assertSame($base->memoKey(), self::visit()->memoKey(), 'equal contexts, equal keys');
        $this->assertSame($base->memoKey(), $base->because(Purpose::Audit)->discarding()->skippingWrites()->memoKey(), 'purpose and policies do not change what a read returns');
        $this->assertSame(
            $base->with(new VariantFacet('b', true))->with(new OtherFacet())->memoKey(),
            $base->with(new OtherFacet())->with(new VariantFacet('b', true))->memoKey(),
            'facets in class order',
        );
        $this->assertNotSame($base->admin('en')->memoKey(), $base->admin('de')->memoKey());
        $this->assertNotSame($base->admin('en')->memoKey(), $base->admin('en', false)->memoKey());
        $this->assertNotSame($base->asOf(new \DateTimeImmutable('2026-01-01'))->memoKey(), $base->asOf(new \DateTimeImmutable('2026-01-02'))->memoKey());
    }

    public function test_at_freezes_now_and_clock(): void
    {
        $at = new \DateTimeImmutable('2026-01-01 00:00:00', new \DateTimeZone('UTC'));
        $ctx = self::visit()->at($at);

        $this->assertEquals($at, $ctx->now);
        $this->assertEquals($at, $ctx->clock->now());
        $this->assertEquals($at, $ctx->clock->now());
        $this->assertEquals($at, $ctx->in('de')->now, 'kept by later withers');
    }

    public function test_for_recipient_is_an_anonymous_mail_context_in_that_locale(): void
    {
        $admin = self::visit()
            ->as(new Viewer(['web' => new FixedIdentity('1')], 'web'))
            ->admin('en')
            ->workingCopies();
        $mail = $admin->forRecipient('de');

        $this->assertSame(Purpose::Mail, $mail->purpose);
        $this->assertTrue($mail->viewer->isAnonymous());
        $this->assertFalse($mail->surface->admin);
        $this->assertSame('de', $mail->locale);
        $this->assertFalse($mail->reads->readsWorkingCopies());
        $this->assertNull($mail->request);
        $this->assertSame($admin->site, $mail->site);
    }

    public function test_extension_facets_are_kept_by_class(): void
    {
        $facet = new VariantFacet('b', true);
        $ctx = self::visit()->with($facet);

        $this->assertSame($facet, $ctx->get(VariantFacet::class));
        $this->assertNull($ctx->get(OtherFacet::class));
        $this->assertNull(self::visit()->get(VariantFacet::class), 'the original has none');
        $this->assertSame('c', $ctx->with(new VariantFacet('c', true))->get(VariantFacet::class)?->variant, 'one facet per class');
    }

    public function test_for_and_because_replace_the_request_and_purpose(): void
    {
        $ctx = self::visit()->for(new ServerRequest('DELETE', '/b'))->because(Purpose::Job);

        $this->assertSame('/b', $ctx->request?->path);
        $this->assertSame('DELETE', $ctx->request?->method);
        $this->assertSame(Purpose::Job, $ctx->purpose);
    }

    public function test_console_has_no_request(): void
    {
        $ctx = RenderContext::console(self::site(), new SystemClock());

        $this->assertNull($ctx->request);
        $this->assertSame(Purpose::Console, $ctx->purpose);
        $this->assertTrue($ctx->viewer->isAnonymous());
        $this->assertSame('en', $ctx->locale);
    }

    public function test_context_holds_no_closure_and_serializes(): void
    {
        $ctx = RenderContext::console(self::site(), new SystemClock())
            ->asRole(new RoleView('member', ['gold']))
            ->in('de')
            ->admin('de')
            ->asOf(new \DateTimeImmutable('2025-06-01'))
            ->at(new \DateTimeImmutable('2026-01-01'))
            ->with(new VariantFacet('b', true));

        $copy = unserialize(serialize($ctx));
        $this->assertEquals($ctx, $copy);
        $this->assertSame($ctx->memoKey(), $copy->memoKey());

        $this->assertNoClosure(RenderContext::fromRequest(new ServerRequest('GET', '/a'), self::site(), new SystemClock()));
        $this->assertNoClosure($ctx);
    }

    public function test_purpose_flags(): void
    {
        $this->assertSame([Purpose::Audit, Purpose::Geo, Purpose::Rebuild, Purpose::Preview], array_values(array_filter(Purpose::cases(), static fn (Purpose $p): bool => $p->leavesNoTrace())));
        $this->assertSame([Purpose::Audit, Purpose::Geo, Purpose::Rebuild, Purpose::Verify, Purpose::Preview], array_values(array_filter(Purpose::cases(), static fn (Purpose $p): bool => $p->isInternal())));
        $this->assertSame([Purpose::Visit, Purpose::Rebuild], array_values(array_filter(Purpose::cases(), static fn (Purpose $p): bool => $p->isStorable())));
    }

    public function test_site_prefix_follows_the_locale_urls(): void
    {
        $this->assertSame('/de', self::site()->prefixFor('de'));
        $this->assertSame('', self::site()->prefixFor('en'));
        $this->assertSame('', (new Site('https://example.test', ['en'], 'en'))->prefixFor('de'), 'no locale URLs, no prefix');
    }

    public function test_surface_and_read_mode_keys(): void
    {
        $this->assertSame('public', Surface::public()->key());
        $this->assertNull(Surface::public()->uiLocale);
        $this->assertFalse(Surface::public()->overrides);
        $this->assertSame('admin:de:overrides', Surface::admin('de')->key());
        $this->assertSame('admin:de:plain', Surface::admin('de', false)->key());

        $this->assertSame('published', ReadMode::published()->key());
        $this->assertSame('working', ReadMode::workingCopies()->key());
        $this->assertSame('asof:2026-01-01T00:00:00.000000Z', ReadMode::asOf(new \DateTimeImmutable('2026-01-01 01:00:00', new \DateTimeZone('+01:00')))->key());
        $this->assertFalse(ReadMode::asOf(new \DateTimeImmutable())->readsWorkingCopies());
    }

    private function assertNoClosure(mixed $value, string $path = 'context'): void
    {
        $this->assertNotInstanceOf(\Closure::class, $value, $path);
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $this->assertNoClosure($item, $path . '[' . $key . ']');
            }
        }
        if (is_object($value) && !$value instanceof ServerRequestInterface && !$value instanceof \DateTimeInterface) {
            foreach ((new \ReflectionObject($value))->getProperties() as $property) {
                if ($property->isInitialized($value)) {
                    $this->assertNoClosure($property->getValue($value), $path . '->' . $property->getName());
                }
            }
        }
    }

    private static function site(): Site
    {
        return new Site('https://example.test', ['en', 'de'], 'en', new LocaleUrls(['en', 'de'], 'en'));
    }

    private static function visit(): RenderContext
    {
        return RenderContext::fromRequest(new ServerRequest('GET', '/a'), self::site(), new SystemClock());
    }
}

final readonly class FixedIdentity implements Identity
{
    public function __construct(private string $id) {}

    public function getAuthId(): string
    {
        return $this->id;
    }
}

final readonly class VariantFacet implements Facet
{
    public function __construct(public string $variant, private bool $publicDefault) {}

    public function isPublicDefault(): bool
    {
        return $this->publicDefault;
    }

    public function memoKey(): string
    {
        return 'variant=' . $this->variant;
    }
}

final readonly class OtherFacet implements Facet
{
    public function isPublicDefault(): bool
    {
        return true;
    }

    public function memoKey(): string
    {
        return 'other';
    }
}

/** A hypermedia client that marks fragments with X-Fragment and history restores with X-Restore. */
final class HeaderProtocol implements HypermediaProtocol
{
    public function isFragmentRequest(ServerRequestInterface $request): bool
    {
        return $request->hasHeader('X-Fragment');
    }

    public function isHistoryRestore(ServerRequestInterface $request): bool
    {
        return $request->hasHeader('X-Restore');
    }

    public function fragmentTarget(ServerRequestInterface $request): ?string
    {
        return null;
    }

    public function varyHeaders(): array
    {
        return ['X-Fragment'];
    }

    public function navigate(ResponseInterface $response, ?string $url): ResponseInterface
    {
        return $response;
    }

    public function announceNonce(ResponseInterface $response, string $nonce): ResponseInterface
    {
        return $response;
    }

    public function nonceHeaders(): array
    {
        return [];
    }

    public function pageNonce(ServerRequestInterface $request): ?string
    {
        return null;
    }
}
