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
}
