<?php

declare(strict_types=1);

namespace Preflow\Members\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Data\Locales;
use Preflow\Members\Http\SafeNext;
use Preflow\Members\Links;
use Preflow\Members\MembersConfig;
use Preflow\Members\Tests\Support\Dom;
use Preflow\Members\Tests\Support\MemberFlows;
use Preflow\Members\Tests\Support\MembersScaffold;
use Psr\Http\Message\ResponseInterface;

/**
 * Where to go afterwards ("next"): the member pages by default, the paths of config/members.php
 * next.allow besides; carried through registration and confirmation as through sign-in.
 */
final class NextTest extends TestCase
{
    use MemberFlows;
    use MembersScaffold;

    protected function tearDown(): void
    {
        $this->removeScaffold();
    }

    /** @param array<string, mixed> $config @param list<string> $locales */
    private function safe(array $config = [], array $locales = ['en', 'de'], bool $prefixed = true): SafeNext
    {
        $members = new MembersConfig($config);

        return new SafeNext($members, new Links('https://site.test', $members->path(), new Locales($locales, $locales[0]), $prefixed));
    }

    public function test_by_default_only_member_pages_are_kept(): void
    {
        $safe = $this->safe(['path' => '/konto']);

        $this->assertSame('/konto/x', $safe->of('/konto/x', 'en'));
        $this->assertSame('/konto', $safe->of('/games/catan', 'en'));
    }

    public function test_next_allow_widens_it_to_the_listed_paths(): void
    {
        $safe = $this->safe(['path' => '/konto', 'next' => ['allow' => ['/games/']]]);

        $this->assertSame('/games/catan', $safe->of('/games/catan', 'en'));
        $this->assertSame('/games/catan?seat=2', $safe->of('/games/catan?seat=2', 'en'));
        $this->assertSame('/de/games/catan', $safe->of('/de/games/catan', 'de'), 'in every locale, as the member pages');
        $this->assertSame('/games', $safe->of('/games', 'en'));
        $this->assertSame('/konto/x', $safe->of('/konto/x', 'en'), 'the member pages stay');
        $this->assertSame('/konto', $safe->of('/gamesfoo', 'en'), 'a prefix ends at a path segment');
        $this->assertSame('/konto', $safe->of('/shop/cart', 'en'));
    }

    public function test_a_prefix_without_trailing_slash_ends_at_a_segment_too(): void
    {
        $safe = $this->safe(['next' => ['allow' => ['/shop/checkout']]]);

        $this->assertSame('/shop/checkout/done', $safe->of('/shop/checkout/done', 'en'));
        $this->assertSame('/members', $safe->of('/shop/checkoutx', 'en'));
    }

    /** @return iterable<string, array{0: string}> */
    public static function foreignCases(): iterable
    {
        foreach (['//evil.example', 'https://evil.example', '/\\evil', '%2F%2Fevil', '/games/../admin', '/games/./../admin', '/games/%2e%2e/admin', '/games/%2F%2Fevil', '/games//evil.example', '/games\\evil', '/games/%5cevil', '/games/%252e%252e/admin', 'games/catan', 'javascript:alert(1)', "/games/x\nSet-Cookie: a=1"] as $bad) {
            yield json_encode($bad) => [$bad];
        }
    }

    #[DataProvider('foreignCases')]
    public function test_schemes_double_slashes_backslashes_and_dot_segments_are_dropped(string $next): void
    {
        $this->assertSame('/members', $this->safe(['next' => ['allow' => ['/games/']]])->of($next, 'en'));
    }

    /** @return iterable<string, array{0: mixed}> */
    public static function badAllowCases(): iterable
    {
        foreach (['games/', '//evil.example', 'https://evil.example', '/games\\x', '/games/../admin', 42] as $bad) {
            yield json_encode($bad) => [$bad];
        }
    }

