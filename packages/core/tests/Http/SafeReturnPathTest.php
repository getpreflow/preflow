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
}
