<?php

declare(strict_types=1);

namespace Preflow\Members\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Preflow\Data\Locales;
use Preflow\Members\Http\SafeNext;
use Preflow\Members\Links;
use Preflow\Members\MembersConfig;

/** Where a login may send the member: only member pages of this site, never another host. */
final class SafeNextTest extends TestCase
{
    /** @param array<string, mixed> $config @param list<string> $locales */
    private function safe(array $config = [], array $locales = ['en', 'de']): SafeNext
    {
        $members = new MembersConfig($config);

        return new SafeNext($members, new Links('https://site.test', $members->path(), new Locales($locales, $locales[0])));
    }

    /** @return iterable<string, array{0: string, 1: string, 2?: string}> */
    public static function nextCases(): iterable
    {
        foreach (['//evil.test', 'https://evil.test', '/\\evil.test', '/members/../folio', '/membersfoo', 'javascript:alert(1)', '', "/members\nSet-Cookie: x=1", '/members//x', '/members\\x', '/members/%2e%2e/folio', '/members/.%2E/%2e%2e/folio', '/members/%252e%252e/folio', '/members?a%00', "/members?a\x00", '/members/%2f/x', '/members/%5cx', '/fr/members', '/en/members'] as $bad) {
            yield 'reject ' . json_encode($bad) => [$bad, '/members'];
        }
        yield 'german fallback' => ['//evil.test', '/de/members', 'de'];
        foreach (['/members', '/members/r/force-map', '/de/members/r/x?y=1', '/de/members'] as $good) {
            yield 'keep ' . $good => [$good, $good];
        }
    }

    #[DataProvider('nextCases')]
    public function test_safe_next_rejects_foreign_and_traversal(string $next, string $expected, string $locale = 'en'): void
    {
        $this->assertSame($expected, $this->safe()->of($next, $locale));
        $this->assertSame($locale === 'de' ? '/de/members' : '/members', $this->safe()->of(null, $locale));
    }

    public function test_safe_next_follows_the_path_the_landing_and_the_locales(): void
    {
        $safe = $this->safe(['path' => '/konto', 'landing' => '/konto/objekte'], ['de', 'fr']);

        $this->assertSame('/konto/objekte', $safe->of(null, 'de'));
        $this->assertSame('/fr/konto/objekte', $safe->of('//evil.test', 'fr'));
        $this->assertSame('/fr/konto/x?y=1', $safe->of('/fr/konto/x?y=1', 'fr'));
        $this->assertSame('/konto', $safe->of('/konto', 'fr'));
        $this->assertSame('/fr/konto/objekte', $safe->of('/de/konto', 'fr'), 'the default locale has no prefix');
        $this->assertSame('/konto/objekte', $safe->of('/members', 'de'));
        $this->assertSame('/konto/objekte', $safe->of('/konto.evil', 'de'));
    }

    public function test_without_locale_prefixes_only_the_bare_path_counts(): void
    {
        $members = new MembersConfig(['path' => '/tools']);
        $safe = new SafeNext($members, new Links('https://site.test', '/tools', new Locales(['en', 'de'], 'en'), prefixed: false));

        $this->assertSame('/tools/x', $safe->of('/tools/x', 'de'));
        $this->assertSame('/tools', $safe->of('/de/tools/x', 'de'));
    }
}
