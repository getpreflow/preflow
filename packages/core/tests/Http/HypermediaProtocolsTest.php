<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\Http;

require_once __DIR__ . '/../Fixtures/Pluggable/SignalDriver.php';

use PHPUnit\Framework\TestCase;
use Preflow\Core\Http\Hypermedia\HypermediaProtocols;
use Preflow\Core\Tests\Fixtures\Pluggable\SignalDriver;
use Preflow\Htmx\HtmxDriver;
use Preflow\Htmx\LazyHypermediaDriver;
use Preflow\Htmx\ResponseHeaders;
use Preflow\View\AssetCollector;
use Preflow\View\NonceGenerator;

final class HypermediaProtocolsTest extends TestCase
{
    public function test_clients_are_told_apart_by_the_class_they_stand_for(): void
    {
        $protocols = new HypermediaProtocols();
        $protocols->add(new LazyHypermediaDriver(static fn () => new SignalDriver(new AssetCollector(new NonceGenerator())), SignalDriver::class));
        $protocols->add(new LazyHypermediaDriver(static fn () => new HtmxDriver(new ResponseHeaders()), HtmxDriver::class));
        $this->assertCount(2, $protocols->all(), 'two wrapped drivers stay two');

        $protocols->add(new HtmxDriver(new ResponseHeaders()));
        $this->assertCount(2, $protocols->all(), 'htmx once: the wrapper stands for it');
    }

    /** @since 0.84.0 */
    public function test_an_html_answer_varies_on_the_clients_request_headers(): void
    {
        $protocols = new HypermediaProtocols();
        $protocols->add(new HtmxDriver(new ResponseHeaders()));
        $factory = new \Nyholm\Psr7\Factory\Psr17Factory();
        $html = ['Content-Type' => 'text/html', 'Vary' => 'Accept-Encoding'];

        foreach ([[], ['HX-Request' => 'true'], ['HX-Request' => 'true', 'HX-History-Restore-Request' => 'true']] as $headers) {
            $request = $factory->createServerRequest('GET', '/x');
            foreach ($headers as $n => $v) {
                $request = $request->withHeader($n, $v);
            }
            $out = $protocols->varying($request, new \Nyholm\Psr7\Response(200, $html));
            $names = array_map(trim(...), explode(',', strtolower($out->getHeaderLine('Vary'))));
            $this->assertContains('hx-request', $names);
            $this->assertContains('accept-encoding', $names, 'what was there stays');
            $this->assertSame($out->getHeaderLine('Vary'), $protocols->varying($request, $out)->getHeaderLine('Vary'), 'each name once');
        }

        $star = new \Nyholm\Psr7\Response(200, ['Content-Type' => 'text/html', 'Vary' => '*']);
        $this->assertSame($star, $protocols->varying($factory->createServerRequest('GET', '/x'), $star));
        $json = new \Nyholm\Psr7\Response(200, ['Content-Type' => 'application/json']);
        $this->assertSame($json, $protocols->varying($factory->createServerRequest('GET', '/x'), $json));
    }
}