    #[DataProvider('badAllowCases')]
    public function test_an_allow_entry_must_be_a_site_path(mixed $entry): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new MembersConfig(['next' => ['allow' => [$entry]]]))->nextAllow();
    }

    /** smart-stack.works' sign-in cases (tests/Integration/MemberLoginTest::nextCases), on its config. */
    public static function smartStackCases(): iterable
    {
        foreach (['//evil.test', 'https://evil.test', '/\\evil.test', '/tools/../folio', '/toolsfoo', 'javascript:alert(1)', '', "/tools\nSet-Cookie: x=1", '/tools//x', '/tools\\x', '/tools/%2e%2e/folio', '/tools/.%2E/%2e%2e/folio', '/tools/%252e%252e/folio', '/tools?a%00', "/tools?a\x00", '/tools/%2f/x', '/tools/%5cx'] as $bad) {
            yield 'reject ' . json_encode($bad) => [$bad, '/tools'];
        }
        yield 'german fallback' => ['//evil.test', '/de/tools', 'de'];
        foreach (['/tools', '/tools/r/force-map', '/de/tools/r/x?y=1', '/de/tools'] as $good) {
            yield 'keep ' . $good => [$good, $good];
        }
    }

    #[DataProvider('smartStackCases')]
    public function test_smart_stack_works_sign_in_cases_hold(string $next, string $expected, string $locale = 'en'): void
    {
        $safe = $this->safe(['path' => '/tools', 'register' => '/tools']);

        $this->assertSame($expected, $safe->of($next, $locale));
        $this->assertSame($locale === 'de' ? '/de/tools' : '/tools', $safe->of(null, $locale));
    }

    #[RunInSeparateProcess]
    public function test_the_register_page_carries_next_and_without_it_has_no_field(): void
    {
        $this->flowSite();

        $html = $this->html('/members/register?next=' . rawurlencode('/members/r/x'));
        $this->assertSame('/members/r/x', Dom::first($html, '//form[@action="/members/register"]//input[@name="next"]')?->getAttribute('value'));
        $this->assertNull(Dom::first($this->html('/members/register'), '//input[@name="next"]'));
    }

    #[RunInSeparateProcess]
    public function test_next_survives_the_switch_between_login_and_register(): void
    {
        $this->flowSite(['next' => ['allow' => ['/games/']]]);
        $q = '?next=' . rawurlencode('/games/catan?seat=2');

        $login = $this->html('/members/login' . $q);
        $this->assertNotNull(Dom::first($login, '//a[@href="/members/register' . $q . '"]'), 'login links to register with next');
        $register = $this->html('/members/register' . $q);
        $this->assertNotNull(Dom::first($register, '//a[@href="/members/login' . $q . '"]'), 'register links to login with next');
        $this->assertNotNull(Dom::first($this->html('/de/members/login' . $q), '//a[@href="/de/members/register' . $q . '"]'));

        // A next SafeNext drops is not passed on; without one the links stay as they were.
        $foreign = '?next=' . rawurlencode('/shop/cart');
        $this->assertNotNull(Dom::first($this->html('/members/login' . $foreign), '//a[@href="/members/register"]'));
        $this->assertNotNull(Dom::first($this->html('/members/register' . $foreign), '//a[@href="/members/login"]'));
        $this->assertNotNull(Dom::first($this->html('/members/login'), '//a[@href="/members/register"]'));
        $this->assertNotNull(Dom::first($this->html('/members/register'), '//a[@href="/members/login"]'));
    }

    #[RunInSeparateProcess]
    public function test_the_redirect_to_a_sites_own_register_page_keeps_next(): void
    {
        $this->flowSite(['register' => '/join', 'next' => ['allow' => ['/games/']]], files: ['app/pages/join.twig' => 'JOIN']);
        $q = '?next=' . rawurlencode('/games/catan');

        $this->assertSame('/join' . $q, $this->send('GET', '/members/register' . $q)->getHeaderLine('Location'));
        $this->assertSame('/de/join' . $q, $this->send('GET', '/de/members/register' . $q)->getHeaderLine('Location'));
        $this->assertSame('/join', $this->send('GET', '/members/register?next=' . rawurlencode('//evil.test'))->getHeaderLine('Location'));
        $this->assertSame('/join', $this->send('GET', '/members/register')->getHeaderLine('Location'));
    }

    #[RunInSeparateProcess]
    public function test_registration_and_confirmation_land_on_next_once(): void
    {
        $this->flowSite();

        $this->register(['next' => '/members/r/x']);
        $response = $this->post('/members/confirm', ['token' => $this->linkToken(0, 'confirm')]);

        $this->assertSame(303, $response->getStatusCode());
        $this->assertSame('/members/r/x', $response->getHeaderLine('Location'));
        $this->assertSame('', (string) $this->member('ada@example.com')->get('next'), 'used once');
        $this->assertSame('/members', $this->send('GET', '/members/login')->getHeaderLine('Location'), 'a later visit goes to the landing');
    }

    #[RunInSeparateProcess]
    public function test_an_allowed_path_survives_registration_and_confirmation(): void
    {
        $this->flowSite(['next' => ['allow' => ['/games/']]]);

        $this->register(['next' => '/de/games/catan'], '/de/members');
        $response = $this->post('/de/members/confirm', ['token' => $this->linkToken(0, 'confirm')]);

        $this->assertSame('/de/games/catan', $response->getHeaderLine('Location'));
    }

    #[RunInSeparateProcess]
    public function test_a_foreign_next_is_not_stored_and_confirmation_lands_on_the_landing(): void
    {
        $this->flowSite();

        $this->register(['next' => '/games/catan']);
        $this->assertSame('', (string) ($this->member('ada@example.com')->get('next') ?? ''));
        $response = $this->post('/members/confirm', ['token' => $this->linkToken(0, 'confirm')]);

        $this->assertSame('/members', $response->getHeaderLine('Location'));
    }

    #[RunInSeparateProcess]
    public function test_sign_in_follows_an_allowed_next(): void
    {
        $this->flowSite(['next' => ['allow' => ['/games/']]]);
        $this->makeMember();

        $response = $this->post('/members/login', ['email' => 'ada@example.com', 'password' => self::PASSWORD, 'next' => '/games/catan']);
        $this->assertSame('/games/catan', $response->getHeaderLine('Location'));
        $this->post('/members/logout', []);

        $response = $this->post('/members/login', ['email' => 'ada@example.com', 'password' => self::PASSWORD, 'next' => '/games/../folio']);
        $this->assertSame('/members', $response->getHeaderLine('Location'));
    }

    /** @param array<string, string> $over */
    private function register(array $over = [], string $base = '/members'): ResponseInterface
    {
        return $this->post($base . '/register', $over + [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'password' => self::PASSWORD,
            'consent' => '1',
        ]);
    }
}
