<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\Context\Preview;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Context\Contexts;
use Preflow\Core\Context\IdentityId;
use Preflow\Core\Context\OutboxPolicy;
use Preflow\Core\Context\Preview\DenyAllPreviewGate;
use Preflow\Core\Context\Preview\PreviewClaims;
use Preflow\Core\Context\Preview\PreviewGate;
use Preflow\Core\Context\Preview\PreviewGrant;
use Preflow\Core\Context\Preview\PreviewToken;
use Preflow\Core\Context\Preview\PreviewTokenRejected;
use Preflow\Core\Context\Purpose;
use Preflow\Core\Context\RenderContext;
use Preflow\Core\Context\RoleView;
use Preflow\Core\Context\Viewer;
use Preflow\Core\Context\WritePolicy;
use Preflow\Core\Http\Session\SessionInterface;
use Preflow\Core\Identity;
use Preflow\Testing\ArraySession;
use Preflow\Testing\ContextFactory;

/**
 * The signed preview token: a reading mode (working copies and/or a role view) granted to the
 * follow-up requests of one staff member's preview. Each threat of the threat model has its test
 * here (T1-T7, T11; T8-T10 and T13 are the middleware's), named after it.
 */
final class PreviewTokenTest extends TestCase
{
    private const KEY = 'preview-token-test-key-0123456789abcdef';

    private const SESSION = 'session-id-of-the-editor-0001';

    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_';

    protected function setUp(): void
    {
        Contexts::reset();
    }

    protected function tearDown(): void
    {
        Contexts::reset();
    }

    public function test_a_valid_token_round_trips_its_claims(): void
    {
        $role = new RoleView('member', ['gold', 'basic']);
        $token = $this->issue($this->previewContext()->in('de')->asRole($role), requires: ['page.edit', 'site.view_as']);

        $claims = $this->tokens()->verify($token, self::SESSION, $this->viewer(), $this->now());

        $this->assertEquals($role, $claims->role);
        $this->assertSame('de', $claims->locale);
        $this->assertTrue($claims->workingCopies);
        $this->assertSame('editor-1', $claims->issuer);
        $this->assertSame(['page.edit', 'site.view_as'], $claims->requires);
        $this->assertSame($this->now()->getTimestamp() + PreviewToken::TTL, $claims->expires);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $claims->jti);

