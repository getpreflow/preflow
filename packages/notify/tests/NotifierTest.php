<?php

declare(strict_types=1);

namespace Preflow\Notify\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Notify\Email;
use Preflow\Notify\Message;
use Preflow\Notify\Notifier;
use Preflow\Notify\SentMessage;
use Preflow\Notify\Sms;
use Preflow\Notify\Transport;
use Preflow\Notify\TransportException;
use Preflow\Notify\TransportFactory;

final class NotifierTest extends TestCase
{
    private TransportFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new TransportFactory([
            'memA' => ['driver' => 'memory'],
            'memB' => ['driver' => 'memory'],
            'emailOnly' => ['driver' => 'email-only'],
        ], sys_get_temp_dir());

        $this->factory->extend('email-only', fn () => new class implements Transport {
            public function supports(Message $message): bool
            {
                return $message instanceof Email;
            }

            public function send(Message $message): SentMessage
            {
                return new SentMessage($message, 'emailOnly');
            }
        });
    }

    public function test_notifier_routes_by_channel(): void
    {
        $notifier = new Notifier($this->factory, [
            'email' => ['transport' => 'memA'],
            'sms' => ['transport' => 'memB'],
        ]);

        $notifier->send(new Email('a@b.test', 'S', 'T'));
        $notifier->send(new Sms('+41790000000', 'T'));

        $this->assertInstanceOf(Email::class, $this->factory->get('memA')->sent()[0]->message);
        $this->assertCount(1, $this->factory->get('memA')->sent());
        $this->assertInstanceOf(Sms::class, $this->factory->get('memB')->sent()[0]->message);
        $this->assertCount(1, $this->factory->get('memB')->sent());
    }

    public function test_channel_default_from_is_applied(): void
    {
        $notifier = new Notifier($this->factory, [
            'email' => ['transport' => 'memA', 'from' => 'site@x.test'],
            'sms' => ['transport' => 'memB', 'from' => 'Preflow'],
        ]);

        $withoutFrom = $notifier->send(new Email('a@b.test', 'S', 'T'));
        $withFrom = $notifier->send(new Email('a@b.test', 'S', 'T', from: 'me@x.test'));
        $sms = $notifier->send(new Sms('+41790000000', 'T'));

        $this->assertSame('site@x.test', $withoutFrom->message->from);
        $this->assertSame('me@x.test', $withFrom->message->from);
        $this->assertSame('Preflow', $sms->message->from);
    }

    public function test_missing_channel_message(): void
    {
        $notifier = new Notifier($this->factory, ['email' => ['transport' => 'memA']]);

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('No transport configured for channel "sms" (config/notify.php → channels.sms.transport).');
        $notifier->send(new Sms('+41790000000', 'T'));
    }

    public function test_unsupported_message(): void
    {
        $notifier = new Notifier($this->factory, ['sms' => ['transport' => 'emailOnly']]);

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('Transport "emailOnly" cannot send Sms messages.');
        $notifier->send(new Sms('+41790000000', 'T'));
    }
}
