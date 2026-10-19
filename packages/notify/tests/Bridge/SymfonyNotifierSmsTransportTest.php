<?php

declare(strict_types=1);

namespace Preflow\Notify\Tests\Bridge;

use PHPUnit\Framework\TestCase;
use Preflow\Notify\Bridge\SymfonyNotifier\SymfonyNotifierSmsTransport;
use Preflow\Notify\Email;
use Preflow\Notify\Sms;
use Preflow\Notify\TransportException;
use Preflow\Notify\TransportFactory;

final class SymfonyNotifierSmsTransportTest extends TestCase
{
    public function test_sms_sends_through_null_dsn(): void
    {
        $sent = (new SymfonyNotifierSmsTransport('null://null', 'sms'))
            ->send(new Sms('+41790000000', 'Code 4711', 'Preflow'));

        $this->assertSame('sms', $sent->transport);
    }

    public function test_sms_bridge_does_not_support_email(): void
    {
        $transport = new SymfonyNotifierSmsTransport('null://null', 'sms');

        $this->assertTrue($transport->supports(new Sms('+41790000000', 'T')));
        $this->assertFalse($transport->supports(new Email('a@b.test', 'S', 'T')));
    }

    public function test_empty_dsn_fails_on_send(): void
    {
        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('Transport "sms": dsn is empty (set it in .env).');
        (new SymfonyNotifierSmsTransport('', 'sms'))->send(new Sms('+41790000000', 'T'));
    }

    public function test_factory_creates_bridges_from_config(): void
    {
        $factory = new TransportFactory([
            'smtp' => ['driver' => 'symfony-mailer', 'dsn' => 'null://null'],
            'texts' => ['driver' => 'symfony-notifier-sms', 'dsn' => 'null://null'],
        ], sys_get_temp_dir());

        $this->assertSame('texts', $factory->get('texts')->send(new Sms('+41790000000', 'T'))->transport);
        $this->assertSame(
            'smtp',
            $factory->get('smtp')->send(new Email('a@b.test', 'S', 'T', from: 'site@x.test'))->transport,
        );
    }
}
