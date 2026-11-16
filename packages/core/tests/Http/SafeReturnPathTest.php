<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Http\SafeReturnPath;

final class SafeReturnPathTest extends TestCase
{
    /** @return iterable<string, array{0: ?string}> */
    public static function refused(): iterable
    {
        foreach ([
            null, '', '//evil.example', '/%2F%2Fevil.example', '/a/../b', '\\evil', '/\\evil.example', 'https://evil.example',
            'javascript:alert(1)', 'employer', '?a=b', '/employerx', '/employer.evil', '/employer/../folio', '/employer//x',
            '/employer\\x', '/employer/%2e%2e/folio', '/employer/.%2E/x', '/employer/%252e%252e/folio', '/employer/%25252e%25252e/x',
            '/employer/%2f/x', '/employer/%5cx', "/employer\nSet-Cookie: x=1", "/employer?a\x00", '/employer?a%00', '/employer/ä',
            '/employer#top', '/other', '/employer?q=a%20b',
        ] as $next) {
            yield 'refuse ' . json_encode($next) => [$next];
        }
    }

    /** @return iterable<string, array{0: string}> */
    public static function refusedOffSite(): iterable
    {
        foreach ([
            '//evil', '/%2F%2Fevil', '/%2f/evil', 'https://evil.example', 'HTTPS://evil.example', 'hTtP://evil', 'javascript:alert(1)',
            'JaVaScRiPt:x', '/%5Cevil', '/%5cevil', '/%255Cevil', '/%25252Fevil', "/\tevil", "/\t/evil", "/%09/evil", "/\r\n/evil",
            "/x\r\nSet-Cookie: a=b", '/x%0d%0aSet-Cookie:a=b', '/x%0A', "/x\0", '/x%00', '/@evil', '/de#//evil', '/%2e%2e/x', '/%2E%2E/x',
            '/de/../x', '/de/%252e%252e/x', "/de/\xC3\xBCber", "/de/%C0%AF", '/de/%FF', '/de/%E2%80', '/de\\evil', '/ /evil',
            "/\x0B/evil", '/%0B/evil', '/%20/evil',
        ] as $next) {
            yield 'refuse ' . json_encode($next) => [$next];
        }
    }

    #[DataProvider('refusedOffSite')]
    public function test_every_off_site_form_stays_refused_under_a_root_prefix(string $next): void
    {
        $this->assertNull(SafeReturnPath::check($next, ['/']));
    }

    /** @return iterable<string, array{0: string}> */
    public static function acceptedSlugs(): iterable
    {
        foreach ([
            '/de/%C3%BCber-uns', '/de/a+b', '/de/a,b', '/de/x:y', '/de/a@b', '/de/(x)', '/de/a;b=c', "/de/it's", '/de/a!$*b',
            '/de/%E6%97%A5%E6%9C%AC', '/de/a%2Bb', '/de/.', '/de/%C3%BCber-uns?x=%C3%BC',
        ] as $next) {
            yield 'accept ' . $next => [$next];
        }
    }

    #[DataProvider('acceptedSlugs')]
    public function test_accepts_encoded_utf8_and_sub_delims_in_path_segments(string $next): void
    {
        $this->assertSame($next, SafeReturnPath::check($next, ['/']));
    }

    #[DataProvider('refused')]
    public function test_refuses_urls_traversal_and_other_paths(?string $next): void
    {
        $this->assertNull(SafeReturnPath::check($next, ['/employer', '/account']));
    }

    /** @return iterable<string, array{0: string}> */
    public static function kept(): iterable
    {
        foreach (['/employer', '/employer/jobs', '/employer/jobs/42?tab=applicants&x=a%2Cb', '/employer?a=b', '/account/x', '/employer/'] as $next) {
            yield 'keep ' . $next => [$next];
        }
    }

    #[DataProvider('kept')]
    public function test_keeps_paths_under_a_prefix_with_their_query(string $next): void
    {
        $this->assertSame($next, SafeReturnPath::check($next, ['/employer/', '/account']));
    }

    public function test_a_root_prefix_allows_every_safe_path_and_no_prefix_allows_none(): void
    {
        $this->assertSame('/', SafeReturnPath::check('/', ['/']));
        $this->assertSame('/any/where?x=1', SafeReturnPath::check('/any/where?x=1', ['/']));
        $this->assertNull(SafeReturnPath::check('//evil.example', ['/']));
        $this->assertNull(SafeReturnPath::check('/a/../b', ['/']));
        $this->assertNull(SafeReturnPath::check('/employer', []));
    }

    /** @return iterable<string, array{0: string}> */
    public static function badPrefixes(): iterable
    {
        foreach (['', 'employer', 'https://site.test/employer'] as $prefix) {
            yield json_encode($prefix) => [$prefix];
        }
    }

    #[DataProvider('badPrefixes')]
    public function test_a_prefix_that_is_no_path_fails(string $prefix): void
    {
        $this->expectExceptionMessage('SafeReturnPath: an allowed prefix is a path starting with "/"');
        $this->expectException(\InvalidArgumentException::class);
        SafeReturnPath::check('/employer', ['/account', $prefix]);
    }
}