        // A visitor's view of the published state.
        $plain = $this->tokens()->verify($this->issue($this->previewContext()->published()), self::SESSION, $this->viewer(), $this->now());
        $this->assertNull($plain->role);
        $this->assertFalse($plain->workingCopies);
        $this->assertNotSame($claims->jti, $plain->jti, 'each token has its own id');
    }

    /** T1: a signature not made with the key derived from the app key is rejected. */
    public function test_a_forged_signature_is_rejected(): void
    {
        $token = $this->issue($this->previewContext());
        [$v, $payload] = explode('.', $token);

        // Signed with the app key itself (not the derived key), and with another app's key.
        $forgeries = [
            $this->sign($payload, self::SESSION, self::KEY),
            $this->sign($payload, self::SESSION, self::derive('another-app-key-0123456789abcdef')),
            "{$v}.{$payload}." . str_repeat('A', 43) . '.' . str_repeat('A', 43),
            // A component token's key (another purpose) never signs a preview token.
            $this->sign($payload, self::SESSION, hash_hmac('sha256', 'preflow/component-token/v1', self::KEY, true)),
        ];
        foreach ($forgeries as $i => $forged) {
            $this->assertRejected('signature', fn () => $this->tokens()->verify($forged, self::SESSION, $this->viewer(), $this->now()), "forgery {$i}");
        }

        // The test's own signer is the format: with the derived key, the same payload passes.
        $this->assertSame('editor-1', $this->tokens()->verify($this->sign($payload, self::SESSION, self::derive(self::KEY)), self::SESSION, $this->viewer(), $this->now())->issuer);
    }

    /**
     * T2: the MAC covers the whole payload: a changed claim (signatures kept) fails.
     *
     * @param \Closure(array<string, mixed>): array<string, mixed> $tamper
     */
    #[DataProvider('tamperedClaims')]
    public function test_each_tampered_claim_is_rejected(\Closure $tamper): void
    {
        $token = $this->issue($this->previewContext()->asRole(new RoleView('staff', ['editor'])), requires: ['page.edit', 'site.view_as']);
        [$v, $payload, $sig, $bind] = explode('.', $token);
        $claims = json_decode(self::unb64($payload), true, 8, JSON_THROW_ON_ERROR);

        $tampered = $v . '.' . self::b64((string) json_encode($tamper($claims), JSON_UNESCAPED_SLASHES)) . '.' . $sig . '.' . $bind;

        $this->assertRejected('signature', fn () => $this->tokens()->verify($tampered, self::SESSION, $this->viewer(), $this->now()));
    }

    /** @return iterable<string, array{\Closure(array<string, mixed>): array<string, mixed>}> */
    public static function tamperedClaims(): iterable
    {
        yield 'r: role to admin' => [static fn (array $c): array => ['r' => ['staff', ['admin']]] + $c];
        yield 'r: role dropped' => [static fn (array $c): array => ['r' => null] + $c];
        yield 'l: locale' => [static fn (array $c): array => ['l' => 'fr'] + $c];
        yield 'w: reads' => [static fn (array $c): array => ['w' => false] + $c];
        yield 'i: issuer' => [static fn (array $c): array => ['i' => ['staff', 'admin-1']] + $c];
        yield 'i: guard' => [static fn (array $c): array => ['i' => ['web', $c['i'][1]]] + $c];
        yield 'q: requires' => [static fn (array $c): array => ['q' => ['page.view']] + $c];
        yield 'e: expiry' => [static fn (array $c): array => ['e' => $c['e'] + 86400] + $c];
        yield 'j: id' => [static fn (array $c): array => ['j' => str_repeat('0', 32)] + $c];
    }

    /** T3 (Review Focus 3): a link pasted into another browser has another session: 'session'. */
    public function test_a_token_from_another_session_is_rejected(): void
    {
        $token = $this->issue($this->previewContext());

        $this->assertRejected('session', fn () => $this->tokens()->verify($token, 'session-id-of-another-browser', $this->viewer(), $this->now()));
        $this->assertRejected('session', fn () => $this->tokens()->verify($token, '', $this->viewer(), $this->now()), 'no session at all');
    }

    /** T4 (Review Focus 3): sign-out and sign-in regenerate the session id, so the token dies with it. */
    public function test_a_token_after_session_regeneration_is_rejected(): void
    {
        $session = new ArraySession();
        $session->start();
        $token = Contexts::run(ContextFactory::forTest(), fn (): string => $this->tokens()->issue($this->previewContext(), $session->getId(), 'staff', $this->editor(), ['page.edit']));
        $this->assertSame('editor-1', $this->tokens()->verify($token, $session->getId(), $this->viewer(), $this->now())->issuer, 'valid in the session it was issued in');

        $session->regenerate(); // what signing out and in again does

        $this->assertRejected('session', fn () => $this->tokens()->verify($token, $session->getId(), $this->viewer(), $this->now()));
    }

    /** T5: exp comes from the context clock; one second after it, the token is dead. */
    public function test_an_expired_token_is_rejected(): void
    {
        $token = $this->issue($this->previewContext(), ttl: 60);
        $exp = $this->now()->getTimestamp() + 60;

        $this->assertSame($exp, $this->tokens()->verify($token, self::SESSION, $this->viewer(), $this->now()->setTimestamp($exp - 1))->expires);
        $this->assertRejected('expired', fn () => $this->tokens()->verify($token, self::SESSION, $this->viewer(), $this->now()->setTimestamp($exp + 1)));
        $this->assertRejected('expired', fn () => $this->tokens()->verify($token, self::SESSION, $this->viewer(), $this->now()->setTimestamp($exp)), 'not at exp itself either');
    }

    /** T5: a TTL is clamped to MAX_TTL; none below one second is issued. */
    public function test_ttl_is_clamped(): void
    {
        $claims = $this->tokens()->verify($this->issue($this->previewContext(), ttl: 86400), self::SESSION, $this->viewer(), $this->now());
        $this->assertSame(PreviewToken::MAX_TTL, $claims->expires - $this->now()->getTimestamp());
        $this->assertSame(3600, PreviewToken::MAX_TTL);
        $this->assertSame(900, PreviewToken::TTL);

        // A token claiming more than MAX_TTL (a clock set back, a key shared with a lax issuer) is not taken.
        $token = $this->issue($this->previewContext());
        $this->assertRejected('expired', fn () => $this->tokens()->verify($token, self::SESSION, $this->viewer(), $this->now()->modify('-1 hour')));

        $this->expectException(\InvalidArgumentException::class);
        $this->issue($this->previewContext(), ttl: 0);
    }

    /** T6: another person signed in on the same device (same session id) cannot use it, nor can nobody. */
    public function test_a_token_for_another_identity_is_rejected(): void
    {
        $token = $this->issue($this->previewContext());

        $viewer = static fn (array $identities, ?string $primary = null): Viewer => new Viewer($identities, $primary);
        $this->assertRejected('identity', fn () => $this->tokens()->verify($token, self::SESSION, $viewer(['staff' => new IdentityId('member-7')]), $this->now()), 'another staff member');
        $this->assertRejected('identity', fn () => $this->tokens()->verify($token, self::SESSION, $viewer(['web' => new IdentityId('editor-1')]), $this->now()), 'the same id under another guard');
        $this->assertRejected('identity', fn () => $this->tokens()->verify($token, self::SESSION, Viewer::anonymous(), $this->now()), 'signed out');
        $this->assertRejected('identity', fn () => $this->tokens()->verify($token, self::SESSION, Viewer::synthetic(new RoleView('staff', ['editor'])), $this->now()), 'a role view is nobody');

        // M7: a member signed in beside the issuing staff member (the member's guard first) does not stop it.
        $both = $viewer(['web' => new IdentityId('member-7'), 'staff' => new IdentityId('editor-1')], 'web');
        $claims = $this->tokens()->verify($token, self::SESSION, $both, $this->now());
        $this->assertSame(['editor-1', 'staff'], [$claims->issuer, $claims->issuerGuard]);
    }

    /** T11: size cap, fixed prefix, strict base64url, strict JSON with exact keys and types. */
    public function test_oversized_and_malformed_tokens_are_rejected(): void
    {
        $valid = $this->issue($this->previewContext());
        $verify = fn (string $token) => fn () => $this->tokens()->verify($token, self::SESSION, $this->viewer(), $this->now());

        $this->assertRejected('malformed', $verify(str_pad($valid, 2049, 'A')), '2049 bytes');
        $this->assertRejected('malformed', $verify('v2.x.y'), 'another version');
        $this->assertRejected('malformed', $verify('v2' . substr($valid, 2)), 'another version, valid shape');
        $this->assertRejected('malformed', $verify(''), 'empty');
        $this->assertRejected('malformed', $verify('v1.!!!.' . str_repeat('A', 43) . '.' . str_repeat('A', 43)), 'bad base64');
        $this->assertRejected('malformed', $verify(substr($valid, 0, -1)), 'a short MAC');
        $this->assertRejected('malformed', $verify($valid . '.x'), 'an extra part');
        $this->assertRejected('malformed', $verify(" {$valid}"), 'whitespace');

        // Payloads that pass the signature (signed here with the derived key) but break the schema.
        $good = ['r' => null, 'l' => 'en', 'w' => true, 'i' => ['staff', 'editor-1'], 'q' => ['page.edit'], 'e' => $this->now()->getTimestamp() + 60, 'j' => str_repeat('a', 32)];
        $this->assertSame('editor-1', $this->tokens()->verify($this->signed($good), self::SESSION, $this->viewer(), $this->now())->issuer, 'the schema itself passes');
        $bad = [
            'extra key' => $good + ['x' => 1],
            'missing key' => array_diff_key($good, ['j' => true]),
            'a list, not an object' => array_values($good),
            'r: a string' => ['r' => 'staff:admin'] + $good,
            'r: three parts' => ['r' => ['staff', ['editor'], 'x']] + $good,
            'r: roles not a list' => ['r' => ['staff', 'editor']] + $good,
            'r: a role with a comma' => ['r' => ['staff', ['a,b']]] + $good,
            'r: unsorted roles' => ['r' => ['staff', ['b', 'a']]] + $good,
            'l: an int' => ['l' => 1] + $good,
            'l: empty' => ['l' => ''] + $good,
            'l: not a locale' => ['l' => 'en"><script>'] + $good,
            'w: a string' => ['w' => 'true'] + $good,
            'w: an int' => ['w' => 1] + $good,
            'i: a bare id' => ['i' => 'editor-1'] + $good,
            'i: an int' => ['i' => ['staff', 7]] + $good,
            'i: empty id' => ['i' => ['staff', '']] + $good,
            'i: empty guard' => ['i' => ['', 'editor-1']] + $good,
            'i: three parts' => ['i' => ['staff', 'editor-1', 'x']] + $good,
            'i: synthetic' => ['i' => ['staff', 'preflow:synthetic:staff:admin']] + $good,
            'r: a role view without view-as' => ['r' => ['member', ['gold']]] + $good,
            'q: empty' => ['q' => []] + $good,
            'q: a string' => ['q' => 'page.edit'] + $good,
            'q: a number in it' => ['q' => [7]] + $good,
            'e: a string' => ['e' => (string) $good['e']] + $good,
            'e: a float' => ['e' => $good['e'] + 0.5] + $good,
            'j: short' => ['j' => 'abc'] + $good,
            'j: not hex' => ['j' => str_repeat('z', 32)] + $good,
        ];
        foreach ($bad as $case => $claims) {
            $this->assertRejected('malformed', $verify($this->signed($claims)), $case);
        }
        // M5: a MAC in the other's place, a bind from another token of the same session, a payload
        // whose base64url is valid but not canonical (non-zero trailing bits), signed correctly.
        [, $payload, $sig, $bind] = explode('.', $valid);
        $this->assertRejected('signature', $verify("v1.{$payload}.{$bind}.{$sig}"), 'swapped MACs');
        [, , , $otherBind] = explode('.', $this->issue($this->previewContext()));
        $this->assertRejected('session', $verify("v1.{$payload}.{$sig}.{$otherBind}"), 'a bind of another token, same session');
        [, $otherPayload, $otherSig] = explode('.', $this->issue($this->previewContext()));
        $this->assertRejected('signature', $verify("v1.{$payload}.{$otherSig}.{$bind}"), 'a sig of another token');
        $canonical = self::b64((string) json_encode($good, JSON_UNESCAPED_SLASHES));
        $this->assertNotSame(0, strlen($canonical) % 4, 'the payload has trailing bits to set');
        $last = strpos(self::ALPHABET, $canonical[-1]);
        $loose = substr($canonical, 0, -1) . self::ALPHABET[$last + 1];
        $this->assertSame(self::unb64($canonical), self::unb64($loose), 'both decode to the same bytes');
        $this->assertRejected('malformed', $verify($this->sign($loose, self::SESSION, self::derive(self::KEY))), 'non-canonical base64url');

        foreach (['not json' => 'nope', 'a deep nest' => str_repeat('[', 64) . str_repeat(']', 64), 'json null' => 'null', 'json number' => '7'] as $case => $json) {
            $payload = self::b64($json);
            $this->assertRejected('malformed', $verify($this->sign($payload, self::SESSION, self::derive(self::KEY))), $case);
        }
    }

    /** I1: issue() and verify() share one limit: every token issue() returns verifies; past it, issue() refuses. */
    public function test_issue_never_mints_a_token_verify_rejects(): void
    {
        $issued = 0;
        for ($n = 1; $n <= 400; $n++) {
            $roles = array_map(static fn (int $i): string => sprintf('role-%03d', $i), range(1, $n));
            try {
                $token = $this->issue($this->previewContext()->asRole(new RoleView('staff', $roles)), requires: ['page.edit', 'site.view_as']);
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString((string) PreviewToken::MAX_LENGTH, $e->getMessage());
                break;
            }
            $this->assertLessThanOrEqual(PreviewToken::MAX_LENGTH, strlen($token));
            $this->assertCount($n, $this->tokens()->verify($token, self::SESSION, $this->viewer(), $this->now())->role?->roles ?? [], "{$n} roles");
            $issued = $n;
        }
        $this->assertGreaterThan(119, $issued, 'the 119-role token of the review is issued and verifies');
        $this->assertLessThan(400, $issued, 'issue() refuses before 400 roles');
    }

    /** T1: no tokens with an empty or a public app key; each use is refused as 'disabled'. */
    public function test_tokens_are_disabled_without_a_real_app_key(): void
    {
        $valid = $this->issue($this->previewContext());
        $weak = ['', ' ', 'a', 'short', str_repeat('k', 31), 'preflow-default-key-change-me!!', "preflow-default-key-change-me!!\n",
            'change-this-to-a-random-32-char-string!', "  change-this-to-a-random-32-char-string!\t"];
        foreach ($weak as $key) {
            $tokens = new PreviewToken($key);
            $this->assertFalse($tokens->enabled());
            $this->assertRejected('disabled', fn () => $tokens->verify($valid, self::SESSION, $this->viewer(), $this->now()));
            // Not even a token a holder of that public key made.
            $mine = $this->sign(explode('.', $valid)[1], self::SESSION, self::derive($key));
            $this->assertRejected('disabled', fn () => $tokens->verify($mine, self::SESSION, $this->viewer(), $this->now()));
            try {
                Contexts::run(ContextFactory::forTest(), fn (): string => $tokens->issue($this->previewContext(), self::SESSION, 'staff', $this->editor(), ['page.edit']));
                $this->fail('issued with a public key');
            } catch (\LogicException $e) {
                if (strlen(trim($key)) >= 8) {
                    $this->assertStringNotContainsString(trim($key), $e->getMessage(), 'the key is never in a message');
                }
            }
        }
        $this->assertTrue($this->tokens()->enabled());
        $this->assertTrue((new PreviewToken(str_repeat('k', 32)))->enabled(), '32 bytes sign');
    }

    public function test_claims_apply_builds_a_refusing_capturing_fragment_context(): void
    {
        $role = new RoleView('member', ['gold']);
        $token = $this->issue($this->previewContext()->in('de')->asRole($role), requires: ['page.edit', 'site.view_as']);
        $claims = $this->tokens()->verify($token, self::SESSION, $this->viewer(), $this->now());

        // The follow-up request: the editor's own visit context, signed in, delivering and writing.
        $request = ContextFactory::forTest()->because(Purpose::Visit)->as(new \Preflow\Core\Context\Viewer(['staff' => $this->editor()], 'staff'))->admin('en');
        $ctx = $claims->apply($request);

        $this->assertTrue($ctx->viewer->isSynthetic());
        $this->assertEquals($role, $ctx->viewer->roleView);
        $this->assertSame('de', $ctx->locale);
        $this->assertFalse($ctx->surface->admin, 'the visitor\'s texts');
        $this->assertTrue($ctx->reads->readsWorkingCopies());
        $this->assertSame(Purpose::Fragment, $ctx->purpose);
        $this->assertSame(WritePolicy::Refuse, $ctx->writes);
        $this->assertSame(OutboxPolicy::Capture, $ctx->outbox);
        $this->assertSame($token, $ctx->get(PreviewGrant::class)?->token(), 'the grant re-emits the same token');
        $this->assertFalse($ctx->isPublicDefault());
        $this->assertFalse($ctx->isStorable());

        // Without a role: an anonymous visitor; published reads when the token says so.
        $plain = $this->tokens()->verify($this->issue($this->previewContext()->published()), self::SESSION, $this->viewer(), $this->now())->apply($request);
        $this->assertTrue($plain->viewer->isAnonymous());
        $this->assertFalse($plain->reads->readsWorkingCopies());
        $this->assertSame('en', $plain->locale);
        $this->assertSame(WritePolicy::Refuse, $plain->writes);
        $this->assertSame(OutboxPolicy::Capture, $plain->outbox);
    }

    /** The token, the claims and a granted context never show the session id, the key or (when dumped) the token. */
    public function test_the_token_never_contains_the_session_id_or_key(): void
    {
        $tokens = $this->tokens();
        $token = $this->issue($this->previewContext()->asRole(new RoleView('staff', ['editor'])), requires: ['page.edit', 'site.view_as']);
        $decoded = implode('|', array_map(static fn (string $part): string => self::unb64($part), array_slice(explode('.', $token), 1)));
        foreach ([self::SESSION, hash('sha256', self::SESSION), self::KEY, self::derive(self::KEY), bin2hex(self::derive(self::KEY))] as $secret) {
            $this->assertStringNotContainsString($secret, $token);
            $this->assertStringNotContainsString($secret, $decoded);
        }

        // The signer itself: no key in a dump, never serialized.
        $derived = self::derive(self::KEY);
        foreach (['print_r' => print_r($tokens, true), 'var_dump' => self::dump($tokens), 'var_export' => var_export($tokens, true),
            '(array)' => print_r((array) $tokens, true), '(array) dumped' => self::dump((array) $tokens), 'get_object_vars' => self::dump(\Closure::bind(static fn (PreviewToken $t): array => get_object_vars($t), null, PreviewToken::class)($tokens))] as $how => $shown) {
            $this->assertStringNotContainsString(self::KEY, $shown, $how);
            $this->assertStringNotContainsString($derived, $shown, $how);
            $this->assertStringNotContainsString(bin2hex($derived), bin2hex($shown), $how);
        }
        try {
            serialize($tokens);
            $this->fail('a signer was serialized');
        } catch (\LogicException) {
        }

        // A rejection names its reason, never the token.
        try {
            $tokens->verify($token, 'another-session', $this->viewer(), $this->now());
            $this->fail('accepted from another session');
        } catch (PreviewTokenRejected $e) {
            $this->assertStringNotContainsString(explode('.', $token)[1], $e->getMessage());
            $this->assertStringNotContainsString('another-session', $e->getMessage());
        }

        // The claims and a granted context keep the token for re-emitting it, but never show it.
        $claims = $tokens->verify($token, self::SESSION, $this->viewer(), $this->now());
        $ctx = $claims->apply(ContextFactory::forTest());
        $mac = explode('.', $token)[2];
        foreach (['claims dump' => print_r($claims, true), 'context dump' => print_r($ctx, true), 'context json' => (string) json_encode($ctx),
            'context serialized' => serialize($ctx), 'grant serialized' => serialize($ctx->get(PreviewGrant::class)), 'claims serialized' => serialize($claims)] as $what => $shown) {
            $this->assertStringNotContainsString($mac, $shown, $what);
        }
        $this->assertStringContainsString('preview', (string) json_encode($ctx), 'the grant shows as its memo key');
        /** @var RenderContext $restored */
        $restored = unserialize(serialize($ctx));
        $this->assertNull($restored->get(PreviewGrant::class)?->token(), 'a restored grant carries no token');
        $this->assertNull(unserialize(serialize($claims))->apply(ContextFactory::forTest())->get(PreviewGrant::class)?->token(), 'restored claims re-emit nothing');
    }

    /** PreviewGrant is a facet that is never the public default. */
    public function test_a_grant_is_a_facet_that_is_never_the_public_default(): void
    {
        $grant = new PreviewGrant('v1.x.y.z');
        $this->assertFalse($grant->isPublicDefault());
        $this->assertSame('preview', $grant->memoKey());
        $this->assertSame('v1.x.y.z', $grant->token());
        $this->assertFalse(ContextFactory::forTest()->with($grant)->isPublicDefault());
    }

    /** grant(): the issuer and session come from the staff request; the context gets the grant. */
    public function test_grant_issues_from_the_staff_request(): void
    {
        $session = new ArraySession();
        $session->start();
        $staff = ContextFactory::forTest()->as(new \Preflow\Core\Context\Viewer(['staff' => $this->editor()], 'staff'))->admin('en');
        $request = (new ServerRequest('GET', 'http://localhost/admin/preview'))
            ->withAttribute(SessionInterface::class, $session)
            ->withAttribute(RenderContext::class, $staff);

        $granted = Contexts::run($staff, fn (): RenderContext => $this->tokens()->grant($this->previewContext()->in('de'), $request, ['page.edit']));

        $token = $granted->get(PreviewGrant::class)?->token();
        $this->assertIsString($token);
        $claims = $this->tokens()->verify($token, $session->getId(), $this->viewer(), $this->now());
        $this->assertSame('editor-1', $claims->issuer);
        $this->assertSame('de', $claims->locale);
        $this->assertSame(['page.edit'], $claims->requires);

        // No session, nobody signed in, or a role view: no token.
        $cases = [
            'no session' => $request->withoutAttribute(SessionInterface::class),
            'nobody' => $request->withAttribute(RenderContext::class, ContextFactory::forTest()),
            'a role view' => $request->withAttribute(RenderContext::class, ContextFactory::forTest()->asRole(new RoleView('staff', ['editor']))),
        ];
        foreach ($cases as $case => $refused) {
            try {
                Contexts::run(ContextFactory::forTest(), fn (): RenderContext => $this->tokens()->grant($this->previewContext(), $refused, ['page.edit']));
                $this->fail("granted with {$case}");
            } catch (\LogicException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /** issue() refuses what a token cannot carry: no requirement, a real viewer, a read as of a date. */
    public function test_issue_refuses_what_a_token_cannot_carry(): void
    {
        $cases = [
            'no requirement' => fn (): string => $this->issue($this->previewContext(), requires: []),
            'a real viewer' => fn (): string => $this->issue($this->previewContext()->as(new \Preflow\Core\Context\Viewer(['staff' => $this->editor()], 'staff'))),
            'reads as of a date' => fn (): string => $this->issue($this->previewContext()->asOf(new \DateTimeImmutable('2025-01-01'))),
            'a frozen time' => fn (): string => $this->issue($this->previewContext()->at(new \DateTimeImmutable('2025-01-01'))),
            'a role view without view-as' => fn (): string => $this->issue($this->previewContext()->asRole(new RoleView('member', ['gold'])), requires: ['page.edit']),
            'no guard' => fn (): string => Contexts::run(ContextFactory::forTest(), fn (): string => $this->tokens()->issue($this->previewContext(), self::SESSION, '', $this->editor(), ['page.edit'])),
            'a synthetic issuer' => fn (): string => Contexts::run(ContextFactory::forTest(), fn (): string => $this->tokens()->issue($this->previewContext(), self::SESSION, 'staff', (new RoleView('staff', ['a']))->identity(), ['page.edit'])),
            'no session' => fn (): string => Contexts::run(ContextFactory::forTest(), fn (): string => $this->tokens()->issue($this->previewContext(), '', 'staff', $this->editor(), ['page.edit'])),
        ];
        foreach ($cases as $case => $issue) {
            try {
                $issue();
                $this->fail("issued with {$case}");
            } catch (\InvalidArgumentException|\LogicException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /**
     * T7 and the role view, at every use (admit()): the gate is asked for each required permission
     * and, for a role view, whether the person may still view as it; the default gate denies all.
     */
    public function test_admit_asks_the_gate_for_permissions_and_the_role_view_each_time(): void
    {
        $role = new RoleView('staff', ['editor']);
        $token = $this->issue($this->previewContext()->asRole($role), requires: ['page.edit', 'site.view_as']);
        $current = ContextFactory::forTest()->as(new \Preflow\Core\Context\Viewer(['staff' => $this->editor()], 'staff'));
        $gate = new class implements PreviewGate {
            public bool $permissions = true;
            public bool $viewAs = true;
            /** @var list<string> */
            public array $asked = [];

            public function allows(RenderContext $ctx, array $permissions): bool
            {
                $this->asked[] = 'allows:' . implode(',', $permissions) . '@' . $ctx->viewer->key();

                return $this->permissions;
            }

            public function mayViewAs(RenderContext $ctx, RoleView $view): bool
            {
                $this->asked[] = 'viewAs:' . implode(',', $view->roles) . '@' . $ctx->viewer->key();

                return $this->viewAs;
            }
        };
        $admit = fn (PreviewGate $gate): PreviewClaims => Contexts::run($current, fn (): PreviewClaims => $this->tokens()->admit($token, self::SESSION, Contexts::current(), $gate));

        $this->assertEquals($role, $admit($gate)->role);
        $this->assertSame(['allows:page.edit,site.view_as@id:staff:editor-1', 'viewAs:editor@id:staff:editor-1'], $gate->asked);

        $gate->viewAs = false; // the editor's own roles shrank below the role viewed as
        $this->assertRejected('permission', fn () => $admit($gate));
        $gate->viewAs = true;
        $gate->permissions = false; // a permission revoked
        $this->assertRejected('permission', fn () => $admit($gate));
        $this->assertRejected('permission', fn () => $admit(new DenyAllPreviewGate()), 'the default gate grants nothing');

        // admit() verifies first: a token of another person never reaches the gate.
        $gate->permissions = true;
        $gate->asked = [];
        $other = ContextFactory::forTest()->as(new \Preflow\Core\Context\Viewer(['staff' => new IdentityId('editor-2')], 'staff'));
        $this->assertRejected('identity', fn () => Contexts::run($other, fn (): PreviewClaims => $this->tokens()->admit($token, self::SESSION, Contexts::current(), $gate)));
        $this->assertSame([], $gate->asked);

        // M7: a member signed in beside the staff issuer (member first) is admitted, and the gate is
        // asked as the issuer: the same identities with the issuer's guard as the primary one.
        $gate->asked = [];
        $both = ContextFactory::forTest()->as(new Viewer(['web' => new IdentityId('member-7'), 'staff' => $this->editor()], 'web'));
        $this->assertEquals($role, Contexts::run($both, fn (): PreviewClaims => $this->tokens()->admit($token, self::SESSION, Contexts::current(), $gate))->role);
        $this->assertSame(['allows:page.edit,site.view_as@id:staff:editor-1;id:web:member-7@staff', 'viewAs:editor@id:staff:editor-1;id:web:member-7@staff'], $gate->asked);

        // And its clock is the render's: past the expiry, the token is dead.
        $late = $current->withClock(new \Preflow\Core\Context\FrozenClock($this->now()->modify('+901 seconds')));
        $this->assertRejected('expired', fn () => Contexts::run($late, fn (): PreviewClaims => $this->tokens()->admit($token, self::SESSION, Contexts::current(), $gate)));
    }

    public function test_a_rejection_has_a_known_reason(): void
    {
        foreach (PreviewTokenRejected::REASONS as $reason) {
            $this->assertSame($reason, (new PreviewTokenRejected($reason))->reason);
        }
        $this->assertSame(['malformed', 'signature', 'expired', 'session', 'identity', 'permission', 'disabled'], PreviewTokenRejected::REASONS);
        $this->expectException(\InvalidArgumentException::class);
        new PreviewTokenRejected('because');
    }

    // --- helpers -------------------------------------------------------------------------------

    private function tokens(): PreviewToken
    {
        return new PreviewToken(self::KEY);
    }

    private function editor(): Identity
    {
        return new IdentityId('editor-1');
    }

    /** The editor signed in with the staff guard. */
    private function viewer(): Viewer
    {
        return new Viewer(['staff' => $this->editor()], 'staff');
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(ContextFactory::NOW);
    }

    /** What a preview renders as: an anonymous visitor reading working copies, in the site's default locale. */
    private function previewContext(): RenderContext
    {
        return ContextFactory::forTest()->asVisitor()->public()->workingCopies()->capturing()->skippingWrites()->because(Purpose::Preview);
    }

    /** @param list<string> $requires */
    private function issue(RenderContext $ctx, array $requires = ['page.edit'], int $ttl = PreviewToken::TTL): string
    {
        return Contexts::run(ContextFactory::forTest(), fn (): string => $this->tokens()->issue($ctx, self::SESSION, 'staff', $this->editor(), $requires, $ttl));
    }

    private function assertRejected(string $reason, \Closure $verify, string $message = ''): void
    {
        try {
            $verify();
        } catch (PreviewTokenRejected $e) {
            $this->assertSame($reason, $e->reason, $message);

            return;
        }
        $this->fail("accepted, expected '{$reason}'" . ($message === '' ? '' : " ({$message})"));
    }

    /** The documented key derivation: HMAC-SHA256(app key, 'preflow/preview-token/v1'). */
    private static function derive(string $appKey): string
    {
        return hash_hmac('sha256', 'preflow/preview-token/v1', $appKey, true);
    }

    /** @param array<mixed> $claims signed with the derived test key for SESSION */
    private function signed(array $claims): string
    {
        return $this->sign(self::b64((string) json_encode($claims, JSON_UNESCAPED_SLASHES)), self::SESSION, self::derive(self::KEY));
    }

    /** The documented format: v1.{payload}.{base64url(HMAC(key, 'v1.' payload))}.{base64url(HMAC(key, 'v1.' payload '.' sha256(session)))}. */
    private function sign(string $payload, string $session, string $key): string
    {
        $signed = 'v1.' . $payload;

        return $signed
            . '.' . self::b64(hash_hmac('sha256', $signed, $key, true))
            . '.' . self::b64(hash_hmac('sha256', $signed . '.' . hash('sha256', $session), $key, true));
    }

    private static function dump(mixed $value): string
    {
        ob_start();
        var_dump($value);

        return (string) ob_get_clean();
    }

    private static function b64(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private static function unb64(string $text): string
    {
        return (string) base64_decode(strtr($text, '-_', '+/'), true);
    }
}
