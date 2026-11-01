<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\Http\Csrf;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Http\Csrf\OriginCheck;
use Preflow\Core\Http\Csrf\OriginVerdict;
use Psr\Http\Message\ServerRequestInterface;

final class OriginCheckTest extends TestCase
{
    private function request(array $headers, string $uri = 'https://club.test/x'): ServerRequestInterface
    {
        $request = (new Psr17Factory())->createServerRequest('POST', $uri);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $request;
    }

    /** @return iterable<string, array{array<string,string>, OriginVerdict}> */
    public static function cases(): iterable
    {
        yield 'sec-fetch same-origin' => [['Sec-Fetch-Site' => 'same-origin'], OriginVerdict::Pass];
        yield 'sec-fetch cross-site' => [['Sec-Fetch-Site' => 'cross-site'], OriginVerdict::Refuse];
        yield 'sec-fetch same-site' => [['Sec-Fetch-Site' => 'same-site'], OriginVerdict::Refuse];
        yield 'sec-fetch none' => [['Sec-Fetch-Site' => 'none'], OriginVerdict::Refuse];
        yield 'sec-fetch unknown value' => [['Sec-Fetch-Site' => 'weird'], OriginVerdict::Refuse];
        yield 'sec-fetch cross-site wins over own origin' => [
            ['Sec-Fetch-Site' => 'cross-site', 'Origin' => 'https://club.test'],
            OriginVerdict::Refuse,
        ];
        yield 'sec-fetch cross-site with trusted origin' => [
            ['Sec-Fetch-Site' => 'cross-site', 'Origin' => 'https://stage.club.test'],
            OriginVerdict::Pass,
        ];
        yield 'origin matches' => [['Origin' => 'https://club.test'], OriginVerdict::Pass];
        yield 'origin trusted' => [['Origin' => 'https://stage.club.test'], OriginVerdict::Pass];
        yield 'origin foreign' => [['Origin' => 'https://evil.test'], OriginVerdict::Refuse];
        yield 'origin null' => [['Origin' => 'null'], OriginVerdict::Unknown];
        yield 'origin null falls back to referer' => [
            ['Origin' => 'null', 'Referer' => 'https://club.test/form'],
            OriginVerdict::Pass,
        ];
        yield 'origin null with foreign referer' => [
            ['Origin' => 'null', 'Referer' => 'https://evil.test/'],
            OriginVerdict::Refuse,
        ];
        yield 'origin unparsable' => [['Origin' => 'not a url'], OriginVerdict::Unknown];
        yield 'referer own' => [['Referer' => 'https://club.test/form'], OriginVerdict::Pass];
        yield 'referer foreign' => [['Referer' => 'https://evil.test/'], OriginVerdict::Refuse];
        yield 'nothing' => [[], OriginVerdict::Unknown];
        yield 'default port equals bare origin' => [['Origin' => 'https://club.test:443'], OriginVerdict::Pass];
        yield 'host case is ignored' => [['Origin' => 'https://CLUB.test'], OriginVerdict::Pass];
        yield 'other port is foreign' => [['Origin' => 'https://club.test:8443'], OriginVerdict::Refuse];
        yield 'other scheme is foreign' => [['Origin' => 'http://club.test'], OriginVerdict::Refuse];
    }

    /** @param array<string,string> $headers */
    #[DataProvider('cases')]
    public function test_verdict(array $headers, OriginVerdict $expected): void
    {
        $check = new OriginCheck('https://club.test', ['https://stage.club.test']);

        $this->assertSame($expected, $check->verdict($this->request($headers)));
    }

    public function test_same_site_is_refused_unless_trusted(): void
    {
        $check = new OriginCheck('https://club.test', ['https://stage.club.test']);

        $this->assertSame(OriginVerdict::Refuse, $check->verdict($this->request([
            'Sec-Fetch-Site' => 'same-site',
            'Origin' => 'https://other.club.test',
        ])));
        $this->assertSame(OriginVerdict::Pass, $check->verdict($this->request([
            'Sec-Fetch-Site' => 'same-site',
            'Origin' => 'https://stage.club.test',
        ])));
    }

    public function test_request_own_origin_passes_when_app_url_differs(): void
    {
        $check = new OriginCheck('https://www.club.test');

        $this->assertSame(OriginVerdict::Pass, $check->verdict($this->request(['Origin' => 'https://club.test'])));
        $this->assertSame(OriginVerdict::Pass, $check->verdict($this->request(['Origin' => 'https://www.club.test'])));
    }

    public function test_without_app_url_only_request_origin_counts(): void
    {
        $check = new OriginCheck(null);

        $this->assertSame(OriginVerdict::Pass, $check->verdict($this->request(['Origin' => 'https://club.test'])));
        $this->assertSame(OriginVerdict::Refuse, $check->verdict($this->request(['Origin' => 'https://evil.test'])));
    }
}
