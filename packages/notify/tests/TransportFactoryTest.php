<?php

declare(strict_types=1);

namespace Preflow\Notify\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Notify\Email;
use Preflow\Notify\Transport\FailoverTransport;
use Preflow\Notify\Transport\InMemoryTransport;
use Preflow\Notify\TransportException;
use Preflow\Notify\TransportFactory;

final class TransportFactoryTest extends TestCase
{
    public function test_get_is_lazy_and_memoized(): void
    {
        $factory = new TransportFactory(['x' => ['driver' => 'counting']], sys_get_temp_dir());
        $calls = 0;
        $factory->extend('counting', function (array $config, string $name) use (&$calls) {
            $calls++;
            return new InMemoryTransport($name);
        });

        $this->assertSame(0, $calls);

        $first = $factory->get('x');
        $second = $factory->get('x');

        $this->assertSame(1, $calls);
        $this->assertSame($first, $second);
    }

    public function test_unknown_transport_message(): void
    {
        $factory = new TransportFactory([], sys_get_temp_dir());

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('Unknown transport "smtp". Define it under \'transports\' in config/notify.php.');
        $factory->get('smtp');
    }

    public function test_unknown_driver_message(): void
    {
        $factory = new TransportFactory(['x' => ['driver' => 'nope']], sys_get_temp_dir());

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('Transport "x" uses driver "nope", which is not available.');
        $factory->get('x');
    }

    public function test_built_in_drivers(): void
    {
        $factory = new TransportFactory([
            'm' => ['driver' => 'memory'],
            'n' => ['driver' => 'null'],
            'l' => ['driver' => 'log'],
        ], sys_get_temp_dir());

        $this->assertTrue($factory->hasDriver('memory'));
        $this->assertTrue($factory->hasDriver('null'));
        $this->assertTrue($factory->hasDriver('log'));
        $this->assertTrue($factory->hasDriver('failover'));
        $this->assertSame('m', $factory->get('m')->send(new Email('a@b.test', 'S', 'T'))->transport);
        $this->assertSame('n', $factory->get('n')->send(new Email('a@b.test', 'S', 'T'))->transport);
    }

    public function test_failover_driver_resolves_named_transports(): void
    {
        $factory = new TransportFactory([
            'mem' => ['driver' => 'memory'],
            'mail' => ['driver' => 'failover', 'transports' => ['mem']],
        ], sys_get_temp_dir());

        $failover = $factory->get('mail');

        $this->assertInstanceOf(FailoverTransport::class, $failover);
        $this->assertSame('mem', $failover->send(new Email('a@b.test', 'S', 'T'))->transport);
        $this->assertCount(1, $factory->get('mem')->sent());
    }

    public function test_failover_self_reference_is_detected(): void
    {
        $factory = new TransportFactory([
            'a' => ['driver' => 'failover', 'transports' => ['b']],
            'b' => ['driver' => 'failover', 'transports' => ['a']],
        ], sys_get_temp_dir());

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('references itself through failover');
        $factory->get('a');
    }
}
