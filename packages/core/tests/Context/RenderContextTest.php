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
        $this->assertSame(Purpose::Fragment, RenderContext::fromRequest(new ServerRequest('GET', '/en/--component/action'), $site, $clock)->purpose, 'the default locale written into the URL');
        $this->assertSame(Purpose::Visit, RenderContext::fromRequest(new ServerRequest('GET', '/fr/--component/action'), $site, $clock)->purpose, 'fr is not a locale of the site');
    }

    public function test_internal_refuses_a_path_with_a_query(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('without a query');

        RenderContext::internal(Purpose::Audit, self::site(), new SystemClock(), '/x?a=1');
    }

    public function test_internal_refuses_a_path_with_a_fragment(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        RenderContext::internal(Purpose::Audit, self::site(), new SystemClock(), '/x#top');
    }

    public function test_a_request_context_serializes_and_dumps_without_secrets(): void
    {
        $request = (new ServerRequest('POST', 'https://example.test/a?token=query-secret', [
            'Cookie' => 'sess=cookie-secret',
            'Authorization' => 'Bearer header-secret',
        ]))
            ->withCookieParams(['sess' => 'cookie-secret'])
            ->withAttribute('callback', static fn (): string => 'x')
            ->withAttribute(HypermediaProtocols::class, new HypermediaProtocols());
        $user = new SecretUser('42', 'hash-secret');
        $ctx = RenderContext::fromRequest($request, self::site(), new SystemClock())
            ->as(new Viewer(['web' => $user], 'web'))
            ->with(new VariantFacet('b', true))
            ->with(new UnsafeFacet(static fn (): string => 'y'));

        $serialized = serialize($ctx);
        ob_start();
        var_dump($ctx);
        $dumps = [$serialized, print_r($ctx, true), (string) ob_get_clean(), json_encode($ctx, JSON_THROW_ON_ERROR), json_encode($ctx->viewer, JSON_THROW_ON_ERROR)];

        foreach ($dumps as $i => $dump) {
            foreach (['cookie-secret', 'header-secret', 'hash-secret', 'query-secret', SecretUser::class, 'Closure', 'ServerRequest'] as $secret) {
                $this->assertStringNotContainsString($secret, $dump, "dump {$i} holds {$secret}");
            }
            $this->assertStringContainsString('42', $dump, "dump {$i} keeps the id");
        }
        $this->assertStringContainsString(UnsafeFacet::class, $serialized, 'an omitted facet is named');

        $json = json_decode((string) json_encode($ctx, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(['path' => '/a', 'method' => 'POST', 'localePrefix' => ''], $json['request']);
        $this->assertSame('visit', $json['purpose']);
        $this->assertSame(['identities' => ['web' => '42'], 'primaryGuard' => 'web', 'roleView' => null], $json['viewer']);
        $this->assertSame(['admin' => false, 'uiLocale' => null, 'overrides' => false], $json['surface']);
        $this->assertSame('published', $json['reads']);
        $this->assertSame('Deliver', $json['outbox']);
        $this->assertSame('Allow', $json['writes']);
        $this->assertSame(SystemClock::class, $json['clock']);
        $this->assertSame([UnsafeFacet::class => 'unsafe', VariantFacet::class => 'variant=b'], $json['extensions'], 'facets by memoKey, in class order');
        $this->assertSame(['member' => 'preflow:synthetic:member:gold'], json_decode((string) json_encode(Viewer::synthetic(new RoleView('member', ['gold']))), true)['identities']);
        $this->assertSame(['population' => 'member', 'roles' => ['gold']], json_decode((string) json_encode(Viewer::synthetic(new RoleView('member', ['gold']))), true)['roleView']);

        $copy = unserialize($serialized);
        $this->assertInstanceOf(RenderContext::class, $copy);
        $this->assertNull($copy->request?->request, 'the PSR request is dropped');
        $this->assertSame('/a', $copy->request?->path);
        $this->assertSame('POST', $copy->request?->method);
        $this->assertSame(Purpose::Visit, $copy->purpose);
        $this->assertSame('42', $copy->viewer->primary()?->getAuthId());
        $this->assertNotInstanceOf(SecretUser::class, $copy->viewer->primary());
        $this->assertSame('web', $copy->viewer->primaryGuard);
        $this->assertNull($copy->get(UnsafeFacet::class), 'a facet that cannot be serialized is left out');
        $this->assertSame('b', $copy->get(VariantFacet::class)?->variant);
        $this->assertSame(
            str_replace('|' . UnsafeFacet::class . '=unsafe', '', $ctx->memoKey()),
            $copy->memoKey(),
            'the memo key round-trips but for the omitted facet',
        );
        $this->assertEquals($ctx->now, $copy->now);
        $this->assertFalse($copy->isPublicDefault(), 'still signed in');

        $role = unserialize(serialize(self::visit()->asRole(new RoleView('member', ['gold']))->at(new \DateTimeImmutable('2026-01-01'))));
        $this->assertTrue($role->viewer->isSynthetic());
        $this->assertSame('preflow:synthetic:member:gold', $role->viewer->primary()?->getAuthId());
        $this->assertSame('role:member:gold', $role->viewer->key());
        $this->assertSame(WritePolicy::Skip, $role->writes);
        $this->assertSame(OutboxPolicy::Capture, $role->delivering()->outbox);
        $this->assertFalse($role->isPublicDefault(), 'still frozen');
    }

    public function test_a_facet_key_that_is_not_utf8_still_encodes_to_json(): void
    {
        $json = json_encode(self::visit()->with(new VariantFacet("\xff\xfe", true)));

        $this->assertIsString($json, 'a log line is never lost to a bad facet key');
        $this->assertSame('hex:' . bin2hex("variant=\xff\xfe"), json_decode($json, true)['extensions'][VariantFacet::class]);
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
            // Each with the policies its purpose renders with: a rebuild skips and discards (internal()).
            $as = $purpose === Purpose::Rebuild ? $ctx->skippingWrites()->discarding() : $ctx;
            $this->assertSame(
                in_array($purpose, [Purpose::Visit, Purpose::Rebuild], true),
                $as->because($purpose)->isStorable(),
                $purpose->name,
            );
        }
        $this->assertFalse($ctx->workingCopies()->isStorable(), 'a visit that is not the public default');
    }

    public function test_storable_only_with_the_purposes_own_write_and_outbox_policies(): void
    {
        $visit = self::visit();
        $this->assertTrue($visit->isStorable());
        $this->assertFalse($visit->capturing()->isStorable(), 'a visit that captures what it would send');
        $this->assertFalse($visit->discarding()->isStorable(), 'a visit that discards what it would send');
        $this->assertFalse($visit->skippingWrites()->isStorable(), 'a visit that skips its writes');
        $this->assertFalse($visit->refusingWrites()->isStorable(), 'a visit that refuses its writes');

        $rebuild = RenderContext::internal(Purpose::Rebuild, self::site(), new SystemClock(), '/a');
        $this->assertTrue($rebuild->isStorable(), 'a rebuild as internal() builds it');
        $this->assertFalse($rebuild->capturing()->isStorable());
        $this->assertFalse($rebuild->delivering()->isStorable(), 'a rebuild never sends');
    }

    /** memoKey() is computed once per context (MemoKeys) and stays what a fresh computation gives. */
    public function test_memo_key_is_kept_per_context_and_equals_a_fresh_computation(): void
    {
        $base = self::visit()->with(new VariantFacet('b', true));
        $first = $base->memoKey();
        $this->assertSame($first, $base->memoKey());
        $this->assertSame($base->computeMemoKey(), $first);
        $de = $base->in('de');
        $this->assertNotSame($first, $de->memoKey(), 'a derivation gets its own key');
        $this->assertSame($de->computeMemoKey(), $de->memoKey());
    }

    /** A derivation changes only the field it names; a role view stays at Skip and Capture. */
    public function test_a_derivation_keeps_every_other_field(): void
    {
        $base = self::visit()->with(new VariantFacet('b', true))->at(new \DateTimeImmutable('2026-02-03T04:05:06Z'))->workingCopies();
        $de = $base->in('de');
        foreach (['request', 'purpose', 'viewer', 'surface', 'reads', 'site', 'clock', 'now', 'outbox', 'writes', 'extensions'] as $field) {
            $this->assertSame($base->{$field}, $de->{$field}, $field);
        }
        $this->assertTrue($de->isFrozen());
        $this->assertSame('de', $de->locale);
        $this->assertNull($base->forRecipient('de')->request, 'a named null is kept');

        $role = $base->asRole(new RoleView('member', ['gold']));
        $this->assertSame(WritePolicy::Skip, $role->writes);
        $this->assertSame(OutboxPolicy::Capture, $role->outbox);
        $this->assertSame(WritePolicy::Skip, $role->delivering()->in('fr')->writes);
        $this->assertSame(OutboxPolicy::Capture, $role->delivering()->outbox);
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

    public function test_is_frozen_only_after_at(): void
    {
        $at = new \DateTimeImmutable('2026-01-01T00:00:00Z');

        $this->assertFalse(self::visit()->isFrozen());
        $this->assertFalse(
            RenderContext::fromRequest(new ServerRequest('GET', '/a'), self::site(), new FrozenClock($at))->isFrozen(),
            'a frozen clock the app was built with is not a render frozen with at()',
        );
        $this->assertTrue(self::visit()->at($at)->isFrozen());
        $this->assertTrue(self::visit()->at($at)->in('de')->because(Purpose::Audit)->isFrozen(), 'kept by later withers');
        $this->assertTrue(unserialize(serialize(self::visit()->at($at)))->isFrozen(), 'kept through serialize()');
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

    /** fromRequest() builds what a fresh visit for() the request gives: its request part, the empty locale prefix. */
    public function test_from_request_carries_the_request_and_no_locale_prefix(): void
    {
        $request = new ServerRequest('PUT', '/de/x');
        $ctx = RenderContext::fromRequest($request, self::site(), new SystemClock());

        $this->assertSame($request, $ctx->request?->request);
        $this->assertSame('/de/x', $ctx->request?->path);
        $this->assertSame('PUT', $ctx->request?->method);
        $this->assertSame('', $ctx->request?->localePrefix);
        $this->assertSame('/', RenderContext::fromRequest(new ServerRequest('GET', ''), self::site(), new SystemClock())->request?->path);
        $this->assertTrue($ctx->isPublicDefault());
        $this->assertSame(Purpose::Visit, $ctx->purpose);
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

    /** without() removes only the facet of that class; nothing else of the context changes, and it can never loosen it. */
    public function test_without_removes_only_that_facet(): void
    {
        $plain = self::visit()->with(new VariantFacet('a', true));
        $this->assertSame($plain, $plain->without(UnsafeFacet::class), 'without the facet: the same instance');

        $ctx = self::visit()->at(new \DateTimeImmutable('2026-01-01T00:00:00Z'))->asRole(new RoleView('member', ['gold']))
            ->refusingWrites()->capturing()->workingCopies()
            ->with(new VariantFacet('a', true))->with(new VariantFacet2('x', false));
        $without = $ctx->without(VariantFacet2::class);

        $this->assertNotSame($ctx, $without);
        $this->assertNotNull($ctx->get(VariantFacet2::class), 'the original keeps it');
        $this->assertNull($without->get(VariantFacet2::class));
        $this->assertSame('a', $without->get(VariantFacet::class)?->variant, 'other facets are kept');
        $this->assertEquals($ctx->viewer, $without->viewer);
        $this->assertSame([WritePolicy::Refuse, OutboxPolicy::Capture], [$without->writes, $without->outbox]);
        $this->assertSame([$ctx->clock, $ctx->now, true], [$without->clock, $without->now, $without->isFrozen()]);
        $this->assertSame([$ctx->locale, $ctx->reads->key(), $ctx->purpose], [$without->locale, $without->reads->key(), $without->purpose]);

        // isPublicDefault() follows the facets that are left.
        $visitor = self::visit()->with(new VariantFacet('a', true))->with(new VariantFacet2('x', false));
        $this->assertFalse($visitor->isPublicDefault());
        $this->assertTrue($visitor->without(VariantFacet2::class)->isPublicDefault());
        $this->assertFalse($visitor->without(VariantFacet::class)->isPublicDefault());
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

/** A user record with something that must never be logged. */
final readonly class SecretUser implements Identity
{
    public function __construct(private string $id, public string $passwordHash) {}

    public function getAuthId(): string
    {
        return $this->id;
    }
}

/** A facet holding something that cannot be serialized. */
final readonly class UnsafeFacet implements Facet
{
    public function __construct(public ?\Closure $fn) {}

    public function isPublicDefault(): bool
    {
        return true;
    }

    public function memoKey(): string
    {
        return 'unsafe';
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

/** A second facet class, to remove one of two. */
final readonly class VariantFacet2 implements Facet
{
    public function __construct(public string $variant, private bool $publicDefault) {}

    public function isPublicDefault(): bool
    {
        return $this->publicDefault;
    }

    public function memoKey(): string
    {
        return 'variant2=' . $this->variant;
    }
}
